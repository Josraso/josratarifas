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
        $this->version = '1.0.0';
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

    protected function getOwnHooks()
    {
        return array(
            'actionProductPriceCalculation',
            'displayProductPriceBlock',
            'actionProductDelete',
            'actionObjectGroupDeleteAfter',
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
            && $this->registerOwnHooks()
            && $this->getUpdater()->registerHooks()
            && $this->saveSettings($this->getSettings());
    }

    public function uninstall()
    {
        $this->getUpdater()->notifyUninstall();

        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`');
        Configuration::deleteByName(self::CONF_SETTINGS);
        Configuration::deleteByName('JOSRATARIFAS_UPD_TS');
        Configuration::deleteByName('JOSRATARIFAS_UPD_INFO');
        Configuration::deleteByName('JOSRATARIFAS_UPD_SU');
        Configuration::deleteByName('JOSRATARIFAS_UPD_LT');

        return parent::uninstall();
    }

    protected function createTable()
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

    protected function registerOwnHooks()
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

    public function getContent()
    {
        $this->selfRepair();

        if (Tools::getValue('josratarifas_ajax')) {
            $this->ajaxResponse();
        }
        if (Tools::getValue('josratarifas_export')) {
            $this->exportCsv();
        }

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
            );
        }

        $this->context->smarty->assign(array(
            'jt_active' => $activeTab,
            'jt_tariffs' => $tariffs,
            'jt_ajax_url' => $this->adminUrl() . '&josratarifas_ajax=1',
            'jt_export_url' => $this->adminUrl() . '&josratarifas_export=1',
            'jt_import_action' => $this->adminUrl(),
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
        $helper->currentIndex = $this->adminUrl(false);
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
    /* AJAX de la rejilla                                                  */
    /* ------------------------------------------------------------------ */

    protected function jsonExit(array $data)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }

    protected function ajaxResponse()
    {
        $action = (string) Tools::getValue('action');
        $idGroup = (int) Tools::getValue('id_group');
        if (!$this->isTariffGroup($idGroup)) {
            $this->jsonExit(array('ok' => false, 'error' => 'Ese grupo no está marcado como tarifa (o es el grupo del PVP). Revisa los ajustes y recarga la página.'));
        }

        if ($action === 'list') {
            $this->jsonExit($this->ajaxList($idGroup));
        }
        if ($action === 'save') {
            if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->jsonExit(array('ok' => false, 'error' => 'Los cambios de precio se envían por POST.'));
            }
            $this->jsonExit($this->ajaxSave($idGroup));
        }

        $this->jsonExit(array('ok' => false, 'error' => 'Acción desconocida: ' . $action . '.'));
    }

    protected function productRow(array $r)
    {
        $rate = $this->taxRate($r['id_tax_rules_group']);
        $tariff = $r['tariff'] === null ? null : (float) $r['tariff'];

        return array(
            'id' => (int) $r['id_product'],
            'ref' => (string) $r['reference'],
            'name' => (string) $r['name'],
            'active' => (int) $r['active'],
            'rate' => $rate,
            'pvp' => round((float) $r['price'], 6),
            'pvp_ti' => round((float) $r['price'] * (1 + $rate / 100), 2),
            'tariff' => $tariff === null ? null : round($tariff, 6),
            'tariff_ti' => $tariff === null ? null : round($tariff * (1 + $rate / 100), 2),
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

    protected function ajaxList($idGroup)
    {
        $q = trim((string) Tools::getValue('q'));
        $filter = (string) Tools::getValue('filter');
        $page = max(1, (int) Tools::getValue('page'));

        $from = $this->productSelect() . '
            LEFT JOIN `' . _DB_PREFIX_ . self::TABLE . '` t ON (t.`id_product` = p.`id_product` AND t.`id_group` = ' . (int) $idGroup . ')';
        $where = array('1');
        if ($q !== '') {
            $like = '\'%' . pSQL(str_replace(array('%', '_'), array('\%', '\_'), $q)) . '%\'';
            $cond = 'p.`reference` LIKE ' . $like . ' OR pl.`name` LIKE ' . $like;
            if (ctype_digit($q)) {
                $cond .= ' OR p.`id_product` = ' . (int) $q;
            }
            $where[] = '(' . $cond . ')';
        }
        if ($filter === 'with') {
            $where[] = 't.`id_product` IS NOT NULL';
        } elseif ($filter === 'without') {
            $where[] = 't.`id_product` IS NULL';
        }
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total = (int) Db::getInstance()->getValue('SELECT COUNT(*) ' . $from . $whereSql);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);

        $rows = Db::getInstance()->executeS(
            'SELECT p.`id_product`, p.`reference`, pl.`name`, ps.`price`, ps.`active`, ps.`id_tax_rules_group`, t.`price` AS tariff '
            . $from . $whereSql
            . ' ORDER BY p.`id_product` DESC LIMIT ' . (($page - 1) * self::PAGE_SIZE) . ', ' . self::PAGE_SIZE
        );

        $out = array();
        foreach ((array) $rows as $r) {
            $out[] = $this->productRow($r);
        }

        return array('ok' => true, 'rows' => $out, 'total' => $total, 'page' => $page, 'pages' => $pages);
    }

    protected function ajaxSave($idGroup)
    {
        $idProduct = (int) Tools::getValue('id_product');
        $raw = (string) Tools::getValue('value');
        $settings = $this->getSettings();

        $row = Db::getInstance()->getRow(
            'SELECT p.`id_product`, p.`reference`, pl.`name`, ps.`price`, ps.`active`, ps.`id_tax_rules_group`, NULL AS tariff '
            . $this->productSelect() . ' WHERE p.`id_product` = ' . $idProduct
        );
        if (!$row) {
            return array('ok' => false, 'error' => 'El producto ' . $idProduct . ' ya no existe en la tienda.');
        }

        if (trim($raw) === '') {
            if (!$this->deletePrice($idGroup, $idProduct)) {
                return array('ok' => false, 'error' => 'No se pudo borrar el precio en la base de datos: ' . Db::getInstance()->getMsgError());
            }
            $row['tariff'] = null;

            return array('ok' => true, 'row' => $this->productRow($row), 'message' => 'Precio de tarifa quitado: se vende a su precio normal.');
        }

        $value = $this->parseNumber($raw);
        if ($value === null) {
            return array('ok' => false, 'error' => '«' . $raw . '» no es un precio válido. Escribe un número, por ejemplo 19,50 (vacío para quitar la tarifa).');
        }
        $rate = $this->taxRate($row['id_tax_rules_group']);
        $taxExcl = !empty($settings['tax_input']) ? $value / (1 + $rate / 100) : $value;

        if (!$this->savePrice($idGroup, $idProduct, $taxExcl)) {
            return array('ok' => false, 'error' => 'No se pudo guardar el precio en la base de datos: ' . Db::getInstance()->getMsgError());
        }
        $row['tariff'] = round($taxExcl, 6);

        return array('ok' => true, 'row' => $this->productRow($row), 'message' => 'Guardado.');
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

    protected function exportCsv()
    {
        $settings = $this->getSettings();
        $groups = $this->getAllGroups();
        $tariffs = array();
        foreach ($this->getTariffGroups() as $idGroup) {
            if (isset($groups[$idGroup])) {
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

        $rows = Db::getInstance()->executeS(
            'SELECT p.`id_product`, p.`reference`, pl.`name`, ps.`price`, ps.`id_tax_rules_group` '
            . $this->productSelect() . ' ORDER BY p.`reference`, p.`id_product`'
        );

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tarifas_' . date('Ymd_Hi') . '.csv"');
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
