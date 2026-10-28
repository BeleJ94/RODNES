/* ETHAN — calcul des prix et marges côté navigateur (module pur, testé sous Node).
   Miroir exact de App\Domain\Shared\Money et App\Domain\Sales\Pricing : le serveur recalcule et fait foi. */
(function (root, factory) {
  'use strict';
  const api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    root.EthPricing = api;
  }
}(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  const SPACES = /[\s  ]/g;

  function clean(text) {
    return String(text == null ? '' : text).replace(SPACES, '').replace(',', '.');
  }

  /** « 1 250,5 » → 125050 centimes ; null si vide ou invalide. */
  function parseAmount(text) {
    const m = /^(\d{1,13})(?:\.(\d{1,2}))?$/.exec(clean(text));
    return m ? Number(m[1]) * 100 + Number((m[2] || '').padEnd(2, '0')) : null;
  }

  /** « 2,5 » → 2500 millièmes ; null si vide ou invalide. */
  function parseQty(text) {
    const m = /^(\d{1,12})(?:\.(\d{1,3}))?$/.exec(clean(text));
    return m ? Number(m[1]) * 1000 + Number((m[2] || '').padEnd(3, '0')) : null;
  }

  /** Division entière arrondie au plus proche (moitié : loin de zéro), comme Money::divRound. */
  function divRound(numerator, denominator) {
    const half = Math.floor(denominator / 2);
    return numerator >= 0
      ? Math.floor((numerator + half) / denominator)
      : -Math.floor((-numerator + half) / denominator);
  }

  function lineAmount(quantityMilli, unitMinor) {
    return divRound(quantityMilli * unitMinor, 1000);
  }

  function percentOf(minor, basisPoints) {
    return divRound(minor * basisPoints, 10000);
  }

  function marginBp(costMinor, priceMinor) {
    return priceMinor > 0 ? divRound((priceMinor - costMinor) * 10000, priceMinor) : null;
  }

  /** Prix atteignant au moins la marge visée : coût / (1 − marge), arrondi au centime supérieur. */
  function priceForMargin(costMinor, bp) {
    if (bp >= 10000) {
      return null;
    }
    const denominator = 10000 - bp;
    return Math.floor((costMinor * 10000 + denominator - 1) / denominator);
  }

  /** Centimes → « 1 250,50 » (affichage) ou « 1250,50 » (champ de saisie, grouping = false). */
  function formatAmount(minor, decimalSep, grouping) {
    const sep = decimalSep || ',';
    const abs = Math.abs(minor);
    let units = String(Math.floor(abs / 100));
    if (grouping !== false) {
      const group = sep === ',' ? ' ' : ',';
      units = units.replace(/\B(?=(\d{3})+(?!\d))/g, group);
    }
    return (minor < 0 ? '-' : '') + units + sep + String(abs % 100).padStart(2, '0');
  }

  /** Points de base → « 15,5 » (sans le signe %). */
  function formatPercent(bp, decimalSep) {
    if (bp === null || bp === undefined) {
      return '';
    }
    return formatAmount(bp, decimalSep || ',', false).replace(/0+$/, '').replace(/[.,]$/, '');
  }

  /**
   * @param {Array<{quantityMilli:number, unitCostMinor:number, unitPriceMinor:number}>} lines
   */
  function totals(lines, vatApplicable, vatRateBp) {
    let cost = 0;
    let subtotal = 0;
    lines.forEach((l) => {
      cost += lineAmount(l.quantityMilli, l.unitCostMinor);
      subtotal += lineAmount(l.quantityMilli, l.unitPriceMinor);
    });
    const vat = vatApplicable ? percentOf(subtotal, vatRateBp) : 0;
    return { cost, subtotal, vat, total: subtotal + vat, margin: subtotal - cost, marginBp: marginBp(cost, subtotal) };
  }

  /**
   * Répartit un montant sur des soldes, dans l'ordre donné (factures de la plus ancienne à la plus récente).
   * Même règle que Receivables::allocateOldestFirst côté serveur.
   */
  function allocate(amount, balances) {
    let left = Math.max(0, amount);
    return balances.map((b) => {
      const part = Math.min(left, Math.max(0, b));
      left -= part;
      return part;
    });
  }

  return { parseAmount, parseQty, divRound, lineAmount, percentOf, marginBp, priceForMargin, formatAmount, formatPercent, totals, allocate };
}));
