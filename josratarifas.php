<?php
/**
 * Tarifas por grupo de cliente: precio fijo por producto para cada grupo
 * marcado como tarifa, sin overrides (hook actionProductPriceCalculation, PS 8.2+).
 *
 * @author josra
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/JosratarifasUpdater.php';

class Josratarifas extends Module
{
    const TABLE = 'josratarifas_precio';
    const TABLE_HISTORY = 'josratarifas_historial';
    const AJAX_CONTROLLER = 'AdminJosratarifasAjax';
    const CONF_SETTINGS = 'JOSRATARIFAS_SETTINGS';
    const MIN_PS = '8.2.0';
    const PAGE_SIZE = 50;

    /** @var array|null */
    protected static $settingsCache = null;

    /** @var array precios de tarifa ya leídos: [id_group][id_product] => float|null */
    protected static $priceCache = array();

    /** @var array datos de precio de combinaciones: [id_shop-id_product] */
    protected static $productDataCache = array();

    /** @var array tipo de IVA por regla de impuestos (país por defecto de la tienda) */
    protected static $taxRateCache = array();

    /** @var bool evita que el hook se llame a sí mismo al calcular el PVP */
    protected static $computingPvp = false;

    public function __construct()
    {
        $this->name = 'josratarifas';
        $this->tab = 'pricing_promotion';
        $this->version = '1.1.0';
        $this->author = 'josra';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => self::MIN_PS, 'max' => '9.99.99');

        parent::__construct();

        $this->displayName = $this->l('Tarifas por grupo de cliente');
        $this->description = $this->l('Precio fijo por producto para cada grupo de cliente (tarifa), con edición en línea, importación CSV y PVP original en la ficha y los listados.');
        $this->confirmUninstall = $this->l('Se borrarán todos los precios de tarifa guardados. ¿Seguro?');
    }

    /* ------------------------------------------------------------------ */
    /* Instalación                                                         */
    /* ------------------------------------------------------------------ */

    public function getUpdater()
    {
        return new JosratarifasUpdater($this);
    }

    /** Aviso periódico al servidor de actualizaciones (solo en el back-office) */
    public function hookActionAdminControllerSetMedia($params)
    {
        $this->getUpdater()->maybePing();
    }

    /** PS 8.1+: la versión nueva aparece en Gestor de módulos > Actualizaciones */
    public function hookActionListModules($params)
    {
        return $this->getUpdater()->getNativeModuleList();
    }

    protected function getOwnHooks()
    {
        return array(
            'actionProductPriceCalculation',
            'displayProductPriceBlock',
            'actionProductDelete',
            'actionObjectGroupDeleteAfter',
            'displayAdminProductsExtra',
        );
    }

    public function install()
    {
        if (version_compare(_PS_VERSION_, self::MIN_PS, '<')) {
            $this->_errors[] = sprintf(
                'Este módulo necesita PrestaShop %s o superior y esta tienda tiene la %s: el precio de tarifa se aplica con el hook actionProductPriceCalculation, que no existe en versiones anteriores, y sin él haría falta un override.',
                self::MIN_PS,
                _PS_VERSION_
            );

            return false;
        }
        if (Shop::isFeatureActive()) {
            $this->_errors[] = 'Este módulo no es compatible con multitienda: desactiva la multitienda o usa el módulo en una tienda sin ella.';

            return false;
        }

        return parent::install()
            && $this->createTable()
            && $this->installTab()
            && $this->registerOwnHooks()
            && $this->getUpdater()->registerHooks()
            && $this->saveSettings($this->getSettings());
    }

    public function uninstall()
    {
        $this->getUpdater()->notifyUninstall();

        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`');
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE_HISTORY . '`');
        $this->uninstallTab();
        Configuration::deleteByName(self::CONF_SETTINGS);
        Configuration::deleteByName('JOSRATARIFAS_UPD_TS');
        Configuration::deleteByName('JOSRATARIFAS_UPD_INFO');
        Configuration::deleteByName('JOSRATARIFAS_UPD_SU');
        Configuration::deleteByName('JOSRATARIFAS_UPD_LT');

        return parent::uninstall();
    }

    public function createTable()
    {
        return $this->createPriceTable() && Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_HISTORY . '` (
                `id_historial` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_group` INT(10) UNSIGNED NOT NULL,
                `id_product` INT(10) UNSIGNED NOT NULL,
                `old_price` DECIMAL(20,6) NULL DEFAULT NULL,
                `new_price` DECIMAL(20,6) NULL DEFAULT NULL,
                `source` VARCHAR(16) NOT NULL DEFAULT \'\',
                `id_employee` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_historial`),
                KEY `id_product` (`id_product`),
                KEY `id_group` (`id_group`),
                KEY `date_add` (`date_add`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    protected function createPriceTable()
    {
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
                `id_group` INT(10) UNSIGNED NOT NULL,
                `id_product` INT(10) UNSIGNED NOT NULL,
                `price` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_group`, `id_product`),
                KEY `id_product` (`id_product`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Controlador oculto para el AJAX (rejilla, ficha del producto, exportar). No sale en el menú.
     */
    public function installTab()
    {
        if ((int) Tab::getIdFromClassName(self::AJAX_CONTROLLER)) {
            return true;
        }
        $tab = new Tab();
        $tab->class_name = self::AJAX_CONTROLLER;
        $tab->module = $this->name;
        $tab->active = 0;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentModulesSf');
        if (!$tab->id_parent) {
            $tab->id_parent = -1;
        }
        $tab->name = array();
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = 'Tarifas por grupo (AJAX)';
        }

        return (bool) $tab->add();
    }

    protected function uninstallTab()
    {
        $idTab = (int) Tab::getIdFromClassName(self::AJAX_CONTROLLER);
        if ($idTab) {
            $tab = new Tab($idTab);

            return (bool) $tab->delete();
        }

        return true;
    }

    public function registerOwnHooks()
    {
        $ok = true;
        foreach ($this->getOwnHooks() as $hook) {
            if (!$this->isRegisteredInHook($hook)) {
                $ok = $this->registerHook($hook) && $ok;
            }
        }

        return $ok;
    }

    /**
     * Al abrir la configuración: tabla, hooks y ajustes al día, por si el
     * módulo se actualizó subiendo archivos por FTP.
     */
    protected function selfRepair()
    {
        $this->createTable();
        $this->installTab();
        $this->registerOwnHooks();
        if (Configuration::getGlobalValue(self::CONF_SETTINGS) === false) {
            $this->saveSettings($this->getSettings());
        }
    }

    /* ------------------------------------------------------------------ */
    /* Ajustes                                                             */
    /* ------------------------------------------------------------------ */

    protected function getDefaults()
    {
        return array(
            'groups' => array(),
            'ref_group' => (int) Configuration::get('PS_CUSTOMER_GROUP'),
            'tax_input' => 1,
            'pvp_show' => 1,
            'pvp_label' => 'PVP:',
            'pvp_color' => '#7a7a7a',
            'pvp_size' => 13,
            'pvp_strike' => 0,
            'pvp_sheet' => 1,
            'pvp_pos_sheet' => 'weight',
            'pvp_pos_list' => 'before_price',
            'pvp_list' => 1,
        );
    }

    /** Ajustes guardados mezclados con los de por defecto: siempre completos */
    public function getSettings()
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }
        $saved = json_decode((string) Configuration::getGlobalValue(self::CONF_SETTINGS), true);
        $settings = array_merge($this->getDefaults(), is_array($saved) ? $saved : array());
        $settings['groups'] = array_values(array_unique(array_map('intval', (array) $settings['groups'])));
        self::$settingsCache = $settings;

        return $settings;
    }

    protected function saveSettings(array $settings)
    {
        self::$settingsCache = null;

        return Configuration::updateGlobalValue(self::CONF_SETTINGS, json_encode($settings));
    }

    protected function sheetPositions()
    {
        return array(
            'weight' => 'Debajo del precio (recomendado en Classic)',
            'after_price' => 'Junto al texto de impuestos',
            'price' => 'Antes del texto de impuestos',
        );
    }

    protected function listPositions()
    {
        return array(
            'before_price' => 'Antes del precio',
            'unit_price' => 'Después del precio (también en Warehouse)',
            'weight' => 'Debajo del precio',
        );
    }

    /** Grupos marcados como tarifa (sin el grupo del PVP) */
    public function getTariffGroups()
    {
        $settings = $this->getSettings();

        return array_values(array_diff($settings['groups'], array((int) $settings['ref_group'])));
    }

    public function isTariffGroup($idGroup)
    {
        return $idGroup > 0 && in_array((int) $idGroup, $this->getTariffGroups(), true);
    }

    /* ------------------------------------------------------------------ */
    /* Cálculo del precio                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Precio de tarifa sin IVA de un producto para un grupo, o null si no tiene.
     */
    public function getTariffPrice($idGroup, $idProduct)
    {
        $idGroup = (int) $idGroup;
        $idProduct = (int) $idProduct;
        if (!isset(self::$priceCache[$idGroup]) || !array_key_exists($idProduct, self::$priceCache[$idGroup])) {
            $value = Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
                'SELECT `price` FROM `' . _DB_PREFIX_ . self::TABLE . '`
                WHERE `id_group` = ' . $idGroup . ' AND `id_product` = ' . $idProduct
            );
            self::$priceCache[$idGroup][$idProduct] = ($value === false || $value === null) ? null : (float) $value;
        }

        return self::$priceCache[$idGroup][$idProduct];
    }

    /**
     * Impacto de precio y ecotasa del producto y sus combinaciones, como los lee
     * Product::priceCalculation(): la combinación 0 es la combinación por defecto.
     */
    protected function getProductPriceData($idShop, $idProduct)
    {
        $key = (int) $idShop . '-' . (int) $idProduct;
        if (isset(self::$productDataCache[$key])) {
            return self::$productDataCache[$key];
        }

        $data = array('ecotax' => 0.0, 'attributes' => array());
        $row = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow(
            'SELECT `ecotax` FROM `' . _DB_PREFIX_ . 'product_shop`
            WHERE `id_product` = ' . (int) $idProduct . ' AND `id_shop` = ' . (int) $idShop
        );
        if ($row) {
            $data['ecotax'] = (float) $row['ecotax'];
        }
        if (Combination::isFeatureActive()) {
            $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
                'SELECT `id_product_attribute`, `price`, `ecotax`, `default_on`
                FROM `' . _DB_PREFIX_ . 'product_attribute_shop`
                WHERE `id_product` = ' . (int) $idProduct . ' AND `id_shop` = ' . (int) $idShop
            );
            foreach ((array) $rows as $r) {
                $item = array('price' => (float) $r['price'], 'ecotax' => (float) $r['ecotax']);
                $data['attributes'][(int) $r['id_product_attribute']] = $item;
                if ((int) $r['default_on'] === 1) {
                    $data['attributes'][0] = $item;
                }
            }
        }
        self::$productDataCache[$key] = $data;

        return $data;
    }

    /**
     * PrestaShop 8.2+: llamado al final de Product::priceCalculation(), antes de
     * redondear. Si el grupo es una tarifa y el producto tiene precio en ella, se
     * rehace el precio desde el precio de tarifa: + impacto de la combinación,
     * + personalización, + IVA, + ecotasa y - reducciones nativas (precios
     * específicos por cantidad, promociones). La reducción por % del grupo no se
     * aplica a los productos con tarifa.
     */
    public function hookActionProductPriceCalculation($params)
    {
        if (self::$computingPvp || empty($params['id_group']) || empty($params['id_product'])) {
            return;
        }
        $idGroup = (int) $params['id_group'];
        if (!$this->isTariffGroup($idGroup)) {
            return;
        }
        $idProduct = (int) $params['id_product'];
        $tariff = $this->getTariffPrice($idGroup, $idProduct);
        if ($tariff === null) {
            return;
        }

        $idShop = !empty($params['id_shop']) ? (int) $params['id_shop'] : (int) $this->context->shop->id;
        $idCurrency = (int) $params['id_currency'];
        $idProductAttribute = $params['id_product_attribute'];
        $specificPrice = isset($params['specific_price']) ? $params['specific_price'] : null;
        $data = $this->getProductPriceData($idShop, $idProduct);

        $price = Tools::convertPrice($tariff, $idCurrency);

        // Impacto de la combinación (false = sin combinación)
        $attribute = null;
        if ($idProductAttribute !== false && isset($data['attributes'][(int) $idProductAttribute])) {
            $attribute = $data['attributes'][(int) $idProductAttribute];
            $price += Tools::convertPrice($attribute['price'], $idCurrency);
        }

        if (!empty($params['id_customization'])) {
            $price += Tools::convertPrice(Customization::getCustomizationPrice((int) $params['id_customization']), $idCurrency);
        }

        $address = isset($params['address']) && $params['address'] instanceof Address ? $params['address'] : new Address();
        $context = isset($params['context']) && $params['context'] instanceof Context ? $params['context'] : $this->context;
        $taxCalculator = TaxManagerFactory::getManager($address, Product::getIdTaxRulesGroupByIdProduct($idProduct, $context))->getTaxCalculator();

        if (!empty($params['use_tax'])) {
            $price = $taxCalculator->addTaxes($price);
        }

        // Ecotasa, igual que el núcleo
        if (!empty($params['with_ecotax'])) {
            $ecotax = $data['ecotax'];
            if ($attribute !== null && $attribute['ecotax'] > 0) {
                $ecotax = $attribute['ecotax'];
            }
            if ($ecotax > 0) {
                if ($idCurrency) {
                    $ecotax = Tools::convertPrice($ecotax, $idCurrency);
                }
                if (!empty($params['use_tax'])) {
                    $ecotaxCalculator = TaxManagerFactory::getManager($address, (int) Configuration::get('PS_ECOTAX_TAX_RULES_GROUP_ID'))->getTaxCalculator();
                    $price += $ecotaxCalculator->addTaxes($ecotax);
                } else {
                    $price += $ecotax;
                }
            }
        }

        // Reducciones nativas (precios específicos) encima del precio de tarifa
        $reduction = 0;
        if ((!empty($params['only_reduc']) || !empty($params['use_reduc'])) && is_array($specificPrice) && !empty($specificPrice['reduction'])) {
            if ($specificPrice['reduction_type'] == 'amount') {
                $reduction = (float) $specificPrice['reduction'];
                if (empty($specificPrice['id_currency'])) {
                    $reduction = Tools::convertPrice($reduction, $idCurrency);
                }
                if (empty($params['use_tax']) && !empty($specificPrice['reduction_tax'])) {
                    $reduction = $taxCalculator->removeTaxes($reduction);
                }
                if (!empty($params['use_tax']) && empty($specificPrice['reduction_tax'])) {
                    $reduction = $taxCalculator->addTaxes($reduction);
                }
            } else {
                $reduction = $price * (float) $specificPrice['reduction'];
            }
        }
        if (!empty($params['use_reduc'])) {
            $price -= $reduction;
        }

        $params['specific_price_reduction'] = $reduction;
        $params['price'] = $price;
    }

    /**
     * PVP del grupo de referencia (el precio normal de la tienda), con el IVA
     * como lo ve el cliente actual.
     */
    protected function getPvp($idProduct, $idProductAttribute)
    {
        $context = $this->context;
        $settings = $this->getSettings();
        $idCustomer = Validate::isLoadedObject($context->customer) ? (int) $context->customer->id : 0;

        $idAddress = null;
        if (Validate::isLoadedObject($context->cart)) {
            $idAddress = $context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')};
        }
        $address = Address::initialize($idAddress, true);

        $useTax = Configuration::get('PS_TAX') && !Product::getTaxCalculationMethod($idCustomer);
        if ($useTax && !empty($address->vat_number)
            && $address->id_country != Configuration::get('VATNUMBER_COUNTRY')
            && Configuration::get('VATNUMBER_MANAGEMENT')) {
            $useTax = false;
        }

        $idCurrency = Validate::isLoadedObject($context->currency) ? (int) $context->currency->id : (int) Currency::getDefaultCurrencyId();
        $specificPrice = null;
        self::$computingPvp = true;
        try {
            $price = Product::priceCalculation(
                (int) $context->shop->id,
                (int) $idProduct,
                $idProductAttribute === null ? null : (int) $idProductAttribute,
                (int) $address->id_country,
                (int) $address->id_state,
                $address->postcode,
                $idCurrency,
                (int) $settings['ref_group'],
                1,
                $useTax,
                6,
                false,
                true,
                true,
                $specificPrice,
                true,
                0,
                false,
                0,
                0
            );
        } catch (Exception $e) {
            $price = null;
        }
        self::$computingPvp = false;

        return $price === null ? null : (float) $price;
    }

    /**
     * PVP en pequeño en la ficha y en los listados, para clientes con tarifa.
     */
    public function hookDisplayProductPriceBlock($params)
    {
        $settings = $this->getSettings();
        if (empty($settings['pvp_show']) || empty($params['product']) || empty($params['type'])) {
            return '';
        }
        $page = isset($this->context->controller->php_self) ? (string) $this->context->controller->php_self : '';
        if (in_array($page, array('cart', 'order', 'order-confirmation', 'order-detail', 'history'), true)) {
            return '';
        }

        $type = (string) $params['type'];
        $origin = isset($params['hook_origin']) ? (string) $params['hook_origin'] : '';
        $isSheet = !empty($settings['pvp_sheet']) && $type === $settings['pvp_pos_sheet']
            && ($type !== 'weight' || $origin === 'product_sheet');
        $isList = !$isSheet && !empty($settings['pvp_list']) && $type === $settings['pvp_pos_list']
            && ($type !== 'weight' || $origin !== 'product_sheet');
        if (!$isSheet && !$isList) {
            return '';
        }

        $idGroup = (int) Group::getCurrent()->id;
        if (!$this->isTariffGroup($idGroup)) {
            return '';
        }

        $product = $params['product'];
        $idProduct = 0;
        $idProductAttribute = null;
        $current = null;
        if ($product instanceof Product) {
            $idProduct = (int) $product->id;
        } elseif (is_array($product) || $product instanceof ArrayAccess) {
            $idProduct = isset($product['id_product']) ? (int) $product['id_product'] : 0;
            if (isset($product['id_product_attribute'])) {
                $idProductAttribute = (int) $product['id_product_attribute'];
            }
            if (isset($product['price_amount'])) {
                $current = (float) $product['price_amount'];
            }
        }
        if (!$idProduct || $this->getTariffPrice($idGroup, $idProduct) === null) {
            return '';
        }

        $pvp = $this->getPvp($idProduct, $idProductAttribute);
        if ($pvp === null || $pvp <= 0 || ($current !== null && abs($pvp - $current) < 0.005)) {
            return '';
        }

        $color = preg_match('/^#[0-9a-fA-F]{3,6}$/', (string) $settings['pvp_color']) ? $settings['pvp_color'] : '#7a7a7a';
        $this->context->smarty->assign(array(
            'josratarifas_label' => (string) $settings['pvp_label'],
            'josratarifas_pvp' => $this->formatPrice($pvp),
            'josratarifas_color' => $color,
            'josratarifas_size' => max(9, min(24, (int) $settings['pvp_size'])),
            'josratarifas_strike' => (int) $settings['pvp_strike'],
            'josratarifas_sheet' => $isSheet ? 1 : 0,
        ));

        return $this->fetch('module:' . $this->name . '/views/templates/hook/pvp.tpl');
    }

    protected function formatPrice($amount)
    {
        $currency = $this->context->currency;
        try {
            return $this->context->getCurrentLocale()->formatPrice($amount, $currency->iso_code);
        } catch (Exception $e) {
            return number_format($amount, 2, ',', '.') . ' ' . $currency->sign;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Limpieza                                                            */
    /* ------------------------------------------------------------------ */

    public function hookActionProductDelete($params)
    {
        $idProduct = isset($params['id_product']) ? (int) $params['id_product'] : 0;
        if ($idProduct) {
            Db::getInstance()->delete(self::TABLE, '`id_product` = ' . $idProduct);
        }
    }

    public function hookActionObjectGroupDeleteAfter($params)
    {
        if (empty($params['object']) || empty($params['object']->id)) {
            return;
        }
        $idGroup = (int) $params['object']->id;
        Db::getInstance()->delete(self::TABLE, '`id_group` = ' . $idGroup);
        $settings = $this->getSettings();
        $settings['groups'] = array_values(array_diff($settings['groups'], array($idGroup)));
        $this->saveSettings($settings);
    }

    /* ------------------------------------------------------------------ */
    /* Back-office                                                         */
    /* ------------------------------------------------------------------ */

    /** URL completa de la configuración (en PS 8/9 una relativa da 404) */
    public function adminUrl($withToken = true)
    {
        $adminDir = defined('_PS_ADMIN_DIR_') ? basename(_PS_ADMIN_DIR_) . '/' : '';

        return Tools::getShopDomainSsl(true) . __PS_BASE_URI__ . $adminDir
            . 'index.php?controller=AdminModules&configure=' . $this->name
            . '&tab_module=' . $this->tab . '&module_name=' . $this->name
            . ($withToken ? '&token=' . Tools::getAdminTokenLite('AdminModules') : '');
    }

    /**
     * URL de la página de configuración tal como se ha abierto (ruta Symfony en PS 8/9,
     * con su _token), para enlaces GET que no deben pasar por una redirección.
     */
    protected function currentPageUrl()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($uri === '' || strpos($uri, 'josratarifas_') !== false) {
            return $this->adminUrl();
        }
        $uri = preg_replace('/#.*$/', '', $uri);

        return Tools::getShopDomainSsl(true) . $uri . (strpos($uri, '?') === false ? '?' : '');
    }

    public function getContent()
    {
        $this->selfRepair();


        $updater = $this->getUpdater();
        $output = $updater->processRequest();

        $activeTab = 'prices';
        if (Tools::isSubmit('submitJosratarifasSettings')) {
            $output .= $this->processSettings();
            $activeTab = 'settings';
        }
        if (Tools::isSubmit('submitJosratarifasImport')) {
            $output .= $this->processImport();
            $activeTab = 'import';
        }
        if (!$this->getTariffGroups()) {
            $activeTab = Tools::isSubmit('submitJosratarifasImport') ? 'import' : 'settings';
        }

        $output .= $this->renderGroupWarnings();
        $content = $this->renderTabs($activeTab);

        $info = $updater->getInfo();
        $panel = $updater->renderPanel();

        return !empty($info['update_available'])
            ? $output . $panel . $content
            : $output . $content . $panel;
    }

    /** Todos los grupos con su nombre en el idioma por defecto */
    protected function getAllGroups()
    {
        $groups = array();
        foreach (Group::getGroups((int) Configuration::get('PS_LANG_DEFAULT')) as $g) {
            $groups[(int) $g['id_group']] = $g;
        }

        return $groups;
    }

    protected function processSettings()
    {
        $settings = $this->getSettings();
        $groups = $this->getAllGroups();

        $selected = array();
        foreach (array_keys($groups) as $idGroup) {
            if (Tools::getValue('tariff_group_' . $idGroup)) {
                $selected[] = (int) $idGroup;
            }
        }
        $refGroup = (int) Tools::getValue('ref_group');
        if (!isset($groups[$refGroup])) {
            return $this->displayError('El grupo del PVP no existe. Elige uno de la lista.');
        }
        if (in_array($refGroup, $selected, true)) {
            return $this->displayError(sprintf(
                'El grupo «%s» no puede ser a la vez tarifa y grupo del PVP: su precio es el que se enseña como PVP. Desmárcalo como tarifa o elige otro grupo para el PVP. No se ha guardado nada.',
                $groups[$refGroup]['name']
            ));
        }

        $color = trim((string) Tools::getValue('pvp_color'));
        if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{3,6}$/', $color)) {
            return $this->displayError('El color del PVP debe ser un código como #7a7a7a. No se ha guardado nada.');
        }
        $posSheet = (string) Tools::getValue('pvp_pos_sheet');
        $posList = (string) Tools::getValue('pvp_pos_list');

        $settings['groups'] = $selected;
        $settings['ref_group'] = $refGroup;
        $settings['tax_input'] = (int) (bool) Tools::getValue('tax_input');
        $settings['pvp_show'] = (int) (bool) Tools::getValue('pvp_show');
        $settings['pvp_label'] = Tools::substr(trim(strip_tags((string) Tools::getValue('pvp_label'))), 0, 60);
        $settings['pvp_color'] = $color !== '' ? $color : '#7a7a7a';
        $settings['pvp_size'] = max(9, min(24, (int) Tools::getValue('pvp_size')));
        $settings['pvp_strike'] = (int) (bool) Tools::getValue('pvp_strike');
        $settings['pvp_sheet'] = (int) (bool) Tools::getValue('pvp_sheet');
        $settings['pvp_list'] = (int) (bool) Tools::getValue('pvp_list');
        $settings['pvp_pos_sheet'] = isset($this->sheetPositions()[$posSheet]) ? $posSheet : 'weight';
        $settings['pvp_pos_list'] = isset($this->listPositions()[$posList]) ? $posList : 'before_price';

        if (!$this->saveSettings($settings)) {
            return $this->displayError('No se pudieron guardar los ajustes en la base de datos (tabla configuration).');
        }

        return $this->displayConfirmation('Ajustes guardados.');
    }

    /** Avisos de grupos de tarifa con reducción por %, que no se aplica a los productos con tarifa */
    protected function renderGroupWarnings()
    {
        $html = '';
        $groups = $this->getAllGroups();
        foreach ($this->getTariffGroups() as $idGroup) {
            if (!isset($groups[$idGroup])) {
                continue;
            }
            $name = $groups[$idGroup]['name'];
            if ((float) $groups[$idGroup]['reduction'] != 0) {
                $html .= $this->displayWarning(sprintf(
                    'La tarifa «%s» tiene una reducción de grupo del %s %%. En los productos con precio de tarifa esa reducción no se aplica; en los demás, sí. Si no la quieres, ponla a 0 en Clientes > Grupos.',
                    $name,
                    rtrim(rtrim(number_format((float) $groups[$idGroup]['reduction'], 2, ',', ''), '0'), ',')
                ));
            }
            $catReductions = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'group_reduction` WHERE `id_group` = ' . (int) $idGroup
            );
            if ($catReductions > 0) {
                $html .= $this->displayWarning(sprintf(
                    'La tarifa «%s» tiene %d reducciones por categoría. En los productos con precio de tarifa no se aplican; en los demás, sí.',
                    $name,
                    $catReductions
                ));
            }
        }

        return $html;
    }

    /** Categorías con sangría según su nivel, para el filtro */
    protected function categoryOptions()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT c.`id_category`, c.`level_depth`, cl.`name`
            FROM `' . _DB_PREFIX_ . 'category` c
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON (cl.`id_category` = c.`id_category`
                AND cl.`id_lang` = ' . (int) Configuration::get('PS_LANG_DEFAULT') . ' AND cl.`id_shop` = ' . (int) $this->context->shop->id . ')
            WHERE c.`id_parent` > 0
            ORDER BY c.`nleft`'
        );
        $out = array();
        foreach ((array) $rows as $r) {
            $out[] = array(
                'id' => (int) $r['id_category'],
                'name' => str_repeat('— ', max(0, (int) $r['level_depth'] - 1)) . $r['name'],
            );
        }

        return $out;
    }

    protected function renderTabs($activeTab)
    {
        $settings = $this->getSettings();
        $groups = $this->getAllGroups();
        $tariffs = array();

        $counts = array();
        foreach ((array) Db::getInstance()->executeS(
            'SELECT `id_group`, COUNT(*) AS n FROM `' . _DB_PREFIX_ . self::TABLE . '` GROUP BY `id_group`'
        ) as $r) {
            $counts[(int) $r['id_group']] = (int) $r['n'];
        }
        $customers = array();
        foreach ((array) Db::getInstance()->executeS(
            'SELECT `id_default_group`, COUNT(*) AS n FROM `' . _DB_PREFIX_ . 'customer`
            WHERE `deleted` = 0 GROUP BY `id_default_group`'
        ) as $r) {
            $customers[(int) $r['id_default_group']] = (int) $r['n'];
        }
        foreach ($this->getTariffGroups() as $idGroup) {
            if (!isset($groups[$idGroup])) {
                continue;
            }
            $tariffs[] = array(
                'id' => $idGroup,
                'name' => $groups[$idGroup]['name'],
                'products' => isset($counts[$idGroup]) ? $counts[$idGroup] : 0,
                'customers' => isset($customers[$idGroup]) ? $customers[$idGroup] : 0,
                'tax_display' => (int) $groups[$idGroup]['price_display_method'] === 1 ? 'sin IVA' : 'con IVA',
                'urls' => $this->groupUrls($idGroup),
            );
        }

        $this->context->smarty->assign(array(
            'jt_active' => $activeTab,
            'jt_tariffs' => $tariffs,
            'jt_ajax_url' => $this->ajaxUrl(),
            'jt_export_url' => $this->ajaxUrl() . '&jt_action=export_all',
            'jt_import_action' => $this->currentPageUrl(),
            'jt_categories' => $this->categoryOptions(),
            'jt_manufacturers' => Manufacturer::getManufacturers(false, (int) Configuration::get('PS_LANG_DEFAULT'), false),
            'jt_tax_input' => (int) $settings['tax_input'],
            'jt_settings_form' => $this->renderSettingsForm(),
            'jt_js_url' => $this->_path . 'views/js/admin.js?v=' . $this->version,
            'jt_ref_group' => isset($groups[(int) $settings['ref_group']]) ? $groups[(int) $settings['ref_group']]['name'] : '',
            'jt_page_size' => self::PAGE_SIZE,
        ));

        return $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    protected function renderSettingsForm()
    {
        $settings = $this->getSettings();
        $groups = $this->getAllGroups();

        $customers = array();
        foreach ((array) Db::getInstance()->executeS(
            'SELECT `id_default_group`, COUNT(*) AS n FROM `' . _DB_PREFIX_ . 'customer`
            WHERE `deleted` = 0 GROUP BY `id_default_group`'
        ) as $r) {
            $customers[(int) $r['id_default_group']] = (int) $r['n'];
        }

        $switchValues = array(
            array('id' => 'on', 'value' => 1, 'label' => $this->l('Sí')),
            array('id' => 'off', 'value' => 0, 'label' => $this->l('No')),
        );

        $groupInputs = array();
        $values = array();
        foreach ($groups as $idGroup => $g) {
            $groupInputs[] = array(
                'type' => 'switch',
                'label' => $g['name'],
                'name' => 'tariff_group_' . $idGroup,
                'is_bool' => true,
                'values' => $switchValues,
                'desc' => sprintf(
                    '%d clientes lo tienen como grupo por defecto · precios %s',
                    isset($customers[$idGroup]) ? $customers[$idGroup] : 0,
                    (int) $g['price_display_method'] === 1 ? 'sin IVA' : 'con IVA'
                ),
            );
            $values['tariff_group_' . $idGroup] = in_array((int) $idGroup, $settings['groups'], true) ? 1 : 0;
        }

        $groupOptions = array();
        foreach ($groups as $idGroup => $g) {
            $groupOptions[] = array('id' => $idGroup, 'name' => $g['name']);
        }
        $toOptions = function ($list) {
            $out = array();
            foreach ($list as $id => $name) {
                $out[] = array('id' => $id, 'name' => $name);
            }

            return $out;
        };
        $sizes = array();
        for ($i = 10; $i <= 18; ++$i) {
            $sizes[] = array('id' => $i, 'name' => $i . ' px');
        }

        $forms = array(
            array('form' => array(
                'legend' => array('title' => $this->l('Qué grupos son tarifa'), 'icon' => 'icon-group'),
                'description' => 'Cada grupo marcado es una tarifa. El precio de tarifa se aplica a los clientes que tienen ese grupo como grupo por defecto (PrestaShop calcula siempre el precio con el grupo por defecto). Los productos sin precio en su tarifa se venden a su precio normal.',
                'input' => $groupInputs,
                'submit' => array('title' => $this->l('Guardar'), 'name' => 'submitJosratarifasSettings'),
            )),
            array('form' => array(
                'legend' => array('title' => $this->l('Precios'), 'icon' => 'icon-euro'),
                'input' => array(
                    array(
                        'type' => 'select',
                        'label' => $this->l('Grupo del PVP'),
                        'name' => 'ref_group',
                        'options' => array('query' => $groupOptions, 'id' => 'id', 'name' => 'name'),
                        'desc' => 'Su precio es el que se enseña como PVP a los clientes con tarifa. No puede ser una tarifa.',
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Precios de tarifa con IVA'),
                        'name' => 'tax_input',
                        'is_bool' => true,
                        'values' => $switchValues,
                        'desc' => 'Sí: en la rejilla y en el CSV los precios de tarifa se escriben con IVA (el del país de la tienda) y se guardan sin IVA. No: se escriben sin IVA. Cambiarlo no modifica los precios guardados.',
                    ),
                ),
                'submit' => array('title' => $this->l('Guardar'), 'name' => 'submitJosratarifasSettings'),
            )),
            array('form' => array(
                'legend' => array('title' => $this->l('PVP en la tienda'), 'icon' => 'icon-tag'),
                'description' => 'Solo lo ven los clientes con tarifa y solo en productos con precio de tarifa.',
                'input' => array(
                    array('type' => 'switch', 'label' => $this->l('Mostrar el PVP'), 'name' => 'pvp_show', 'is_bool' => true, 'values' => $switchValues),
                    array('type' => 'text', 'label' => $this->l('Texto'), 'name' => 'pvp_label', 'class' => 'fixed-width-xl', 'desc' => 'Delante del precio, por ejemplo «PVP:».'),
                    array('type' => 'color', 'label' => $this->l('Color'), 'name' => 'pvp_color'),
                    array('type' => 'select', 'label' => $this->l('Tamaño'), 'name' => 'pvp_size', 'options' => array('query' => $sizes, 'id' => 'id', 'name' => 'name')),
                    array('type' => 'switch', 'label' => $this->l('Tachado'), 'name' => 'pvp_strike', 'is_bool' => true, 'values' => $switchValues),
                    array('type' => 'switch', 'label' => $this->l('En la ficha del producto'), 'name' => 'pvp_sheet', 'is_bool' => true, 'values' => $switchValues),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Posición en la ficha'),
                        'name' => 'pvp_pos_sheet',
                        'options' => array('query' => $toOptions($this->sheetPositions()), 'id' => 'id', 'name' => 'name'),
                        'desc' => 'Si tu tema no lo enseña en una posición, prueba otra.',
                    ),
                    array('type' => 'switch', 'label' => $this->l('En los listados'), 'name' => 'pvp_list', 'is_bool' => true, 'values' => $switchValues),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Posición en los listados'),
                        'name' => 'pvp_pos_list',
                        'options' => array('query' => $toOptions($this->listPositions()), 'id' => 'id', 'name' => 'name'),
                    ),
                ),
                'submit' => array('title' => $this->l('Guardar'), 'name' => 'submitJosratarifasSettings'),
            )),
        );

        foreach (array('ref_group', 'tax_input', 'pvp_show', 'pvp_label', 'pvp_color', 'pvp_size', 'pvp_strike', 'pvp_sheet', 'pvp_list', 'pvp_pos_sheet', 'pvp_pos_list') as $k) {
            $values[$k] = $settings[$k];
        }

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->identifier = 'id_module';
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = $this->currentPageUrl();
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->show_toolbar = false;
        $helper->submit_action = 'submitJosratarifasSettings';
        $helper->tpl_vars = array('fields_value' => $values);

        return $helper->generateForm($forms);
    }

    /* ------------------------------------------------------------------ */
    /* IVA en el back-office                                               */
    /* ------------------------------------------------------------------ */

    /** % de IVA de una regla de impuestos en el país por defecto de la tienda */
    protected function taxRate($idTaxRulesGroup)
    {
        $idTaxRulesGroup = (int) $idTaxRulesGroup;
        if (!Configuration::get('PS_TAX') || !$idTaxRulesGroup) {
            return 0.0;
        }
        if (!isset(self::$taxRateCache[$idTaxRulesGroup])) {
            $address = new Address();
            $address->id_country = (int) Configuration::get('PS_COUNTRY_DEFAULT');
            $address->id_state = 0;
            $address->postcode = '';
            self::$taxRateCache[$idTaxRulesGroup] = (float) TaxManagerFactory::getManager($address, $idTaxRulesGroup)
                ->getTaxCalculator()->getTotalRate();
        }

        return self::$taxRateCache[$idTaxRulesGroup];
    }

    /** "1.234,56", "19,5 €", "19.50" → float; null si no es un número válido */
    protected function parseNumber($value)
    {
        $value = str_replace(array("\xc2\xa0", ' ', '€'), '', trim((string) $value));
        if ($value === '') {
            return null;
        }
        if (strpos($value, ',') !== false) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }
        if (!preg_match('/^\d+(\.\d+)?$/', $value)) {
            return null;
        }

        return (float) $value;
    }

    protected function savePrice($idGroup, $idProduct, $priceTaxExcl)
    {
        self::$priceCache = array();

        return Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '` (`id_group`, `id_product`, `price`, `date_upd`)
            VALUES (' . (int) $idGroup . ', ' . (int) $idProduct . ', ' . number_format((float) $priceTaxExcl, 6, '.', '') . ', NOW())
            ON DUPLICATE KEY UPDATE `price` = VALUES(`price`), `date_upd` = VALUES(`date_upd`)'
        );
    }

    protected function deletePrice($idGroup, $idProduct)
    {
        self::$priceCache = array();

        return Db::getInstance()->delete(self::TABLE, '`id_group` = ' . (int) $idGroup . ' AND `id_product` = ' . (int) $idProduct);
    }

    /* ------------------------------------------------------------------ */
    /* Historial                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Guarda cambios en el historial. Cada fila: array(id_group, id_product, antes|null, después|null).
     */
    public function logHistory(array $changes, $source)
    {
        if (!$changes) {
            return true;
        }
        $idEmployee = isset($this->context->employee) && Validate::isLoadedObject($this->context->employee) ? (int) $this->context->employee->id : 0;
        $values = array();
        foreach ($changes as $c) {
            $values[] = '(' . (int) $c[0] . ', ' . (int) $c[1] . ', '
                . ($c[2] === null ? 'NULL' : number_format((float) $c[2], 6, '.', '')) . ', '
                . ($c[3] === null ? 'NULL' : number_format((float) $c[3], 6, '.', '')) . ', \''
                . pSQL($source) . '\', ' . $idEmployee . ', NOW())';
        }
        $ok = true;
        foreach (array_chunk($values, 500) as $chunk) {
            $ok = Db::getInstance()->execute(
                'INSERT INTO `' . _DB_PREFIX_ . self::TABLE_HISTORY . '`
                (`id_group`, `id_product`, `old_price`, `new_price`, `source`, `id_employee`, `date_add`) VALUES ' . implode(',', $chunk)
            ) && $ok;
        }

        return $ok;
    }

    /** Historial de más de 2 años: fuera */
    protected function purgeHistory()
    {
        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . self::TABLE_HISTORY . '` WHERE `date_add` < DATE_SUB(NOW(), INTERVAL 2 YEAR)'
        );
    }

    /**
     * Pone o quita (null) el precio de tarifa de un producto y lo apunta en el historial.
     *
     * @return true|string
     */
    public function setPrice($idGroup, $idProduct, $priceTaxExcl, $source)
    {
        $old = $this->getTariffPrice($idGroup, $idProduct);
        if ($priceTaxExcl === null) {
            if ($old === null) {
                return true;
            }
            if (!$this->deletePrice($idGroup, $idProduct)) {
                return 'No se pudo borrar el precio en la base de datos: ' . Db::getInstance()->getMsgError();
            }
        } else {
            $priceTaxExcl = round((float) $priceTaxExcl, 6);
            if ($old !== null && abs($old - $priceTaxExcl) < 0.0000005) {
                return true;
            }
            if (!$this->savePrice($idGroup, $idProduct, $priceTaxExcl)) {
                return 'No se pudo guardar el precio en la base de datos: ' . Db::getInstance()->getMsgError();
            }
        }
        $this->logHistory(array(array($idGroup, $idProduct, $old, $priceTaxExcl)), $source);

        return true;
    }

    /* ------------------------------------------------------------------ */
    /* AJAX (controlador oculto AdminJosratarifasAjax)                     */
    /* ------------------------------------------------------------------ */

    /** URL del controlador oculto de AJAX, con su token */
    public function ajaxUrl()
    {
        return $this->context->link->getAdminLink(self::AJAX_CONTROLLER);
    }

    protected function jsonExit(array $data)
    {
        // Fuera cualquier salida previa (avisos de PHP en modo depuración, HTML del controlador)
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $flags = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
        $json = json_encode($data, $flags | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            $json = json_encode(array('ok' => false, 'error' => 'No se pudo preparar la respuesta: ' . json_last_error_msg() . '.'));
        }
        header('Content-Type: application/json; charset=utf-8');
        echo $json;
        exit;
    }

    /** Punto de entrada del controlador AJAX. Siempre termina con exit. */
    public function ajaxResponse()
    {
        $action = (string) Tools::getValue('jt_action');
        try {
            switch ($action) {
                case 'export_all':
                    $this->exportCsv(null, array());
                    // no break (exportCsv termina)
                case 'history':
                    $this->jsonExit($this->ajaxHistory());
                    // no break
                case 'product':
                    $this->jsonExit($this->ajaxProduct((int) Tools::getValue('id_product')));
                    // no break
                case 'saveproduct':
                    $this->requirePost();
                    $this->jsonExit($this->ajaxSaveProduct((int) Tools::getValue('id_product')));
                    // no break
            }

            $idGroup = (int) Tools::getValue('id_group');
            if (!$this->isTariffGroup($idGroup)) {
                $this->jsonExit(array('ok' => false, 'error' => 'Ese grupo no está marcado como tarifa (o es el grupo del PVP). Revisa los ajustes y recarga la página.'));
            }
            switch ($action) {
                case 'list':
                    $this->jsonExit($this->ajaxList($idGroup));
                    // no break
                case 'save':
                    $this->requirePost();
                    $this->jsonExit($this->ajaxSave($idGroup));
                    // no break
                case 'bulk':
                    $this->requirePost();
                    $this->jsonExit($this->ajaxBulk($idGroup));
                    // no break
                case 'export':
                    $this->exportCsv($idGroup, $this->readFilters());
                    // no break
            }
        } catch (Exception $e) {
            $this->jsonExit(array('ok' => false, 'error' => 'Error del servidor: ' . $e->getMessage()));
        }

        $this->jsonExit(array('ok' => false, 'error' => 'Acción desconocida: «' . $action . '».'));
    }

    protected function requirePost()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonExit(array('ok' => false, 'error' => 'Los cambios se envían por POST.'));
        }
    }

    /** Nombre de cada regla de impuestos */
    protected function taxRulesNames()
    {
        static $names = null;
        if ($names === null) {
            $names = array();
            foreach ((array) Db::getInstance()->executeS(
                'SELECT `id_tax_rules_group`, `name` FROM `' . _DB_PREFIX_ . 'tax_rules_group`'
            ) as $r) {
                $names[(int) $r['id_tax_rules_group']] = $r['name'];
            }
        }

        return $names;
    }

    protected function productEditUrl($idProduct)
    {
        try {
            return $this->context->link->getAdminLink(
                'AdminProducts',
                true,
                array('id_product' => (int) $idProduct, 'updateproduct' => '1'),
                array('id_product' => (int) $idProduct, 'updateproduct' => '1')
            );
        } catch (Exception $e) {
            return '';
        } catch (Throwable $e) {
            return '';
        }
    }

    protected function productFrontUrl($idProduct)
    {
        try {
            return $this->context->link->getProductLink((int) $idProduct);
        } catch (Exception $e) {
            return '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Enlaces a un grupo: ver (con sus clientes) y editar. Funciona con la página antigua
     * de Grupos y con la nueva de PS 9 (si está activada).
     */
    public function groupUrls($idGroup)
    {
        $urls = array('view' => '', 'edit' => '');
        foreach (array('view' => 'viewgroup', 'edit' => 'updategroup') as $key => $action) {
            try {
                $urls[$key] = $this->context->link->getAdminLink('AdminGroups', true, array(), array('id_group' => (int) $idGroup, $action => '1'));
            } catch (Exception $e) {
                $urls[$key] = '';
            } catch (Throwable $e) {
                $urls[$key] = '';
            }
            if ($urls[$key] === '') {
                // Página nueva de Grupos (PS 9): se genera la ruta directamente
                try {
                    $router = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance()->get('router');
                    $urls[$key] = $key === 'edit'
                        ? $router->generate('admin_customer_groups_edit', array('groupId' => (int) $idGroup))
                        : $router->generate('admin_customer_groups_index');
                } catch (Exception $e) {
                    $urls[$key] = '';
                } catch (Throwable $e) {
                    $urls[$key] = '';
                }
            }
        }

        return $urls;
    }

    protected function productRow(array $r)
    {
        $rate = $this->taxRate($r['id_tax_rules_group']);
        $tariff = $r['tariff'] === null ? null : (float) $r['tariff'];
        $pvp = (float) $r['price'];
        $names = $this->taxRulesNames();
        $idTax = (int) $r['id_tax_rules_group'];

        return array(
            'id' => (int) $r['id_product'],
            'ref' => (string) $r['reference'],
            'name' => (string) $r['name'],
            'active' => (int) $r['active'],
            'rate' => $rate,
            'tax_name' => $idTax ? (isset($names[$idTax]) ? $names[$idTax] : 'Regla ' . $idTax) : 'Sin IVA',
            'pvp' => round($pvp, 6),
            'pvp_ti' => round($pvp * (1 + $rate / 100), 2),
            'tariff' => $tariff === null ? null : round($tariff, 6),
            'tariff_ti' => $tariff === null ? null : round($tariff * (1 + $rate / 100), 2),
            'warn' => $tariff !== null && ($tariff <= 0 || $tariff > $pvp) ? ($tariff <= 0 ? 'La tarifa vale 0' : 'La tarifa es mayor que el PVP') : '',
            'url_front' => $this->productFrontUrl($r['id_product']),
            'url_edit' => $this->productEditUrl($r['id_product']),
        );
    }

    protected function productSelect()
    {
        $idShop = (int) $this->context->shop->id;
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');

        return 'FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON (ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON (pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . $idLang . ' AND pl.`id_shop` = ' . $idShop . ')';
    }

    /** Filtros de la rejilla (también para exportar lo filtrado y rellenar en bloque) */
    protected function readFilters()
    {
        return array(
            'q' => trim((string) Tools::getValue('q')),
            'filter' => (string) Tools::getValue('filter'),
            'id_category' => (int) Tools::getValue('id_category'),
            'id_manufacturer' => (int) Tools::getValue('id_manufacturer'),
            'sort' => (string) Tools::getValue('sort'),
            'dir' => Tools::strtolower((string) Tools::getValue('dir')) === 'asc' ? 'ASC' : 'DESC',
        );
    }

    /** FROM + WHERE de los productos de una tarifa con los filtros aplicados */
    protected function filteredFrom($idGroup, array $f)
    {
        $from = $this->productSelect() . '
            LEFT JOIN `' . _DB_PREFIX_ . self::TABLE . '` t ON (t.`id_product` = p.`id_product` AND t.`id_group` = ' . (int) $idGroup . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'tax_rules_group` trg ON (trg.`id_tax_rules_group` = ps.`id_tax_rules_group`)';
        $where = array('1');
        if (!empty($f['q'])) {
            $like = '\'%' . pSQL(str_replace(array('%', '_'), array('\%', '\_'), $f['q'])) . '%\'';
            $cond = 'p.`reference` LIKE ' . $like . ' OR pl.`name` LIKE ' . $like;
            if (ctype_digit($f['q'])) {
                $cond .= ' OR p.`id_product` = ' . (int) $f['q'];
            }
            $where[] = '(' . $cond . ')';
        }
        if ($f['filter'] === 'with') {
            $where[] = 't.`id_product` IS NOT NULL';
        } elseif ($f['filter'] === 'without') {
            $where[] = 't.`id_product` IS NULL';
        } elseif ($f['filter'] === 'warn') {
            $where[] = '(t.`id_product` IS NOT NULL AND (t.`price` <= 0 OR t.`price` > ps.`price`))';
        }
        if (!empty($f['id_manufacturer'])) {
            $where[] = 'p.`id_manufacturer` = ' . (int) $f['id_manufacturer'];
        }
        if (!empty($f['id_category'])) {
            $cat = Db::getInstance()->getRow(
                'SELECT `nleft`, `nright` FROM `' . _DB_PREFIX_ . 'category` WHERE `id_category` = ' . (int) $f['id_category']
            );
            if ($cat) {
                // La categoría y sus subcategorías
                $where[] = 'EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'category_product` cp
                    INNER JOIN `' . _DB_PREFIX_ . 'category` c ON (c.`id_category` = cp.`id_category`)
                    WHERE cp.`id_product` = p.`id_product` AND c.`nleft` >= ' . (int) $cat['nleft'] . ' AND c.`nright` <= ' . (int) $cat['nright'] . ')';
            }
        }

        return $from . ' WHERE ' . implode(' AND ', $where);
    }

    protected function orderBy(array $f)
    {
        $columns = array(
            'id' => 'p.`id_product`',
            'ref' => 'p.`reference`',
            'name' => 'pl.`name`',
            'tax' => 'trg.`name`',
            'pvp' => 'ps.`price`',
            'tariff' => 't.`price`',
            'diff' => '(t.`price` - ps.`price`) / NULLIF(ps.`price`, 0)',
        );
        $col = isset($columns[$f['sort']]) ? $columns[$f['sort']] : $columns['id'];

        return ' ORDER BY ' . $col . ' ' . $f['dir'] . ', p.`id_product` DESC';
    }

    protected function rowSelect()
    {
        return 'SELECT p.`id_product`, p.`reference`, pl.`name`, ps.`price`, ps.`active`, ps.`id_tax_rules_group`, t.`price` AS tariff ';
    }

    protected function ajaxList($idGroup)
    {
        $f = $this->readFilters();
        $page = max(1, (int) Tools::getValue('page'));
        $from = $this->filteredFrom($idGroup, $f);

        $total = (int) Db::getInstance()->getValue('SELECT COUNT(*) ' . $from);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);
        $warnings = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) ' . $this->filteredFrom($idGroup, array_merge($f, array('filter' => 'warn')))
        );

        $rows = Db::getInstance()->executeS(
            $this->rowSelect() . $from . $this->orderBy($f)
            . ' LIMIT ' . (($page - 1) * self::PAGE_SIZE) . ', ' . self::PAGE_SIZE
        );

        $out = array();
        foreach ((array) $rows as $r) {
            $out[] = $this->productRow($r);
        }

        return array('ok' => true, 'rows' => $out, 'total' => $total, 'page' => $page, 'pages' => $pages, 'warnings' => $warnings);
    }

    protected function productRowById($idGroup, $idProduct)
    {
        return Db::getInstance()->getRow(
            'SELECT p.`id_product`, p.`reference`, pl.`name`, ps.`price`, ps.`active`, ps.`id_tax_rules_group`, t.`price` AS tariff '
            . $this->productSelect() . '
            LEFT JOIN `' . _DB_PREFIX_ . self::TABLE . '` t ON (t.`id_product` = p.`id_product` AND t.`id_group` = ' . (int) $idGroup . ')
            WHERE p.`id_product` = ' . (int) $idProduct
        );
    }

    /**
     * Precio escrito por el empleado → sin IVA, según el ajuste. null = quitar.
     *
     * @return array(float|null, string error)
     */
    protected function inputToTaxExcl($raw, $idTaxRulesGroup)
    {
        if (trim((string) $raw) === '') {
            return array(null, '');
        }
        $value = $this->parseNumber($raw);
        if ($value === null) {
            return array(null, '«' . $raw . '» no es un precio válido. Escribe un número, por ejemplo 19,50 (vacío para quitar la tarifa).');
        }
        $settings = $this->getSettings();
        $rate = $this->taxRate($idTaxRulesGroup);

        return array(!empty($settings['tax_input']) ? $value / (1 + $rate / 100) : $value, '');
    }

    protected function ajaxSave($idGroup)
    {
        $idProduct = (int) Tools::getValue('id_product');
        $raw = (string) Tools::getValue('value');
        $row = $this->productRowById($idGroup, $idProduct);
        if (!$row) {
            return array('ok' => false, 'error' => 'El producto ' . $idProduct . ' ya no existe en la tienda.');
        }
        list($taxExcl, $error) = $this->inputToTaxExcl($raw, $row['id_tax_rules_group']);
        if ($error !== '') {
            return array('ok' => false, 'error' => $error);
        }
        $result = $this->setPrice($idGroup, $idProduct, $taxExcl, 'rejilla');
        if ($result !== true) {
            return array('ok' => false, 'error' => $result);
        }
        $row['tariff'] = $taxExcl === null ? null : round($taxExcl, 6);

        return array(
            'ok' => true,
            'row' => $this->productRow($row),
            'message' => $taxExcl === null ? 'Tarifa quitada: se vende a su precio normal.' : 'Guardado.',
        );
    }

    /** Redondeo del rellenado en bloque, sobre el precio tal como se escribe (con o sin IVA) */
    protected function roundValue($v, $mode)
    {
        switch ($mode) {
            case '005':
                $v = round($v / 0.05) * 0.05;
                break;
            case '010':
                $v = round($v / 0.10) * 0.10;
                break;
            case '1':
                $v = round($v);
                break;
            case '095':
                $v = round($v - 0.95) + 0.95;
                break;
            case '099':
                $v = round($v - 0.99) + 0.99;
                break;
            default:
                $v = round($v, 2);
        }

        return max(0, round($v, 2));
    }

    /**
     * Rellenar en bloque los productos del filtro: tarifa = (PVP u otra tarifa) ± X %,
     * con redondeo. Con dry=1 solo cuenta y enseña ejemplos.
     */
    protected function ajaxBulk($idGroup)
    {
        $f = $this->readFilters();
        $settings = $this->getSettings();
        $base = (string) Tools::getValue('base');
        $onlyEmpty = (bool) Tools::getValue('only_empty');
        $percent = $this->parseSigned(Tools::getValue('percent'));
        $rounding = (string) Tools::getValue('rounding');
        $dry = (bool) Tools::getValue('dry');

        if ($percent === null) {
            return array('ok' => false, 'error' => 'El porcentaje no es un número. Escribe por ejemplo -10 para un 10 % menos o 5 para un 5 % más.');
        }
        if ($percent <= -100) {
            return array('ok' => false, 'error' => 'Un ' . $percent . ' % dejaría los precios a 0 o en negativo.');
        }
        $sourceGroup = 0;
        if ($base !== 'pvp') {
            $sourceGroup = (int) $base;
            if (!$this->isTariffGroup($sourceGroup)) {
                return array('ok' => false, 'error' => 'La tarifa de origen no está marcada como tarifa.');
            }
        }

        @set_time_limit(0);
        $from = $this->filteredFrom($idGroup, $f);
        $select = $this->rowSelect();
        if ($sourceGroup) {
            // filteredFrom termina en WHERE: el JOIN de la tarifa de origen va antes
            $pos = strpos($from, ' WHERE ');
            $from = substr($from, 0, $pos)
                . ' LEFT JOIN `' . _DB_PREFIX_ . self::TABLE . '` src ON (src.`id_product` = p.`id_product` AND src.`id_group` = ' . $sourceGroup . ')'
                . substr($from, $pos);
            $select .= ', src.`price` AS source_price ';
        }
        $rows = Db::getInstance()->executeS($select . $from);

        $withTax = !empty($settings['tax_input']);
        $stats = array('change' => 0, 'same' => 0, 'has_price' => 0, 'no_source' => 0);
        $sample = array();
        $changes = array();
        foreach ((array) $rows as $r) {
            $current = $r['tariff'] === null ? null : (float) $r['tariff'];
            if ($onlyEmpty && $current !== null) {
                ++$stats['has_price'];
                continue;
            }
            if ($sourceGroup) {
                if ($r['source_price'] === null) {
                    ++$stats['no_source'];
                    continue;
                }
                $baseValue = (float) $r['source_price'];
            } else {
                $baseValue = (float) $r['price'];
            }
            $rate = $this->taxRate($r['id_tax_rules_group']);
            $factor = $withTax ? 1 + $rate / 100 : 1;
            $shown = $this->roundValue($baseValue * (1 + $percent / 100) * $factor, $rounding);
            $new = round($shown / $factor, 6);
            if ($current !== null && abs($current - $new) < 0.0000005) {
                ++$stats['same'];
                continue;
            }
            ++$stats['change'];
            $changes[] = array($idGroup, (int) $r['id_product'], $current, $new);
            if (count($sample) < 10) {
                $sample[] = array(
                    'ref' => $r['reference'],
                    'name' => $r['name'],
                    'before' => $current === null ? null : round($current * $factor, $withTax ? 2 : 6),
                    'after' => round($new * $factor, $withTax ? 2 : 6),
                );
            }
        }

        if (!$dry && $changes) {
            $values = array();
            foreach ($changes as $c) {
                $values[] = '(' . (int) $c[0] . ', ' . (int) $c[1] . ', ' . number_format($c[3], 6, '.', '') . ', NOW())';
            }
            foreach (array_chunk($values, 500) as $chunk) {
                if (!Db::getInstance()->execute(
                    'INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '` (`id_group`, `id_product`, `price`, `date_upd`) VALUES '
                    . implode(',', $chunk)
                    . ' ON DUPLICATE KEY UPDATE `price` = VALUES(`price`), `date_upd` = VALUES(`date_upd`)'
                )) {
                    return array('ok' => false, 'error' => 'Se ha parado a medias al guardar en la base de datos: ' . Db::getInstance()->getMsgError());
                }
            }
            self::$priceCache = array();
            $this->logHistory($changes, 'bloque');
        }

        return array('ok' => true, 'dry' => $dry, 'stats' => $stats, 'sample' => $sample, 'tax_input' => $withTax ? 1 : 0);
    }

    /** "-10", "+5", "7,5" → float; null si no es un número */
    protected function parseSigned($value)
    {
        $value = str_replace(array(' ', '%'), '', trim((string) $value));
        if ($value === '') {
            return 0.0;
        }
        $sign = 1;
        if ($value[0] === '-' || $value[0] === '+') {
            $sign = $value[0] === '-' ? -1 : 1;
            $value = substr($value, 1);
        }
        $n = $this->parseNumber($value);

        return $n === null ? null : $sign * $n;
    }

    protected function ajaxHistory()
    {
        $groups = $this->getAllGroups();
        $idGroup = (int) Tools::getValue('id_group');
        $q = trim((string) Tools::getValue('q'));
        $source = (string) Tools::getValue('source');
        $page = max(1, (int) Tools::getValue('page'));
        $settings = $this->getSettings();
        $withTax = !empty($settings['tax_input']);

        $from = 'FROM `' . _DB_PREFIX_ . self::TABLE_HISTORY . '` h
            LEFT JOIN `' . _DB_PREFIX_ . 'product` p ON (p.`id_product` = h.`id_product`)
            LEFT JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON (ps.`id_product` = h.`id_product` AND ps.`id_shop` = ' . (int) $this->context->shop->id . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON (pl.`id_product` = h.`id_product` AND pl.`id_lang` = ' . (int) Configuration::get('PS_LANG_DEFAULT') . ' AND pl.`id_shop` = ' . (int) $this->context->shop->id . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'employee` e ON (e.`id_employee` = h.`id_employee`)';
        $where = array('1');
        if ($idGroup) {
            $where[] = 'h.`id_group` = ' . $idGroup;
        }
        if (in_array($source, array('rejilla', 'ficha', 'csv', 'bloque'), true)) {
            $where[] = 'h.`source` = \'' . pSQL($source) . '\'';
        }
        if ($q !== '') {
            $like = '\'%' . pSQL(str_replace(array('%', '_'), array('\%', '\_'), $q)) . '%\'';
            $cond = 'p.`reference` LIKE ' . $like . ' OR pl.`name` LIKE ' . $like;
            if (ctype_digit($q)) {
                $cond .= ' OR h.`id_product` = ' . (int) $q;
            }
            $where[] = '(' . $cond . ')';
        }
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total = (int) Db::getInstance()->getValue('SELECT COUNT(*) ' . $from . $whereSql);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);
        $rows = Db::getInstance()->executeS(
            'SELECT h.*, p.`reference`, pl.`name`, ps.`id_tax_rules_group`, e.`firstname`, e.`lastname` '
            . $from . $whereSql . ' ORDER BY h.`id_historial` DESC LIMIT ' . (($page - 1) * self::PAGE_SIZE) . ', ' . self::PAGE_SIZE
        );

        $labels = array('rejilla' => 'Rejilla', 'ficha' => 'Ficha del producto', 'csv' => 'Importación CSV', 'bloque' => 'Rellenar en bloque');
        $out = array();
        foreach ((array) $rows as $r) {
            $factor = $withTax ? 1 + $this->taxRate($r['id_tax_rules_group']) / 100 : 1;
            $date = DateTime::createFromFormat('Y-m-d H:i:s', $r['date_add']);
            $out[] = array(
                'date' => $date ? $date->format('d/m/Y H:i') : $r['date_add'],
                'employee' => trim($r['firstname'] . ' ' . $r['lastname']) !== '' ? trim($r['firstname'] . ' ' . $r['lastname']) : '—',
                'tariff' => isset($groups[(int) $r['id_group']]) ? $groups[(int) $r['id_group']]['name'] : 'Grupo ' . (int) $r['id_group'] . ' (borrado)',
                'id_product' => (int) $r['id_product'],
                'ref' => (string) $r['reference'],
                'name' => $r['name'] !== null ? (string) $r['name'] : 'Producto borrado',
                'old' => $r['old_price'] === null ? null : round((float) $r['old_price'] * $factor, $withTax ? 2 : 6),
                'new' => $r['new_price'] === null ? null : round((float) $r['new_price'] * $factor, $withTax ? 2 : 6),
                'source' => isset($labels[$r['source']]) ? $labels[$r['source']] : $r['source'],
            );
        }

        return array('ok' => true, 'rows' => $out, 'total' => $total, 'page' => $page, 'pages' => $pages, 'tax_input' => $withTax ? 1 : 0);
    }

    /* ------------------------------------------------------------------ */
    /* Ficha del producto en el back-office                                */
    /* ------------------------------------------------------------------ */

    /**
     * Pestaña Módulos de la ficha del producto (página de 8.2 y la nueva de 8.2/9):
     * sus precios en cada tarifa, editables ahí mismo. Los campos no llevan name,
     * para que no viajen con el formulario del producto, y se guardan por AJAX.
     */
    public function hookDisplayAdminProductsExtra($params)
    {
        $idProduct = isset($params['id_product']) ? (int) $params['id_product'] : (int) Tools::getValue('id_product');
        $settings = $this->getSettings();
        $this->context->smarty->assign(array(
            'jt_id_product' => $idProduct,
            'jt_has_tariffs' => (bool) $this->getTariffGroups(),
            'jt_ajax_url' => $this->ajaxUrl(),
            'jt_config_url' => $this->context->link->getAdminLink('AdminModules', true, array(), array('configure' => $this->name)),
            'jt_tax_input' => (int) $settings['tax_input'],
            'jt_js_url' => $this->_path . 'views/js/product.js?v=' . $this->version,
        ));

        return $this->display(__FILE__, 'views/templates/admin/product.tpl');
    }

    protected function ajaxProduct($idProduct)
    {
        $settings = $this->getSettings();
        $product = Db::getInstance()->getRow(
            'SELECT p.`id_product`, p.`reference`, pl.`name`, ps.`price`, ps.`id_tax_rules_group` '
            . $this->productSelect() . ' WHERE p.`id_product` = ' . (int) $idProduct
        );
        if (!$product) {
            return array('ok' => false, 'error' => 'Guarda primero el producto: todavía no existe en la base de datos.');
        }
        $rate = $this->taxRate($product['id_tax_rules_group']);
        $names = $this->taxRulesNames();
        $groups = $this->getAllGroups();
        $rows = array();
        foreach ($this->getTariffGroups() as $idGroup) {
            if (!isset($groups[$idGroup])) {
                continue;
            }
            $price = $this->getTariffPrice($idGroup, $idProduct);
            $rows[] = array(
                'id_group' => $idGroup,
                'name' => $groups[$idGroup]['name'],
                'tariff' => $price === null ? null : round($price, 6),
                'tariff_ti' => $price === null ? null : round($price * (1 + $rate / 100), 2),
                'warn' => $price !== null && ($price <= 0 || $price > (float) $product['price']) ? ($price <= 0 ? 'La tarifa vale 0' : 'Mayor que el PVP') : '',
            );
        }
        $idTax = (int) $product['id_tax_rules_group'];

        return array(
            'ok' => true,
            'tax_input' => (int) $settings['tax_input'],
            'pvp' => round((float) $product['price'], 6),
            'pvp_ti' => round((float) $product['price'] * (1 + $rate / 100), 2),
            'tax_name' => $idTax ? (isset($names[$idTax]) ? $names[$idTax] : 'Regla ' . $idTax) : 'Sin IVA',
            'rate' => $rate,
            'rows' => $rows,
        );
    }

    protected function ajaxSaveProduct($idProduct)
    {
        $product = Db::getInstance()->getRow(
            'SELECT p.`id_product`, ps.`id_tax_rules_group` ' . $this->productSelect() . ' WHERE p.`id_product` = ' . (int) $idProduct
        );
        if (!$product) {
            return array('ok' => false, 'error' => 'Guarda primero el producto: todavía no existe en la base de datos.');
        }
        $errors = array();
        $saved = 0;
        $groups = $this->getAllGroups();
        foreach ($this->getTariffGroups() as $idGroup) {
            $key = 'price_' . $idGroup;
            if (!isset($_POST[$key])) {
                continue;
            }
            list($taxExcl, $error) = $this->inputToTaxExcl(Tools::getValue($key), $product['id_tax_rules_group']);
            if ($error !== '') {
                $errors[] = (isset($groups[$idGroup]) ? $groups[$idGroup]['name'] : $idGroup) . ': ' . $error;
                continue;
            }
            $result = $this->setPrice($idGroup, $idProduct, $taxExcl, 'ficha');
            if ($result !== true) {
                $errors[] = (isset($groups[$idGroup]) ? $groups[$idGroup]['name'] : $idGroup) . ': ' . $result;
                continue;
            }
            ++$saved;
        }
        $data = $this->ajaxProduct($idProduct);
        $data['errors'] = $errors;
        $data['saved'] = $saved;

        return $data;
    }

    /* ------------------------------------------------------------------ */
    /* CSV                                                                 */
    /* ------------------------------------------------------------------ */

    protected function formatCsvNumber($value, $decimals)
    {
        $s = number_format((float) $value, $decimals, ',', '');
        if ($decimals > 2) {
            $s = rtrim($s, '0');
            $parts = explode(',', $s);
            if (!isset($parts[1]) || Tools::strlen($parts[1]) < 2) {
                $s = number_format((float) $value, 2, ',', '');
            }
        }

        return $s;
    }

    /**
     * Exporta a CSV. Sin $onlyGroup: todos los productos y todas las tarifas.
     * Con $onlyGroup: los productos del filtro y solo esa tarifa.
     */
    protected function exportCsv($onlyGroup, array $filters)
    {
        $settings = $this->getSettings();
        $groups = $this->getAllGroups();
        $tariffs = array();
        foreach ($this->getTariffGroups() as $idGroup) {
            if (isset($groups[$idGroup]) && (!$onlyGroup || $idGroup === (int) $onlyGroup)) {
                $tariffs[$idGroup] = $groups[$idGroup]['name'];
            }
        }
        $withTax = !empty($settings['tax_input']);

        $prices = array();
        if ($tariffs) {
            foreach ((array) Db::getInstance()->executeS(
                'SELECT `id_group`, `id_product`, `price` FROM `' . _DB_PREFIX_ . self::TABLE . '`
                WHERE `id_group` IN (' . implode(',', array_map('intval', array_keys($tariffs))) . ')'
            ) as $r) {
                $prices[(int) $r['id_product']][(int) $r['id_group']] = (float) $r['price'];
            }
        }

        if ($onlyGroup) {
            $rows = Db::getInstance()->executeS(
                'SELECT p.`id_product`, p.`reference`, pl.`name`, ps.`price`, ps.`id_tax_rules_group` '
                . $this->filteredFrom($onlyGroup, $filters) . $this->orderBy($filters)
            );
            $file = 'tarifa_' . Tools::str2url($tariffs ? reset($tariffs) : 'tarifa') . '_' . date('Ymd_Hi') . '.csv';
        } else {
            $rows = Db::getInstance()->executeS(
                'SELECT p.`id_product`, p.`reference`, pl.`name`, ps.`price`, ps.`id_tax_rules_group` '
                . $this->productSelect() . ' ORDER BY p.`reference`, p.`id_product`'
            );
            $file = 'tarifas_' . date('Ymd_Hi') . '.csv';
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge(array('referencia', 'nombre', $withTax ? 'pvp (con IVA)' : 'pvp (sin IVA)'), array_values($tariffs)), ';');
        foreach ((array) $rows as $r) {
            $rate = $this->taxRate($r['id_tax_rules_group']);
            $factor = $withTax ? 1 + $rate / 100 : 1;
            $line = array(
                $r['reference'],
                $r['name'],
                $this->formatCsvNumber((float) $r['price'] * $factor, $withTax ? 2 : 6),
            );
            foreach (array_keys($tariffs) as $idGroup) {
                $line[] = isset($prices[(int) $r['id_product']][$idGroup])
                    ? $this->formatCsvNumber($prices[(int) $r['id_product']][$idGroup] * $factor, $withTax ? 2 : 6)
                    : '';
            }
            fputcsv($out, $line, ';');
        }
        fclose($out);
        exit;
    }

    protected function processImport()
    {
        if (empty($_FILES['josratarifas_csv']) || !is_uploaded_file($_FILES['josratarifas_csv']['tmp_name'])) {
            $code = isset($_FILES['josratarifas_csv']['error']) ? (int) $_FILES['josratarifas_csv']['error'] : UPLOAD_ERR_NO_FILE;
            if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
                return $this->displayError('El archivo es más grande de lo que permite el servidor (upload_max_filesize = ' . ini_get('upload_max_filesize') . '). Divídelo en varios o sube el límite en PHP.');
            }

            return $this->displayError('No ha llegado ningún archivo. Elige el CSV y pulsa Importar.');
        }

        @set_time_limit(0);
        $content = Tools::file_get_contents($_FILES['josratarifas_csv']['tmp_name']);
        if ($content === false || trim($content) === '') {
            return $this->displayError('El archivo está vacío o no se pudo leer.');
        }
        if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
            $content = substr($content, 3);
        }
        if (function_exists('mb_check_encoding') && !mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $content = str_replace(array("\r\n", "\r"), "\n", $content);

        $firstLine = strtok($content, "\n");
        $delimiter = ';';
        $best = -1;
        foreach (array(';', ',', "\t") as $d) {
            $n = substr_count($firstLine, $d);
            if ($n > $best) {
                $best = $n;
                $delimiter = $d;
            }
        }

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        $header = fgetcsv($fh, 0, $delimiter);
        if (!$header) {
            fclose($fh);

            return $this->displayError('No se encuentra la fila de cabecera del CSV.');
        }

        $settings = $this->getSettings();
        $groups = $this->getAllGroups();
        $tariffByName = array();
        foreach ($this->getTariffGroups() as $idGroup) {
            if (isset($groups[$idGroup])) {
                $tariffByName[Tools::strtolower(trim($groups[$idGroup]['name']))] = $idGroup;
            }
        }

        $warnings = array();
        $refCol = null;
        $tariffCols = array();
        $ignored = array('referencia', 'reference', 'ref', 'nombre', 'name', 'pvp', 'pvp (con iva)', 'pvp (sin iva)', 'precio', 'price', 'id', 'id_product');
        foreach ($header as $i => $title) {
            $key = Tools::strtolower(trim((string) $title));
            if ($refCol === null && in_array($key, array('referencia', 'reference', 'ref'), true)) {
                $refCol = $i;
                continue;
            }
            if ($key === '' || in_array($key, $ignored, true)) {
                continue;
            }
            if (isset($tariffByName[$key])) {
                $tariffCols[$i] = $tariffByName[$key];
            } else {
                $warnings[] = sprintf(
                    'La columna «%s» no es ninguna tarifa (las tarifas son: %s). Se ha saltado entera.',
                    trim((string) $title),
                    $tariffByName ? implode(', ', array_map(function ($id) use ($groups) { return $groups[$id]['name']; }, $tariffByName)) : 'ninguna, márcalas en Ajustes'
                );
            }
        }
        if ($refCol === null) {
            fclose($fh);

            return $this->displayError('El CSV no tiene columna «referencia» en la cabecera, y sin ella no se puede saber a qué producto va cada precio.');
        }
        if (!$tariffCols) {
            fclose($fh);

            return $this->displayError('Ninguna columna del CSV coincide con el nombre de una tarifa, así que no hay nada que importar.')
                . $this->renderImportWarnings($warnings);
        }

        // Productos por referencia (las repetidas no se pueden importar)
        $byRef = array();
        foreach ((array) Db::getInstance()->executeS(
            'SELECT p.`id_product`, p.`reference`, ps.`id_tax_rules_group`
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON (ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . (int) $this->context->shop->id . ")
            WHERE p.`reference` <> ''"
        ) as $r) {
            $k = Tools::strtolower(trim($r['reference']));
            $byRef[$k][] = $r;
        }

        $existing = array();
        foreach ((array) Db::getInstance()->executeS(
            'SELECT `id_group`, `id_product`, `price` FROM `' . _DB_PREFIX_ . self::TABLE . '`
            WHERE `id_group` IN (' . implode(',', array_map('intval', array_unique($tariffCols))) . ')'
        ) as $r) {
            $existing[(int) $r['id_group']][(int) $r['id_product']] = (float) $r['price'];
        }

        $stats = array('rows' => 0, 'new' => 0, 'changed' => 0, 'same' => 0, 'deleted' => 0);
        $history = array();
        $inserts = array();
        $deletes = array();
        $line = 1;
        while (($cells = fgetcsv($fh, 0, $delimiter)) !== false) {
            ++$line;
            if ($cells === array(null) || !array_filter($cells, function ($c) { return trim((string) $c) !== ''; })) {
                continue;
            }
            ++$stats['rows'];
            $ref = isset($cells[$refCol]) ? trim((string) $cells[$refCol]) : '';
            if ($ref === '') {
                $warnings[] = sprintf('Línea %d: sin referencia. Se ha saltado.', $line);
                continue;
            }
            $k = Tools::strtolower($ref);
            if (!isset($byRef[$k])) {
                $warnings[] = sprintf('Línea %d: no hay ningún producto con la referencia «%s». Se ha saltado.', $line, $ref);
                continue;
            }
            if (count($byRef[$k]) > 1) {
                $warnings[] = sprintf('Línea %d: la referencia «%s» la tienen %d productos (IDs %s), así que no se sabe cuál es. Se ha saltado.', $line, $ref, count($byRef[$k]), implode(', ', array_map(function ($p) { return $p['id_product']; }, $byRef[$k])));
                continue;
            }
            $product = $byRef[$k][0];
            $idProduct = (int) $product['id_product'];
            $rate = $this->taxRate($product['id_tax_rules_group']);

            foreach ($tariffCols as $i => $idGroup) {
                $cell = isset($cells[$i]) ? trim((string) $cells[$i]) : '';
                $had = isset($existing[$idGroup][$idProduct]);
                if ($cell === '') {
                    if ($had) {
                        $deletes[$idGroup][] = $idProduct;
                        $history[] = array($idGroup, $idProduct, $existing[$idGroup][$idProduct], null);
                        unset($existing[$idGroup][$idProduct]);
                        ++$stats['deleted'];
                    }
                    continue;
                }
                $value = $this->parseNumber($cell);
                if ($value === null) {
                    $warnings[] = sprintf('Línea %d (%s), tarifa «%s»: «%s» no es un precio válido. No se ha tocado.', $line, $ref, $groups[$idGroup]['name'], $cell);
                    continue;
                }
                $taxExcl = round(!empty($settings['tax_input']) ? $value / (1 + $rate / 100) : $value, 6);
                if ($had && abs($existing[$idGroup][$idProduct] - $taxExcl) < 0.0000005) {
                    ++$stats['same'];
                    continue;
                }
                ++$stats[$had ? 'changed' : 'new'];
                $history[] = array($idGroup, $idProduct, $had ? $existing[$idGroup][$idProduct] : null, $taxExcl);
                $existing[$idGroup][$idProduct] = $taxExcl;
                $inserts[] = '(' . (int) $idGroup . ', ' . $idProduct . ', ' . number_format($taxExcl, 6, '.', '') . ', NOW())';
            }
        }
        fclose($fh);

        $db = Db::getInstance();
        foreach (array_chunk($inserts, 500) as $chunk) {
            if (!$db->execute(
                'INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '` (`id_group`, `id_product`, `price`, `date_upd`) VALUES '
                . implode(',', $chunk)
                . ' ON DUPLICATE KEY UPDATE `price` = VALUES(`price`), `date_upd` = VALUES(`date_upd`)'
            )) {
                return $this->displayError('La importación se ha parado a medias al guardar en la base de datos: ' . $db->getMsgError());
            }
        }
        foreach ($deletes as $idGroup => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $db->delete(self::TABLE, '`id_group` = ' . (int) $idGroup . ' AND `id_product` IN (' . implode(',', array_map('intval', $chunk)) . ')');
            }
        }
        self::$priceCache = array();
        $this->logHistory($history, 'csv');
        $this->purgeHistory();

        $names = array();
        foreach (array_unique($tariffCols) as $idGroup) {
            $names[] = $groups[$idGroup]['name'];
        }
        $summary = sprintf(
            '<strong>Importación terminada.</strong> Tarifas: %s. Filas leídas: %d. Precios nuevos: %d. Cambiados: %d. Sin cambios: %d. Quitados (celda vacía): %d. Avisos: %d.',
            Tools::safeOutput(implode(', ', $names)),
            $stats['rows'],
            $stats['new'],
            $stats['changed'],
            $stats['same'],
            $stats['deleted'],
            count($warnings)
        );

        return '<div class="alert alert-success">' . $summary . '</div>' . $this->renderImportWarnings($warnings);
    }

    protected function renderImportWarnings(array $warnings)
    {
        if (!$warnings) {
            return '';
        }
        $max = 200;
        $html = '<div class="alert alert-warning"><p><strong>' . sprintf('Avisos (%d):', count($warnings)) . '</strong></p><ul>';
        foreach (array_slice($warnings, 0, $max) as $w) {
            $html .= '<li>' . Tools::safeOutput($w) . '</li>';
        }
        $html .= '</ul>';
        if (count($warnings) > $max) {
            $html .= '<p>' . sprintf('…y %d avisos más.', count($warnings) - $max) . '</p>';
        }

        return $html . '</div>';
    }
}
