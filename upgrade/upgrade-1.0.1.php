<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_1($module)
{
    return $module->getUpdater()->registerHooks() && $module->registerHook('actionProductPriceCalculation')
        && $module->registerHook('displayProductPriceBlock') && $module->registerHook('actionProductDelete')
        && $module->registerHook('actionObjectGroupDeleteAfter');
}
