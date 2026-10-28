/* ETHAN — exports « exécutifs » d'un tableau affiché (Excel mis en forme, PDF paysage).
   Module pur : le modèle (titre, filtres, colonnes typées, lignes, totaux) est construit par app.js à partir
   de la vue DataTables (recherche, filtres, tri et colonnes visibles) ; ce module le met en forme pour
   ExcelJS et pdfmake, chargés à la demande. Testé sous Node. */
(function (root, factory) {
  'use strict';
  const api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    root.EthExport = api;
  }
}(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  const BRAND = '0E4D64';      // en-tête (couleur d'accent de l'application)
  const BRAND_SOFT = 'E8EEF6'; // bandeau de titre / ligne de total
  const ZEBRA = 'F6F8FA';
  const RULE = 'D5DBE3';
  const NEGATIVE = 'B42318';

  /** Types de colonne : text, money (centimes), percent (points de base), qty (millièmes), int, date (AAAA-MM-JJ…). */
  const NUMERIC = ['money', 'percent', 'qty', 'int'];

  /**
   * Valeur typée d'une cellule à partir de sa valeur brute (data-order / data-export) et de son texte affiché.
   * @return {string|number|Date|null}
   */
  function typedValue(type, raw, text) {
    const s = raw === undefined || raw === null ? '' : String(raw).trim();
    if (type === 'date') {
      const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(s);
      return m ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0))) : (text || null);
    }
    if (NUMERIC.includes(type)) {
      if (s === '' || isNaN(Number(s))) {
        return null;
      }
      const n = Number(s);
      return type === 'money' ? n / 100 : type === 'percent' ? n / 10000 : type === 'qty' ? n / 1000 : n;
    }
    return text === undefined || text === null ? '' : String(text);
  }

  /** Totaux des colonnes marquées total (somme des valeurs numériques). */
  function totals(columns, rows) {
    return columns.map((c, i) => {
      if (!c.total) {
        return null;
      }
      let sum = 0;
      rows.forEach((r) => { if (typeof r[i] === 'number') { sum += r[i]; } });
      return Math.round(sum * 100) / 100;
    });
  }

  /** Nombre formaté pour le PDF selon la langue (espace fine insécable en français). */
  function formatNumber(n, decimals, locale) {
    const fixed = Math.abs(n).toFixed(decimals);
    const parts = fixed.split('.');
    const group = locale === 'en' ? ',' : String.fromCharCode(0xA0); // espace insécable : présente dans la police du PDF
    const dec = locale === 'en' ? '.' : ',';
    const int = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, group);
    return (n < 0 ? '−' : '') + int + (parts[1] ? dec + parts[1] : '');
  }

  function formatCell(type, v, locale) {
    if (v === null || v === undefined || v === '') {
      return '';
    }
    if (v instanceof Date) {
      const p = (x) => String(x).padStart(2, '0');
      const day = p(v.getUTCDate()) + '/' + p(v.getUTCMonth() + 1) + '/' + v.getUTCFullYear();
      return v.getUTCHours() || v.getUTCMinutes() ? day + ' ' + p(v.getUTCHours()) + ':' + p(v.getUTCMinutes()) : day;
    }
    if (typeof v !== 'number') {
      return String(v);
    }
    if (type === 'money') {
      return formatNumber(v, 2, locale);
    }
    if (type === 'percent') {
      return formatNumber(v * 100, 1, locale) + ' %';
    }
    if (type === 'qty') {
      const dec = locale === 'en' ? '.' : ',';
      const s = formatNumber(v, 3, locale);
      return s.includes(dec) ? s.replace(/0+$/, '').replace(new RegExp('\\' + dec + '$'), '') : s;
    }
    return formatNumber(v, 0, locale);
  }

  /** Nom de fichier sûr : « Factures 2026-10-27 ». */
  function fileName(title, date) {
    return (String(title).replace(/[\\/:*?"<>|]+/g, ' ').replace(/\s+/g, ' ').trim() || 'export') + ' ' + date;
  }

  const NUMFMT = { money: '#,##0.00;[Red]-#,##0.00', percent: '0.0%', qty: '#,##0.###', int: '#,##0', date: 'dd/mm/yyyy' };

  /**
   * Classeur ExcelJS : bandeau (société, titre, sous-titre, filtres, date), en-tête coloré, zébrage,
   * filets, formats numériques, totaux en formules SUBTOTAL (suivent le filtre automatique), volet figé,
   * mise en page paysage ajustée à la largeur, en-tête répété à l'impression, pied de page numéroté.
   */
  function toExcel(ExcelJS, model) {
    const wb = new ExcelJS.Workbook();
    wb.creator = model.company || 'ETHAN';
    wb.created = new Date();
    const ws = wb.addWorksheet(String(model.sheet || model.title).replace(/[\\/?*[\]:]/g, ' ').slice(0, 31) || 'Export', {
      pageSetup: { orientation: 'landscape', paperSize: 9, fitToPage: true, fitToWidth: 1, fitToHeight: 0, margins: { left: 0.4, right: 0.4, top: 0.6, bottom: 0.6, header: 0.3, footer: 0.3 } },
      headerFooter: { oddFooter: '&L&8' + (model.company || '') + ' — ' + model.title + '&R&8&P / &N' },
      views: [{ showGridLines: false }],
    });
    const n = model.columns.length;
    const last = ws.getColumn(n).letter;
    const band = (text, font, height) => {
      const row = ws.addRow([text]);
      ws.mergeCells(row.number, 1, row.number, n);
      row.getCell(1).font = font;
      row.getCell(1).alignment = { vertical: 'middle', wrapText: true };
      row.height = height;
      return row;
    };
    band(model.company || '', { name: 'Calibri', size: 9, bold: true, color: { argb: 'FF' + BRAND } }, 16);
    band(model.title, { name: 'Calibri', size: 16, bold: true, color: { argb: 'FF1D2939' } }, 26);
    if (model.subtitle) {
      band(model.subtitle, { name: 'Calibri', size: 10, color: { argb: 'FF5B6573' } }, 16);
    }
    band(model.filtersLabel + ' : ' + (model.filters.length ? model.filters.join(' · ') : model.noFilter), { name: 'Calibri', size: 9, italic: true, color: { argb: 'FF5B6573' } }, 16);
    band(model.generated, { name: 'Calibri', size: 9, color: { argb: 'FF5B6573' } }, 14);
    ws.addRow([]);
    // Logo de la société en haut à droite du bandeau (hauteur 40 px, proportions conservées).
    if (model.logo && model.logo.dataUrl) {
      const h = 40;
      const w = Math.min(160, Math.round(h * (model.logo.width || 1) / (model.logo.height || 1)));
      const image = wb.addImage({ base64: model.logo.dataUrl, extension: 'png' });
      ws.addImage(image, { tl: { col: Math.max(0, n - 1.6), row: 0.15 }, ext: { width: w, height: h }, editAs: 'absolute' });
    }

    const header = ws.addRow(model.columns.map((c) => c.label));
    header.height = 22;
    header.eachCell((cell, i) => {
      const c = model.columns[i - 1];
      cell.font = { name: 'Calibri', size: 10, bold: true, color: { argb: 'FFFFFFFF' } };
      cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF' + BRAND } };
      cell.alignment = { vertical: 'middle', horizontal: NUMERIC.includes(c.type) ? 'right' : 'left', wrapText: true };
      cell.border = { bottom: { style: 'thin', color: { argb: 'FF' + BRAND } } };
    });
    const headerRow = header.number;

    model.rows.forEach((values, r) => {
      const row = ws.addRow(values.map((v) => (v === '' ? null : v)));
      row.eachCell({ includeEmpty: true }, (cell, i) => {
        const c = model.columns[i - 1];
        cell.font = { name: 'Calibri', size: 10 };
        cell.alignment = { vertical: 'top', horizontal: NUMERIC.includes(c.type) ? 'right' : 'left', wrapText: c.type === 'text' };
        cell.border = { bottom: { style: 'hair', color: { argb: 'FF' + RULE } } };
        if (NUMFMT[c.type]) {
          cell.numFmt = NUMFMT[c.type];
        }
        if (r % 2 === 1) {
          cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF' + ZEBRA } };
        }
      });
    });
    const firstData = headerRow + 1;
    const lastData = headerRow + model.rows.length;

    if (model.totals.some((v) => v !== null) && model.rows.length > 0) {
      const values = model.columns.map((c, i) => {
        if (i === 0) {
          return model.totalLabel;
        }
        if (model.totals[i] === null) {
          return null;
        }
        const col = ws.getColumn(i + 1).letter;
        return { formula: 'SUBTOTAL(109,' + col + firstData + ':' + col + lastData + ')', result: model.totals[i] };
      });
      const row = ws.addRow(values);
      row.eachCell({ includeEmpty: true }, (cell, i) => {
        const c = model.columns[i - 1];
        cell.font = { name: 'Calibri', size: 10, bold: true };
        cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF' + BRAND_SOFT } };
        cell.border = { top: { style: 'thin', color: { argb: 'FF' + BRAND } }, bottom: { style: 'double', color: { argb: 'FF' + BRAND } } };
        cell.alignment = { horizontal: NUMERIC.includes(c.type) ? 'right' : 'left' };
        if (NUMFMT[c.type]) {
          cell.numFmt = NUMFMT[c.type];
        }
      });
    }

    // Largeurs : d'après le contenu, bornées.
    model.columns.forEach((c, i) => {
      let width = c.label.length + 2;
      model.rows.forEach((r) => {
        const len = r[i] instanceof Date ? 11 : typeof r[i] === 'number' ? 14 : String(r[i] || '').length;
        width = Math.max(width, Math.min(len + 2, 48));
      });
      ws.getColumn(i + 1).width = Math.max(10, Math.min(width, c.type === 'text' ? 48 : 18));
    });
    ws.views = [{ state: 'frozen', ySplit: headerRow, showGridLines: false }];
    if (model.rows.length > 0) {
      ws.autoFilter = { from: { row: headerRow, column: 1 }, to: { row: lastData, column: n } };
    }
    ws.pageSetup.printTitlesRow = headerRow + ':' + headerRow;
    ws.pageSetup.printArea = 'A1:' + last + (ws.rowCount);
    return wb;
  }

  /** Document pdfmake : A4 paysage, bandeau, filtres, tableau zébré à en-tête répété, totaux, pied numéroté. */
  function toPdf(model) {
    const widths = model.columns.map((c) => (c.type === 'text' ? '*' : 'auto'));
    const head = model.columns.map((c) => ({ text: c.label, style: 'th', alignment: NUMERIC.includes(c.type) ? 'right' : 'left' }));
    const body = [head];
    model.rows.forEach((r) => {
      body.push(model.columns.map((c, i) => {
        const v = r[i];
        const cell = { text: formatCell(c.type, v, model.locale), alignment: NUMERIC.includes(c.type) ? 'right' : 'left', noWrap: NUMERIC.includes(c.type) || c.type === 'date' };
        if (typeof v === 'number' && v < 0) {
          cell.color = '#' + NEGATIVE;
        }
        return cell;
      }));
    });
    const hasTotals = model.totals.some((v) => v !== null) && model.rows.length > 0;
    if (hasTotals) {
      body.push(model.columns.map((c, i) => ({
        text: i === 0 ? model.totalLabel : (model.totals[i] === null ? '' : formatCell(c.type, model.totals[i], model.locale)),
        bold: true, fillColor: '#' + BRAND_SOFT, alignment: NUMERIC.includes(c.type) ? 'right' : 'left', noWrap: i > 0,
      })));
    }
    const lastRow = body.length - 1;
    return {
      pageSize: 'A4',
      pageOrientation: 'landscape',
      pageMargins: [28, 64, 28, 40],
      info: { title: model.title, author: model.company || 'ETHAN', creator: 'ETHAN' },
      header: () => ({
        margin: [28, 18, 28, 0],
        columns: [
          model.logo && model.logo.dataUrl ? { image: model.logo.dataUrl, fit: [90, 34], width: 'auto', margin: [0, 0, 10, 0] } : null,
          { stack: [{ text: model.company || '', style: 'company' }, { text: model.title, style: 'title' }] },
          { text: model.generated, style: 'meta', alignment: 'right', margin: [0, 14, 0, 0] },
        ].filter(Boolean),
      }),
      footer: (page, count) => ({
        margin: [28, 10, 28, 0],
        columns: [
          { text: (model.company || '') + ' — ' + model.confidential, style: 'meta' },
          { text: model.pageLabel.replace('{n}', page).replace('{total}', count), style: 'meta', alignment: 'right' },
        ],
      }),
      content: [
        model.subtitle ? { text: model.subtitle, style: 'subtitle' } : null,
        { text: model.filtersLabel + ' : ' + (model.filters.length ? model.filters.join(' · ') : model.noFilter), style: 'filters' },
        { text: model.countLabel, style: 'filters', margin: [0, 0, 0, 8] },
        {
          table: { headerRows: 1, widths: widths, body: body, dontBreakRows: true },
          layout: {
            fillColor: (row) => (row === 0 ? '#' + BRAND : (hasTotals && row === lastRow ? null : (row % 2 === 0 ? '#' + ZEBRA : null))),
            hLineWidth: (i) => (i === 0 || i === 1 ? 0 : (hasTotals && i === lastRow ? 1 : 0.5)),
            vLineWidth: () => 0,
            hLineColor: (i) => (hasTotals && i === lastRow ? '#' + BRAND : '#' + RULE),
            paddingLeft: () => 5,
            paddingRight: () => 5,
            paddingTop: () => 3.5,
            paddingBottom: () => 3.5,
          },
        },
      ].filter(Boolean),
      defaultStyle: { font: 'Roboto', fontSize: 8, color: '#1D2939' },
      styles: {
        company: { fontSize: 8, bold: true, color: '#' + BRAND },
        title: { fontSize: 14, bold: true },
        subtitle: { fontSize: 9, color: '#5B6573', margin: [0, 0, 0, 2] },
        meta: { fontSize: 7, color: '#5B6573' },
        filters: { fontSize: 7.5, italics: true, color: '#5B6573' },
        th: { bold: true, color: '#FFFFFF', fontSize: 7.5 },
      },
    };
  }

  return { typedValue, totals, formatCell, formatNumber, fileName, toExcel, toPdf, NUMERIC };
}));
