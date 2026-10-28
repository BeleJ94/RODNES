'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const core = require('../../public/assets/js/eth-core.js');
const { tables } = require('../../public/assets/js/eth-tables.js');

const EVIL = '<script>alert(1)</script>';
const ctx = { locale: 'fr', t: core.translator({}) };

function evilRow() {
  const row = {};
  ['reference', 'status_label', 'status_tone', 'delivery_note_number', 'client_name', 'product_name', 'route_code',
    'origin_name', 'destination_name', 'vehicle_plate', 'trailer_plate', 'driver_name', 'driver_phone',
    'planned_departure_at', 'qty_loaded', 'qty_planned', 'unit'].forEach((k) => { row[k] = EVIL; });
  row.has_open_incident = true;
  row.is_dangerous = true;
  return row;
}

test('chaque colonne déclare data, title (clé i18n), html et text', () => {
  Object.entries(tables).forEach(([name, columns]) => {
    assert.ok(columns.length > 0, name);
    columns.forEach((c) => {
      assert.match(c.data, /^[a-z_]+$/);
      assert.match(c.title, /^tables\.[a-z_]+\.[a-z_]+$/);
      assert.equal(typeof c.html, 'function');
      assert.equal(typeof c.text, 'function');
    });
  });
});

test('aucun rendu HTML ne laisse passer de balise issue des données', () => {
  Object.values(tables).forEach((columns) => {
    columns.forEach((c) => {
      const html = c.html(evilRow(), ctx);
      assert.ok(!html.includes('<script'), c.data + ' : ' + html);
    });
  });
});

test('les lignes vides ne cassent pas le rendu', () => {
  Object.values(tables).forEach((columns) => {
    columns.forEach((c) => {
      assert.equal(typeof c.html({}, ctx), 'string');
    });
  });
});
