<?php
/**
 * Controlador oculto (sin entrada en el menú) para el AJAX de josratarifas:
 * rejilla de precios, rellenar en bloque, historial, exportar y bloque de la ficha del producto.
 *
 * @author josra
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminJosratarifasAjaxController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    /** Todo lo resuelve el módulo y termina con exit (JSON o descarga del CSV) */
    public function postProcess()
    {
        if ($this->module && method_exists($this->module, 'ajaxResponse')) {
            $this->module->ajaxResponse();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => false, 'error' => 'El módulo josratarifas no está cargado.'));
        exit;
    }
}
