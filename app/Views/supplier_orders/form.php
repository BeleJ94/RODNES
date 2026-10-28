<?php

/**
 * Création (choix du fournisseur puis lignes restant à commander) ou modification d'un brouillon.
 *
 * @var array<string, mixed> $clientOrder
 * @var array<string, mixed>|null $supplier
 * @var list<array{id: int, label: string}> $suppliers
 * @var list<string> $currencies
 * @var list<array<string, mixed>> $lines
 * @var array<string, mixed>|null $order brouillon en modification, null en création
 */

$editing = $order !== null;
$oldRows = old_rows('lines');
$incoterms = \App\Controllers\SourcingController::INCOTERMS;
$offerCurrency = null;
foreach ($lines as $l) {
    $offerCurrency ??= $l['offer']['currency_code'] ?? null;
}
$currency = $order['currency_code'] ?? $offerCurrency ?? ($supplier['currency_code'] ?? 'USD');
?>
<?= partial('partials/page-header', [
    'icon' => 'shopping-cart',
    'title' => $title,
    'meta' => '<a href="' . e(url('/client-orders/' . $clientOrder['id'])) . '">' . e($clientOrder['reference']) . '</a> · ' . e($clientOrder['client_name'])
        . ' · ' . e(t('client_orders.their_po', ['ref' => $clientOrder['client_po_number']])),
]) ?>

<?php if (!$editing): ?>
  <form method="get" action="<?= e(url('/supplier-orders/new')) ?>" class="card form" data-autosubmit>
    <input type="hidden" name="client_order_id" value="<?= (int) $clientOrder['id'] ?>">
    <div class="form-grid">
      <div class="field">
        <label for="pick-supplier" class="required"><?= e(t('sourcing.supplier')) ?></label>
        <select id="pick-supplier" name="supplier_id" required>
          <option value=""><?= e(t('common.choose')) ?></option>
          <?php foreach ($suppliers as $s): ?>
            <option value="<?= (int) $s['id'] ?>"<?= $supplier !== null && (int) $supplier['id'] === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="hint"><?= e(t('supplier_orders.pick_hint')) ?></p>
      </div>
    </div>
  </form>
<?php endif; ?>

<?php if ($supplier !== null): ?>
<form method="post" action="<?= e($editing ? url('/supplier-orders/' . $order['id']) : url('/supplier-orders')) ?>" class="card form form--sections section-gap">
  <?= csrf_field() ?>
  <?php if (!$editing): ?>
    <input type="hidden" name="client_order_id" value="<?= (int) $clientOrder['id'] ?>">
    <input type="hidden" name="supplier_id" value="<?= (int) $supplier['id'] ?>">
  <?php endif; ?>
  <fieldset class="form-section">
    <legend><?= e(t('supplier_orders.section_header', ['supplier' => $supplier['name']])) ?></legend>
    <div class="form-grid">
      <?= form_select('currency_code', t('sourcing.currency'), array_combine($currencies, $currencies), $currency, ['required' => true]) ?>
      <?= form_select('incoterm', 'Incoterm', array_combine($incoterms, $incoterms), $order['incoterm'] ?? '', ['placeholder' => '—']) ?>
      <?= form_input('expected_ready_on', t('supplier_orders.expected_ready_on'), $order['expected_ready_on'] ?? '', ['type' => 'date', 'hint' => t('supplier_orders.expected_ready_hint')]) ?>
      <?= form_input('payment_terms_days', t('common.payment_terms'), $order['payment_terms_days'] ?? $supplier['payment_terms_days'], ['type' => 'number', 'attrs' => ' min="0" max="365"', 'hint' => t('common.days_hint')]) ?>
      <?= form_input('delivery_address', t('supplier_orders.delivery_address'), $order['delivery_address'] ?? trim($clientOrder['client_name'] . ($clientOrder['delivery_site'] ? ' — ' . $clientOrder['delivery_site'] : '')), ['maxlength' => 255, 'span' => true, 'hint' => t('supplier_orders.delivery_hint')]) ?>
      <?= form_textarea('notes', t('supplier_orders.notes'), $order['notes'] ?? '', ['span' => true, 'maxlength' => 2000, 'hint' => t('supplier_orders.notes_hint')]) ?>
    </div>
  </fieldset>
  <fieldset class="form-section">
    <legend><?= e(t('supplier_orders.section_lines')) ?></legend>
    <?= field_error('lines') ?>
    <?php if ($lines === []): ?>
      <p class="muted"><?= e(t('supplier_orders.nothing_to_order')) ?></p>
    <?php else: ?>
    <div class="table-scroll">
      <table class="table lines-table" data-no-dt>
        <thead><tr>
          <th scope="col">#</th>
          <th scope="col" class="w-40"><?= e(t('rfqs.line.description')) ?></th>
          <th scope="col" class="num"><?= e($editing ? t('supplier_orders.line.client_qty') : t('client_orders.line.to_order')) ?></th>
          <th scope="col" class="w-qty"><?= e(t('rfqs.line.quantity')) ?></th>
          <th scope="col" class="w-money"><?= e(t('supplier_orders.line.unit_price')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($lines as $line):
            $key = (int) $line['id'];
            $row = $oldRows[$key] ?? ($editing
                ? ['quantity' => input_qty((int) $line['quantity_milli']), 'price' => input_money((int) $line['unit_price_minor'])]
                : ['quantity' => input_qty((int) $line['remaining_milli']), 'price' => $line['offer'] !== null ? input_money((int) $line['offer']['unit_price_minor']) : '']);
            $n = 'lines[' . $key . ']'; ?>
          <tr>
            <td class="muted"><?= (int) ($editing ? $line['client_line_no'] : $line['line_no']) ?></td>
            <td><?= e($line['description']) ?>
              <?php if (!$editing && $line['offer'] !== null): ?><br><span class="muted"><?= e(t('supplier_orders.offer_price', ['price' => format_money((int) $line['offer']['unit_price_minor'], $line['offer']['currency_code'])])) ?></span><?php endif; ?></td>
            <td class="num nowrap muted"><?= e(format_qty((int) ($editing ? $line['client_qty_milli'] : $line['remaining_milli']))) ?> <?= e(unit_label($line['unit'])) ?></td>
            <td><input name="<?= $n ?>[quantity]" value="<?= e($row['quantity'] ?? '') ?>" inputmode="decimal" class="num" aria-label="<?= e(t('rfqs.line.quantity')) ?>"></td>
            <td><input name="<?= $n ?>[price]" value="<?= e($row['price'] ?? '') ?>" inputmode="decimal" class="num" aria-label="<?= e(t('supplier_orders.line.unit_price')) ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint"><?= e(t($editing ? 'supplier_orders.lines_hint_edit' : 'supplier_orders.lines_hint_create')) ?></p>
    <?php endif; ?>
  </fieldset>
  <?= form_actions($editing ? url('/supplier-orders/' . $order['id']) : url('/client-orders/' . $clientOrder['id'])) ?>
</form>
<?php endif; ?>
