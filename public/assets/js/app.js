/* ETHAN — branchement des interactions déclaratives (attributs data-*). Aucun script inline (CSP). */
(function (window, document) {
  'use strict';

  const core = window.EthCore;
  const tablesDef = window.EthTables;
  const $ = window.jQuery;

  function readJson(id) {
    const el = document.getElementById(id);
    if (!el) {
      return {};
    }
    try {
      return JSON.parse(el.textContent || '{}');
    } catch (e) {
      return {};
    }
  }

  const config = readJson('eth-config');
  const t = core.translator(readJson('eth-i18n'));

  function initIcons() {
    if (window.lucide) {
      window.lucide.createIcons();
    }
  }

  /** Sections du menu : état ouvert / replié retenu par navigateur ; la section de la page courante reste ouverte. */
  function bindNavSections() {
    let saved = {};
    try {
      saved = JSON.parse(window.localStorage.getItem('eth.nav') || '{}');
    } catch (e) {
      saved = {};
    }
    document.querySelectorAll('details[data-nav-section]').forEach((group) => {
      const key = group.dataset.navSection;
      if (!group.classList.contains('is-current') && saved[key] !== undefined) {
        group.open = saved[key];
      }
      group.addEventListener('toggle', () => {
        saved[key] = group.open;
        try {
          window.localStorage.setItem('eth.nav', JSON.stringify(saved));
        } catch (e) { /* stockage indisponible : état non retenu */ }
      });
    });
  }

  function initSidebar() {
    bindNavSections();
    document.querySelectorAll('[data-toggle-sidebar]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const open = document.body.classList.toggle('sidebar-open');
        btn.setAttribute('aria-expanded', String(open));
      });
    });
    document.querySelectorAll('[data-sidebar-close]').forEach((el) => {
      el.addEventListener('click', () => document.body.classList.remove('sidebar-open'));
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        document.body.classList.remove('sidebar-open');
      }
    });
  }

  function initNotifications() {
    const toggle = document.querySelector('[data-notif-toggle]');
    const panel = document.querySelector('[data-notif-panel]');
    if (!toggle || !panel) {
      return;
    }
    const close = () => {
      panel.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
    };
    toggle.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = panel.hidden;
      panel.hidden = !open;
      toggle.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('click', (e) => {
      if (!panel.hidden && !panel.contains(e.target)) {
        close();
      }
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        close();
      }
    });
  }

  /** Connexion : alerte « Verr. Maj activée » et bouton en attente pendant l'envoi (pas de double clic). */
  function initLoginForm() {
    const form = document.querySelector('form[data-login]');
    if (!form || form.dataset.ready) {
      return;
    }
    form.dataset.ready = '1';
    const caps = form.querySelector('[data-caps-warning]');
    const password = form.querySelector('input[type=password], input[name=password]');
    const check = (e) => {
      if (caps && e.getModifierState) {
        caps.hidden = !e.getModifierState('CapsLock');
      }
    };
    if (password) {
      password.addEventListener('keydown', check);
      password.addEventListener('keyup', check);
      password.addEventListener('blur', () => { if (caps) { caps.hidden = true; } });
    }
    form.addEventListener('submit', () => {
      const btn = form.querySelector('[data-login-submit]');
      if (btn) {
        btn.disabled = true;
        btn.classList.add('is-busy');
        btn.querySelector('span').textContent = btn.dataset.busyLabel || btn.textContent;
      }
    });
  }

  function initPasswordToggle() {
    initLoginForm();
    document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const input = document.getElementById(btn.getAttribute('aria-controls'));
        if (!input) {
          return;
        }
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', String(show));
      });
    });
  }

  /** form[data-confirm] : confirmation avant toute action irréversible. */
  function initSubmitGuard() {
    document.addEventListener('submit', (e) => {
      if (e.defaultPrevented) {
        return;
      }
      const buttons = e.target.querySelectorAll('button[type="submit"]');
      window.setTimeout(() => {
        if (!e.defaultPrevented) {
          buttons.forEach((b) => { b.disabled = true; });
        }
      }, 0);
    });
  }

  function initRowLinks(scope) {
    scope.addEventListener('click', (e) => {
      const row = e.target.closest('tr.row-link');
      if (!row || e.target.closest('a, button, input, select, label')) {
        return;
      }
      window.location.href = row.dataset.href;
    });
    scope.addEventListener('keydown', (e) => {
      const row = e.target.closest('tr.row-link');
      if (row && e.key === 'Enter') {
        window.location.href = row.dataset.href;
      }
    });
  }

  function filterEntries(form) {
    if (!form) {
      return [];
    }
    return Array.from(form.querySelectorAll('[data-filter]')).map((el) => [el.dataset.filter, el.value]);
  }

  function updateFilterState(form) {
    const n = core.countActiveFilters(filterEntries(form).map((entry) => entry[1]));
    const count = form.querySelector('[data-filters-count]');
    const reset = form.querySelector('[data-filters-reset]');
    if (count) {
      count.textContent = t('filters.active', { n: n });
      count.hidden = n === 0;
    }
    if (reset) {
      reset.hidden = n === 0;
    }
  }

  function dtLanguage() {
    const keys = ['search', 'lengthMenu', 'info', 'infoEmpty', 'infoFiltered', 'zeroRecords', 'emptyTable', 'processing', 'loadingRecords'];
    const lang = {};
    keys.forEach((k) => { lang[k] = t('dt.' + k); });
    return lang;
  }

  /** table[data-table] : DataTables en mode serveur, colonnes définies dans eth-tables.js. */
  function initTables() {
    if (!$ || !window.DataTable) {
      return;
    }
    $.ajaxSetup({ headers: { 'X-CSRF-Token': config.csrf, 'X-Requested-With': 'XMLHttpRequest' } });
    $(document).ajaxError((event, xhr) => {
      if (xhr.status === 401) {
        window.location.reload();
      }
    });
    window.DataTable.ext.errMode = 'none';

    document.querySelectorAll('table[data-table]').forEach((table) => {
      const name = table.dataset.table;
      const columns = tablesDef.tables[name];
      if (!columns) {
        return;
      }
      const filters = document.querySelector('form[data-filters-for="' + name + '"]');
      const ctx = { locale: config.locale, t: t };

      const dt = new window.DataTable(table, {
        serverSide: true,
        processing: true,
        searchDelay: 350,
        pageLength: 15,
        lengthMenu: [15, 25, 50, 100],
        order: [],
        layout: { topStart: 'search', topEnd: 'pageLength', bottomStart: 'info', bottomEnd: 'paging' },
        ajax: {
          url: table.dataset.source,
          data: (d) => {
            filterEntries(filters).forEach((entry) => { d[entry[0]] = entry[1]; });
          },
        },
        columns: columns.map((c) => ({
          data: c.data,
          title: t(c.title),
          orderable: c.orderable !== false,
          className: c.className || '',
          defaultContent: '',
          render: (data, type, row) => (type === 'display' ? c.html(row, ctx) : (c.text ? c.text(row, ctx) : '')),
        })),
        language: dtLanguage(),
        createdRow: (tr, row) => {
          if (table.dataset.rowLink) {
            tr.classList.add('row-link');
            tr.tabIndex = 0;
            tr.dataset.href = core.fillUrl(table.dataset.rowLink, row);
          }
        },
        drawCallback: initIcons,
      });

      dt.on('error.dt', () => {
        if (window.Swal) {
          window.Swal.fire({ icon: 'error', text: t('dt.error') });
        }
      });

      if (filters) {
        filters.addEventListener('submit', (e) => e.preventDefault());
        filters.addEventListener('change', () => {
          updateFilterState(filters);
          dt.ajax.reload();
        });
        const reset = filters.querySelector('[data-filters-reset]');
        if (reset) {
          reset.addEventListener('click', () => {
            filters.querySelectorAll('[data-filter]').forEach((el) => { el.value = ''; });
            updateFilterState(filters);
            dt.ajax.reload();
          });
        }
        updateFilterState(filters);
      }

      document.querySelectorAll('[data-set-filter]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const target = filters && filters.querySelector('[data-filter="' + btn.dataset.setFilter + '"]');
          if (!target) {
            return;
          }
          target.value = btn.dataset.value;
          target.dispatchEvent(new Event('change', { bubbles: true }));
          table.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      });
    });
  }

  /**
   * Tous les tableaux .table (hors saisie .lines-table, hors data-no-dt) deviennent des DataTables compacts :
   *  - liste (par défaut) : barre d'outils complète, tri, pagination ;
   *  - data-dt="compact" : tableau de fiche ; barre d'outils dès 8 lignes, sans pagination.
   * Barre d'outils : recherche (y compris dans les détails), filtres déclarés, déplier tout, colonnes, exports Excel / PDF.
   * Déclarations sur l'en-tête :
   *  - <th data-filter="select|date|range"> : filtre (select : td[data-filter] sinon 1re ligne du texte ;
   *    date / range : td[data-order], data-filter-scale pour la saisie) ;
   *  - <th data-x="text|money|percent|qty|int|date|none"> : type à l'export (déduit sinon) ; data-x-total="1" : total ;
   *  - <th data-priority="low"> : colonne masquée sur petit écran (son contenu reste dans le détail).
   * Détail dépliable : <template data-detail> dans la ligne (row_detail() côté PHP).
   */
  const tableFilters = new WeakMap();

  function cellValue(td) {
    if (!td) {
      return '';
    }
    if (td.dataset.filter !== undefined) {
      return td.dataset.filter;
    }
    return (td.innerText || td.textContent || '').split('\n')[0].trim();
  }

  function fold(text) {
    return String(text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  }

  /** Texte de recherche d'une ligne : cellules (même masquées) + détail. */
  function rowSearchText(row) {
    if (row._ethText === undefined) {
      const cells = (row.anCells || []).map((td) => (td ? td.textContent : '')).join(' ');
      const tpl = row.nTr && row.nTr.querySelector(':scope > template[data-detail]');
      row._ethText = fold(cells + ' ' + (tpl ? tpl.content.textContent : ''));
    }
    return row._ethText;
  }

  function registerTableSearch() {
    window.DataTable.ext.search.push((settings, data, dataIndex) => {
      const state = tableFilters.get(settings.nTable);
      if (!state) {
        return true;
      }
      const row = settings.aoData[dataIndex];
      if (!row || !row.nTr) {
        return true;
      }
      if (state.query) {
        const text = rowSearchText(row);
        if (!state.query.split(/\s+/).every((word) => text.includes(word))) {
          return false;
        }
      }
      for (const f of state.filters) {
        const td = row.anCells ? row.anCells[f.index] : row.nTr.cells[f.index];
        if (f.type === 'select') {
          if (f.value !== '' && cellValue(td) !== f.value) {
            return false;
          }
        } else {
          const raw = td ? (td.dataset.order || '') : '';
          if (f.type === 'date') {
            const day = raw.slice(0, 10);
            if ((f.from && (!day || day < f.from)) || (f.to && (!day || day > f.to))) {
              return false;
            }
          } else {
            const n = raw === '' ? null : Number(raw);
            if ((f.min !== null && (n === null || n < f.min)) || (f.max !== null && (n === null || n > f.max))) {
              return false;
            }
          }
        }
      }
      return true;
    });
  }

  function el(tag, attrs, text) {
    const node = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([k, v]) => node.setAttribute(k, v));
    if (text !== undefined) {
      node.textContent = text;
    }
    return node;
  }

  function iconBtn(icon, label, cls) {
    const b = el('button', { type: 'button', class: 'btn btn--ghost btn--sm ' + (cls || '') });
    b.appendChild(el('i', { 'data-lucide': icon, 'aria-hidden': 'true' }));
    b.appendChild(el('span', {}, label));
    return b;
  }

  /** Lignes dépliables : bouton chevron dans la 1re cellule, détail affiché en ligne enfant. */
  function initDetails(table, dt) {
    if (!table.querySelector('tbody > tr > template[data-detail]')) {
      return null;
    }
    table.classList.add('table--expandable');
    dt.rows().every(function () {
      const tr = this.node();
      const td = tr.cells[0];
      if (!tr.querySelector(':scope > template[data-detail]') || !td || td.querySelector('.row-toggle')) {
        return;
      }
      const btn = el('button', { type: 'button', class: 'row-toggle', 'aria-expanded': 'false', 'aria-label': t('grid.details') });
      btn.appendChild(el('i', { 'data-lucide': 'chevron-right', 'aria-hidden': 'true' }));
      td.insertBefore(btn, td.firstChild);
    });
    const toggle = (tr, open) => {
      const row = dt.row(tr);
      const tpl = tr.querySelector(':scope > template[data-detail]');
      if (!tpl) {
        return;
      }
      const show = open === undefined ? !row.child.isShown() : open;
      if (show && !row.child.isShown()) {
        row.child(tpl.innerHTML, 'row-detail-cell').show();
        initIcons();
      } else if (!show && row.child.isShown()) {
        row.child.hide();
      }
      tr.classList.toggle('is-open', show);
      const btn = tr.querySelector('.row-toggle');
      if (btn) {
        btn.setAttribute('aria-expanded', String(show));
      }
    };
    table.tBodies[0].addEventListener('click', (e) => {
      const btn = e.target.closest('.row-toggle');
      const tr = e.target.closest('tr');
      if (btn) {
        e.stopPropagation();
        toggle(btn.closest('tr'));
      } else if (tr && !tr.classList.contains('row-link') && tr.querySelector(':scope > template[data-detail]') && !e.target.closest('a, button, input, select, label, form')) {
        toggle(tr);
      }
    }, true);
    return {
      all: (open) => dt.rows({ search: 'applied' }).nodes().each((tr) => toggle(tr, open)),
    };
  }

  /** Libellé visible d'un en-tête (hors textes réservés aux lecteurs d'écran). */
  function headerLabel(th) {
    const clone = th.cloneNode(true);
    clone.querySelectorAll('.visually-hidden, .dt-column-order').forEach((n) => n.remove());
    return clone.textContent.replace(/\s+/g, ' ').trim();
  }

  /** Type d'export d'une colonne : déclaré (data-x) ou déduit des filtres et de l'alignement. */
  function columnType(th, sampleTd) {
    if (th.dataset.x) {
      return th.dataset.x;
    }
    if (headerLabel(th) === '') {
      return 'none';
    }
    if (th.dataset.filter === 'date') {
      return 'date';
    }
    if (th.dataset.filterScale === '100' && th.classList.contains('num')) {
      return 'money';
    }
    if (th.classList.contains('num') && sampleTd && sampleTd.dataset.order !== undefined && sampleTd.dataset.order !== '' && !isNaN(Number(sampleTd.dataset.order))) {
      return 'int';
    }
    return 'text';
  }

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      if (document.querySelector('script[data-src="' + src + '"]')) {
        resolve();
        return;
      }
      const s = el('script', { src: src, 'data-src': src });
      s.onload = () => resolve();
      s.onerror = () => reject(new Error(src));
      document.head.appendChild(s);
    });
  }

  function download(blob, name) {
    const a = el('a', { href: URL.createObjectURL(blob), download: name });
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  }

  /** Texte d'une cellule sur une ligne : les retours (<br>) deviennent « · », sans boutons ni textes d'accessibilité. */
  function exportText(td) {
    const clone = td.cloneNode(true);
    clone.querySelectorAll('.row-toggle, .visually-hidden, template, form, button').forEach((n) => n.remove());
    clone.querySelectorAll('br').forEach((br) => br.replaceWith('\n'));
    const text = clone.textContent.split('\n').map((s) => s.replace(/\s+/g, ' ').trim()).filter(Boolean).join(' · ');
    return text === '—' ? '' : text;
  }

  /** Modèle d'export de la vue affichée : colonnes visibles, lignes filtrées dans l'ordre du tri. */
  function exportModel(table, dt, describeFilters) {
    const head = table.tHead.rows[0];
    const sample = dt.rows({ search: 'applied' }).indexes()[0];
    const cols = [];
    dt.columns().every(function (index) {
      const th = this.header();
      const type = columnType(th, sample !== undefined ? dt.cell(sample, index).node() : null);
      if (type === 'none' || (!this.visible() && th.dataset.xAlways !== '1')) {
        return;
      }
      const label = th.dataset.xLabel || headerLabel(th);
      cols.push({ index, label, type, total: th.dataset.xTotal === '1' || (type === 'money' && th.dataset.xTotal !== '0') });
    });
    const rows = [];
    dt.rows({ search: 'applied', order: 'applied' }).indexes().each((r) => {
      rows.push(cols.map((c) => {
        const td = dt.cell(r, c.index).node();
        const raw = td.dataset.export !== undefined ? td.dataset.export : td.dataset.order;
        return window.EthExport.typedValue(c.type, raw, exportText(td));
      }));
    });
    const card = table.closest('.card');
    const caption = card && card.querySelector('.card__title h2');
    const h1 = document.querySelector('.page-title h1');
    // Plusieurs tableaux sur la page (fiche) : le titre de la carte précise lequel.
    const several = document.querySelectorAll('table.table--dt').length > 1;
    const title = table.dataset.exportTitle || ((h1 ? h1.textContent.trim() : document.title) + (caption && several ? ' — ' + caption.textContent.trim() : ''));
    const now = new Date();
    const p = (x) => String(x).padStart(2, '0');
    const meta = document.querySelector('.page-meta');
    return {
      company: config.company || '',
      title: title,
      sheet: table.dataset.exportTitle || (caption ? caption.textContent.trim() : (h1 ? h1.textContent.trim() : 'Export')),
      subtitle: table.dataset.exportTitle ? t('grid.export_subtitle') : (meta ? meta.textContent.replace(/\s+/g, ' ').trim() : ''),
      filters: describeFilters(),
      filtersLabel: t('grid.filters_label'),
      noFilter: t('grid.no_filter'),
      generated: t('grid.generated', { date: p(now.getDate()) + '/' + p(now.getMonth() + 1) + '/' + now.getFullYear() + ' ' + p(now.getHours()) + ':' + p(now.getMinutes()), user: config.user || '' }),
      confidential: t('grid.confidential'),
      pageLabel: t('grid.page'),
      countLabel: t('grid.count', { n: rows.length }),
      totalLabel: t('grid.total'),
      locale: config.locale,
      columns: cols.map((c) => ({ label: c.label, type: c.type, total: c.total })),
      rows: rows,
      totals: window.EthExport.totals(cols, rows),
      day: now.getFullYear() + '-' + p(now.getMonth() + 1) + '-' + p(now.getDate()),
    };
  }

  /**
   * Logo de la société pour les exports, converti en PNG (ExcelJS et pdfmake ne lisent ni WebP ni SVG).
   * Chargé une fois ; null sans logo ou en cas d'échec (l'export se fait alors sans).
   */
  let logoPromise = null;
  function companyLogo() {
    if (!config.logoUrl) {
      return Promise.resolve(null);
    }
    if (!logoPromise) {
      logoPromise = new Promise((resolve) => {
        const img = new Image();
        img.onload = () => {
          try {
            const canvas = document.createElement('canvas');
            canvas.width = img.naturalWidth;
            canvas.height = img.naturalHeight;
            canvas.getContext('2d').drawImage(img, 0, 0);
            resolve({ dataUrl: canvas.toDataURL('image/png'), width: img.naturalWidth, height: img.naturalHeight });
          } catch (e) {
            resolve(null);
          }
        };
        img.onerror = () => resolve(null);
        img.src = config.logoUrl;
      });
    }
    return logoPromise;
  }

  async function runExport(kind, table, dt, describeFilters, button) {
    button.disabled = true;
    button.classList.add('is-busy');
    try {
      const logo = await companyLogo();
      if (kind === 'xlsx') {
        await loadScript(config.vendor.exceljs);
        const model = Object.assign(exportModel(table, dt, describeFilters), { logo: logo });
        const wb = window.EthExport.toExcel(window.ExcelJS, model);
        const buffer = await wb.xlsx.writeBuffer();
        download(new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), window.EthExport.fileName(model.title, model.day) + '.xlsx');
      } else {
        await loadScript(config.vendor.pdfmake);
        await loadScript(config.vendor.pdfmakeFonts);
        const model = Object.assign(exportModel(table, dt, describeFilters), { logo: logo });
        window.pdfMake.createPdf(window.EthExport.toPdf(model)).download(window.EthExport.fileName(model.title, model.day) + '.pdf');
      }
    } catch (err) {
      if (window.Swal) {
        window.Swal.fire({ icon: 'error', text: t('grid.export_error') });
      }
    } finally {
      button.disabled = false;
      button.classList.remove('is-busy');
    }
  }

  function storageKey(table) {
    const all = Array.from(document.querySelectorAll('table.table--dt'));
    return 'eth.cols.' + window.location.pathname + '.' + all.indexOf(table);
  }

  /** Menu « Colonnes » : afficher / masquer, mémorisé par navigateur. */
  function columnsMenu(table, dt) {
    const menu = el('details', { class: 'dt-menu' });
    const summary = el('summary', { class: 'btn btn--ghost btn--sm' });
    summary.appendChild(el('i', { 'data-lucide': 'columns-3', 'aria-hidden': 'true' }));
    summary.appendChild(el('span', {}, t('grid.columns')));
    const panel = el('div', { class: 'dt-menu__panel' });
    let saved = {};
    try {
      saved = JSON.parse(window.localStorage.getItem(storageKey(table)) || '{}');
    } catch (e) {
      saved = {};
    }
    const save = () => {
      const state = {};
      // Écart à l'affichage par défaut seulement (colonnes data-hidden affichées, autres masquées).
      dt.columns().every(function (i) {
        const byDefault = this.header().dataset.hidden === undefined;
        if (this.visible() !== byDefault) {
          state[i] = this.visible();
        }
      });
      try {
        window.localStorage.setItem(storageKey(table), JSON.stringify(state));
      } catch (e) { /* stockage indisponible : préférence non retenue */ }
    };
    dt.columns().every(function (i) {
      const label = headerLabel(this.header());
      if (i === 0 || label === '') {
        return;
      }
      if (saved[i] === false) {
        this.visible(false, false);
      } else if (saved[i] === true) {
        this.visible(true, false);
      }
      const id = 'col-' + Math.random().toString(36).slice(2, 8);
      const line = el('label', { class: 'check', for: id });
      const box = el('input', { type: 'checkbox', id: id });
      box.checked = this.visible();
      const col = this;
      box.addEventListener('change', () => { col.visible(box.checked); save(); });
      line.append(box, el('span', {}, label));
      panel.appendChild(line);
    });
    dt.columns.adjust();
    document.addEventListener('click', (e) => { if (!menu.contains(e.target)) { menu.open = false; } });
    menu.append(summary, panel);
    return panel.children.length > 0 ? menu : null;
  }

  function buildToolbar(table, dt, details) {
    const head = table.tHead && table.tHead.rows[0];
    const declared = head ? Array.from(head.cells).map((th, index) => ({ th, index })).filter((c) => c.th.dataset.filter) : [];
    const state = { query: '', filters: [] };
    const toolbar = el('div', { class: 'dt-toolbar', role: 'search' });
    const fields = el('div', { class: 'dt-toolbar__fields' });

    const searchWrap = el('label', { class: 'dt-tool dt-tool--search' });
    const search = el('input', { type: 'search', placeholder: t('filters.search_placeholder'), 'aria-label': t('dt.search') });
    let timer = null;
    search.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => { state.query = fold(search.value.trim()); dt.draw(); refresh(); }, 200);
    });
    searchWrap.appendChild(search);
    const top = el('div', { class: 'dt-toolbar__top' });
    top.appendChild(searchWrap);

    const toggle = el('button', { type: 'button', class: 'btn btn--sm dt-toolbar__toggle', 'aria-expanded': 'false' });
    toggle.appendChild(el('i', { 'data-lucide': 'sliders-horizontal', 'aria-hidden': 'true' }));
    const toggleLabel = el('span', {}, t('filters.more'));
    toggle.appendChild(toggleLabel);
    toggle.addEventListener('click', () => {
      const open = toolbar.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', String(open));
    });
    if (declared.length > 0) {
      top.appendChild(toggle);
    }

    declared.forEach(({ th, index }) => {
      const label = (th.dataset.filterLabel || th.textContent).replace(/\s+/g, ' ').trim();
      const type = th.dataset.filter;
      const box = el('div', { class: 'dt-tool dt-tool--' + type });
      box.appendChild(el('span', { class: 'dt-tool__label' }, label));
      if (type === 'select') {
        const values = new Set();
        dt.rows().every(function () {
          const v = cellValue(dt.cell(this.index(), index).node());
          if (v !== '' && v !== '—') {
            values.add(v);
          }
        });
        const select = el('select', { 'aria-label': label });
        select.appendChild(el('option', { value: '' }, t('filters.all')));
        Array.from(values).sort((a, b) => a.localeCompare(b, config.locale)).forEach((v) => select.appendChild(el('option', { value: v }, v)));
        const f = { type, index, label, value: '', control: [select] };
        select.addEventListener('change', () => { f.value = select.value; dt.draw(); refresh(); });
        box.appendChild(select);
        state.filters.push(f);
      } else {
        const isDate = type === 'date';
        const from = el('input', isDate ? { type: 'date', 'aria-label': label + ' — ' + t('filters.from') } : { type: 'number', step: 'any', placeholder: t('filters.min'), 'aria-label': label + ' — ' + t('filters.min') });
        const to = el('input', isDate ? { type: 'date', 'aria-label': label + ' — ' + t('filters.to') } : { type: 'number', step: 'any', placeholder: t('filters.max'), 'aria-label': label + ' — ' + t('filters.max') });
        const scale = Number(th.dataset.filterScale || 1); // ex. 100 : saisie en USD, valeur stockée en centimes
        const f = isDate ? { type, index, label, from: '', to: '', control: [from, to] } : { type, index, label, min: null, max: null, control: [from, to] };
        const apply = () => {
          if (isDate) {
            f.from = from.value;
            f.to = to.value;
          } else {
            f.min = from.value === '' ? null : Number(from.value) * scale;
            f.max = to.value === '' ? null : Number(to.value) * scale;
          }
          dt.draw();
          refresh();
        };
        from.addEventListener('change', apply);
        to.addEventListener('change', apply);
        const pair = el('span', { class: 'dt-tool__pair' });
        pair.append(from, el('span', { class: 'dt-tool__sep', 'aria-hidden': 'true' }, '→'), to);
        box.appendChild(pair);
        state.filters.push(f);
      }
      fields.appendChild(box);
    });

    const meta = el('div', { class: 'dt-toolbar__meta' });
    const count = el('span', { class: 'dt-toolbar__count' });
    const reset = iconBtn('rotate-ccw', t('filters.reset'));
    reset.addEventListener('click', () => {
      search.value = '';
      state.query = '';
      state.filters.forEach((f) => {
        f.control.forEach((c) => { c.value = ''; });
        if (f.type === 'select') {
          f.value = '';
        } else if (f.type === 'date') {
          f.from = '';
          f.to = '';
        } else {
          f.min = null;
          f.max = null;
        }
      });
      dt.draw();
      refresh();
    });
    const actions = el('div', { class: 'dt-toolbar__actions' });
    if (details) {
      let open = false;
      const all = iconBtn('chevrons-up-down', t('grid.expand_all'));
      all.addEventListener('click', () => {
        open = !open;
        details.all(open);
        all.querySelector('span').textContent = t(open ? 'grid.collapse_all' : 'grid.expand_all');
      });
      actions.appendChild(all);
    }
    const columns = columnsMenu(table, dt);
    if (columns) {
      actions.appendChild(columns);
    }
    const describe = () => {
      const out = [];
      document.querySelectorAll('form.filters select, form.filters input:not([type=hidden])').forEach((c) => {
        const label = c.closest('label') && c.closest('label').querySelector('span');
        const value = c.tagName === 'SELECT' ? (c.value !== '' ? c.options[c.selectedIndex].text : '') : c.value;
        if (label && value) {
          out.push(label.textContent.trim() + ' : ' + value);
        }
      });
      if (search.value.trim()) {
        out.push(t('grid.search_label') + ' : « ' + search.value.trim() + ' »');
      }
      state.filters.forEach((f) => {
        if (f.type === 'select' && f.value) {
          out.push(f.label + ' : ' + f.value);
        } else if (f.type !== 'select' && f.control.some((c) => c.value !== '')) {
          out.push(f.label + ' : ' + (f.control[0].value || '…') + ' → ' + (f.control[1].value || '…'));
        }
      });
      return out;
    };
    const xlsx = iconBtn('file-spreadsheet', t('grid.excel'), 'dt-export');
    xlsx.title = t('grid.excel_hint');
    xlsx.addEventListener('click', () => runExport('xlsx', table, dt, describe, xlsx));
    const pdf = iconBtn('file-text', t('grid.pdf'), 'dt-export');
    pdf.title = t('grid.pdf_hint');
    pdf.addEventListener('click', () => runExport('pdf', table, dt, describe, pdf));
    actions.append(xlsx, pdf);
    meta.append(count, reset);
    top.append(meta, actions);
    toolbar.append(top);
    if (declared.length > 0) {
      toolbar.appendChild(fields);
    }

    function refresh() {
      const active = (search.value.trim() !== '' ? 1 : 0) + state.filters.filter((f) => (f.type === 'select' ? f.value !== '' : f.control.some((c) => c.value !== ''))).length;
      const shown = dt.rows({ search: 'applied' }).count();
      const total = dt.rows().count();
      count.textContent = active > 0
        ? t('filters.results_filtered', { n: shown, total: total }) + ' · ' + t('filters.active', { n: active })
        : t('filters.results', { n: total });
      count.classList.toggle('is-filtered', active > 0);
      reset.hidden = active === 0;
      const columnFilters = active - (search.value.trim() !== '' ? 1 : 0);
      toggleLabel.textContent = columnFilters > 0 ? t('filters.more_active', { n: columnFilters }) : t('filters.more');
    }

    tableFilters.set(table, state);
    const container = table.closest('.dt-container') || table;
    container.parentNode.insertBefore(toolbar, container);
    refresh();
  }

  let tableSearchRegistered = false;

  function initStaticTables(root) {
    if (!$ || !window.DataTable) {
      return;
    }
    if (!tableSearchRegistered) {
      registerTableSearch();
      tableSearchRegistered = true;
    }
    (root || document).querySelectorAll('table.table:not([data-table]):not([data-no-dt]):not(.lines-table):not(.dataTable)').forEach((table) => {
      if (!table.tBodies[0] || !table.tHead) {
        return;
      }
      const compact = table.dataset.dt === 'compact';
      const rowCount = table.tBodies[0].rows.length;
      table.classList.add('table--dt', compact ? 'table--dt-compact' : 'table--dt-list');
      const heads = Array.from(table.tHead.rows[0].cells);
      const low = heads.map((th, i) => (th.dataset.priority === 'low' ? i : -1)).filter((i) => i >= 0);
      const hidden = heads.map((th, i) => (th.dataset.hidden !== undefined ? i : -1)).filter((i) => i >= 0);
      const dt = new window.DataTable(table, {
        paging: !compact,
        searching: true, // nécessaire aux filtres ; le champ natif est masqué (barre d'outils dédiée)
        info: !compact,
        ordering: true,
        autoWidth: false,
        pageLength: Number(table.dataset.pageLength || 25),
        lengthMenu: [10, 25, 50, 100],
        order: [],
        layout: compact
          ? { topStart: null, topEnd: null, bottomStart: null, bottomEnd: null }
          : { topStart: null, topEnd: null, bottomStart: ['pageLength', 'info'], bottomEnd: 'paging' },
        language: dtLanguage(),
        columnDefs: [{ targets: 'no-sort', orderable: false }, { targets: low, className: 'col-low' }, { targets: hidden, visible: false }],
        drawCallback: initIcons,
      });
      const details = initDetails(table, dt);
      if (!compact || rowCount >= 8) {
        buildToolbar(table, dt, details);
      } else if (rowCount > 0) {
        buildMiniBar(table, dt, details);
      }
    });
    initIcons();
  }

  /** Petit tableau de fiche : seulement « déplier tout » et les exports, alignés à droite. */
  function buildMiniBar(table, dt, details) {
    tableFilters.set(table, { query: '', filters: [] });
    const bar = el('div', { class: 'dt-minibar' });
    if (details) {
      let open = false;
      const all = iconBtn('chevrons-up-down', t('grid.expand_all'));
      all.addEventListener('click', () => {
        open = !open;
        details.all(open);
        all.querySelector('span').textContent = t(open ? 'grid.collapse_all' : 'grid.expand_all');
      });
      bar.appendChild(all);
    }
    const describe = () => [];
    const xlsx = iconBtn('file-spreadsheet', t('grid.excel'), 'dt-export');
    xlsx.title = t('grid.excel_hint');
    xlsx.addEventListener('click', () => runExport('xlsx', table, dt, describe, xlsx));
    const pdf = iconBtn('file-text', t('grid.pdf'), 'dt-export');
    pdf.title = t('grid.pdf_hint');
    pdf.addEventListener('click', () => runExport('pdf', table, dt, describe, pdf));
    bar.append(xlsx, pdf);
    const container = table.closest('.dt-container') || table;
    container.parentNode.insertBefore(bar, container);
  }

  /**
   * Tableau de bord : graphiques (Chart.js, palette --series-*, un seul axe) et fenêtre de détail.
   * [data-panel] (indicateur ou notification) charge /dashboard/panels/{clé} dans la fenêtre : tableau compact
   * avec la barre d'outils habituelle (recherche, filtres, colonnes, exports Excel / PDF).
   */
  function initDashboard() {
    const holder = document.getElementById('dashboard-data');
    if (!holder) {
      return;
    }
    const data = JSON.parse(holder.textContent);
    if (window.Chart && window.EthPricing) {
      const css = getComputedStyle(document.documentElement);
      const token = (name) => css.getPropertyValue(name).trim();
      const sep = config.locale === 'en' ? '.' : ',';
      const usd = (minor) => window.EthPricing.formatAmount(minor, sep) + ' USD';
      const C = window.Chart;
      C.defaults.font.family = getComputedStyle(document.body).fontFamily;
      C.defaults.color = token('--text-muted');
      const tooltip = {
        backgroundColor: token('--surface'), titleColor: token('--text'), bodyColor: token('--text'), borderColor: token('--border-strong'), borderWidth: 1,
        padding: 10, usePointStyle: true, boxWidth: 8, boxHeight: 8,
      };
      const options = (horizontal, money, legend, panel) => ({
        responsive: true,
        onHover: (evt, elements) => { evt.native.target.style.cursor = elements.length ? 'pointer' : 'default'; },
        onClick: (evt, elements, chart) => {
          const hit = chart.getElementsAtEventForMode(evt.native, 'nearest', { intersect: true }, false)[0] || elements[0];
          if (hit && panel) {
            panel(hit.index, hit.datasetIndex, evt.native.target);
          }
        },
        maintainAspectRatio: false,
        indexAxis: horizontal ? 'y' : 'x',
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: legend
            ? { position: 'top', align: 'start', labels: { usePointStyle: true, boxWidth: 8, boxHeight: 8, padding: 14, color: token('--text') } }
            : { display: false },
          tooltip: Object.assign({}, tooltip, { callbacks: { label: (ctx) => ' ' + ctx.dataset.label + ' : ' + (money ? usd(ctx.parsed[horizontal ? 'x' : 'y']) : ctx.formattedValue) + (ctx.dataset.amounts ? ' · ' + usd(ctx.dataset.amounts[ctx.dataIndex]) : '') } }),
        },
        scales: {
          [horizontal ? 'y' : 'x']: { grid: { display: false }, border: { color: token('--border-strong') } },
          [horizontal ? 'x' : 'y']: {
            beginAtZero: true, grid: { color: token('--border') }, border: { display: false },
            ticks: { precision: 0, maxTicksLimit: 5, callback: money ? (v) => window.EthPricing.formatAmount(Math.round(v / 100) * 100, sep).replace(/[.,]00$/, '') : undefined },
          },
        },
      });
      const bar = (label, values, color, extra) => Object.assign({
        label, data: values, backgroundColor: color, borderRadius: 4, borderSkipped: 'start', barPercentage: 0.72, categoryPercentage: 0.72,
        borderColor: token('--surface'), borderWidth: 1,
      }, extra || {});
      const draw = (id, config) => {
        const canvas = document.getElementById(id);
        if (canvas) {
          new C(canvas, config);
        }
      };
      if (data.activity) {
        const colors = [token('--series-1'), token('--series-2'), token('--series-3')];
        draw('chart-activity', { type: 'bar', data: { labels: data.activity.labels, datasets: data.activity.series.map((s, i) => bar(s.name, s.values, colors[i])) }, options: options(false, false, true, (i, s, el) => openPanel('chart.activity', data.activity.keys[s] + ':' + data.activity.months[i], data.activity.series[s].name + ' — ' + data.activity.labels[i], el)) });
      }
      if (data.pipeline) {
        draw('chart-pipeline', { type: 'bar', data: { labels: data.pipeline.labels, datasets: [bar(data.pipeline.name, data.pipeline.values, token('--series-1'), { amounts: data.pipeline.amounts })] }, options: options(true, false, false, (i, s, el) => openPanel('chart.pipeline', data.pipeline.keys[i], data.pipeline.labels[i], el)) });
      }
      if (data.shipments) {
        draw('chart-shipments', { type: 'bar', data: { labels: data.shipments.labels, datasets: [bar(data.shipments.name, data.shipments.values, token('--series-1'))] }, options: options(true, false, false, (i, s, el) => openPanel('chart.shipments', data.shipments.keys[i], data.shipments.labels[i], el)) });
      }
      if (data.aging) {
        draw('chart-aging', { type: 'bar', data: { labels: data.aging.labels, datasets: [bar(data.aging.name, data.aging.values, token('--series-1'))] }, options: options(false, true, false, (i, s, el) => openPanel('chart.aging', data.aging.keys[i], data.aging.labels[i], el)) });
      }
    }

    const modal = document.getElementById('panel-modal');
    if (!modal) {
      return;
    }
    const body = modal.querySelector('.panel-modal__body');
    const title = modal.querySelector('.panel-modal__title');
    const count = modal.querySelector('.panel-modal__count');
    const page = modal.querySelector('.panel-modal__page');
    let opener = null;
    let request = 0;
    const close = () => {
      modal.close();
    };
    modal.addEventListener('close', () => {
      body.innerHTML = '';
      if (opener) {
        opener.focus();
      }
    });
    modal.addEventListener('click', (e) => {
      if (e.target === modal) { // clic sur le fond (hors du contenu)
        close();
      }
    });
    modal.querySelector('[data-panel-close]').addEventListener('click', close);

    document.querySelectorAll('[data-panel]').forEach((trigger) => {
      trigger.addEventListener('click', () => openPanel(trigger.dataset.panel, '', (trigger.querySelector('.dkpi__label, .dnotif__text') || trigger).textContent.trim(), trigger));
    });

    async function openPanel(key, param, label, from) {
      {
        opener = from;
        const id = ++request;
        title.textContent = label;
        count.hidden = true;
        page.hidden = true;
        body.innerHTML = '<div class="panel-modal__loading">' + core.escapeHtml(t('auth.signing_in')) + '</div>';
        modal.showModal();
        try {
          const res = await fetch(config.basePath + '/dashboard/panels/' + encodeURIComponent(key) + (param ? '?p=' + encodeURIComponent(param) : ''), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
          if (res.status === 401) {
            window.location.reload();
            return;
          }
          if (!res.ok) {
            throw new Error(String(res.status));
          }
          const html = await res.text();
          if (id !== request || !modal.open) {
            return;
          }
          body.innerHTML = html;
          const panel = body.querySelector('.dash-panel');
          if (panel) {
            title.textContent = panel.dataset.panelTitle;
            count.textContent = t('grid.count', { n: panel.dataset.panelCount });
            count.hidden = false;
            page.href = panel.dataset.panelPage;
            page.hidden = false;
          }
          initStaticTables(body);
          const search = body.querySelector('.dt-tool--search input');
          if (search) {
            search.focus();
          }
        } catch (err) {
          body.innerHTML = '<div class="alert alert--error">' + core.escapeHtml(t('grid.panel_error')) + '</div>';
        }
      }
    }
  }

  /**
   * Colonne « Article » des lignes de RFQ : recherche intelligente dans le référentiel.
   * Sans accents ni casse, plusieurs mots, sur code, nom, fabricant et réf. fabricant ; classement par pertinence,
   * termes surlignés, navigation au clavier (↑ ↓ Entrée Échap). Un nom absent du référentiel est signalé
   * « Nouvel article » : il y sera ajouté à l'enregistrement de la RFQ.
   */
  let comboReload = null;

  function initItemCombo() {
    const holder = document.getElementById('rfq-items');
    if (!holder) {
      return;
    }
    if (comboReload) {
      comboReload(JSON.parse(holder.textContent));
      return;
    }
    let data = null;
    let items = [];
    let byId = new Map();
    const list = el('ul', { class: 'combo__list', role: 'listbox', id: 'combo-list', hidden: '' });
    document.body.appendChild(list);
    let input = null;
    let results = [];
    let active = -1;

    const tokens = (q) => fold(q).split(/[^a-z0-9]+/).filter(Boolean);
    const score = (it, words, raw) => {
      let s = 0;
      for (const w of words) {
        if (!it._all.includes(w)) {
          return -1;
        }
        s += it._code.startsWith(w) ? 30 : (new RegExp('(^|[^a-z0-9])' + w).test(it._all) ? 12 : 4);
      }
      if (it._code === raw) {
        s += 200;
      } else if (it._name === raw) {
        s += 150;
      } else if (it._name.startsWith(raw)) {
        s += 40;
      }
      return s;
    };
    const search = (q) => {
      const words = tokens(q);
      if (words.length === 0) {
        return items.slice(0, 8);
      }
      const raw = fold(q.trim());
      return items.map((it) => [it, score(it, words, raw)]).filter((x) => x[1] >= 0)
        .sort((a, b) => b[1] - a[1] || a[0].name.localeCompare(b[0].name, config.locale)).slice(0, 8).map((x) => x[0]);
    };
    // Surligne les mots trouvés dans un texte (échappé).
    const mark = (text, words) => {
      let html = core.escapeHtml(text);
      const plain = fold(text);
      const ranges = [];
      words.forEach((w) => {
        let i = plain.indexOf(w);
        while (i !== -1) {
          ranges.push([i, i + w.length]);
          i = plain.indexOf(w, i + w.length);
        }
      });
      if (ranges.length === 0 || plain.length !== text.length) {
        return html;
      }
      ranges.sort((a, b) => a[0] - b[0]);
      let out = '';
      let pos = 0;
      ranges.forEach(([a, b]) => {
        if (a < pos) {
          return;
        }
        out += core.escapeHtml(text.slice(pos, a)) + '<mark>' + core.escapeHtml(text.slice(a, b)) + '</mark>';
        pos = b;
      });
      html = out + core.escapeHtml(text.slice(pos));
      return html;
    };

    const rowOf = (inp) => inp.closest('[data-line]');
    const refreshState = (inp) => {
      const row = rowOf(inp);
      const id = row.querySelector('[data-item-id]').value;
      const state = row.querySelector('[data-item-state]');
      const it = byId.get(id);
      if (it) {
        state.className = 'combo__state combo__state--known';
        state.textContent = it.code;
        state.title = t('tables.item_known');
      } else if (inp.value.trim() !== '') {
        state.className = 'combo__state combo__state--new';
        state.textContent = t(data.canCreate ? 'tables.item_new' : 'tables.item_free');
        state.title = t(data.canCreate ? 'tables.item_new_hint' : 'tables.item_free_hint');
      } else {
        state.className = 'combo__state';
        state.textContent = '';
      }
    };
    const close = () => {
      list.hidden = true;
      if (input) {
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
      }
      active = -1;
    };
    const place = () => {
      const r = input.getBoundingClientRect();
      list.style.left = (r.left + window.scrollX) + 'px';
      list.style.top = (r.bottom + window.scrollY + 2) + 'px';
      list.style.minWidth = Math.max(r.width, 360) + 'px';
    };
    const highlight = (i) => {
      active = i;
      Array.from(list.children).forEach((li, j) => li.setAttribute('aria-selected', String(j === i)));
      const li = list.children[i];
      if (li) {
        input.setAttribute('aria-activedescendant', li.id);
        li.scrollIntoView({ block: 'nearest' });
      }
    };
    const open = () => {
      const q = input.value;
      const words = tokens(q);
      results = search(q);
      list.innerHTML = '';
      results.forEach((it, i) => {
        const li = el('li', { role: 'option', id: 'combo-opt-' + i, class: 'combo__option', 'aria-selected': 'false' });
        li.innerHTML = '<span class="combo__code">' + mark(it.code, words) + '</span><span class="combo__name">' + mark(it.name, words) + '</span>'
          + '<span class="combo__meta">' + mark([it.maker, it.part].filter(Boolean).join(' · '), words) + (it.maker || it.part ? ' · ' : '') + core.escapeHtml(it.unitLabel) + '</span>';
        li.addEventListener('mousedown', (e) => { e.preventDefault(); choose(it); });
        list.appendChild(li);
      });
      const exact = results.some((it) => it._name === fold(q.trim()) || it._code === fold(q.trim()));
      if (q.trim() !== '' && !exact) {
        const li = el('li', { role: 'option', id: 'combo-opt-new', class: 'combo__option combo__option--new', 'aria-selected': 'false' });
        li.textContent = t(data.canCreate ? 'tables.item_create' : 'tables.item_use_free', { name: q.trim() });
        li.addEventListener('mousedown', (e) => { e.preventDefault(); choose(null); });
        list.appendChild(li);
      }
      if (results.length === 0 && q.trim() === '') {
        list.appendChild(el('li', { class: 'combo__empty' }, t('tables.item_none')));
      }
      place();
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      input.setAttribute('aria-controls', 'combo-list');
      highlight(results.length > 0 && q.trim() !== '' ? 0 : -1);
    };
    const choose = (it) => {
      const row = rowOf(input);
      const hidden = row.querySelector('[data-item-id]');
      const description = row.querySelector('[data-line-description]');
      const unit = row.querySelector('[data-line-unit]');
      if (it) {
        hidden.value = String(it.id);
        input.value = it.name;
        if (description && description.value.trim() === '') {
          description.value = it.name;
        }
        if (unit && it.unit) {
          unit.value = it.unit;
        }
      } else {
        hidden.value = '';
        if (description && description.value.trim() === '') {
          description.value = input.value.trim();
        }
      }
      refreshState(input);
      close();
      const qty = row.querySelector('input[name$="[quantity]"]');
      if (qty && qty.value === '') {
        qty.focus();
      }
    };

    document.addEventListener('focusin', (e) => {
      const inp = e.target.closest('[data-item-input]');
      if (inp) {
        input = inp;
        open();
      }
    });
    document.addEventListener('input', (e) => {
      const inp = e.target.closest('[data-item-input]');
      if (!inp) {
        return;
      }
      input = inp;
      rowOf(inp).querySelector('[data-item-id]').value = ''; // le texte a changé : plus lié à l'article choisi
      refreshState(inp);
      open();
    });
    document.addEventListener('keydown', (e) => {
      const inp = e.target.closest('[data-item-input]');
      if (!inp || list.hidden) {
        if (inp && e.key === 'ArrowDown') {
          input = inp;
          open();
        }
        return;
      }
      const count = list.querySelectorAll('[role=option]').length;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlight(Math.min(count - 1, active + 1));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlight(Math.max(0, active - 1));
      } else if (e.key === 'Enter') {
        if (active >= 0) {
          e.preventDefault();
          choose(active < results.length ? results[active] : null);
        }
      } else if (e.key === 'Escape') {
        close();
      }
    });
    document.addEventListener('focusout', (e) => {
      if (e.target.closest('[data-item-input]')) {
        setTimeout(close, 120);
      }
    });
    window.addEventListener('resize', () => { if (!list.hidden) { place(); } });
    comboReload = (next) => {
      data = next;
      items = data.items.map((it) => Object.assign(it, {
        _code: fold(it.code),
        _name: fold(it.name),
        _all: fold([it.code, it.name, it.maker, it.part].join(' ')),
      }));
      byId = new Map(items.map((it) => [String(it.id), it]));
      document.querySelectorAll('[data-item-input]').forEach(refreshState);
    };
    comboReload(JSON.parse(holder.textContent));
    // Ligne ajoutée : état vierge.
    document.addEventListener('click', (e) => {
      if (e.target.closest('[data-add-line]')) {
        setTimeout(() => document.querySelectorAll('[data-item-input]').forEach(refreshState), 0);
      }
    });
  }

  /**
   * Formulaire de RFQ :
   *  - documents du client : dépôt (glisser ou parcourir, sélections cumulées), pastilles, aperçu à côté
   *    des lignes (PDF et images ; autres formats signalés), retrait d'un fichier avant envoi ;
   *  - client choisi : acheteur et e-mail préremplis depuis la fiche, échéance calculée affichée ;
   *  - lignes : compteur, Entrée sur la quantité de la dernière ligne ajoute une ligne.
   */
  function initRfqForm() {
    const form = document.querySelector('form[data-rfq-form]');
    const holder = document.getElementById('rfq-form-data');
    if (!form || !holder) {
      return;
    }
    const data = JSON.parse(holder.textContent);
    const L = data.i18n;
    const fill = (text, params) => Object.entries(params).reduce((s, [k, v]) => s.replace('{' + k + '}', v), text);
    const input = form.querySelector('#f-attachments');
    const zone = form.querySelector('[data-dropzone]');
    const chips = form.querySelector('[data-doc-chips]');
    const viewer = form.querySelector('[data-doc-viewer]');
    const empty = viewer.innerHTML;
    const summary = form.querySelector('[data-rfq-summary]');
    let picked = []; // fichiers choisis, pas encore envoyés
    const urls = new Map();

    const kindOf = (file) => (file.type.startsWith('image/') ? 'image' : (file.type === 'application/pdf' || /\.pdf$/i.test(file.name) ? 'pdf' : 'other'));
    const size = (bytes) => (bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' Mo' : Math.max(1, Math.round(bytes / 1024)) + ' Ko').replace('.', config.locale === 'en' ? '.' : ',');
    const iconFor = (kind) => (kind === 'image' ? 'image' : (kind === 'pdf' ? 'file-text' : 'file'));

    // Le champ fichier reflète toujours la liste « picked » (sélections successives cumulées).
    const sync = () => {
      const dt = new DataTransfer();
      picked.forEach((f) => dt.items.add(f));
      input.files = dt.files;
    };
    const show = (chip) => {
      chips.querySelectorAll('.doc-chip').forEach((c) => c.classList.toggle('is-active', c === chip));
      if (!chip) {
        viewer.innerHTML = empty;
        initIcons();
        return;
      }
      const src = chip.dataset.src;
      const kind = chip.dataset.kind;
      viewer.innerHTML = '';
      if (kind === 'pdf') {
        viewer.appendChild(el('iframe', { src: src + (src.startsWith('blob:') ? '#view=FitH' : ''), title: chip.dataset.name, class: 'doc-viewer__frame' }));
      } else if (kind === 'image') {
        viewer.appendChild(el('img', { src: src, alt: chip.dataset.name, class: 'doc-viewer__img' }));
      } else {
        const box = el('div', { class: 'doc-viewer__empty' });
        box.appendChild(el('i', { 'data-lucide': 'file', 'aria-hidden': 'true' }));
        box.appendChild(el('p', {}, chip.dataset.name));
        box.appendChild(el('p', { class: 'muted' }, L.noPreview));
        viewer.appendChild(box);
        initIcons();
      }
    };
    const render = () => {
      chips.querySelectorAll('.doc-chip:not([data-saved])').forEach((c) => c.remove());
      picked.forEach((file, i) => {
        const key = file.name + '|' + file.size + '|' + file.lastModified;
        if (!urls.has(key)) {
          urls.set(key, URL.createObjectURL(file));
        }
        const kind = kindOf(file);
        const li = el('li', { class: 'doc-chip', 'data-src': urls.get(key), 'data-kind': kind, 'data-name': file.name });
        const open = el('button', { type: 'button', class: 'doc-chip__open' });
        open.appendChild(el('i', { 'data-lucide': iconFor(kind), 'aria-hidden': 'true' }));
        open.appendChild(el('span', { class: 'doc-chip__name' }, file.name));
        open.appendChild(el('span', { class: 'doc-chip__size' }, size(file.size)));
        const badge = el('span', { class: 'chip chip--warn' }, L.newFile);
        const remove = el('button', { type: 'button', class: 'icon-btn doc-chip__remove', 'aria-label': L.remove + ' ' + file.name, title: L.remove });
        remove.appendChild(el('i', { 'data-lucide': 'x', 'aria-hidden': 'true' }));
        remove.addEventListener('click', () => {
          const wasActive = li.classList.contains('is-active');
          picked.splice(i, 1);
          sync();
          render();
          if (wasActive) {
            show(chips.querySelector('.doc-chip'));
          }
        });
        li.append(open, badge, remove);
        chips.appendChild(li);
      });
      zone.classList.toggle('has-files', picked.length > 0);
      initIcons();
      refreshSummary();
    };
    chips.addEventListener('click', (e) => {
      const open = e.target.closest('.doc-chip__open');
      if (open) {
        show(open.closest('.doc-chip'));
      }
    });
    input.addEventListener('change', () => {
      const added = Array.from(input.files).filter((f) => !picked.some((p) => p.name === f.name && p.size === f.size && p.lastModified === f.lastModified));
      picked = picked.concat(added);
      sync();
      render();
      zone.classList.remove('is-invalid');
      if (added.length > 0) {
        // Montre le premier document affichable parmi les nouveaux.
        const chip = Array.from(chips.querySelectorAll('.doc-chip:not([data-saved])')).find((c) => c.dataset.name === added[0].name);
        show(chip || null);
      }
    });
    ['dragenter', 'dragover'].forEach((t) => zone.addEventListener(t, () => zone.classList.add('is-over')));
    ['dragleave', 'drop'].forEach((t) => zone.addEventListener(t, () => zone.classList.remove('is-over')));
    const firstSaved = chips.querySelector('.doc-chip[data-saved]');
    if (firstSaved) {
      show(firstSaved);
    }

    // Client : préremplissage du contact (sans écraser une saisie) et échéance calculée.
    const client = form.querySelector('#f-client_id');
    const received = form.querySelector('#f-received_on');
    const due = form.querySelector('#f-due_at');
    const buyer = form.querySelector('#f-buyer_name');
    const email = form.querySelector('#f-buyer_email');
    const duePreview = form.querySelector('[data-due-preview]');
    const prefillHint = form.querySelector('[data-prefill-hint]');
    let lastPrefill = { buyer: buyer.value, email: email.value };
    const addDays = (iso, days, mode) => {
      const d = new Date(iso + 'T00:00:00');
      if (mode !== 'business') {
        d.setDate(d.getDate() + days);
        return d;
      }
      let n = days;
      while (n > 0) {
        d.setDate(d.getDate() + 1);
        if (d.getDay() !== 0 && d.getDay() !== 6) {
          n--;
        }
      }
      return d;
    };
    const refreshDue = () => {
      const info = data.clients[client.value];
      if (due.value !== '' || !received.value) {
        duePreview.hidden = true;
        return;
      }
      const days = info ? info.days : data.defaultDays;
      const d = addDays(received.value, days, data.mode);
      const p = (x) => String(x).padStart(2, '0');
      duePreview.querySelector('span').textContent = fill(L.due, {
        date: p(d.getDate()) + '/' + p(d.getMonth() + 1) + '/' + d.getFullYear() + ' 17:00', n: days, unit: L['days_' + data.mode],
      });
      duePreview.hidden = false;
    };
    client.addEventListener('change', () => {
      const info = data.clients[client.value];
      let changed = false;
      if (info) {
        if (buyer.value === '' || buyer.value === lastPrefill.buyer) {
          changed = changed || buyer.value !== info.buyer;
          buyer.value = info.buyer;
        }
        if (email.value === '' || email.value === lastPrefill.email) {
          changed = changed || email.value !== info.email;
          email.value = info.email;
        }
        lastPrefill = { buyer: info.buyer, email: info.email };
      }
      prefillHint.hidden = !changed;
      refreshDue();
    });
    [received, due].forEach((f) => f.addEventListener('input', refreshDue));
    refreshDue();

    // Lignes : compteur et ajout au clavier.
    const body = form.querySelector('[data-lines-body]');
    const count = form.querySelector('[data-lines-count]');
    function refreshSummary() {
      const filled = Array.from(body.rows).filter((r) => r.querySelector('[data-line-description]').value.trim() !== '' || r.querySelector('[data-item-input]').value.trim() !== '').length;
      count.textContent = fill(L.lines, { n: filled });
      const files = picked.length + chips.querySelectorAll('.doc-chip[data-saved]').length;
      summary.textContent = fill(L.lines, { n: filled }) + ' · ' + fill(L.files, { n: files });
    }
    body.addEventListener('input', refreshSummary);
    body.addEventListener('keydown', (e) => {
      const qty = e.target.closest('[data-line-qty]');
      if (!qty || e.key !== 'Enter') {
        return;
      }
      e.preventDefault();
      const row = qty.closest('[data-line]');
      if (row === body.rows[body.rows.length - 1]) {
        form.querySelector('[data-add-line]').click();
      } else {
        const next = row.nextElementSibling.querySelector('[data-item-input]');
        if (next) {
          next.focus();
        }
      }
    });
    form.querySelector('[data-add-line]').addEventListener('click', () => setTimeout(() => {
      const last = body.rows[body.rows.length - 1];
      const field = last && last.querySelector('[data-item-input]');
      if (field) {
        field.focus();
      }
      refreshSummary();
    }, 0));
    body.addEventListener('click', (e) => { if (e.target.closest('[data-remove-line]')) { setTimeout(refreshSummary, 0); } });
    refreshSummary();
  }

  /**
   * Fenêtres <dialog> : [data-open-dialog="id"] ouvre, [data-close-dialog] ou un clic sur le fond ferme.
   * Fenêtre de demande de prix : recherche de fournisseurs, sélection des lignes (quantité désactivée si décochée),
   * résumé en direct, contrôle avant envoi (au moins un fournisseur et une ligne).
   */
  let dialogsBound = false;

  function initDialogs() {
    if (!dialogsBound) {
      dialogsBound = true;
      bindDialogClicks();
    }
    initSourcingForm();
  }

  function bindDialogClicks() {
    document.addEventListener('click', (e) => {
      const opener = e.target.closest('[data-open-dialog]');
      if (opener) {
        const dialog = document.getElementById(opener.dataset.openDialog);
        if (dialog && !dialog.open) {
          // Jalon d'expédition : l'étape cliquée est présélectionnée.
          if (opener.dataset.milestone) {
            const radio = dialog.querySelector('input[name="status"][value="' + opener.dataset.milestone + '"]');
            if (radio) {
              radio.checked = true;
            }
          }
          dialog.showModal();
          const first = dialog.querySelector('[data-supplier-search], input:not([type=hidden]):not([type=radio]), select, textarea');
          if (first) {
            first.focus();
          }
        }
        return;
      }
      const closer = e.target.closest('[data-close-dialog]');
      if (closer) {
        closer.closest('dialog').close();
        return;
      }
      if (e.target.tagName === 'DIALOG' && e.target.matches('.sourcing-modal, .remote-modal')) {
        e.target.close();
      }
    });
  }

  function initSourcingForm() {
    const form = document.querySelector('[data-sourcing-form]');
    const holder = document.getElementById('sourcing-data');
    if (!form || !holder) {
      return;
    }
    const L = JSON.parse(holder.textContent);
    const search = form.querySelector('[data-supplier-search]');
    const picks = Array.from(form.querySelectorAll('.supplier-pick'));
    const lineChecks = Array.from(form.querySelectorAll('[data-line-check]'));
    const all = form.querySelector('[data-check-all]');
    const summary = form.querySelector('[data-sourcing-summary]');
    const refresh = () => {
      const suppliers = picks.filter((p) => p.querySelector('input').checked).length;
      const lines = lineChecks.filter((c) => c.checked).length;
      summary.textContent = L.summary.replace('{suppliers}', suppliers).replace('{lines}', lines);
      all.checked = lines === lineChecks.length;
      all.indeterminate = lines > 0 && lines < lineChecks.length;
      lineChecks.forEach((c) => {
        const qty = c.closest('tr').querySelector('input[name$="[quantity]"]');
        qty.disabled = !c.checked;
        c.closest('tr').classList.toggle('is-off', !c.checked);
      });
      picks.forEach((p) => p.classList.toggle('is-checked', p.querySelector('input').checked));
    };
    search.addEventListener('input', () => {
      const q = fold(search.value.trim());
      picks.forEach((p) => { p.hidden = q !== '' && !fold(p.dataset.name).includes(q); });
    });
    all.addEventListener('change', () => {
      lineChecks.forEach((c) => { c.checked = all.checked; });
      refresh();
    });
    form.addEventListener('change', refresh);
    form.addEventListener('submit', (e) => {
      const problem = !picks.some((p) => p.querySelector('input').checked) ? L.noSupplier : (!lineChecks.some((c) => c.checked) ? L.noLine : null);
      if (problem) {
        e.preventDefault();
        e.stopImmediatePropagation();
        if (window.Swal) {
          window.Swal.fire({ icon: 'warning', text: problem, target: form.closest('dialog') });
        }
      }
    });
    refresh();
  }

  /**
   * Soumissions sans rechargement : tout formulaire POST passe par une confirmation (SweetAlert) résumant
   * la saisie, puis part en AJAX. Le serveur répond comme d'habitude (redirection après POST) ; la page
   * d'arrivée remplace le contenu principal, le menu (pastilles) et la cloche, l'adresse suit (history),
   * le message flash s'affiche en notification. Exclus : déconnexion, changement de langue, data-no-ajax.
   * En cas d'échec réseau, la soumission classique prend le relais.
   */
  function labelFor(field) {
    const form = field.form;
    const byFor = field.id && form ? form.querySelector('label[for="' + CSS.escape(field.id) + '"]') : null;
    const label = byFor || field.closest('label');
    const named = label && label.querySelector('[data-label]');
    const text = named ? named.textContent : label ? label.textContent : (field.getAttribute('aria-label') || field.placeholder || field.name);
    return String(text).replace(/\*/g, '').replace(/\s+/g, ' ').trim();
  }

  /** Résumé lisible de la saisie : [libellé, valeur], cases groupées par nom, tableaux de lignes comptés. */
  function summarize(form) {
    const rows = [];
    const groups = new Map();
    const tables = new Set();
    form.querySelectorAll('input, select, textarea').forEach((field) => {
      if (!field.name || field.disabled || field.type === 'hidden' || field.type === 'submit' || field.type === 'button' || field.closest('template')) {
        return;
      }
      const table = field.closest('[data-lines], .sourcing-lines, .lines-table');
      if (table) {
        tables.add(table);
        return;
      }
      if (field.type === 'checkbox' || field.type === 'radio') {
        if (!field.checked) {
          return;
        }
        const fieldset = field.closest('fieldset');
        const legend = fieldset && fieldset.querySelector('legend');
        const key = field.name.replace(/\[\]$/, '');
        const groupLabel = field.name.endsWith('[]') && legend ? legend.textContent.replace(/^\d+/, '').trim() : null;
        if (groupLabel) {
          if (!groups.has(key)) {
            groups.set(key, [groupLabel, []]);
            rows.push(groups.get(key));
          }
          groups.get(key)[1].push(labelFor(field));
        } else {
          rows.push([labelFor(field), t('common.yes')]);
        }
        return;
      }
      let value = '';
      if (field.type === 'file') {
        value = Array.from(field.files).map((f) => f.name).join(', ');
      } else if (field.tagName === 'SELECT') {
        value = field.value !== '' && field.selectedOptions[0] ? field.selectedOptions[0].text : '';
      } else if (field.type === 'date' || field.type === 'datetime-local') {
        const m = /^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}:\d{2}))?/.exec(field.value);
        value = m ? m[3] + '/' + m[2] + '/' + m[1] + (m[4] ? ' ' + m[4] : '') : field.value;
      } else if (field.type === 'password') {
        value = field.value ? '••••••' : '';
      } else {
        value = field.value.trim();
      }
      if (value === '') {
        return;
      }
      rows.push([labelFor(field), value.length > 90 ? value.slice(0, 87) + '…' : value]);
    });
    tables.forEach((table) => {
      const filled = Array.from(table.querySelectorAll('tbody tr')).filter((tr) => {
        const check = tr.querySelector('input[type=checkbox]');
        if (check) {
          return check.checked;
        }
        return Array.from(tr.querySelectorAll('input:not([type=hidden]), textarea')).some((i) => i.value.trim() !== '');
      }).length;
      const section = table.closest('fieldset, section');
      const title = section && section.querySelector('legend, h2');
      rows.push([title ? title.textContent.replace(/^\d+/, '').replace(/\s+/g, ' ').trim() : t('ajax.lines'), t('ajax.lines_n', { n: filled })]);
    });
    return rows.map(([label, value]) => [label, Array.isArray(value) ? value.join(', ') : value]);
  }

  function confirmSubmit(form, submitter) {
    if (!window.Swal) {
      return Promise.resolve(form.dataset.confirm ? window.confirm(form.dataset.confirm) : true);
    }
    const rows = summarize(form);
    const action = submitter ? submitter.textContent.replace(/\s+/g, ' ').trim() : '';
    const title = form.dataset.confirm || (action ? t('ajax.confirm_action', { action: action }) : t('ajax.confirm_title'));
    const list = rows.length === 0 ? '' : '<dl class="swal-summary">' + rows.slice(0, 14).map(([l, v]) => '<div><dt>' + core.escapeHtml(l) + '</dt><dd>' + core.escapeHtml(v) + '</dd></div>').join('')
      + (rows.length > 14 ? '<div><dt></dt><dd>' + core.escapeHtml(t('ajax.more', { n: rows.length - 14 })) + '</dd></div>' : '') + '</dl>';
    return window.Swal.fire({
      title: title,
      html: list || undefined,
      icon: form.dataset.confirm ? 'warning' : undefined,
      showCancelButton: true,
      confirmButtonText: action || t('common.confirm'),
      cancelButtonText: t('common.cancel'),
      reverseButtons: true,
      focusConfirm: true,
      customClass: { popup: 'swal-confirm', confirmButton: 'btn btn--primary', cancelButton: 'btn' },
      target: form.closest('dialog') || 'body',
    }).then((r) => r.isConfirmed);
  }

  const scriptsLoaded = new Set();

  /** Remplace la page par la réponse : contenu, titre, menu, cloche ; puis réinitialise les composants. */
  async function swapPage(html, url) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const next = doc.getElementById('main');
    const main = document.getElementById('main');
    if (!next || !main) {
      window.location.href = url;
      return;
    }
    document.querySelectorAll('dialog[open]').forEach((d) => d.close());
    document.querySelectorAll('.combo__list').forEach((l) => { l.hidden = true; });
    document.title = doc.title;
    main.innerHTML = next.innerHTML;
    [['.sidebar .nav', true], ['.notif__panel', false], ['[data-notif-toggle]', false], ['.topbar__title', false]].forEach(([selector, isNav]) => {
      const from = doc.querySelector(selector);
      const to = document.querySelector(selector);
      if (from && to) {
        to.innerHTML = from.innerHTML;
        if (isNav) {
          bindNavSections();
        }
      }
    });
    if (url && url !== window.location.href) {
      window.history.pushState({ ajax: true }, '', url);
    }
    // Scripts propres à la page (ex. Chart.js) : chargés s'ils ne le sont pas encore.
    for (const s of Array.from(next.querySelectorAll('script[src]'))) {
      const src = s.getAttribute('src');
      if (!scriptsLoaded.has(src) && !document.querySelector('script[src="' + src + '"]')) {
        scriptsLoaded.add(src);
        await loadScript(src);
      }
    }
    initPage();
    // Message flash : notification, l'encadré reste pour les erreurs (lecture prolongée).
    const flash = main.querySelector(':scope > .alert');
    if (flash && window.Swal) {
      const type = flash.classList.contains('alert--error') ? 'error' : (flash.classList.contains('alert--info') ? 'info' : 'success');
      if (type !== 'error') {
        flash.remove();
      }
      window.Swal.fire({ toast: true, position: 'top-end', icon: type, title: flash.textContent.trim(), showConfirmButton: false, timer: type === 'error' ? 6000 : 3500, timerProgressBar: true });
    }
    const target = main.querySelector('.field-error, .alert--error, [aria-invalid="true"]');
    (target || main).scrollIntoView({ block: 'start', behavior: 'smooth' });
    main.focus({ preventScroll: true });
  }

  function initAjaxForms() {
    document.addEventListener('submit', async (e) => {
      const form = e.target;
      // Attributs lus directement : un champ nommé « action », « method » ou « target » masque la propriété du formulaire.
      if (!(form instanceof HTMLFormElement) || (form.getAttribute('method') || '').toLowerCase() !== 'post' || e.defaultPrevented) {
        return;
      }
      const action = form.getAttribute('action') || '';
      const session = /\/(logout|locale|login)$/.test(action.split('?')[0]);
      const excluded = session || form.hasAttribute('data-no-ajax') || form.getAttribute('target') === '_blank';
      if (form.dataset.confirmed === '1' || (session && !form.dataset.confirm)) {
        delete form.dataset.confirmed;
        return; // soumission classique (session, ou repli après échec)
      }
      e.preventDefault();
      const submitter = e.submitter || form.querySelector('button[type=submit]');
      if (!(await confirmSubmit(form, submitter))) {
        return;
      }
      if (excluded) {
        form.dataset.confirmed = '1';
        form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
        return;
      }
      const buttons = form.querySelectorAll('button[type=submit]');
      buttons.forEach((b) => { b.disabled = true; b.classList.add('is-busy'); });
      const busy = window.Swal ? window.Swal.fire({ title: t('ajax.sending'), allowOutsideClick: false, showConfirmButton: false, target: form.closest('dialog') || 'body', didOpen: () => window.Swal.showLoading() }) : null;
      try {
        const body = submitter && submitter.name ? new FormData(form, submitter) : new FormData(form);
        const res = await fetch(new URL(action || window.location.href, window.location.href).href, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const html = await res.text();
        if (window.Swal && busy) {
          window.Swal.close();
        }
        if (res.status >= 500 || !(res.headers.get('content-type') || '').includes('text/html')) {
          throw new Error(String(res.status));
        }
        await swapPage(html, res.url);
      } catch (err) {
        buttons.forEach((b) => { b.disabled = false; b.classList.remove('is-busy'); });
        if (window.Swal) {
          const retry = await window.Swal.fire({ icon: 'error', title: t('ajax.error_title'), text: t('ajax.error_text'), showCancelButton: true, confirmButtonText: t('ajax.retry_classic'), cancelButtonText: t('common.cancel') });
          if (!retry.isConfirmed) {
            return;
          }
        }
        form.dataset.confirmed = '1';
        form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
      }
    });
    // Retour arrière après une soumission AJAX : la page est rechargée depuis le serveur.
    window.addEventListener('popstate', () => window.location.reload());
  }

  /**
   * Documents dans une fenêtre ou un formulaire ([data-doc-picker]) : pastilles des fichiers joints ou choisis,
   * aperçu (PDF, image) à côté ; le champ fichier, s'il existe, cumule les sélections et permet de retirer
   * un fichier avant l'envoi.
   */
  function initDocPickers(root) {
    root.querySelectorAll('[data-doc-picker]:not([data-picker-ready])').forEach((box) => {
      box.dataset.pickerReady = '1';
      const input = box.querySelector('input[type=file]');
      const chips = box.querySelector('[data-doc-chips]');
      const viewer = box.querySelector('[data-doc-viewer]');
      const zone = box.querySelector('[data-dropzone]');
      const empty = viewer ? viewer.innerHTML : '';
      let picked = [];
      const urls = new Map();
      const kindOf = (f) => (f.type.startsWith('image/') ? 'image' : (f.type === 'application/pdf' || /\.pdf$/i.test(f.name) ? 'pdf' : 'other'));
      const size = (b) => (b >= 1048576 ? (b / 1048576).toFixed(1) + ' Mo' : Math.max(1, Math.round(b / 1024)) + ' Ko');
      const show = (chip) => {
        chips.querySelectorAll('.doc-chip').forEach((c) => c.classList.toggle('is-active', c === chip));
        if (!viewer) {
          return;
        }
        if (!chip) {
          viewer.innerHTML = empty;
          initIcons();
          return;
        }
        viewer.innerHTML = '';
        if (chip.dataset.kind === 'pdf') {
          viewer.appendChild(el('iframe', { src: chip.dataset.src, title: chip.dataset.name, class: 'doc-viewer__frame' }));
        } else if (chip.dataset.kind === 'image') {
          viewer.appendChild(el('img', { src: chip.dataset.src, alt: chip.dataset.name, class: 'doc-viewer__img' }));
        } else {
          const boxEmpty = el('div', { class: 'doc-viewer__empty' });
          boxEmpty.appendChild(el('i', { 'data-lucide': 'file', 'aria-hidden': 'true' }));
          boxEmpty.appendChild(el('p', {}, chip.dataset.name));
          boxEmpty.appendChild(el('p', { class: 'muted' }, t('preview.no_preview')));
          viewer.appendChild(boxEmpty);
          initIcons();
        }
      };
      const render = () => {
        chips.querySelectorAll('.doc-chip:not([data-saved])').forEach((c) => c.remove());
        picked.forEach((file, i) => {
          const key = file.name + '|' + file.size + '|' + file.lastModified;
          if (!urls.has(key)) {
            urls.set(key, URL.createObjectURL(file));
          }
          const kind = kindOf(file);
          const li = el('li', { class: 'doc-chip', 'data-src': urls.get(key), 'data-kind': kind, 'data-name': file.name });
          const open = el('button', { type: 'button', class: 'doc-chip__open' });
          open.appendChild(el('i', { 'data-lucide': kind === 'image' ? 'image' : (kind === 'pdf' ? 'file-text' : 'file'), 'aria-hidden': 'true' }));
          open.appendChild(el('span', { class: 'doc-chip__name' }, file.name));
          open.appendChild(el('span', { class: 'doc-chip__size' }, size(file.size)));
          const remove = el('button', { type: 'button', class: 'icon-btn doc-chip__remove', 'aria-label': t('common.delete') + ' ' + file.name, title: t('common.delete') });
          remove.appendChild(el('i', { 'data-lucide': 'x', 'aria-hidden': 'true' }));
          remove.addEventListener('click', () => {
            const wasActive = li.classList.contains('is-active');
            picked.splice(i, 1);
            sync();
            render();
            if (wasActive) {
              show(chips.querySelector('.doc-chip'));
            }
          });
          li.append(open, el('span', { class: 'chip chip--warn' }, t('preview.to_upload')), remove);
          chips.appendChild(li);
        });
        if (zone) {
          zone.classList.toggle('has-files', picked.length > 0);
        }
        initIcons();
      };
      const sync = () => {
        const dt = new DataTransfer();
        picked.forEach((f) => dt.items.add(f));
        input.files = dt.files;
      };
      chips.addEventListener('click', (e) => {
        const open = e.target.closest('.doc-chip__open');
        if (open) {
          show(open.closest('.doc-chip'));
        }
      });
      if (input) {
        input.addEventListener('change', () => {
          const added = Array.from(input.files).filter((f) => !picked.some((p) => p.name === f.name && p.size === f.size && p.lastModified === f.lastModified));
          picked = input.multiple ? picked.concat(added) : Array.from(input.files).slice(-1);
          sync();
          render();
          if (added.length > 0) {
            show(Array.from(chips.querySelectorAll('.doc-chip:not([data-saved])')).find((c) => c.dataset.name === added[0].name) || null);
          }
        });
        if (zone) {
          ['dragenter', 'dragover'].forEach((type) => zone.addEventListener(type, () => zone.classList.add('is-over')));
          ['dragleave', 'drop'].forEach((type) => zone.addEventListener(type, () => zone.classList.remove('is-over')));
        }
      }
      const first = chips.querySelector('.doc-chip');
      if (first) {
        show(first);
      }
    });
  }

  /**
   * Facture fournisseur (form[data-supplier-invoice], fenêtre ou page) : total saisi HT + TVA en direct,
   * dans la devise du PO. Écouteur délégué posé une seule fois ; recalcul à chaque contenu chargé.
   */
  function refreshSupplierInvoice(form) {
    const P = window.EthPricing;
    const out = form.querySelector('[data-si-total]');
    if (!P || !out) {
      return;
    }
    const sep = config.locale === 'en' ? '.' : ',';
    let subtotal = 0;
    form.querySelectorAll('[data-si-line]').forEach((row) => {
      const qty = P.parseQty(row.querySelector('[data-qty]').value);
      const price = P.parseAmount(row.querySelector('[data-price]').value);
      if (qty !== null && price !== null) {
        subtotal += P.lineAmount(qty, price);
      }
    });
    const vatInput = form.querySelector('[data-si-vat]');
    const vat = (vatInput && P.parseAmount(vatInput.value)) || 0;
    const cur = ' ' + (form.dataset.currency || '');
    out.textContent = out.dataset.text
      .replace('{total}', P.formatAmount(subtotal + vat, sep) + cur)
      .replace('{subtotal}', P.formatAmount(subtotal, sep) + cur)
      .replace('{vat}', P.formatAmount(vat, sep) + cur);
  }

  /** Expédition (form[data-shipment-form]) : lignes non cochées grisées, nombre de lignes à expédier. */
  function refreshShipmentForm(form) {
    let n = 0;
    form.querySelectorAll('[data-ship-line]').forEach((row) => {
      const on = row.querySelector('[data-ship]').checked;
      row.classList.toggle('is-excluded', !on);
      n += on ? 1 : 0;
    });
    const out = form.querySelector('[data-ship-count]');
    if (out) {
      out.textContent = out.dataset.text.replace('{n}', n);
    }
  }

  /**
   * Facturation d'un PO client (form[data-invoice-create]) : lignes cochées / décochées, total par ligne,
   * HT / TVA / TTC en direct, dépassement du facturable signalé, libellé du bouton selon brouillon / émission.
   */
  function refreshInvoiceCreate(form) {
    const P = window.EthPricing;
    if (!P) {
      return;
    }
    const sep = config.locale === 'en' ? '.' : ',';
    const rows = Array.from(form.querySelectorAll('[data-inv-line]'));
    const items = [];
    let over = false;
    rows.forEach((row) => {
      const on = row.querySelector('[data-inv-include]').checked;
      const input = row.querySelector('[data-qty]');
      row.classList.toggle('is-excluded', !on);
      input.disabled = !on;
      const qty = on ? P.parseQty(input.value) : null;
      const price = Number(row.dataset.price || 0);
      row.querySelector('[data-line-total]').textContent = qty !== null && qty > 0 ? P.formatAmount(P.lineAmount(qty, price), sep) : '—';
      const tooMuch = qty !== null && qty > Number(row.dataset.max || 0);
      input.classList.toggle('is-negative', tooMuch);
      over = over || tooMuch;
      if (qty !== null && qty > 0) {
        items.push({ quantityMilli: qty, unitCostMinor: 0, unitPriceMinor: price });
      }
    });
    const tot = P.totals(items, form.dataset.vat === '1', Number(form.dataset.vatRate || 0));
    const set = (key, value) => {
      const el = form.querySelector('[data-inv-total="' + key + '"]');
      if (el) {
        el.textContent = P.formatAmount(value, sep) + ' USD';
      }
    };
    set('subtotal', tot.subtotal);
    set('vat', tot.vat);
    set('total', tot.total);
    const count = form.querySelector('[data-inv-count]');
    if (count) {
      count.textContent = items.length + ' / ' + rows.length;
    }
    const all = form.querySelector('[data-inv-all]');
    if (all) {
      const checked = rows.filter((r) => r.querySelector('[data-inv-include]').checked).length;
      all.checked = checked === rows.length;
      all.indeterminate = checked > 0 && checked < rows.length;
    }
    const warn = form.querySelector('[data-inv-warn]');
    if (warn) {
      warn.hidden = !over;
    }
    const issue = (form.querySelector('[data-inv-mode]:checked') || {}).value === 'issue';
    const block = form.querySelector('[data-inv-issue]');
    if (block) {
      block.hidden = !issue;
    }
    const submit = form.querySelector('[data-inv-submit]');
    if (submit) {
      submit.querySelector('span').textContent = issue ? submit.dataset.labelIssue : submit.dataset.labelDraft;
      submit.disabled = items.length === 0 || over;
    }
  }

  let supplierInvoiceListener = false;
  function initSupplierInvoiceForms(root) {
    root.querySelectorAll('form[data-supplier-invoice]').forEach(refreshSupplierInvoice);
    root.querySelectorAll('form[data-shipment-form]').forEach(refreshShipmentForm);
    root.querySelectorAll('form[data-invoice-create]').forEach(refreshInvoiceCreate);
    if (!supplierInvoiceListener) {
      supplierInvoiceListener = true;
      ['input', 'change'].forEach((type) => document.addEventListener(type, (e) => {
        const form = e.target.closest && e.target.closest('form[data-invoice-create]');
        if (!form) {
          return;
        }
        if (type === 'change' && e.target.matches('[data-inv-all]')) {
          form.querySelectorAll('[data-inv-include]').forEach((box) => { box.checked = e.target.checked; });
        }
        // Ligne recochée : quantité facturable reprise en entier.
        if (type === 'change' && e.target.matches('[data-inv-include]') && e.target.checked) {
          const input = e.target.closest('[data-inv-line]').querySelector('[data-qty]');
          if (input.value === '') {
            input.value = input.dataset.full;
          }
        }
        if (type === 'change' && e.target.matches('[data-inv-all]') && e.target.checked) {
          form.querySelectorAll('[data-qty]').forEach((input) => { if (input.value === '') { input.value = input.dataset.full; } });
        }
        refreshInvoiceCreate(form);
      }));
      document.addEventListener('input', (e) => {
        const form = e.target.closest && e.target.closest('form[data-supplier-invoice]');
        if (form) {
          refreshSupplierInvoice(form);
        }
      });
      document.addEventListener('change', (e) => {
        const form = e.target.matches && e.target.matches('[data-ship]') && e.target.closest('form[data-shipment-form]');
        if (form) {
          refreshShipmentForm(form);
        }
      });
    }
  }

  /** Contenu chargé dans une fenêtre : documents, tableaux, icônes. */
  function initRemoteContent(body) {
    initSupplierInvoiceForms(body);
    initDocPickers(body);
    initStaticTables(body);
    initIcons();
  }

  /**
   * Fenêtre générique #remote-modal : [data-remote-dialog="url"] charge un fragment (saisie ou consultation
   * d'une offre…) ; le titre vient de son attribut data-title. La fenêtre rendue par le serveur avec
   * data-auto-open (erreur de saisie) s'ouvre au chargement.
   */
  let remoteBound = false;

  /**
   * POD signé : « tout reçu conformément » coché = quantités livrées reportées telles quelles (tableau masqué) ;
   * décoché = tableau ouvert pour saisir les écarts.
   */
  let podQtyBound = false;
  function initPodQuantities() {
    if (podQtyBound) {
      return;
    }
    podQtyBound = true;
    document.addEventListener('change', (e) => {
      if (!e.target.matches || !e.target.matches('[data-pod-all]')) {
        return;
      }
      const box = e.target.closest('[data-pod-qty]');
      const table = box.querySelector('[data-pod-table]');
      table.hidden = e.target.checked;
      if (e.target.checked) {
        box.querySelectorAll('input[data-full]').forEach((input) => { input.value = input.dataset.full; });
      } else {
        const first = table.querySelector('input');
        if (first) {
          first.focus();
          first.select();
        }
      }
    });
  }

  /** Lien d'aperçu marqué data-auto-preview (ex. POD tout juste généré) : ouvert au chargement. */
  function initAutoPreview() {
    const link = document.querySelector('a[data-auto-preview]');
    if (link) {
      link.removeAttribute('data-auto-preview');
      window.setTimeout(() => link.click(), 150);
    }
  }

  function initRemoteDialogs() {
    // Autres fenêtres de la page rendues avec data-auto-open (erreur de saisie) : rouvertes au chargement.
    document.querySelectorAll('dialog[data-auto-open]:not(#remote-modal)').forEach((dialog) => {
      dialog.removeAttribute('data-auto-open');
      if (!dialog.open) {
        dialog.showModal();
      }
    });
    const modal = document.getElementById('remote-modal');
    if (modal && modal.hasAttribute('data-auto-open') && !modal.open) {
      modal.removeAttribute('data-auto-open');
      modal.showModal();
      initRemoteContent(modal.querySelector('[data-remote-body]'));
      const error = modal.querySelector('.error, [aria-invalid="true"]');
      if (error) {
        error.scrollIntoView({ block: 'center' });
      }
    }
    if (remoteBound) {
      return;
    }
    remoteBound = true;
    let request = 0;
    document.addEventListener('click', async (e) => {
      const trigger = e.target.closest('[data-remote-dialog]');
      const dialog = document.getElementById('remote-modal');
      if (!trigger || !dialog) {
        return;
      }
      e.preventDefault();
      const id = ++request;
      const body = dialog.querySelector('[data-remote-body]');
      const title = dialog.querySelector('.panel-modal__title');
      title.textContent = (trigger.textContent || '').trim();
      body.innerHTML = '<div class="panel-modal__loading">' + core.escapeHtml(t('auth.signing_in')) + '</div>';
      if (!dialog.open) {
        dialog.showModal();
      }
      try {
        const res = await fetch(trigger.dataset.remoteDialog, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (res.status === 401) {
          window.location.reload();
          return;
        }
        if (!res.ok) {
          throw new Error(String(res.status));
        }
        const html = await res.text();
        if (id !== request || !dialog.open) {
          return;
        }
        body.innerHTML = html;
        const titled = body.querySelector('[data-title]');
        if (titled) {
          title.textContent = titled.dataset.title;
        }
        initRemoteContent(body);
        const first = body.querySelector('input:not([type=hidden]):not([type=file]), select, textarea');
        if (first) {
          first.focus();
        }
      } catch (err) {
        body.innerHTML = '<div class="alert alert--error">' + core.escapeHtml(t('grid.panel_error')) + '</div>';
      }
    });
  }

  /** form[data-autosubmit] : filtres appliqués dès qu'une liste change (rechargement de la page filtrée). */
  function initAutoSubmit() {
    document.querySelectorAll('form[data-autosubmit]').forEach((form) => {
      form.addEventListener('change', () => form.requestSubmit());
    });
  }

  function initPrint() {
    document.querySelectorAll('[data-print]').forEach((btn) => btn.addEventListener('click', () => window.print()));
    initDocFit();
  }

  /**
   * Document généré (page A4 de 210 mm) : à l'écran, réduit à la largeur disponible au lieu d'être recomposé,
   * pour que l'aperçu soit la réplique exacte de l'impression (le style d'impression remet l'échelle à 1).
   */
  let docFitBound = false;
  function initDocFit() {
    if (!document.body.classList.contains('layout-print')) {
      return;
    }
    const fit = () => {
      const page = document.querySelector('.print-page');
      if (!page) {
        return;
      }
      const style = getComputedStyle(page);
      const available = page.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
      page.querySelectorAll('.doc').forEach((doc) => {
        doc.style.zoom = '';
        const natural = doc.offsetWidth;
        doc.style.zoom = natural > available && available > 0 ? String(Math.floor((available / natural) * 1000) / 1000) : '';
      });
    };
    fit();
    if (!docFitBound) {
      docFitBound = true;
      window.addEventListener('resize', fit);
      window.addEventListener('load', fit);
    }
  }

  /** table[data-lines] : ajout / suppression de lignes, numérotation, article du catalogue → désignation et unité. */
  function initLines() {
    document.querySelectorAll('[data-lines]').forEach((table) => {
      const body = table.querySelector('[data-lines-body]');
      const form = table.closest('form');
      const template = form && form.querySelector('template[data-line-template]');
      const addBtn = form && form.querySelector('[data-add-line]');
      let next = body.rows.length;

      const renumber = () => {
        Array.from(body.querySelectorAll('[data-line-no]')).forEach((cell, i) => { cell.textContent = String(i + 1); });
      };
      if (addBtn && template) {
        addBtn.addEventListener('click', () => {
          body.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__i__/g, String(next++)));
          renumber();
          initIcons();
          const last = body.rows[body.rows.length - 1];
          const first = last && last.querySelector('select, input');
          if (first) {
            first.focus();
          }
        });
      }
      body.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-remove-line]');
        if (!btn) {
          return;
        }
        const row = btn.closest('[data-line]');
        if (body.rows.length > 1) {
          row.remove();
        } else {
          row.querySelectorAll('input').forEach((i) => { i.value = ''; });
          row.querySelectorAll('[data-item-id]').forEach((h) => { h.value = ''; });
        }
        renumber();
      });
      renumber();
    });
  }

  function initQuotationForm() {
    const form = document.querySelector('form[data-quotation]');
    const P = window.EthPricing;
    if (!form || !P) {
      return;
    }
    const sep = config.locale === 'en' ? '.' : ',';
    const vatRate = Number(form.dataset.vatRate || 0);
    const vatBox = form.querySelector('input[name="vat_applicable"]');
    const rows = Array.from(form.querySelectorAll('[data-quote-line]'));
    const field = (row, name) => row.querySelector('input[data-' + name + '], select[data-' + name + ']');
    const unitCost = (row) => (P.parseAmount(field(row, 'cost').value) || 0) + (P.parseAmount(field(row, 'extra').value) || 0);
    // Seuil d'approbation (points de base) ; vide si l'approbation des marges est désactivée.
    const threshold = form.dataset.threshold ? Number(form.dataset.threshold) : null;

    function refreshLine(row) {
      const price = P.parseAmount(field(row, 'price').value);
      const qty = P.parseQty(field(row, 'qty').value);
      const cell = row.querySelector('[data-line-total]');
      cell.textContent = price !== null && qty !== null ? P.formatAmount(P.lineAmount(qty, price), sep) : '—';
      const margin = price !== null ? P.marginBp(unitCost(row), price) : null;
      const marginInput = field(row, 'margin');
      if (document.activeElement !== marginInput) {
        marginInput.value = P.formatPercent(margin, ',');
      }
      marginInput.classList.toggle('is-negative', margin !== null && margin < 0);
      row.classList.toggle('is-low', threshold !== null && margin !== null && margin < threshold);
    }

    function refreshTotals() {
      const lines = rows.map((row) => ({
        quantityMilli: P.parseQty(field(row, 'qty').value) || 0,
        unitCostMinor: unitCost(row),
        unitPriceMinor: P.parseAmount(field(row, 'price').value) || 0,
      }));
      const tot = P.totals(lines, vatBox ? vatBox.checked : true, vatRate);
      const set = (key, text) => {
        const cell = form.querySelector('[data-total="' + key + '"]');
        if (cell) {
          cell.textContent = text;
        }
      };
      set('subtotal', P.formatAmount(tot.subtotal, sep) + ' USD');
      set('vat', P.formatAmount(tot.vat, sep) + ' USD');
      set('total', P.formatAmount(tot.total, sep) + ' USD');
      set('margin', P.formatAmount(tot.cost, sep) + ' / ' + (tot.marginBp === null ? '—' : P.formatPercent(tot.marginBp, sep) + ' %'));
      set('cost', P.formatAmount(tot.cost, sep) + ' USD');
      set('margin_amount', P.formatAmount(tot.subtotal - tot.cost, sep) + ' USD');
      set('margin_pct', tot.marginBp === null ? '—' : P.formatPercent(tot.marginBp, sep) + ' %');
      const low = tot.marginBp !== null && threshold !== null && tot.marginBp < threshold;
      const bar = form.querySelector('[data-margin-bar]');
      if (bar) {
        // Échelle de la jauge : 0 à 50 % de marge.
        bar.style.width = Math.min(100, Math.max(0, (tot.marginBp || 0) / 50)) + '%';
        bar.classList.toggle('is-low', low || (tot.marginBp !== null && tot.marginBp < 0));
      }
      const alert = form.querySelector('[data-approval-alert]');
      if (alert) {
        alert.hidden = !low;
      }
      const lowCount = form.querySelectorAll('[data-quote-line].is-low').length;
      const info = form.querySelector('[data-low-lines]');
      if (info) {
        info.textContent = lowCount > 0 ? info.dataset.lowText.replace('{n}', lowCount) : (threshold !== null ? info.dataset.okText : '');
        info.classList.toggle('is-warn', lowCount > 0);
      }
    }

    // Marge appliquée d'un coup à toutes les lignes dont le coût est connu.
    const bulkInput = form.querySelector('[data-bulk-margin]');
    const bulkButton = form.querySelector('[data-apply-margin]');
    if (bulkInput && bulkButton) {
      bulkButton.addEventListener('click', () => {
        const bp = P.parseAmount(bulkInput.value);
        if (bp === null || bp >= 10000) {
          bulkInput.focus();
          return;
        }
        rows.forEach((row) => {
          if (unitCost(row) > 0) {
            field(row, 'price').value = P.formatAmount(P.priceForMargin(unitCost(row), bp), ',', false);
            refreshLine(row);
          }
        });
        refreshTotals();
      });
      bulkInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          bulkButton.click();
        }
      });
    }

    rows.forEach((row) => {
      const source = field(row, 'source');
      const cost = field(row, 'cost');
      const lockCost = () => { cost.readOnly = source.value !== ''; };
      lockCost();
      source.addEventListener('change', () => {
        const option = source.selectedOptions[0];
        if (option && option.dataset.cost) {
          cost.value = option.dataset.cost;
        }
        lockCost();
        // La marge visée est conservée : le prix suit le nouveau coût.
        const bp = P.parseAmount(field(row, 'margin').value);
        if (bp !== null && unitCost(row) > 0) {
          field(row, 'price').value = P.formatAmount(P.priceForMargin(unitCost(row), bp), ',', false);
        }
        refreshLine(row);
        refreshTotals();
      });
field(row, 'margin').addEventListener('input', () => {
        const bp = P.parseAmount(field(row, 'margin').value);
        if (bp !== null && bp < 10000 && unitCost(row) > 0) {
          field(row, 'price').value = P.formatAmount(P.priceForMargin(unitCost(row), bp), ',', false);
        }
        refreshLine(row);
        refreshTotals();
      });
      ['qty', 'cost', 'extra', 'price'].forEach((name) => {
        field(row, name).addEventListener('input', () => {
          refreshLine(row);
          refreshTotals();
        });
      });
      refreshLine(row);
    });
    if (vatBox) {
      vatBox.addEventListener('change', refreshTotals);
    }
    refreshTotals();
  }

  /**
   * PO client (form[data-client-order]) : total par ligne et du PO en direct, écart avec la quotation signalé,
   * lignes décochées grisées (création) et case « tout cocher ».
   */
  function initClientOrderForm() {
    const form = document.querySelector('form[data-client-order]');
    const P = window.EthPricing;
    if (!form || !P) {
      return;
    }
    const sep = config.locale === 'en' ? '.' : ',';
    const rows = Array.from(form.querySelectorAll('[data-co-line]'));
    const all = form.querySelector('[data-include-all]');
    const summary = form.querySelector('[data-co-summary]');
    const included = (row) => {
      const box = row.querySelector('[data-include]');
      return !box || box.checked;
    };

    function refresh() {
      let count = 0;
      let total = 0;
      let quoted = 0;
      rows.forEach((row) => {
        const qty = P.parseQty(row.querySelector('[data-qty]').value);
        const price = P.parseAmount(row.querySelector('[data-price]').value);
        const amount = qty !== null && price !== null ? P.lineAmount(qty, price) : null;
        const on = included(row);
        row.classList.toggle('is-excluded', !on);
        row.querySelector('[data-line-total]').textContent = amount !== null ? P.formatAmount(amount, sep) : '—';
        const qq = Number(row.dataset.quotedQty || 0);
        const qp = Number(row.dataset.quotedPrice || 0);
        row.querySelector('[data-line-diff]').hidden = !on || qq === 0 || (qty === qq && price === qp);
        if (on) {
          count += 1;
          total += amount || 0;
          quoted += qq > 0 ? P.lineAmount(qq, qp) : 0;
        }
      });
      if (all) {
        const checked = rows.filter(included).length;
        all.checked = checked === rows.length;
        all.indeterminate = checked > 0 && checked < rows.length;
      }
      if (summary) {
        const diff = total - quoted;
        const text = (diff === 0 ? summary.dataset.text : summary.dataset.textDiff)
          .replace('{n}', count)
          .replace('{total}', P.formatAmount(total, sep) + ' USD')
          .replace('{diff}', (diff > 0 ? '+' : '') + P.formatAmount(diff, sep) + ' USD');
        summary.textContent = text;
        summary.classList.toggle('is-warn', diff !== 0);
      }
    }

    form.addEventListener('input', (e) => {
      if (e.target.closest('[data-co-line]')) {
        refresh();
      }
    });
    form.addEventListener('change', (e) => {
      if (e.target === all) {
        rows.forEach((row) => {
          const box = row.querySelector('[data-include]');
          if (box) {
            box.checked = all.checked;
          }
        });
      }
      if (e.target === all || e.target.matches('[data-include]')) {
        refresh();
      }
    });
    refresh();
  }

  /**
   * a[data-preview] : aperçu dans une fenêtre modale (image, PDF, document produit par ETHAN).
   * Le lien garde son href : clic du milieu, Ctrl/Cmd+clic ou absence de JS ouvrent la page normalement.
   * Toutes les données affichées passent par textContent / attributs (jamais d'HTML issu des données).
   */
  function initPreview() {
    const ICONS = { image: 'image', pdf: 'file-text', document: 'file-text' };
    let modal = null;
    let lastFocus = null;
    let current = null;

    function button(cls, icon, label, attrs) {
      const el = document.createElement(attrs && attrs.href !== undefined ? 'a' : 'button');
      el.className = cls;
      if (el.tagName === 'BUTTON') {
        el.type = 'button';
      }
      Object.entries(attrs || {}).forEach(([k, v]) => el.setAttribute(k, v));
      const i = document.createElement('i');
      i.setAttribute('data-lucide', icon);
      i.setAttribute('aria-hidden', 'true');
      el.appendChild(i);
      if (label) {
        const span = document.createElement('span');
        span.textContent = label;
        el.appendChild(span);
      }
      return el;
    }

    function build() {
      modal = document.createElement('div');
      modal.className = 'preview';
      modal.hidden = true;
      const backdrop = document.createElement('div');
      backdrop.className = 'preview__backdrop';
      backdrop.dataset.previewClose = '';
      const dialog = document.createElement('div');
      dialog.className = 'preview__dialog';
      dialog.setAttribute('role', 'dialog');
      dialog.setAttribute('aria-modal', 'true');
      dialog.setAttribute('aria-labelledby', 'preview-title');

      const head = document.createElement('header');
      head.className = 'preview__head';
      const icon = document.createElement('span');
      icon.className = 'preview__icon';
      const titles = document.createElement('div');
      titles.className = 'preview__titles';
      const title = document.createElement('h2');
      title.id = 'preview-title';
      title.className = 'preview__title';
      const meta = document.createElement('p');
      meta.className = 'preview__meta';
      titles.append(title, meta);

      const actions = document.createElement('div');
      actions.className = 'preview__actions';
      const zoom = button('btn btn--sm btn--ghost', 'zoom-in', t('preview.zoom'), { 'data-preview-zoom': '', 'aria-pressed': 'false' });
      const print = button('btn btn--sm', 'printer', t('preview.print'), { 'data-preview-print': '' });
      const download = button('btn btn--sm', 'download', t('preview.download'), { href: '#', 'data-preview-download': '', download: '' });
      const open = button('btn btn--sm', 'external-link', t('preview.open'), { href: '#', target: '_blank', rel: 'noopener', 'data-preview-open': '' });
      const closeButton = button('icon-btn', 'x', '', { 'data-preview-close': '', 'aria-label': t('preview.close'), title: t('preview.close_hint') });
      actions.append(zoom, print, download, open, closeButton);
      head.append(icon, titles, actions);

      const body = document.createElement('div');
      body.className = 'preview__body';
      dialog.append(head, body);
      modal.append(backdrop, dialog);
      document.body.appendChild(modal);

      modal.addEventListener('click', (e) => {
        if (e.target.closest('[data-preview-close]')) {
          close();
        }
      });
      zoom.addEventListener('click', () => {
        const zoomed = body.classList.toggle('is-zoomed');
        zoom.setAttribute('aria-pressed', String(zoomed));
      });
      print.addEventListener('click', () => {
        const frame = body.querySelector('iframe');
        if (frame && frame.contentWindow) {
          frame.contentWindow.focus();
          frame.contentWindow.print();
        }
      });
      modal.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          e.preventDefault();
          close();
          return;
        }
        if (e.key !== 'Tab') {
          return;
        }
        // Le focus reste dans la fenêtre.
        const focusable = Array.from(dialog.querySelectorAll('a[href], button, iframe')).filter((el) => !el.hidden && el.offsetParent !== null);
        if (focusable.length === 0) {
          return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      });
    }

    function status(body, cls, text) {
      const box = document.createElement('div');
      box.className = 'preview__status ' + cls;
      box.textContent = text;
      body.appendChild(box);
      return box;
    }

    function openPreview(trigger) {
      if (!modal) {
        build();
      }
      const kind = core.previewKind(trigger.dataset.preview);
      const href = trigger.getAttribute('href');
      const body = modal.querySelector('.preview__body');
      const icon = modal.querySelector('.preview__icon');
      current = { kind: kind };
      lastFocus = trigger;

      modal.querySelector('.preview__title').textContent = trigger.dataset.previewTitle || trigger.textContent.trim();
      modal.querySelector('.preview__meta').textContent = trigger.dataset.previewMeta || t('preview.kind.' + kind);
      icon.replaceChildren();
      const i = document.createElement('i');
      i.setAttribute('data-lucide', ICONS[kind]);
      icon.appendChild(i);

      const zoom = modal.querySelector('[data-preview-zoom]');
      const print = modal.querySelector('[data-preview-print]');
      const download = modal.querySelector('[data-preview-download]');
      const open = modal.querySelector('[data-preview-open]');
      zoom.hidden = kind !== 'image';
      zoom.setAttribute('aria-pressed', 'false');
      print.hidden = kind !== 'document';
      download.hidden = !trigger.dataset.previewDownload;
      download.setAttribute('href', trigger.dataset.previewDownload || '#');
      open.setAttribute('href', href);

      body.replaceChildren();
      body.className = 'preview__body preview__body--' + kind;
      const loading = status(body, 'preview__status--loading', t('preview.loading'));
      const failed = () => {
        loading.remove();
        status(body, 'preview__status--error', t('preview.error'));
      };

      if (kind === 'image') {
        const img = new Image();
        img.alt = trigger.dataset.previewTitle || '';
        img.addEventListener('load', () => loading.remove());
        img.addEventListener('error', failed);
        img.addEventListener('click', () => zoom.click());
        img.src = href;
        body.appendChild(img);
      } else {
        const frame = document.createElement('iframe');
        frame.title = trigger.dataset.previewTitle || t('preview.kind.' + kind);
        frame.addEventListener('load', () => loading.remove());
        frame.src = kind === 'document' ? core.withQuery(href, 'embed', '1') : href;
        body.appendChild(frame);
      }

      modal.hidden = false;
      document.body.classList.add('has-modal');
      initIcons();
      modal.querySelector('[data-preview-close].icon-btn').focus();
    }

    function close() {
      if (!modal || modal.hidden) {
        return;
      }
      modal.hidden = true;
      modal.querySelector('.preview__body').replaceChildren();
      document.body.classList.remove('has-modal');
      current = null;
      if (lastFocus && document.contains(lastFocus)) {
        lastFocus.focus();
      }
    }

    document.addEventListener('click', (e) => {
      const trigger = e.target.closest('a[data-preview]');
      if (!trigger || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) {
        return;
      }
      if (!core.previewKind(trigger.dataset.preview)) {
        return;
      }
      e.preventDefault();
      e.stopPropagation();
      openPreview(trigger);
    }, true);

    return { close: close, isOpen: () => current !== null };
  }

  /** table[data-alloc] : total réparti en direct, « Répartir automatiquement » sur les plus anciennes factures. */
  function initAllocation() {
    const P = window.EthPricing;
    if (!P) {
      return;
    }
    const sep = config.locale === 'en' ? '.' : ',';
    document.querySelectorAll('table[data-alloc]').forEach((table) => {
      const form = table.closest('form');
      const inputs = Array.from(table.querySelectorAll('input[data-alloc-balance]'));
      const total = table.querySelector('[data-alloc-total]');
      const autoBtn = form && form.querySelector('[data-alloc-auto]');
      const source = () => (form ? form.querySelector(table.dataset.allocAmount) : null);

      function refresh() {
        const sum = inputs.reduce((acc, i) => acc + (P.parseAmount(i.value) || 0), 0);
        const src = source();
        const max = src ? P.parseAmount(src.value) : null;
        total.textContent = P.formatAmount(sum, sep) + ' ' + (table.dataset.allocCurrency || 'USD');
        total.classList.toggle('overdue', max !== null && sum > max);
      }

      inputs.forEach((i) => i.addEventListener('input', refresh));
      const src = source();
      if (src) {
        src.addEventListener('input', refresh);
      }
      if (autoBtn) {
        autoBtn.addEventListener('click', () => {
          const s = source();
          const amount = s ? P.parseAmount(s.value) || 0 : 0;
          const parts = P.allocate(amount, inputs.map((i) => Number(i.dataset.allocBalance)));
          inputs.forEach((i, k) => { i.value = parts[k] > 0 ? P.formatAmount(parts[k], sep, false) : ''; });
          refresh();
        });
      }
      refresh();
    });
  }

  /**
   * Tableau de bord finance (Chart.js) : données dans <script type="application/json" id="finance-data">.
   * Couleurs = jetons --series-N (palette validée, ordre fixe) ; un seul axe en USD ; info-bulle sur toute la colonne.
   */
  function initFinanceCharts() {
    const holder = document.getElementById('finance-data');
    const P = window.EthPricing;
    if (!holder || !window.Chart || !P) {
      return;
    }
    const data = JSON.parse(holder.textContent);
    const css = getComputedStyle(document.documentElement);
    const token = (name) => css.getPropertyValue(name).trim();
    const sep = config.locale === 'en' ? '.' : ',';
    const usd = (minor) => P.formatAmount(minor, sep) + ' USD';
    const axis = (minor) => P.formatAmount(Math.round(minor / 100) * 100, sep).replace(/[.,]00$/, '');
    const C = window.Chart;
    C.defaults.font.family = getComputedStyle(document.body).fontFamily;
    C.defaults.color = token('--text-muted');
    const common = {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top', align: 'start', labels: { usePointStyle: true, boxWidth: 8, boxHeight: 8, padding: 16, color: token('--text'), sort: (a, b) => a.datasetIndex - b.datasetIndex } },
        tooltip: {
          itemSort: (a, b) => a.datasetIndex - b.datasetIndex,
          backgroundColor: token('--surface'), titleColor: token('--text'), bodyColor: token('--text'), borderColor: token('--border-strong'), borderWidth: 1,
          padding: 10, usePointStyle: true, boxWidth: 8, boxHeight: 8,
          callbacks: { label: (ctx) => ' ' + ctx.dataset.label + ' : ' + usd(ctx.dataset.absolute ? Math.abs(ctx.parsed.y) : ctx.parsed.y) },
        },
      },
      scales: {
        x: { grid: { display: false }, border: { color: token('--border-strong') } },
        y: { grid: { color: token('--border') }, border: { display: false }, ticks: { callback: (v) => axis(v), maxTicksLimit: 6 } },
      },
    };
    const bar = (label, values, color, extra) => Object.assign({
      type: 'bar', label, data: values, backgroundColor: color, borderRadius: 4, borderSkipped: 'start',
      barPercentage: 0.7, categoryPercentage: 0.7, borderColor: token('--surface'), borderWidth: { left: 1, right: 1 },
    }, extra || {});

    const cashCanvas = document.getElementById('chart-cash');
    if (cashCanvas) {
      const c = data.cash;
      new C(cashCanvas, {
        data: {
          labels: c.labels,
          datasets: [
            bar(c.names[0], c.in, token('--series-1'), { stack: 'flows', order: 1 }),
            bar(c.names[1], c.out, token('--series-2'), { stack: 'flows', order: 1, absolute: true }),
            { type: 'line', label: c.names[2], data: c.cumulative, borderColor: token('--series-3'), backgroundColor: token('--series-3'), borderWidth: 2,
              pointRadius: 4, pointHoverRadius: 6, pointBorderColor: token('--surface'), pointBorderWidth: 2, tension: 0, stack: 'cumulative', order: 0 },
          ],
        },
        options: Object.assign({}, common, { scales: { x: Object.assign({ stacked: true }, common.scales.x), y: Object.assign({ stacked: true }, common.scales.y) } }),
      });
    }
    const monthsCanvas = document.getElementById('chart-months');
    if (monthsCanvas) {
      const m = data.months;
      new C(monthsCanvas, {
        type: 'bar',
        data: { labels: m.labels, datasets: [bar(m.names[0], m.revenue, token('--series-1')), bar(m.names[1], m.margin, token('--series-2'))] },
        options: common,
      });
    }
  }

  /** Composants de la page (contenu principal) : rejoués après chaque rafraîchissement AJAX. */
  /** [data-copy] : copie le texte du [data-copy-source] voisin (mot de passe provisoire). */
  function initCopyButtons() {
    document.querySelectorAll('[data-copy]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const source = btn.parentElement.querySelector('[data-copy-source]');
        if (!source) {
          return;
        }
        try {
          await navigator.clipboard.writeText(source.textContent.trim());
        } catch (e) {
          window.getSelection().selectAllChildren(source); // copie manuelle (contexte non sécurisé)
          return;
        }
        const label = btn.querySelector('span');
        const original = label.textContent;
        label.textContent = btn.dataset.copiedLabel || original;
        window.setTimeout(() => { label.textContent = original; }, 2000);
      });
    });
  }

  /** form[data-pw-form] : règles du nouveau mot de passe cochées au fil de la saisie. */
  function initPasswordRules() {
    document.querySelectorAll('form[data-pw-form]').forEach((form) => {
      const input = form.querySelector('[data-pw-new]');
      const confirm = form.querySelector('[data-pw-confirm]');
      const rules = form.querySelector('[data-pw-rules]');
      if (!input || !confirm || !rules) {
        return;
      }
      const min = Number(input.getAttribute('minlength') || 12);
      const update = () => {
        const v = input.value;
        const classes = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter((re) => re.test(v)).length;
        const state = { length: v.length >= min, mix: classes >= 3, match: v !== '' && v === confirm.value };
        rules.querySelectorAll('[data-rule]').forEach((li) => li.classList.toggle('is-ok', state[li.dataset.rule]));
        confirm.setCustomValidity(confirm.value !== '' && !state.match ? (rules.querySelector('[data-rule="match"] span').textContent) : '');
      };
      input.addEventListener('input', update);
      confirm.addEventListener('input', update);
    });
  }

  function initPage() {
    initPasswordToggle();
    initCopyButtons();
    initPasswordRules();
    initTables();
    initStaticTables();
    initAutoSubmit();
    initPrint();
    initLines();
    initQuotationForm();
    initClientOrderForm();
    initDocPickers(document);
    initSupplierInvoiceForms(document);
    initAllocation();
    initFinanceCharts();
    initDashboard();
    initItemCombo();
    initRfqForm();
    initDialogs();
    initRemoteDialogs();
    initAutoPreview();
    initPodQuantities();
    initIcons();
  }

  document.addEventListener('DOMContentLoaded', () => {
    if (window.Swal) {
      // Boîtes de dialogue sobres, aux boutons de l'application.
      window.Swal = window.Swal.mixin({ buttonsStyling: false, reverseButtons: true, customClass: { confirmButton: 'btn btn--primary', cancelButton: 'btn', denyButton: 'btn' } });
    }
    initPreview();
    initSidebar();
    initNotifications();
    initAjaxForms(); // confirmation + envoi AJAX (remplace la confirmation simple data-confirm)
    initSubmitGuard();
    initRowLinks(document);
    initPage();
  });
}(window, document));
