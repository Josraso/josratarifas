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
    if (!box || box.getAttribute('data-ready') === '1' || !box.querySelector('tbody')) {
      return;
    }
    box.setAttribute('data-ready', '1');

    var ajaxUrl = box.getAttribute('data-ajax-url');
    var idProduct = box.getAttribute('data-id-product');
    var taxInput = box.getAttribute('data-tax-input') === '1';
    var tbody = box.querySelector('tbody');
    var info = box.querySelector('.jt-p-info');
    var status = box.querySelector('.jt-p-status');
    var saveBtn = box.querySelector('.jt-p-save');
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
      return fetch(url, opts).then(function (r) {
        return r.text().then(function (text) {
          try {
            return JSON.parse(text);
          } catch (e) {
            var snippet = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 300);
            throw new Error('El servidor no ha devuelto datos (código ' + r.status + '). ' +
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

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
