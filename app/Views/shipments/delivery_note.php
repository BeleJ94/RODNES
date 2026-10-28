<?php

/**
 * Bon de livraison (Proof of Delivery) : accompagne la marchandise ; le client y porte les quantités reçues,
 * ses réserves, son nom, sa signature et son cachet. Le document signé est ensuite joint à l'expédition (POD).
 * Aucun prix.
 *
 * @var string $number n° du bon (BL-…)
 * @var array<string, mixed> $shipment
 * @var array<string, mixed> $client
 * @var list<array<string, mixed>> $lines
 * @var array<string, string> $s paramètres société
 */

$clientPos = implode(', ', array_unique(array_filter(array_map(static fn (array $l): string => (string) $l['client_po_number'], $lines))));
$orders = implode(', ', array_unique(array_map(static fn (array $l): string => (string) $l['client_order_reference'], $lines)));
?>
<?php $issued = $shipment['delivery_note_issued_at'] ?? null; ?>
<article class="doc dnote<?= $issued === null ? ' doc--watermark' : '' ?>"<?= $issued === null ? ' data-watermark="' . e(t('delivery_note.draft')) . '"' : '' ?>>
  <header class="doc__head">
    <div>
      <?= doc_logo() ?>
      <p class="doc__company"><?= e($s['company_name']) ?></p>
      <p class="doc__muted"><?= e($s['company_address']) ?></p>
      <p class="doc__muted"><?= e(implode(' · ', array_filter([$s['company_phone'], $s['company_email']]))) ?></p>
      <p class="doc__muted"><?= e(implode(' · ', array_filter([
          $s['company_rccm'] !== '' ? 'RCCM ' . $s['company_rccm'] : '',
          $s['company_idnat'] !== '' ? 'Id. Nat. ' . $s['company_idnat'] : '',
          $s['company_nif'] !== '' ? 'NIF ' . $s['company_nif'] : '',
      ]))) ?></p>
    </div>
    <div class="doc__title">
      <h1><?= e(t('delivery_note.title')) ?></h1>
      <p class="doc__muted dnote__subtitle"><?= e(t('delivery_note.subtitle')) ?></p>
      <p><strong><?= e($number) ?></strong></p>
      <p class="doc__muted"><?= e(t('quotations.doc_date', ['date' => format_date(substr((string) ($issued ?? date('Y-m-d')), 0, 10))])) ?></p>
      <?php if ($shipment['delivered_at']): ?><p class="doc__muted"><?= e(t('delivery_note.delivered_at', ['date' => format_datetime($shipment['delivered_at'])])) ?></p><?php endif; ?>
      <p class="doc__muted"><?= e(t('delivery_note.shipment', ['ref' => $shipment['reference']])) ?></p>
    </div>
  </header>

  <section class="doc__parties">
    <div>
      <p class="doc__label"><?= e(t('delivery_note.client')) ?></p>
      <p><strong><?= e($client['name']) ?></strong></p>
      <?php if (trim(($client['address'] ?? '') . ' ' . ($client['city'] ?? '')) !== ''): ?><p><?= e(trim(($client['address'] ?? '') . ' ' . ($client['city'] ?? ''))) ?></p><?php endif; ?>
      <?php if ($client['contact_name'] || $client['contact_phone']): ?><p class="doc__muted"><?= e(implode(' · ', array_filter([$client['contact_name'], $client['contact_phone']]))) ?></p><?php endif; ?>
      <?php if ($clientPos !== ''): ?><p><?= e(t('delivery_note.client_po', ['ref' => $clientPos])) ?></p><?php endif; ?>
    </div>
    <div>
      <p class="doc__label"><?= e(t('delivery_note.ship_to')) ?></p>
      <p><strong><?= e($shipment['destination'] ?: $client['name']) ?></strong></p>
      <dl class="dnote__facts">
        <div><dt><?= e(t('shipments.col.carrier')) ?></dt><dd><?= e($shipment['carrier_name'] ?: t('shipments.carrier_type.' . $shipment['carrier_type'])) ?></dd></div>
        <?php if ($shipment['tracking_reference']): ?><div><dt><?= e(t('shipments.tracking_reference')) ?></dt><dd><?= e($shipment['tracking_reference']) ?></dd></div><?php endif; ?>
        <?php if ($shipment['origin']): ?><div><dt><?= e(t('shipments.origin')) ?></dt><dd><?= e($shipment['origin']) ?></dd></div><?php endif; ?>
        <div><dt><?= e(t('delivery_note.our_orders')) ?></dt><dd><?= e($orders) ?></dd></div>
      </dl>
    </div>
  </section>

  <table class="doc__table dnote__table">
    <thead><tr>
      <th>#</th>
      <th><?= e(t('rfqs.line.description')) ?></th>
      <th><?= e(t('delivery_note.col.order')) ?></th>
      <th class="num"><?= e(t('delivery_note.col.shipped')) ?></th>
      <th class="num dnote__fill"><?= e(t('delivery_note.col.received')) ?></th>
      <th class="dnote__fill"><?= e(t('delivery_note.col.remarks')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $i => $l): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= e($l['description']) ?></td>
        <td class="doc__muted"><?= e($l['client_po_number'] ?: $l['client_order_reference']) ?></td>
        <td class="num nowrap"><?= e(format_qty((int) $l['quantity_milli'])) ?> <?= e(unit_label($l['unit'])) ?></td>
        <td class="dnote__fill"></td>
        <td class="dnote__fill"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="doc__total"><th colspan="3" class="num"><?= e(t('delivery_note.total_lines', ['n' => count($lines)])) ?></th><td class="num nowrap"><?= e(format_qty(array_sum(array_map(static fn (array $l): int => (int) $l['quantity_milli'], $lines)))) ?></td><td colspan="2"></td></tr>
    </tfoot>
  </table>

  <section class="dnote__reserves">
    <p class="doc__label"><?= e(t('delivery_note.reserves')) ?></p>
    <div class="dnote__box"></div>
    <p class="doc__muted"><?= e(t('delivery_note.reserves_hint')) ?></p>
  </section>

  <section class="dnote__sign">
    <div>
      <p class="doc__label"><?= e(t('delivery_note.delivered_by')) ?></p>
      <p class="dnote__line"><?= e(t('delivery_note.name')) ?></p>
      <p class="dnote__line"><?= e(t('delivery_note.date_time')) ?></p>
      <p class="dnote__line dnote__line--sign"><?= e(t('delivery_note.signature')) ?></p>
    </div>
    <div>
      <p class="doc__label"><?= e(t('delivery_note.received_by')) ?></p>
      <p class="dnote__line"><?= e(t('delivery_note.name_function')) ?></p>
      <p class="dnote__line"><?= e(t('delivery_note.date_time')) ?></p>
      <p class="dnote__line dnote__line--sign"><?= e(t('delivery_note.signature_stamp')) ?></p>
    </div>
  </section>

  <p class="dnote__statement"><?= e(t('delivery_note.statement', ['company' => $s['company_name'], 'ref' => $number])) ?></p>
  <footer class="doc__foot"><?= e($s['company_name']) ?> · <?= e($number) ?> · ETHAN</footer>
</article>
