<?php

use App\Domain\Finance\LandedCost;
use App\Domain\Shared\Fx;

/**
 * Facture de frais logistiques : transitaire, douane, frontière… réparties par expédition.
 *
 * @var list<array{id: int, label: string}> $suppliers
 * @var list<string> $currencies
 * @var array<string, int> $rates dernier taux par devise
 * @var list<array<string, mixed>> $shipments expéditions non annulées
 * @var int|null $shipmentId expédition présélectionnée
 */

$oldRows = old_rows('costs') ?? [];
$typeOptions = '';
$shipmentOptions = static function (string $selected) use ($shipments): string {
    $html = '<option value="">' . e(t('logistics.choose_shipment')) . '</option>';
    foreach ($shipments as $s) {
        $html .= '<option value="' . (int) $s['id'] . '"' . ((string) $s['id'] === $selected ? ' selected' : '') . '>'
            . e($s['reference'] . ' · ' . $s['client_name'] . ($s['carrier_name'] ? ' · ' . $s['carrier_name'] : '') . ' · ' . t('shipment_status.' . $s['status'])) . '</option>';
    }
    return $html;
};
$rateHint = [];
foreach ($currencies as $code) {
    if ($code !== Fx::USD && isset($rates[$code])) {
        $rateHint[] = $code . ' ' . format_rate($rates[$code]);
    }
}
?>
<?= partial('partials/page-header', ['icon' => 'truck', 'title' => $title, 'meta' => e(t('logistics.form_meta'))]) ?>
<form method="post" action="<?= e(url('/supplier-invoices/logistics')) ?>" class="card form form--sections" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <fieldset class="form-section">
    <legend><?= e(t('supplier_invoices.section_header')) ?></legend>
    <div class="form-grid">
      <?= form_select('supplier_id', t('logistics.supplier'), array_column($suppliers, 'label', 'id'), '', ['required' => true, 'placeholder' => t('supplier_payments.choose_supplier'), 'hint' => t('logistics.supplier_hint')]) ?>
      <?= form_input('supplier_invoice_number', t('supplier_invoices.supplier_invoice_number'), '', ['required' => true, 'maxlength' => 64, 'hint' => t('supplier_invoices.number_hint')]) ?>
      <?= form_input('invoice_date', t('supplier_invoices.col.invoice_date'), date('Y-m-d'), ['type' => 'date', 'required' => true, 'hint' => t('logistics.due_hint')]) ?>
      <?= form_select('currency', t('supplier_payments.currency'), array_combine($currencies, $currencies), Fx::USD, ['required' => true]) ?>
      <?= form_input('fx_rate', t('logistics.fx_rate'), '1', ['required' => true, 'attrs' => ' inputmode="decimal"', 'hint' => t('logistics.fx_hint', ['rates' => implode(' · ', $rateHint) ?: '—'])]) ?>
      <?= form_input('vat', t('logistics.vat'), '', ['attrs' => ' inputmode="decimal"', 'hint' => t('supplier_invoices.vat_hint')]) ?>
      <div class="field">
        <label for="f-invoice_file"><?= e(t('supplier_invoices.file')) ?></label>
        <input type="file" id="f-invoice_file" name="invoice_file" accept="image/jpeg,image/png,image/webp,application/pdf"<?= invalid('invoice_file') ?>>
        <p class="hint"><?= e(t('supplier_invoices.file_hint')) ?></p>
        <?= field_error('invoice_file') ?>
      </div>
    </div>
  </fieldset>
  <fieldset class="form-section">
    <legend><?= e(t('logistics.section_lines')) ?></legend>
    <?= field_error('costs') ?>
    <div class="table-scroll">
      <table class="table lines-table" data-no-dt>
        <thead><tr>
          <th scope="col">#</th>
          <th scope="col" class="w-40"><?= e(t('logistics.col.shipment')) ?></th>
          <th scope="col"><?= e(t('logistics.col.type')) ?></th>
          <th scope="col"><?= e(t('rfqs.line.description')) ?></th>
          <th scope="col" class="w-qty"><?= e(t('logistics.col.amount')) ?></th>
        </tr></thead>
        <tbody>
        <?php for ($i = 0; $i < 6; $i++):
            $row = $oldRows[$i] ?? [];
            $selectedShipment = (string) ($row['shipment_id'] ?? ($i === 0 && $shipmentId ? $shipmentId : ''));
            $selectedType = (string) ($row['cost_type'] ?? ($i === 0 ? 'transport' : '')); ?>
          <tr>
            <td class="muted"><?= $i + 1 ?></td>
            <td><select name="costs[<?= $i ?>][shipment_id]" aria-label="<?= e(t('logistics.col.shipment') . ' ' . ($i + 1)) ?>"><?= $shipmentOptions($selectedShipment) ?></select></td>
            <td><select name="costs[<?= $i ?>][cost_type]" aria-label="<?= e(t('logistics.col.type') . ' ' . ($i + 1)) ?>">
              <?php foreach (LandedCost::TYPES as $type): ?><option value="<?= e($type) ?>"<?= $selectedType === $type ? ' selected' : '' ?>><?= e(t('cost_type.' . $type)) ?></option><?php endforeach; ?>
            </select></td>
            <td><input name="costs[<?= $i ?>][description]" maxlength="255" value="<?= e($row['description'] ?? '') ?>" aria-label="<?= e(t('rfqs.line.description') . ' ' . ($i + 1)) ?>"></td>
            <td><input name="costs[<?= $i ?>][amount]" value="<?= e($row['amount'] ?? '') ?>" inputmode="decimal" class="num" aria-label="<?= e(t('logistics.col.amount') . ' ' . ($i + 1)) ?>"></td>
          </tr>
        <?php endfor; ?>
        </tbody>
      </table>
    </div>
    <p class="hint"><?= e(t('logistics.lines_hint')) ?></p>
  </fieldset>
  <fieldset class="form-section">
    <legend><?= e(t('invoices.notes')) ?></legend>
    <div class="form-grid"><?= form_textarea('notes', t('invoices.notes'), '', ['span' => true, 'maxlength' => 2000]) ?></div>
  </fieldset>
  <?= form_actions(url('/supplier-invoices'), t('logistics.record')) ?>
</form>
