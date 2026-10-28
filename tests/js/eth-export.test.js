'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const x = require('../../public/assets/js/eth-export.js');

test('typedValue convertit les unités internes', () => {
  assert.equal(x.typedValue('money', '123456', '1 234,56 USD'), 1234.56);
  assert.equal(x.typedValue('percent', '1250', '12,5 %'), 0.125);
  assert.equal(x.typedValue('qty', '2500', '2,5'), 2.5);
  assert.equal(x.typedValue('int', '7', '7'), 7);
  assert.equal(x.typedValue('money', '', '—'), null);
  assert.deepEqual(x.typedValue('date', '2026-10-27', '27/10/2026'), new Date(Date.UTC(2026, 9, 27)));
  assert.deepEqual(x.typedValue('date', '2026-10-27 16:05:00', ''), new Date(Date.UTC(2026, 9, 27, 16, 5)));
  assert.equal(x.typedValue('date', '', '—'), '—');
  assert.equal(x.typedValue('text', 'ignored', 'Minière Alpha'), 'Minière Alpha');
});

test('totals additionne seulement les colonnes marquées', () => {
  const cols = [{ type: 'text' }, { type: 'money', total: true }, { type: 'percent' }];
  assert.deepEqual(x.totals(cols, [['a', 10.1, 0.2], ['b', 20.2, null], ['c', null, 0.1]]), [null, 30.3, null]);
});

test('formatCell suit la langue', () => {
  assert.equal(x.formatCell('money', 1234567.5, 'fr'), '1\u00A0234\u00A0567,50');
  assert.equal(x.formatCell('money', -12.5, 'en'), '−12.50');
  assert.equal(x.formatCell('percent', 0.125, 'fr'), '12,5 %');
  assert.equal(x.formatCell('qty', 2.5, 'fr'), '2,5');
  assert.equal(x.formatCell('qty', 1200, 'en'), '1,200');
  assert.equal(x.formatCell('date', new Date(Date.UTC(2026, 9, 27)), 'fr'), '27/10/2026');
  assert.equal(x.formatCell('date', new Date(Date.UTC(2026, 9, 27, 9, 5)), 'fr'), '27/10/2026 09:05');
  assert.equal(x.formatCell('text', null, 'fr'), '');
});

test('fileName retire les caractères interdits', () => {
  assert.equal(x.fileName('PO clients / ouverts: *', '2026-10-27'), 'PO clients ouverts 2026-10-27');
});

test('toPdf : en-tête répété, ligne de total, montants alignés à droite', () => {
  const doc = x.toPdf({
    company: 'ACME', title: 'Factures', subtitle: '', filters: ['Statut : Ouvertes'], filtersLabel: 'Filtres', noFilter: 'aucun',
    generated: 'Généré le 27/10/2026', confidential: 'Confidentiel', pageLabel: 'Page {n} / {total}', countLabel: '2 lignes', totalLabel: 'Total',
    locale: 'fr',
    columns: [{ label: 'Réf', type: 'text' }, { label: 'Montant', type: 'money', total: true }],
    rows: [['FAC-1', 10], ['FAC-2', -2.5]],
    totals: [null, 7.5],
  });
  const table = doc.content.find((c) => c.table).table;
  assert.equal(table.headerRows, 1);
  assert.equal(table.body.length, 4);
  assert.equal(table.body[2][1].text, '−2,50');
  assert.equal(table.body[2][1].color, '#B42318');
  assert.equal(table.body[3][0].text, 'Total');
  assert.equal(table.body[3][1].text, '7,50');
  assert.equal(table.body[1][1].alignment, 'right');
  assert.equal(doc.pageOrientation, 'landscape');
  assert.equal(doc.footer(2, 5).columns[1].text, 'Page 2 / 5');
});
