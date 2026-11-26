/**
 * josratarifas: rejilla de precios por tarifa (edición en línea, orden, filtros,
 * rellenar en bloque, exportar lo filtrado) e historial.
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
    var app = document.getElementById('josratarifas-app');
    if (!app) {
      return;
    }
    var ajaxUrl = app.getAttribute('data-ajax-url');
    var taxInput = app.getAttribute('data-tax-input') === '1';

    function buildUrl(params) {
      var url = ajaxUrl;
      Object.keys(params).forEach(function (k) { url += '&' + k + '=' + encodeURIComponent(params[k]); });
      return url;
    }

    function request(params, post) {
      var opts = { method: 'GET', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
      var url = ajaxUrl;
      if (post) {
        opts.method = 'POST';
        var body = new FormData();
        Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
        opts.body = body;
        url = buildUrl({ jt_action: params.jt_action });
      } else {
        url = buildUrl(params);
      }
      return fetch(url, opts).then(function (r) {
        return r.text().then(function (text) {
          try {
            return JSON.parse(text);
          } catch (e) {
            var snippet = text.replace(/<script[\s\S]*?<\/script>/gi, ' ').replace(/<style[\s\S]*?<\/style>/gi, ' ')
              .replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 300);
            throw new Error('El servidor no ha devuelto datos (código ' + r.status + '). ' +
              (snippet ? 'Ha respondido: «' + snippet + '»' : 'La respuesta ha llegado vacía.') +
              ' Si es la página de acceso, la sesión ha caducado: recarga la página.');
          }
        });
      });
    }

    initGrid(request, buildUrl, taxInput);
    initHistory(request, taxInput);
  }

  /* ---------------------------------------------------------------- */
  /* Rejilla                                                          */
  /* ---------------------------------------------------------------- */

  function initGrid(request, buildUrl, taxInput) {
    var table = document.getElementById('jt-table');
    if (!table) {
      return;
    }
    var tbody = table.querySelector('tbody');
    var groupSel = document.getElementById('jt-group');
    var qInput = document.getElementById('jt-q');
    var filterSel = document.getElementById('jt-filter');
    var catSel = document.getElementById('jt-category');
    var manSel = document.getElementById('jt-manufacturer');
    var status = document.getElementById('jt-status');
    var countBox = document.getElementById('jt-count');
    var prevBtn = document.getElementById('jt-prev');
    var nextBtn = document.getElementById('jt-next');
    var state = { page: 1, pages: 1, loading: false, sort: 'id', dir: 'desc', rows: {} };
    var COLS = 11;

    function showStatus(type, text) {
      status.innerHTML = text ? '<div class="alert alert-' + type + '">' + esc(text) + '</div>' : '';
    }

    function filters() {
      return {
        id_group: groupSel.value,
        q: qInput.value,
        filter: filterSel.value,
        id_category: catSel ? catSel.value : 0,
        id_manufacturer: manSel ? manSel.value : 0,
        sort: state.sort,
        dir: state.dir
      };
    }

    function merge(a, b) {
      var out = {};
      Object.keys(a).forEach(function (k) { out[k] = a[k]; });
      Object.keys(b).forEach(function (k) { out[k] = b[k]; });
      return out;
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

    function links(row) {
      var html = '';
      if (row.url_front) {
        html += '<a href="' + esc(row.url_front) + '" target="_blank" rel="noopener" title="Ver en la tienda (pestaña nueva)"><i class="icon-eye"></i></a>';
      }
      if (row.url_edit) {
        html += (html ? ' ' : '') + '<a href="' + esc(row.url_edit) + '" target="_blank" rel="noopener" title="Editar el producto (pestaña nueva)"><i class="icon-pencil"></i></a>';
      }
      return html;
    }

    function rowHtml(row) {
      var value = editableValue(row);
      var cls = row.warn ? ' class="danger"' : (row.active ? '' : ' class="text-muted"');
      return '<tr data-id="' + row.id + '"' + cls + '>' +
        '<td class="text-nowrap" style="white-space:nowrap">' + links(row) + '</td>' +
        '<td>' + row.id + '</td>' +
        '<td>' + esc(row.ref) + '</td>' +
        '<td>' + esc(row.name) + (row.active ? '' : ' <span class="label label-default">inactivo</span>') + '</td>' +
        '<td>' + esc(row.tax_name) + ' · ' + num(row.rate, 2).replace(/,00$/, '') + ' %</td>' +
        '<td class="text-right">' + num(row.pvp, 6) + '</td>' +
        '<td class="text-right">' + num(row.pvp_ti, 2) + '</td>' +
        '<td class="text-right jt-cell" style="cursor:pointer" title="Pincha para cambiar el precio">' +
          (value === null ? '<span class="text-muted">—</span>' : '<strong>' + num(value, taxInput ? 2 : 6) + '</strong>') +
        '</td>' +
        '<td class="text-right">' + num(otherValue(row), taxInput ? 6 : 2) + '</td>' +
        '<td class="text-right">' + diffText(row) + '</td>' +
        '<td class="jt-msg">' + (row.warn ? '<span class="text-danger"><i class="icon-warning"></i> ' + esc(row.warn) + '</span>' : '') + '</td>' +
        '</tr>';
    }

    function markSort() {
      Array.prototype.forEach.call(table.querySelectorAll('.jt-sort'), function (a) {
        var icon = a.querySelector('i');
        if (icon) {
          a.removeChild(icon);
        }
        if (a.getAttribute('data-sort') === state.sort) {
          var i = document.createElement('i');
          i.className = state.dir === 'asc' ? 'icon-caret-up' : 'icon-caret-down';
          a.appendChild(document.createTextNode(''));
          a.appendChild(i);
        }
      });
    }

    function load(page) {
      if (state.loading) {
        return;
      }
      state.loading = true;
      showStatus('', '');
      markSort();
      tbody.innerHTML = '<tr><td colspan="' + COLS + '" class="text-center">Cargando…</td></tr>';
      request(merge(filters(), { jt_action: 'list', page: page || 1 }))
        .then(function (data) {
          state.loading = false;
          if (!data.ok) {
            tbody.innerHTML = '';
            showStatus('danger', data.error);
            return;
          }
          state.page = data.page;
          state.pages = data.pages;
          state.rows = {};
          tbody.innerHTML = data.rows.length
            ? data.rows.map(function (r) { state.rows[r.id] = r; return rowHtml(r); }).join('')
            : '<tr><td colspan="' + COLS + '" class="text-center">No hay productos con ese filtro.</td></tr>';
          countBox.innerHTML = esc(data.total + ' productos · página ' + data.page + ' de ' + data.pages) +
            (data.warnings ? ' · <span class="text-danger"><i class="icon-warning"></i> ' + data.warnings + ' con avisos</span>' : '');
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
      var row = state.rows[id];
      var value = editableValue(row);
      var original = value === null ? '' : num(value, taxInput ? 2 : 6);
      var input = document.createElement('input');
      input.type = 'text';
      input.className = 'form-control text-right';
      input.style.minWidth = '90px';
      input.value = original;
      td.innerHTML = '';
      td.appendChild(input);
      input.focus();
      input.select();

      var done = false;
      function setMsg(html) {
        var current = tbody.querySelector('tr[data-id="' + id + '"]');
        if (current) {
          current.querySelector('.jt-msg').innerHTML = html;
        }
      }
      function finish(save) {
        if (done) {
          return;
        }
        done = true;
        if (!save || input.value.trim() === original) {
          tr.outerHTML = rowHtml(row);
          return;
        }
        input.disabled = true;
        tr.querySelector('.jt-msg').innerHTML = '<span class="text-muted">Guardando…</span>';
        request({ jt_action: 'save', id_group: groupSel.value, id_product: id, value: input.value.trim() }, true)
          .then(function (data) {
            if (!data.ok) {
              tr.outerHTML = rowHtml(row);
              setMsg('<span class="text-danger">' + esc(data.error) + '</span>');
              return;
            }
            state.rows[id] = data.row;
            tr.outerHTML = rowHtml(data.row);
            setMsg((data.row.warn ? '<span class="text-danger"><i class="icon-warning"></i> ' + esc(data.row.warn) + '</span> ' : '') +
              '<span class="text-success"><i class="icon-check"></i> ' + esc(data.message) + '</span>');
          })
          .catch(function (e) {
            tr.outerHTML = rowHtml(row);
            setMsg('<span class="text-danger">' + esc(e.message) + '</span>');
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
    Array.prototype.forEach.call(table.querySelectorAll('.jt-sort'), function (a) {
      a.addEventListener('click', function (ev) {
        ev.preventDefault();
        var col = a.getAttribute('data-sort');
        if (state.sort === col) {
          state.dir = state.dir === 'asc' ? 'desc' : 'asc';
        } else {
          state.sort = col;
          state.dir = (col === 'ref' || col === 'name' || col === 'tax') ? 'asc' : 'desc';
        }
        load(1);
      });
    });
    document.getElementById('jt-search').addEventListener('click', function () { load(1); });
    qInput.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        load(1);
      }
    });
    [groupSel, filterSel, catSel, manSel].forEach(function (el) {
      if (el) {
        el.addEventListener('change', function () { load(1); resetBulk(); });
      }
    });
    qInput.addEventListener('change', resetBulk);
    prevBtn.addEventListener('click', function () { if (state.page > 1) { load(state.page - 1); } });
    nextBtn.addEventListener('click', function () { if (state.page < state.pages) { load(state.page + 1); } });

    // Exportar lo filtrado (solo la tarifa elegida)
    var exportBtn = document.getElementById('jt-export-filtered');
    if (exportBtn) {
      exportBtn.addEventListener('click', function () {
        window.location.href = buildUrl(merge(filters(), { jt_action: 'export' }));
      });
    }

    // Rellenar en bloque
    var bulkBox = document.getElementById('jt-bulk');
    var bulkResult = document.getElementById('jt-bulk-result');
    var applyBtn = document.getElementById('jt-bulk-apply');
    var previewBtn = document.getElementById('jt-bulk-preview');
    var bulkFields = ['jt-bulk-base', 'jt-bulk-percent', 'jt-bulk-rounding', 'jt-bulk-empty'];

    function resetBulk() {
      if (applyBtn) {
        applyBtn.disabled = true;
      }
      if (bulkResult) {
        bulkResult.innerHTML = '';
      }
    }

    function bulkParams(dry) {
      return merge(filters(), {
        jt_action: 'bulk',
        base: document.getElementById('jt-bulk-base').value,
        percent: document.getElementById('jt-bulk-percent').value,
        rounding: document.getElementById('jt-bulk-rounding').value,
        only_empty: document.getElementById('jt-bulk-empty').checked ? 1 : 0,
        dry: dry ? 1 : 0
      });
    }

    function bulkSummary(data) {
      var s = data.stats;
      var parts = [];
      parts.push('<strong>' + s.change + '</strong> precios ' + (data.dry ? 'cambiarían' : 'cambiados'));
      if (s.same) { parts.push(s.same + ' ya tienen ese precio'); }
      if (s.has_price) { parts.push(s.has_price + ' se saltan porque ya tienen precio'); }
      if (s.no_source) { parts.push(s.no_source + ' se saltan porque no tienen precio en la tarifa de origen'); }
      var html = '<div class="alert alert-' + (data.dry ? 'info' : 'success') + '">' +
        (data.dry ? 'Vista previa, no se ha guardado nada: ' : '<strong>Hecho.</strong> ') + parts.join(' · ') + '.</div>';
      if (data.sample && data.sample.length) {
        var dec = data.tax_input ? 2 : 6;
        html += '<table class="table"><thead><tr><th>Referencia</th><th>Producto</th><th class="text-right">Antes</th><th class="text-right">' +
          (data.dry ? 'Quedaría' : 'Ahora') + '</th></tr></thead><tbody>' +
          data.sample.map(function (r) {
            return '<tr><td>' + esc(r.ref) + '</td><td>' + esc(r.name) + '</td><td class="text-right">' +
              (r.before === null ? '—' : num(r.before, dec)) + '</td><td class="text-right"><strong>' + num(r.after, dec) + '</strong></td></tr>';
          }).join('') + '</tbody></table>' +
          (data.stats.change > data.sample.length ? '<p class="help-block">Se muestran los ' + data.sample.length + ' primeros.</p>' : '');
      }
      return html;
    }

    if (bulkBox) {
      document.getElementById('jt-bulk-toggle').addEventListener('click', function () {
        bulkBox.style.display = bulkBox.style.display === 'none' ? '' : 'none';
      });
      bulkFields.forEach(function (id) {
        document.getElementById(id).addEventListener('change', resetBulk);
      });
      previewBtn.addEventListener('click', function () {
        resetBulk();
        bulkResult.innerHTML = '<p class="text-muted">Calculando…</p>';
        request(bulkParams(true), true).then(function (data) {
          if (!data.ok) {
            bulkResult.innerHTML = '<div class="alert alert-danger">' + esc(data.error) + '</div>';
            return;
          }
          bulkResult.innerHTML = bulkSummary(data);
          applyBtn.disabled = data.stats.change === 0;
        }).catch(function (e) {
          bulkResult.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>';
        });
      });
      applyBtn.addEventListener('click', function () {
        if (!window.confirm('¿Aplicar el cambio a todos los productos del filtro? Queda apuntado en el historial.')) {
          return;
        }
        applyBtn.disabled = true;
        bulkResult.innerHTML = '<p class="text-muted">Guardando…</p>';
        request(bulkParams(false), true).then(function (data) {
          if (!data.ok) {
            bulkResult.innerHTML = '<div class="alert alert-danger">' + esc(data.error) + '</div>';
            return;
          }
          bulkResult.innerHTML = bulkSummary(data);
          load(state.page);
        }).catch(function (e) {
          bulkResult.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>';
        });
      });
    }

    load(1);
  }

  /* ---------------------------------------------------------------- */
  /* Historial                                                        */
  /* ---------------------------------------------------------------- */

  function initHistory(request, taxInput) {
    var table = document.getElementById('jt-h-table');
    if (!table) {
      return;
    }
    var tbody = table.querySelector('tbody');
    var status = document.getElementById('jt-h-status');
    var countBox = document.getElementById('jt-h-count');
    var prevBtn = document.getElementById('jt-h-prev');
    var nextBtn = document.getElementById('jt-h-next');
    var qInput = document.getElementById('jt-h-q');
    var state = { page: 1, pages: 1, loaded: false };
    var dec = taxInput ? 2 : 6;

    function load(page) {
      state.loaded = true;
      status.innerHTML = '';
      tbody.innerHTML = '<tr><td colspan="8" class="text-center">Cargando…</td></tr>';
      request({
        jt_action: 'history',
        id_group: document.getElementById('jt-h-group').value,
        source: document.getElementById('jt-h-source').value,
        q: qInput.value,
        page: page || 1
      }).then(function (data) {
        if (!data.ok) {
          tbody.innerHTML = '';
          status.innerHTML = '<div class="alert alert-danger">' + esc(data.error) + '</div>';
          return;
        }
        state.page = data.page;
        state.pages = data.pages;
        tbody.innerHTML = data.rows.length ? data.rows.map(function (r) {
          return '<tr><td>' + esc(r.date) + '</td><td>' + esc(r.employee) + '</td><td>' + esc(r.tariff) + '</td><td>' + esc(r.ref) +
            '</td><td>' + esc(r.name) + '</td><td class="text-right">' + (r.old === null ? '—' : num(r.old, dec)) +
            '</td><td class="text-right"><strong>' + (r.new === null ? '—' : num(r.new, dec)) + '</strong></td><td>' + esc(r.source) + '</td></tr>';
        }).join('') : '<tr><td colspan="8" class="text-center">No hay cambios con ese filtro.</td></tr>';
        countBox.textContent = data.total + ' cambios · página ' + data.page + ' de ' + data.pages;
        prevBtn.disabled = data.page <= 1;
        nextBtn.disabled = data.page >= data.pages;
      }).catch(function (e) {
        tbody.innerHTML = '';
        status.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>';
      });
    }

    var tab = document.getElementById('jt-history-tab');
    if (tab) {
      tab.addEventListener('click', function () {
        if (!state.loaded) {
          load(1);
        }
      });
    }
    document.getElementById('jt-h-search').addEventListener('click', function () { load(1); });
    qInput.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        load(1);
      }
    });
    ['jt-h-group', 'jt-h-source'].forEach(function (id) {
      document.getElementById(id).addEventListener('change', function () { load(1); });
    });
    prevBtn.addEventListener('click', function () { if (state.page > 1) { load(state.page - 1); } });
    nextBtn.addEventListener('click', function () { if (state.page < state.pages) { load(state.page + 1); } });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
