'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const core = require('../../public/assets/js/eth-core.js');

test('escapeHtml neutralise les caractères HTML', () => {
  assert.equal(core.escapeHtml('<img src=x onerror="a(\'b\')">`'), '&lt;img src=x onerror=&quot;a(&#39;b&#39;)&quot;&gt;&#96;');
  assert.equal(core.escapeHtml(null), '');
  assert.equal(core.escapeHtml(undefined), '');
  assert.equal(core.escapeHtml(0), '0');
});

test('fillUrl encode les valeurs de ligne', () => {
  assert.equal(core.fillUrl('/deliveries/{id}', { id: 12 }), '/deliveries/12');
  assert.equal(core.fillUrl('/x/{id}', { id: '../../admin?x=1' }), '/x/..%2F..%2Fadmin%3Fx%3D1');
  assert.equal(core.fillUrl('/x/{missing}', {}), '/x/');
});

test('translator : repli sur la clé et interpolation', () => {
  const t = core.translator({ 'filters.active': '{n} filtre(s) actif(s)' });
  assert.equal(t('filters.active', { n: 2 }), '2 filtre(s) actif(s)');
  assert.equal(t('inconnue.cle'), 'inconnue.cle');
  assert.equal(t('filters.active'), '{n} filtre(s) actif(s)');
});

test('formatDateTime conserve l\'heure murale', () => {
  assert.equal(core.formatDateTime('2026-10-26 08:30:00'), '26/10/26 08:30');
  assert.equal(core.formatDateTime(null), '');
  assert.equal(core.formatDateTime('n/a'), 'n/a');
});

test('withQuery ajoute ou remplace un paramètre en gardant le fragment', () => {
  assert.equal(core.withQuery('/quotations/5/print', 'embed', '1'), '/quotations/5/print?embed=1');
  assert.equal(core.withQuery('/x?a=1&embed=0#doc', 'embed', '1'), '/x?a=1&embed=1#doc');
});

test('previewKind n\'accepte que les types connus', () => {
  assert.equal(core.previewKind('pdf'), 'pdf');
  assert.equal(core.previewKind('document'), 'document');
  assert.equal(core.previewKind('javascript'), null);
  assert.equal(core.previewKind(undefined), null);
});

test('countActiveFilters ignore les valeurs vides', () => {
  assert.equal(core.countActiveFilters(['', 'late', null, '4']), 2);
});
