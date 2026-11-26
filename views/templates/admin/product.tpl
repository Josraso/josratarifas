<div class="josratarifas-product panel card" id="josratarifas-product">
  <h3 class="card-header">Tarifas por grupo de cliente</h3>
  <div class="card-body">
    {if !$jt_has_tariffs}
      <div class="alert alert-info">Todavía no hay tarifas. Márcalas en <a href="{$jt_config_url|escape:'html':'UTF-8'}" target="_blank">la configuración del módulo</a>.</div>
    {elseif !$jt_id_product}
      <div class="alert alert-info">Guarda primero el producto para poder ponerle precios de tarifa.</div>
    {else}
      <span id="jt-p-id" class="d-none hidden">{$jt_id_product|intval}</span>
      <span id="jt-p-tax" class="d-none hidden">{$jt_tax_input|intval}</span>
      <a id="jt-p-ajax" class="d-none hidden" href="{$jt_ajax_url|escape:'html':'UTF-8'}">ajax</a>
      <div id="jt-p-app"><p class="text-muted">Cargando… Si no cambia en unos segundos, recarga la página con Ctrl+F5.</p></div>
      <p class="help-block small">Vacío = sin precio de tarifa (se vende a su precio normal). Las combinaciones suman su impacto. El IVA es el que tiene guardado el producto: si lo acabas de cambiar, guarda antes el producto. Estos precios se guardan con su propio botón, aparte del producto.</p>
      <p><a href="{$jt_config_url|escape:'html':'UTF-8'}" target="_blank">Abrir la rejilla de tarifas</a></p>
    {/if}
  </div>
</div>
