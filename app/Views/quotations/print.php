<?php

use App\Domain\Shared\Money;

/**
 * Devis remis au client, au format « Quotation » : en-tête société (logo), cartouche (date, expiration,
 * conditions, n° de devis, identifiant client, n° de RFQ), client et livraison, articles avec disponibilité,
 * totaux, remarques, informations d'expédition, mention de certification.
 * Jamais de coût, de fournisseur ni de marge.
 *
 * @var array<string, mixed> $q
 * @var array<string, mixed> $client
 * @var array<string, mixed> $rfq
 * @var list<array<string, mixed>> $lines
 * @var array<string, int|null> $totals
 * @var array<string, string> $s paramètres société
 * @var string $logo image intégrée (data:) ou chaîne vide
 */

$issued = substr((string) ($q['sent_at'] ?? $q['created_at']), 0, 10);
$legal = implode(' · ', array_filter([
    $s['company_rccm'] !== '' ? 'RCCM ' . $s['company_rccm'] : '',
    $s['company_idnat'] !== '' ? 'Id. Nat. ' . $s['company_idnat'] : '',
    $s['company_nif'] !== '' ? 'NIF ' . $s['company_nif'] : '',
]));
$payment = t('quotations.payment_terms_text.' . ($q['payment_terms_basis'] ?? 'delivery'), ['n' => $q['payment_terms_days']]);
$certification = $s['quote_certification'] !== '' ? $s['quote_certification'] : t('quotations.doc_certification_default');
$certification = str_replace('{incoterm}', (string) ($q['incoterm'] ?: '—'), $certification);
$shipTo = trim((string) ($q['ship_to_address'] ?? '')) !== '' ? (string) $q['ship_to_address'] : trim($client['address'] . ' ' . $client['city']);
$weight = static fn (?int $milli): string => $milli !== null ? format_qty($milli) . ' kg' : '';
$shipping = array_filter([
    t('quotations.country_of_origin') => (string) ($q['country_of_origin'] ?? ''),
    t('quotations.doc.currency') => 'USD',
    t('quotations.estimated_ship_date') => format_date($q['estimated_ship_date'] ?? null),
    t('quotations.transport_mode') => ($q['transport_mode'] ?? null) ? t('quotations.transport.' . $q['transport_mode']) : '',
    t('quotations.doc.transport_terms') => trim(($q['incoterm'] ?? '') . (($q['delivery_terms'] ?? '') !== '' ? ' — ' . $q['delivery_terms'] : '')),
    t('quotations.packages_count') => ($q['packages_count'] ?? null) !== null ? (string) $q['packages_count'] : '',
    t('quotations.gross_weight') => $weight($q['gross_weight_milli'] !== null ? (int) $q['gross_weight_milli'] : null),
    t('quotations.net_weight') => $weight($q['net_weight_milli'] !== null ? (int) $q['net_weight_milli'] : null),
    t('quotations.carrier') => (string) ($q['carrier'] ?? ''),
], static fn (string $v): bool => $v !== '');
$totalQty = array_sum(array_map(static fn (array $l): int => (int) $l['quantity_milli'], $lines));
?>
<article class="doc qdoc">
  <header class="qdoc__head">
    <div class="qdoc__brand">
      <?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt="<?= e($s['company_name']) ?>" class="qdoc__logo"><?php endif; ?>
      <div>
        <p class="qdoc__company"><?= e($s['company_name']) ?></p>
        <?php if ($s['company_address'] !== ''): ?><p class="doc__muted"><?= nl2br(e($s['company_address'])) ?></p><?php endif; ?>
        <p class="doc__muted"><?= e(implode(' · ', array_filter([$s['company_phone'], $s['company_email']]))) ?></p>
      </div>
    </div>
    <div class="qdoc__title">
      <h1><?= e(t('quotations.doc.title')) ?></h1>
      <?php if ($legal !== ''): ?><p class="doc__muted"><?= e($legal) ?></p><?php endif; ?>
    </div>
  </header>

  <section class="qdoc__meta">
    <div><span><?= e(t('quotations.doc.date')) ?></span><strong><?= e(format_date($issued)) ?></strong></div>
    <div><span><?= e(t('quotations.doc.expiration')) ?></span><strong><?= e(format_date($q['valid_until'])) ?></strong></div>
    <div class="qdoc__meta-wide"><span><?= e(t('quotations.doc.payment_terms')) ?></span><strong><?= e($payment) ?></strong></div>
    <div><span><?= e(t('quotations.doc.quote_no')) ?></span><strong><?= e($q['reference']) ?><?= (int) $q['version'] > 1 ? ' v' . (int) $q['version'] : '' ?></strong></div>
    <div><span><?= e(t('quotations.doc.customer_id')) ?></span><strong><?= e(($client['customer_number'] ?? '') !== '' ? $client['customer_number'] : $client['code']) ?></strong></div>
    <div class="qdoc__meta-wide"><span><?= e(t('quotations.doc.rfq_number')) ?></span><strong><?= e($rfq['client_reference'] ?: $rfq['reference']) ?></strong></div>
  </section>

  <section class="qdoc__parties">
    <div class="qdoc__party">
      <p class="qdoc__label"><?= e(t('quotations.doc.customer')) ?></p>
      <dl>
        <div><dt><?= e(t('quotations.doc.full_name')) ?></dt><dd><strong><?= e($client['name']) ?></strong></dd></div>
        <div><dt><?= e(t('quotations.doc.address')) ?></dt><dd><?= e(trim($client['address'] . ' ' . $client['city'])) ?: '—' ?></dd></div>
        <?php if ($rfq['buyer_name'] || $client['contact_name']): ?><div><dt><?= e(t('quotations.doc.attention')) ?></dt><dd><?= e($rfq['buyer_name'] ?: $client['contact_name']) ?></dd></div><?php endif; ?>
        <div><dt><?= e(t('quotations.doc.telephone')) ?></dt><dd><?= e($client['contact_phone'] ?: '—') ?></dd></div>
        <div><dt><?= e(t('quotations.doc.bus_reg')) ?></dt><dd><?= e(implode(' · ', array_filter([$client['rccm'] ? 'RCCM ' . $client['rccm'] : '', $client['nif'] ? 'NIF ' . $client['nif'] : '']))) ?: '—' ?></dd></div>
      </dl>
    </div>
    <div class="qdoc__party">
      <p class="qdoc__label"><?= e(t('quotations.doc.ship_to')) ?></p>
      <dl>
        <div><dt><?= e(t('quotations.doc.full_name')) ?></dt><dd><strong><?= e(($q['ship_to_name'] ?? '') !== '' ? $q['ship_to_name'] : $client['name']) ?></strong></dd></div>
        <div><dt><?= e(t('quotations.doc.address')) ?></dt><dd><?= nl2br(e($shipTo)) ?: '—' ?></dd></div>
        <div><dt><?= e(t('quotations.doc.telephone')) ?></dt><dd><?= e(($q['ship_to_phone'] ?? '') ?: '—') ?></dd></div>
      </dl>
    </div>
  </section>

  <table class="doc__table qdoc__items">
    <thead><tr>
      <th><?= e(t('quotations.doc.item_no')) ?></th>
      <th><?= e(t('quotations.doc.unit')) ?></th>
      <th><?= e(t('quotations.doc.description')) ?></th>
      <th><?= e(t('quotations.doc.availability')) ?></th>
      <th class="num"><?= e(t('quotations.doc.qty')) ?></th>
      <th class="num"><?= e(t('quotations.doc.unit_value')) ?></th>
      <th class="num"><?= e(t('quotations.doc.total_value')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $l): $ref = trim(($l['manufacturer'] ?? '') . ' ' . ($l['part_number'] ?? '')); ?>
      <tr>
        <td><?= (int) $l['line_no'] ?></td>
        <td><?= e(mb_strtoupper(unit_label($l['unit']))) ?></td>
        <td><?= e($l['description']) ?><?php if ($ref !== ''): ?><br><span class="doc__muted"><?= e(t('quotations.doc.part_number', ['ref' => $ref])) ?></span><?php endif; ?></td>
        <td><?= e($l['availability'] ?? '') ?: '—' ?></td>
        <td class="num"><?= e(format_qty((int) $l['quantity_milli'])) ?></td>
        <td class="num"><?= e(format_money((int) $l['unit_price_minor'], 'USD')) ?></td>
        <td class="num"><?= e(format_money(Money::lineAmount((int) $l['quantity_milli'], (int) $l['unit_price_minor']), 'USD')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <section class="qdoc__bottom">
    <div class="qdoc__remarks">
      <p class="qdoc__label"><?= e(t('quotations.doc.remarks')) ?></p>
      <div class="qdoc__box"><?= $q['notes'] ? nl2br(e($q['notes'])) : '<span class="doc__muted">—</span>' ?></div>
    </div>
    <table class="qdoc__totals">
      <tr><th><?= e(t('quotations.doc.total_qty')) ?></th><td><?= e(format_qty($totalQty)) ?></td></tr>
      <tr><th><?= e(t('quotations.doc.subtotal')) ?></th><td><?= e(format_money($totals['subtotal'], 'USD')) ?></td></tr>
      <tr><th><?= e($q['vat_applicable'] ? t('quotations.doc.vat_rate', ['rate' => format_percent((int) $q['vat_rate_bp'])]) : t('quotations.doc.vat_exempt')) ?></th><td><?= e(format_money($totals['vat'], 'USD')) ?></td></tr>
      <tr class="qdoc__grand"><th><?= e(t('quotations.doc.total')) ?></th><td><?= e(format_money($totals['total'], 'USD')) ?></td></tr>
    </table>
  </section>

  <?php if ($shipping !== []): ?>
  <section class="qdoc__shipping">
    <p class="qdoc__label"><?= e(t('quotations.doc.shipment')) ?></p>
    <dl>
      <?php foreach ($shipping as $label => $value): ?><div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div><?php endforeach; ?>
    </dl>
  </section>
  <?php endif; ?>

  <?php if ($s['company_bank'] !== ''): ?>
  <section class="qdoc__bank">
    <p class="qdoc__label"><?= e(t('quotations.doc_bank')) ?></p>
    <p><?= nl2br(e($s['company_bank'])) ?></p>
  </section>
  <?php endif; ?>

  <p class="qdoc__certification"><?= e($certification) ?></p>
  <div class="qdoc__sign">
    <div><span><?= e(t('quotations.doc.prepared_by')) ?></span><strong><?= e($rfq['owner_name'] ?? '') ?></strong></div>
    <div><span><?= e(t('quotations.doc.signature')) ?></span></div>
  </div>

  <footer class="qdoc__foot">
    <?php if ($legal !== ''): ?><p><?= e($legal) ?></p><?php endif; ?>
    <p><?= e(implode(' · ', array_filter([$s['company_phone'], $s['company_email'], $s['company_website']]))) ?></p>
  </footer>
</article>
