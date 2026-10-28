'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const P = require('../../public/assets/js/eth-pricing.js');
const vectors = require('../pricing-vectors.json');

test('mêmes résultats que le PHP (vecteurs partagés)', () => {
  vectors.lineAmount.forEach((v) => assert.equal(P.lineAmount(v.qtyMilli, v.unitMinor), v.expected));
  vectors.marginBp.forEach((v) => assert.equal(P.marginBp(v.cost, v.price), v.expected));
  vectors.priceForMargin.forEach((v) => assert.equal(P.priceForMargin(v.cost, v.marginBp), v.expected));
  const t = vectors.totals;
  const withVat = P.totals(t.lines, true, t.vatRateBp);
  assert.deepEqual(withVat, t.withVat);
  const withoutVat = P.totals(t.lines, false, t.vatRateBp);
  assert.equal(withoutVat.vat, t.withoutVat.vat);
  assert.equal(withoutVat.total, t.withoutVat.total);
});

test('saisie des montants et quantités (virgule ou point, espaces)', () => {
  assert.equal(P.parseAmount('1 250,5'), 125050);
  assert.equal(P.parseAmount('1250.50'), 125050);
  assert.equal(P.parseAmount(''), null);
  assert.equal(P.parseAmount('-3'), null);
  assert.equal(P.parseAmount('1,234'), null);
  assert.equal(P.parseQty('2,5'), 2500);
  assert.equal(P.parseQty('0,001'), 1);
});

test('affichage', () => {
  assert.equal(P.formatAmount(125050, ','), '1 250,50');
  assert.equal(P.formatAmount(125050, ',', false), '1250,50');
  assert.equal(P.formatAmount(125050, '.'), '1,250.50');
  assert.equal(P.formatPercent(1550, ','), '15,5');
  assert.equal(P.formatPercent(1600, ','), '16');
  assert.equal(P.formatPercent(null), '');
  assert.equal(P.priceForMargin(1000, 10000), null);
});

test('allocate : plus anciennes d\'abord, jamais au-delà du solde ni du montant', () => {
  assert.deepEqual(P.allocate(15000, [10000, 8000, 5000]), [10000, 5000, 0]);
  assert.deepEqual(P.allocate(50000, [10000, 8000]), [10000, 8000]);
  assert.deepEqual(P.allocate(0, [10000]), [0]);
  assert.deepEqual(P.allocate(-5, [10000]), [0]);
});
