<div class="josratarifas-product panel card" id="josratarifas-product"
     data-ajax-url="{$jt_ajax_url|escape:'html':'UTF-8'}"
     data-id-product="{$jt_id_product|intval}"
     data-tax-input="{$jt_tax_input|intval}">
  <h3 class="card-header">Tarifas por grupo de cliente</h3>
  <div class="card-body">
    {if !$jt_has_tariffs}
      <div class="alert alert-info">Todavía no hay tarifas. Márcalas en <a href="{$jt_config_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">la configuración del módulo</a>.</div>
    {elseif !$jt_id_product}
      <div class="alert alert-info">Guarda primero el producto para poder ponerle precios de tarifa.</div>
    {else}
      <p class="jt-p-info text-muted">Cargando…</p>
      <table class="table">
        <thead>
          <tr>
            <th>Tarifa</th>
            <th class="text-right">Precio {if $jt_tax_input}con IVA{else}sin IVA{/if}</th>
            <th class="text-right">{if $jt_tax_input}Sin IVA{else}Con IVA{/if}</th>
            <th></th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
      <p class="help-block small">Vacío = sin precio de tarifa (se vende a su precio normal). Las combinaciones suman su impacto. El IVA es el que tiene guardado el producto: si lo acabas de cambiar, guarda antes el producto. Estos precios se guardan con su propio botón, aparte del producto.</p>
      <div class="jt-p-status"></div>
      <button type="button" class="btn btn-primary jt-p-save">Guardar precios de tarifa</button>
      <a class="btn btn-link" href="{$jt_config_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">Abrir la rejilla de tarifas</a>
    {/if}
  </div>
</div>
<script>{$jt_inline_js nofilter}</script>
