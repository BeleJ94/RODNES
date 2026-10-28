/* ETHAN — fonctions pures partagées (navigateur + tests Node, sans dépendance). */
(function (root, factory) {
  'use strict';
  const api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    root.EthCore = api;
  }
}(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  const HTML_ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' };
  const has = (obj, key) => obj != null && Object.prototype.hasOwnProperty.call(obj, key);

  /** Toute donnée insérée en HTML passe par ici. */
  function escapeHtml(value) {
    if (value === null || value === undefined) {
      return '';
    }
    return String(value).replace(/[&<>"'`]/g, (c) => HTML_ESCAPES[c]);
  }

  function interpolate(template, params) {
    return String(template).replace(/\{(\w+)\}/g, (match, key) => (has(params, key) ? String(params[key]) : match));
  }

  /** Traducteur sur le catalogue filtré envoyé par le serveur (clé absente → clé affichée). */
  function translator(catalog) {
    return function t(key, params) {
      return interpolate(has(catalog, key) ? catalog[key] : key, params);
    };
  }

  /** Remplace les jetons {champ} d'une URL de ligne ; valeurs encodées (pas d'injection de chemin). */
  function fillUrl(template, row) {
    return String(template).replace(/\{(\w+)\}/g, (match, key) => encodeURIComponent(has(row, key) && row[key] != null ? row[key] : ''));
  }

  /** « 2026-10-26 08:30:00 » → « 26/10/26 08:30 » (FR et EN-GB) : heure murale conservée, sans conversion de fuseau. */
  function formatDateTime(value) {
    const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(value || ''));
    if (!m) {
      return value ? String(value) : '';
    }
    return m[3] + '/' + m[2] + '/' + m[1].slice(2) + ' ' + m[4] + ':' + m[5];
  }

  function countActiveFilters(values) {
    return values.filter((v) => v !== '' && v !== null && v !== undefined).length;
  }

  /** Ajoute (ou remplace) un paramètre de requête en conservant le fragment : « /x?a=1#b » + embed=1. */
  function withQuery(url, key, value) {
    const [beforeHash, hash] = String(url).split('#', 2);
    const [path, query] = beforeHash.split('?', 2);
    const params = (query || '').split('&').filter((p) => p !== '' && p.split('=')[0] !== key);
    params.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
    return path + '?' + params.join('&') + (hash !== undefined ? '#' + hash : '');
  }

  /** Seuls les types connus ouvrent la fenêtre d'aperçu (le reste suit le lien normalement). */
  function previewKind(value) {
    return ['image', 'pdf', 'document'].indexOf(value) >= 0 ? value : null;
  }

  return { escapeHtml, interpolate, translator, fillUrl, formatDateTime, countActiveFilters, withQuery, previewKind };
}));
