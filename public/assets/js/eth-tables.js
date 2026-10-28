/* ETHAN — définitions des colonnes des tableaux en mode serveur (module pur, testé sous Node).
   Chaque colonne : data (clé de tri serveur, liste blanche côté repository), title (clé i18n),
   html(row, ctx) pour l'affichage (échappé), text(row, ctx) pour les exports.
   Phase 1 : les listes sont des tableaux statiques (volume modéré) ; les tableaux serveur arriveront
   avec les volumes des phases 3 et 4 (expéditions, factures). */
(function (root, factory) {
  'use strict';
  const isNode = typeof module === 'object' && module.exports;
  const api = factory(isNode ? require('./eth-core.js') : root.EthCore);
  if (isNode) {
    module.exports = api;
  } else {
    root.EthTables = api;
  }
}(typeof self !== 'undefined' ? self : this, function (core) {
  'use strict';

  const esc = core.escapeHtml;

  function twoLines(main, sub) {
    return '<strong>' + esc(main) + '</strong>' + (sub ? '<br><span class="muted">' + esc(sub) + '</span>' : '');
  }

  function badge(tone, label) {
    return '<span class="badge badge--' + esc(tone) + '">' + esc(label) + '</span>';
  }

  const tables = {};

  return { tables, badge, twoLines };
}));
