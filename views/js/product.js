/**
 * josratarifas: bloque de precios de tarifa en la ficha del producto (back-office).
 * Los campos no llevan name, para no viajar con el formulario del producto: se guardan por AJAX.
 * @author josra
 */
(function () {
  'use strict';

  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function num(v, decimals) {
    if (v === null || v === undefined) {
      return '';
    }
    var n = Number(v);
    var s = n.toFixed(decimals);
    if (decimals > 2) {
      s = s.replace(/0+$/, '');
      var parts = s.split('.');
      if (!parts[1] || parts[1].length < 2) {
        s = n.toFixed(2);
      }
    }
    return s.replace('.', ',');
  }

  function init() {
    var box = document.getElementById('josratarifas-product');
    var app = box ? box.querySelector('#jt-p-app') : null;
    if (!box || !app || box.getAttribute('data-jt-ready') === '1' || app.querySelector('.jt-p-table')) {
      return;
    }
    // Desde PS 9.1 el HTML del hook pasa por HTMLPurifier (sin data-*, <script>, botones ni campos):
    // la configuración va en elementos ocultos y la interfaz se construye aquí.
    var idEl = document.getElementById('jt-p-id');
    var taxEl = document.getElementById('jt-p-tax');
    var ajaxEl = document.getElementById('jt-p-ajax');
    var idProduct = idEl ? idEl.textContent.trim() : '';
    var taxInput = taxEl ? taxEl.textContent.trim() === '1' : false;
    var ajaxUrl = ajaxEl ? (ajaxEl.getAttribute('href') || '') : '';
    if (!idProduct || !ajaxUrl) {
      app.innerHTML = '<div class="alert alert-danger">No se ha podido leer la configuración del bloque de tarifas (producto o dirección AJAX).</div>';
      return;
    }
    box.setAttribute('data-jt-ready', '1');
    var labelIn = taxInput ? 'con IVA' : 'sin IVA';
    var labelOut = taxInput ? 'sin IVA' : 'con IVA';
    app.innerHTML =
      '<p class="jt-p-info text-muted">Cargando precios…</p>' +
      '<div class="table-responsive"><table class="table jt-p-table">' +
      '<thead><tr><th>Tarifa (grupo)</th><th class="text-right">Precio ' + labelIn + '</th><th class="text-right">Precio ' + labelOut + '</th><th>Aviso</th></tr></thead>' +
      '<tbody></tbody></table></div>' +
      '<div class="jt-p-status"></div>' +
      '<p><button type="button" class="btn btn-primary jt-p-save">Guardar precios de tarifa</button></p>';

    var tbody = app.querySelector('tbody');
    var info = app.querySelector('.jt-p-info');
    var status = app.querySelector('.jt-p-status');
    var saveBtn = app.querySelector('.jt-p-save');
    var dec = taxInput ? 2 : 6;
    var original = {};

    function call(action, data) {
      var opts = { method: data ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
      var url = ajaxUrl + '&jt_action=' + action + '&id_product=' + encodeURIComponent(idProduct);
      if (data) {
        var body = new FormData();
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        opts.body = body;
      }
      var timer = null;
      if (window.AbortController) {
        var ctrl = new AbortController();
        opts.signal = ctrl.signal;
        timer = setTimeout(function () { ctrl.abort(); }, 20000);
      }
      return fetch(url, opts).catch(function (e) {
        throw new Error((e && e.name === 'AbortError')
          ? 'El servidor no ha contestado en 20 segundos (' + url.split('&token')[0] + ').'
          : 'No se pudo conectar con ' + url.split('&token')[0] + ': ' + (e && e.message ? e.message : e));
      }).then(function (r) {
        if (timer) {
          clearTimeout(timer);
        }
        return r.text().then(function (text) {
          try {
            return JSON.parse(text);
          } catch (e) {
            var snippet = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 300);
            throw new Error('El servidor no ha devuelto datos (código ' + r.status + ', ' + r.url.split('&token')[0] + '). ' +
              (snippet ? 'Ha respondido: «' + snippet + '»' : 'La respuesta ha llegado vacía.') + ' Si la sesión ha caducado, recarga la página.');
          }
        });
      });
    }

    function render(data) {
      info.innerHTML = 'PVP: <strong>' + num(data.pvp, 6) + '</strong> sin IVA · <strong>' + num(data.pvp_ti, 2) + '</strong> con IVA · ' +
        esc(data.tax_name) + ' (' + num(data.rate, 2).replace(/,00$/, '') + ' %)';
      original = {};
      tbody.innerHTML = data.rows.length ? data.rows.map(function (r) {
        var value = taxInput ? r.tariff_ti : r.tariff;
        var other = taxInput ? r.tariff : r.tariff_ti;
        original[r.id_group] = value === null ? '' : num(value, dec);
        return '<tr' + (r.warn ? ' class="danger table-danger"' : '') + ' data-group="' + r.id_group + '">' +
          '<td>' + esc(r.name) + '</td>' +
          '<td class="text-right"><input type="text" class="form-control text-right jt-p-input" style="max-width:140px;margin-left:auto" value="' + esc(original[r.id_group]) + '" autocomplete="off"></td>' +
          '<td class="text-right">' + num(other, taxInput ? 6 : 2) + '</td>' +
          '<td class="text-danger">' + (r.warn ? esc(r.warn) : '') + '</td></tr>';
      }).join('') : '<tr><td colspan="4">No hay tarifas.</td></tr>';
    }

    function showStatus(type, text) {
      status.innerHTML = text ? '<div class="alert alert-' + type + '">' + text + '</div>' : '';
    }

    function load() {
      call('product').then(function (data) {
        if (!data.ok) {
          info.textContent = '';
          showStatus('danger', esc(data.error));
          return;
        }
        render(data);
      }).catch(function (e) {
        info.textContent = '';
        showStatus('danger', esc(e.message));
      });
    }

    function save() {
      var data = {};
      var changed = 0;
      Array.prototype.forEach.call(tbody.querySelectorAll('tr[data-group]'), function (tr) {
        var g = tr.getAttribute('data-group');
        var v = tr.querySelector('.jt-p-input').value.trim();
        if (v !== original[g]) {
          data['price_' + g] = v;
          changed++;
        }
      });
      if (!changed) {
        showStatus('info', 'No has cambiado ningún precio de tarifa.');
        return;
      }
      saveBtn.disabled = true;
      showStatus('info', 'Guardando…');
      call('saveproduct', data).then(function (res) {
        saveBtn.disabled = false;
        if (!res.ok) {
          showStatus('danger', esc(res.error));
          return;
        }
        render(res);
        if (res.errors && res.errors.length) {
          showStatus('warning', 'Guardados ' + res.saved + '. Con error: <br>' + res.errors.map(esc).join('<br>'));
        } else {
          showStatus('success', 'Precios de tarifa guardados (' + res.saved + ').');
        }
      }).catch(function (e) {
        saveBtn.disabled = false;
        showStatus('danger', esc(e.message));
      });
    }

    saveBtn.addEventListener('click', function (ev) {
      ev.preventDefault();
      save();
    });
    // Intro en un precio guarda las tarifas, sin enviar el formulario del producto
    tbody.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && ev.target.classList.contains('jt-p-input')) {
        ev.preventDefault();
        ev.stopPropagation();
        save();
      }
    });

    load();
  }

  function safeInit() {
    try {
      init();
    } catch (e) {
      var app = document.getElementById('jt-p-app');
      if (app) {
        app.innerHTML = '<div class="alert alert-danger"></div>';
        app.firstChild.textContent = 'Error al preparar el bloque de tarifas: ' + (e && e.message ? e.message : e);
      }
    }
  }

  // Se intenta al momento, al terminar de cargar la página y al abrir la pestaña Módulos
  safeInit();
  document.addEventListener('DOMContentLoaded', safeInit);
  window.addEventListener('load', safeInit);
  document.addEventListener('click', function (ev) {
    if (ev.target && ev.target.closest && ev.target.closest('.modules-list-button, .module-render-container, [href*="modules"], [data-target*="module"]')) {
      setTimeout(safeInit, 50);
    }
  }, true);
  // Por si el contenido de la pestaña Módulos se pinta más tarde
  if (window.MutationObserver) {
    var obs = new MutationObserver(function () {
      if (document.getElementById('jt-p-app')) {
        safeInit();
        var box = document.getElementById('josratarifas-product');
        if (box && box.getAttribute('data-jt-ready') === '1') {
          obs.disconnect();
        }
      }
    });
    var startObs = function () {
      if (document.body) {
        obs.observe(document.body, { childList: true, subtree: true });
      }
    };
    if (document.body) { startObs(); } else { document.addEventListener('DOMContentLoaded', startObs); }
  }
})();
