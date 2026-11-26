<div id="josratarifas-app"
     data-ajax-url="{$jt_ajax_url|escape:'html':'UTF-8'}"
     data-tax-input="{$jt_tax_input|intval}">

  <ul class="nav nav-tabs" role="tablist">
    <li class="{if $jt_active == 'prices'}active{/if}"><a href="#jt-prices" data-toggle="tab"><i class="icon-euro"></i> Precios por tarifa</a></li>
    <li class="{if $jt_active == 'import'}active{/if}"><a href="#jt-import" data-toggle="tab"><i class="icon-upload"></i> Importar / exportar</a></li>
    <li><a href="#jt-history" data-toggle="tab" id="jt-history-tab"><i class="icon-time"></i> Historial</a></li>
    <li class="{if $jt_active == 'settings'}active{/if}"><a href="#jt-settings" data-toggle="tab"><i class="icon-cogs"></i> Ajustes</a></li>
    <li><a href="#jt-help" data-toggle="tab"><i class="icon-question-circle"></i> Ayuda</a></li>
  </ul>

  <div class="tab-content">

    {* ---------------- Precios ---------------- *}
    <div class="tab-pane{if $jt_active == 'prices'} active{/if}" id="jt-prices">
      {if !$jt_tariffs}
        <div class="panel"><div class="alert alert-info">Todavía no hay tarifas. Ve a <strong>Ajustes</strong> y marca qué grupos de clientes son tarifa.</div></div>
      {else}
        <div class="panel">
          <div class="panel-heading"><i class="icon-group"></i> Tarifas</div>
          <table class="table">
            <thead><tr><th>Tarifa (grupo)</th><th class="text-center">Clientes</th><th class="text-center">Productos con precio</th><th>Precios en la tienda</th><th class="text-right">Grupo</th></tr></thead>
            <tbody>
              {foreach $jt_tariffs as $t}
                <tr>
                  <td><strong>{$t.name|escape:'html':'UTF-8'}</strong></td>
                  <td class="text-center">{$t.customers|intval}</td>
                  <td class="text-center">{$t.products|intval}</td>
                  <td>{$t.tax_display|escape:'html':'UTF-8'}</td>
                  <td class="text-right">
                    <div class="btn-group">
                      {if $t.urls.view}<a class="btn btn-default btn-sm" href="{$t.urls.view|escape:'html':'UTF-8'}" target="_blank" rel="noopener" title="Ver el grupo y sus clientes (pestaña nueva)"><i class="icon-eye"></i> Ver</a>{/if}
                      {if $t.urls.edit}<a class="btn btn-default btn-sm" href="{$t.urls.edit|escape:'html':'UTF-8'}" target="_blank" rel="noopener" title="Editar el grupo (pestaña nueva)"><i class="icon-pencil"></i> Editar</a>{/if}
                    </div>
                  </td>
                </tr>
              {/foreach}
            </tbody>
          </table>
        </div>

        <div class="panel">
          <div class="panel-heading"><i class="icon-list"></i> Productos y precios</div>
          <div class="form-inline" style="margin-bottom:12px">
            <div class="form-group">
              <label for="jt-group">Tarifa</label>
              <select id="jt-group" class="form-control">
                {foreach $jt_tariffs as $t}<option value="{$t.id|intval}">{$t.name|escape:'html':'UTF-8'}</option>{/foreach}
              </select>
            </div>
            <div class="form-group">
              <label for="jt-q">Buscar</label>
              <input type="text" id="jt-q" class="form-control" placeholder="Referencia, nombre o ID">
            </div>
            <div class="form-group">
              <label for="jt-filter">Mostrar</label>
              <select id="jt-filter" class="form-control">
                <option value="all">Todos los productos</option>
                <option value="with">Solo con precio de tarifa</option>
                <option value="without">Solo sin precio de tarifa</option>
                <option value="warn">Solo con avisos (tarifa a 0 o mayor que el PVP)</option>
              </select>
            </div>
          </div>
          <div class="form-inline" style="margin-bottom:12px">
            <div class="form-group">
              <label for="jt-category">Categoría</label>
              <select id="jt-category" class="form-control">
                <option value="0">Todas</option>
                {foreach $jt_categories as $c}<option value="{$c.id|intval}">{$c.name|escape:'html':'UTF-8'}</option>{/foreach}
              </select>
            </div>
            <div class="form-group">
              <label for="jt-manufacturer">Marca</label>
              <select id="jt-manufacturer" class="form-control">
                <option value="0">Todas</option>
                {foreach $jt_manufacturers as $m}<option value="{$m.id_manufacturer|intval}">{$m.name|escape:'html':'UTF-8'}</option>{/foreach}
              </select>
            </div>
            <button type="button" id="jt-search" class="btn btn-default"><i class="icon-search"></i> Buscar</button>
            <button type="button" id="jt-export-filtered" class="btn btn-default"><i class="icon-download"></i> Exportar lo filtrado</button>
            <button type="button" id="jt-bulk-toggle" class="btn btn-default"><i class="icon-magic"></i> Rellenar en bloque</button>
          </div>

          <div id="jt-bulk" class="well" style="display:none">
            <p><strong>Rellenar en bloque</strong>: se aplica a <strong>todos los productos del filtro actual</strong> (búsqueda, categoría, marca y «Mostrar»), no solo a la página que ves.</p>
            <div class="form-inline">
              <div class="form-group">
                <label for="jt-bulk-base">Partir de</label>
                <select id="jt-bulk-base" class="form-control">
                  <option value="pvp">El PVP del producto</option>
                  {foreach $jt_tariffs as $t}<option value="{$t.id|intval}">La tarifa {$t.name|escape:'html':'UTF-8'}</option>{/foreach}
                </select>
              </div>
              <div class="form-group">
                <label for="jt-bulk-percent">y cambiar un</label>
                <div class="input-group" style="width:120px">
                  <input type="text" id="jt-bulk-percent" class="form-control text-right" value="-10">
                  <span class="input-group-addon">%</span>
                </div>
              </div>
              <div class="form-group">
                <label for="jt-bulk-rounding">Redondeo</label>
                <select id="jt-bulk-rounding" class="form-control">
                  <option value="none">A céntimos</option>
                  <option value="005">A 0,05</option>
                  <option value="010">A 0,10</option>
                  <option value="1">A euro entero</option>
                  <option value="095">Terminado en ,95</option>
                  <option value="099">Terminado en ,99</option>
                </select>
              </div>
              <div class="checkbox">
                <label><input type="checkbox" id="jt-bulk-empty" checked> Solo productos sin precio en esta tarifa</label>
              </div>
            </div>
            <p class="help-block">Ejemplo: «El PVP» y −10 % pone la tarifa un 10 % por debajo del PVP. Una tarifa de origen y +5 % la copia subiéndola un 5 %. El redondeo se hace sobre el precio {if $jt_tax_input}con IVA{else}sin IVA{/if}, como se escribe. Los productos sin precio en la tarifa de origen se saltan.</p>
            <button type="button" id="jt-bulk-preview" class="btn btn-default"><i class="icon-eye"></i> Calcular (sin guardar)</button>
            <button type="button" id="jt-bulk-apply" class="btn btn-primary" disabled><i class="icon-save"></i> Aplicar</button>
            <div id="jt-bulk-result" style="margin-top:12px"></div>
          </div>
          <p class="help-block">
            Pincha en el precio de tarifa para cambiarlo. Pincha en la cabecera de una columna para ordenar.
            <i class="icon-eye"></i> abre el producto en la tienda y <i class="icon-pencil"></i> lo abre para editar, los dos en pestaña nueva. En rojo, las tarifas a 0 o mayores que el PVP. <strong>Intro</strong> guarda, <strong>Esc</strong> cancela y dejarlo vacío quita la tarifa (vuelve a su precio normal).
            Los precios de tarifa se escriben <strong>{if $jt_tax_input}con IVA{else}sin IVA{/if}</strong> (se cambia en Ajustes). El PVP es el precio base del producto, sin impactos de combinación.
          </p>
          <div id="jt-status"></div>
          <div class="table-responsive">
            <table class="table" id="jt-table">
              <thead>
                <tr>
                  <th><a href="#" class="jt-sort" data-sort="id">ID</a></th>
                  <th><a href="#" class="jt-sort" data-sort="ref">Referencia</a></th>
                  <th><a href="#" class="jt-sort" data-sort="name">Producto</a></th>
                  <th><a href="#" class="jt-sort" data-sort="tax">IVA</a></th>
                  <th class="text-right"><a href="#" class="jt-sort" data-sort="pvp">PVP sin IVA</a></th>
                  <th class="text-right">PVP con IVA</th>
                  <th class="text-right"><a href="#" class="jt-sort" data-sort="tariff">Tarifa {if $jt_tax_input}con IVA{else}sin IVA{/if}</a></th>
                  <th class="text-right">Tarifa {if $jt_tax_input}sin IVA{else}con IVA{/if}</th>
                  <th class="text-right"><a href="#" class="jt-sort" data-sort="diff">Dif. sobre PVP</a></th>
                  <th></th>
                </tr>
              </thead>
              <tbody><tr><td colspan="10" class="text-center">Cargando…</td></tr></tbody>
            </table>
          </div>
          <div class="row">
            <div class="col-sm-6" id="jt-count"></div>
            <div class="col-sm-6 text-right">
              <div class="btn-group">
                <button type="button" class="btn btn-default" id="jt-prev"><i class="icon-chevron-left"></i> Anterior</button>
                <button type="button" class="btn btn-default" id="jt-next">Siguiente <i class="icon-chevron-right"></i></button>
              </div>
            </div>
          </div>
        </div>
      {/if}
    </div>

    {* ---------------- Importar / exportar ---------------- *}
    <div class="tab-pane{if $jt_active == 'import'} active{/if}" id="jt-import">
      <div class="panel">
        <div class="panel-heading"><i class="icon-download"></i> Exportar</div>
        <p>Descarga todos los productos con su referencia, nombre, PVP y una columna por cada tarifa. Sirve como plantilla para importar: cambia los precios en Excel y vuelve a subirlo.</p>
        <a class="btn btn-default" href="{$jt_export_url|escape:'html':'UTF-8'}"><i class="icon-download"></i> Exportar CSV</a>
      </div>

      <div class="panel">
        <div class="panel-heading"><i class="icon-upload"></i> Importar</div>
        <form method="post" enctype="multipart/form-data" action="{$jt_import_action|escape:'html':'UTF-8'}" class="form-horizontal">
          <div class="form-group">
            <label class="control-label col-lg-3">Archivo CSV</label>
            <div class="col-lg-9">
              <input type="file" name="josratarifas_csv" accept=".csv,text/csv,text/plain">
              <div class="help-block">
                <p>Formato: <code>referencia;nombre;pvp;Tarifa 1;Tarifa 2…</code> La primera fila es la cabecera. Separador <code>;</code>, <code>,</code> o tabulador (se detecta solo). Decimales con coma o con punto.</p>
                <ul>
                  <li>Cada columna con el <strong>nombre de un grupo marcado como tarifa</strong> se importa. Si una columna no coincide con ninguna tarifa, se avisa y se salta; no se crea nada.</li>
                  <li>Los productos se buscan por <strong>referencia</strong>. Las columnas nombre y pvp solo son informativas: no se cambian.</li>
                  <li><strong>Celda vacía = quitar el precio de tarifa</strong> de ese producto (vuelve a su precio normal).</li>
                  <li>Los precios se leen <strong>{if $jt_tax_input}con IVA{else}sin IVA{/if}</strong>, como está en Ajustes.</li>
                </ul>
              </div>
            </div>
          </div>
          <div class="panel-footer">
            <button type="submit" name="submitJosratarifasImport" value="1" class="btn btn-default pull-right"><i class="process-icon-import"></i> Importar</button>
          </div>
        </form>
      </div>
    </div>

    {* ---------------- Historial ---------------- *}
    <div class="tab-pane" id="jt-history">
      <div class="panel">
        <div class="panel-heading"><i class="icon-time"></i> Historial de cambios de precio</div>
        <div class="form-inline" style="margin-bottom:12px">
          <div class="form-group">
            <label for="jt-h-group">Tarifa</label>
            <select id="jt-h-group" class="form-control">
              <option value="0">Todas</option>
              {foreach $jt_tariffs as $t}<option value="{$t.id|intval}">{$t.name|escape:'html':'UTF-8'}</option>{/foreach}
            </select>
          </div>
          <div class="form-group">
            <label for="jt-h-source">Origen</label>
            <select id="jt-h-source" class="form-control">
              <option value="">Todos</option>
              <option value="rejilla">Rejilla</option>
              <option value="ficha">Ficha del producto</option>
              <option value="csv">Importación CSV</option>
              <option value="bloque">Rellenar en bloque</option>
            </select>
          </div>
          <div class="form-group">
            <label for="jt-h-q">Producto</label>
            <input type="text" id="jt-h-q" class="form-control" placeholder="Referencia, nombre o ID">
          </div>
          <button type="button" id="jt-h-search" class="btn btn-default"><i class="icon-search"></i> Buscar</button>
        </div>
        <p class="help-block">Precios {if $jt_tax_input}con IVA{else}sin IVA{/if}. «—» es sin precio de tarifa (precio normal). Se guarda lo de los dos últimos años.</p>
        <div id="jt-h-status"></div>
        <div class="table-responsive">
          <table class="table" id="jt-h-table">
            <thead><tr><th>Fecha</th><th>Empleado</th><th>Tarifa</th><th>Referencia</th><th>Producto</th><th class="text-right">Antes</th><th class="text-right">Después</th><th>Origen</th></tr></thead>
            <tbody><tr><td colspan="8" class="text-center">Abre esta pestaña para cargar el historial.</td></tr></tbody>
          </table>
        </div>
        <div class="row">
          <div class="col-sm-6" id="jt-h-count"></div>
          <div class="col-sm-6 text-right">
            <div class="btn-group">
              <button type="button" class="btn btn-default" id="jt-h-prev"><i class="icon-chevron-left"></i> Anterior</button>
              <button type="button" class="btn btn-default" id="jt-h-next">Siguiente <i class="icon-chevron-right"></i></button>
            </div>
          </div>
        </div>
      </div>
    </div>

    {* ---------------- Ajustes ---------------- *}
    <div class="tab-pane{if $jt_active == 'settings'} active{/if}" id="jt-settings">
      {$jt_settings_form nofilter}
    </div>

    {* ---------------- Ayuda ---------------- *}
    <div class="tab-pane" id="jt-help">
      <div class="panel">
        <div class="panel-heading"><i class="icon-question-circle"></i> Cómo funciona</div>
        <ul>
          <li><strong>Cada grupo marcado como tarifa</strong> tiene su lista de precios. El cliente ve los precios de la tarifa de su <strong>grupo por defecto</strong> (en Clientes, ficha del cliente). Estar en otros grupos no cuenta para el precio.</li>
          <li><strong>Precio de tarifa = precio del producto</strong> sin IVA. Las combinaciones le suman su impacto de precio, igual que al precio normal.</li>
          <li>Un producto <strong>sin precio en la tarifa</strong> se vende a su precio normal, con todo lo que tenga (promociones, reducción del grupo…).</li>
          <li><strong>Promociones y escalados nativos</strong> (Precios específicos del producto, reglas de catálogo): los descuentos en % o importe se aplican encima del precio de tarifa. Un precio fijo de un precio específico queda sustituido por el de la tarifa.</li>
          <li>La <strong>reducción por % del grupo</strong> y las reducciones por categoría del grupo no se aplican a los productos con precio de tarifa.</li>
          <li>Carrito, pedido, facturas y reglas de carrito usan el precio de tarifa: el pedido se guarda con él.</li>
          <li><strong>PVP en pequeño:</strong> es el precio que tendría el producto para el grupo «{$jt_ref_group|escape:'html':'UTF-8'}» (Ajustes > Grupo del PVP), con o sin IVA según vea los precios el cliente. Si coincide con su precio no se muestra. Si tu tema no lo enseña, cambia la posición en Ajustes (Warehouse en los listados: «Después del precio»).</li>
          <li><strong>Ficha del producto:</strong> en la pestaña Módulos de la ficha del producto está el bloque «Tarifas por grupo de cliente» con su precio en cada tarifa, editable ahí mismo con su botón Guardar (se guarda aparte del producto).</li>
          <li><strong>Historial:</strong> cada cambio de precio (rejilla, ficha, CSV o rellenar en bloque) se apunta con fecha, empleado, valor anterior y nuevo.</li>
          <li><strong>Limitaciones:</strong> ordenar o filtrar por precio en los listados y en el buscador facetado usa el precio normal, porque PrestaShop lo hace por base de datos. No es compatible con multitienda. Necesita PrestaShop 8.2 o superior.</li>
        </ul>
      </div>
    </div>

  </div>
</div>
<script src="{$jt_js_url|escape:'html':'UTF-8'}"></script>
