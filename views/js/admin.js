/**
 * josratarifas: rejilla de precios por tarifa con edición en línea.
 * @author josra
 */
(function () {
  'use strict';

  function init() {
    var app = document.getElementById('josratarifas-app');
    var table = document.getElementById('jt-table');
    if (!app || !table) {
      return;
    }

    var ajaxUrl = app.getAttribute('data-ajax-url');
    var taxInput = app.getAttribute('data-tax-input') === '1';
    var tbody = table.querySelector('tbody');
    var groupSel = document.getElementById('jt-group');
    var qInput = document.getElementById('jt-q');
    var filterSel = document.getElementById('jt-filter');
    var status = document.getElementById('jt-status');
    var countBox = document.getElementById('jt-count');
    var prevBtn = document.getElementById('jt-prev');
    var nextBtn = document.getElementById('jt-next');
    var state = { page: 1, pages: 1, loading: false };

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

    function showStatus(type, text) {
      status.innerHTML = text ? '<div class="alert alert-' + type + '">' + esc(text) + '</div>' : '';
    }

    function request(params, method) {
      var url = ajaxUrl + '&id_group=' + encodeURIComponent(groupSel.value);
      var opts = { method: method || 'GET', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
      if (opts.method === 'POST') {
        var body = new FormData();
        Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
        opts.body = body;
      } else {
        Object.keys(params).forEach(function (k) { url += '&' + k + '=' + encodeURIComponent(params[k]); });
      }
      return fetch(url, opts).then(function (r) {
        return r.text().then(function (text) {
          var data;
          try {
            data = JSON.parse(text);
          } catch (e) {
            throw new Error('El servidor no ha devuelto una respuesta válida (código ' + r.status + '). Puede que la sesión del back-office haya caducado: recarga la página.');
          }
          return data;
        });
      });
    }

    function editableValue(row) {
      return taxInput ? row.tariff_ti : row.tariff;
    }

    function otherValue(row) {
      return taxInput ? row.tariff : row.tariff_ti;
    }

    function diffText(row) {
      if (row.tariff === null || !row.pvp) {
        return '';
      }
      var d = (row.tariff - row.pvp) / row.pvp * 100;
      return (d > 0 ? '+' : '') + d.toFixed(1).replace('.', ',') + ' %';
    }

    function rowHtml(row) {
      var value = editableValue(row);
      return '<tr data-id="' + row.id + '"' + (row.active ? '' : ' class="text-muted"') + '>' +
        '<td>' + row.id + '</td>' +
        '<td>' + esc(row.ref) + '</td>' +
        '<td>' + esc(row.name) + (row.active ? '' : ' <span class="label label-default">inactivo</span>') + '</td>' +
        '<td class="text-right">' + num(row.pvp, 6) + '</td>' +
        '<td class="text-right">' + num(row.pvp_ti, 2) + '</td>' +
        '<td class="text-right jt-cell" style="cursor:pointer" title="Pincha para cambiar el precio">' +
          (value === null ? '<span class="text-muted">—</span>' : '<strong>' + num(value, taxInput ? 2 : 6) + '</strong>') +
        '</td>' +
        '<td class="text-right">' + num(otherValue(row), taxInput ? 6 : 2) + '</td>' +
        '<td class="text-right">' + diffText(row) + '</td>' +
        '<td class="jt-msg"></td>' +
        '</tr>';
    }

    function load(page) {
      if (state.loading) {
        return;
      }
      state.loading = true;
      showStatus('', '');
      tbody.innerHTML = '<tr><td colspan="9" class="text-center">Cargando…</td></tr>';
      request({ action: 'list', q: qInput.value, filter: filterSel.value, page: page || 1 })
        .then(function (data) {
          state.loading = false;
          if (!data.ok) {
            tbody.innerHTML = '';
            showStatus('danger', data.error);
            return;
          }
          state.page = data.page;
          state.pages = data.pages;
          var rows = {};
          tbody.innerHTML = data.rows.length
            ? data.rows.map(function (r) { rows[r.id] = r; return rowHtml(r); }).join('')
            : '<tr><td colspan="9" class="text-center">No hay productos con ese filtro.</td></tr>';
          tbody._rows = rows;
          countBox.textContent = data.total + ' productos · página ' + data.page + ' de ' + data.pages;
          prevBtn.disabled = data.page <= 1;
          nextBtn.disabled = data.page >= data.pages;
        })
        .catch(function (e) {
          state.loading = false;
          tbody.innerHTML = '';
          showStatus('danger', e.message);
        });
    }

    function startEdit(td) {
      if (td.querySelector('input')) {
        return;
      }
      var tr = td.parentNode;
      var id = tr.getAttribute('data-id');
      var row = tbody._rows[id];
      var value = editableValue(row);
      var input = document.createElement('input');
      input.type = 'text';
      input.className = 'form-control text-right';
      input.style.minWidth = '90px';
      input.value = value === null ? '' : num(value, taxInput ? 2 : 6);
      td.innerHTML = '';
      td.appendChild(input);
      input.focus();
      input.select();

      var done = false;
      function finish(save) {
        if (done) {
          return;
        }
        done = true;
        var original = value === null ? '' : num(value, taxInput ? 2 : 6);
        if (!save || input.value.trim() === original) {
          tr.outerHTML = rowHtml(row);
          return;
        }
        input.disabled = true;
        var msg = tr.querySelector('.jt-msg');
        msg.innerHTML = '<span class="text-muted">Guardando…</span>';
        request({ action: 'save', id_product: id, value: input.value.trim() }, 'POST')
          .then(function (data) {
            if (!data.ok) {
              tr.outerHTML = rowHtml(row);
              var newTr = tbody.querySelector('tr[data-id="' + id + '"]');
              newTr.querySelector('.jt-msg').innerHTML = '<span class="text-danger">' + esc(data.error) + '</span>';
              return;
            }
            tbody._rows[id] = data.row;
            tr.outerHTML = rowHtml(data.row);
            var saved = tbody.querySelector('tr[data-id="' + id + '"]');
            saved.querySelector('.jt-msg').innerHTML = '<span class="text-success"><i class="icon-check"></i> ' + esc(data.message) + '</span>';
          })
          .catch(function (e) {
            tr.outerHTML = rowHtml(row);
            var newTr = tbody.querySelector('tr[data-id="' + id + '"]');
            newTr.querySelector('.jt-msg').innerHTML = '<span class="text-danger">' + esc(e.message) + '</span>';
          });
      }

      input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          finish(true);
        } else if (ev.key === 'Escape') {
          ev.preventDefault();
          finish(false);
        }
      });
      input.addEventListener('blur', function () { finish(true); });
    }

    tbody.addEventListener('click', function (ev) {
      var td = ev.target.closest ? ev.target.closest('.jt-cell') : null;
      if (td) {
        startEdit(td);
      }
    });
    document.getElementById('jt-search').addEventListener('click', function () { load(1); });
    qInput.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        load(1);
      }
    });
    groupSel.addEventListener('change', function () { load(1); });
    filterSel.addEventListener('change', function () { load(1); });
    prevBtn.addEventListener('click', function () { if (state.page > 1) { load(state.page - 1); } });
    nextBtn.addEventListener('click', function () { if (state.page < state.pages) { load(state.page + 1); } });

    load(1);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
