<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.1.0: tabla del historial, controlador oculto para el AJAX y bloque en la ficha del producto.
 */
function upgrade_module_1_1_0($module)
{
    return $module->createTable()
        && $module->installTab()
        && $module->registerOwnHooks()
        && $module->getUpdater()->registerHooks();
}
