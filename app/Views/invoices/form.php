<?php

use App\Domain\Shared\Money;

/**
 * Création (lignes de PO client facturables) ou modification d'un brouillon (lignes de facture).
 *
 * @var array<string, mixed> $order
 * @var array<string, mixed>|null $invoice
 * @var list<array<string, mixed>> $lines
 * @var string $action
 * @var string $cancel
 */

$editing = $invoice !== null;
$oldRows = old_rows('lines');
$hasInvoiceable = $editing || array_sum(array_column($lines, 'invoiceable_milli')) > 0;
?>
<?= partial('partials/page-header', [
    'icon' => 'receipt',
    'title' => $title,
    'meta' => '<a href="' . e(url('/client-orders/' . $order['id'])) . '">' . e($order['reference']) . '</a> · ' . e($order['client_name'])
        . ' · ' . e(t('client_orders.their_po', ['ref' => $order['client_po_number']])),
]) ?>
<form method="post" action="<?= e($action) ?>" class="card form form--sections">
  <?= csrf_field() ?>
  <fieldset class="form-section">
    <legend><?= e(t('invoices.section_lines')) ?></legend>
    <?= field_error('lines') ?>
    <?php if (!$hasInvoiceable): ?>
      <div class="empty-state"><?= icon('package-search') ?><p><?= e(t('invoices.nothing_delivered')) ?></p></div>
    <?php else: ?>
    <div class="table-scroll">
      <table class="table lines-table" data-no-dt>
        <thead><tr>
          <th scope="col">#</th>
          <th scope="col" class="w-40"><?= e(t('rfqs.line.description')) ?></th>
          <?php if (!$editing): ?>
            <th scope="col" class="num"><?= e(t('invoices.line.ordered')) ?></th>
            <th scope="col" class="num"><?= e(t('invoices.line.delivered')) ?></th>
            <th scope="col" class="num"><?= e(t('invoices.line.invoiced')) ?></th>
          <?php endif; ?>
          <th scope="col" class="num"><?= e(t('invoices.line.invoiceable')) ?></th>
          <th scope="col" class="w-qty"><?= e(t('invoices.line.to_invoice')) ?></th>
          <th scope="col" class="num"><?= e(t('quotations.line.price')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($lines as $line):
            $id = (int) $line['id'];
            $invoiceable = (int) $line['invoiceable_milli'];
            $default = $editing ? (int) $line['quantity_milli'] : $invoiceable;
            $value = $oldRows[$id]['quantity'] ?? ($default > 0 ? input_qty($default) : ''); ?>
          <tr<?= !$editing && $invoiceable === 0 ? ' class="is-muted"' : '' ?>>
            <td class="muted"><?= (int) ($editing ? $line['order_line_no'] : $line['line_no']) ?></td>
            <td><?= e($line['description']) ?></td>
            <?php if (!$editing): ?>
              <td class="num nowrap muted"><?= e(format_qty((int) $line['quantity_milli'])) ?></td>
              <td class="num nowrap"><?= e(format_qty((int) $line['delivered_milli'])) ?></td>
              <td class="num nowrap muted"><?= e(format_qty((int) $line['invoiced_milli'])) ?></td>
            <?php endif; ?>
            <td class="num nowrap"><strong><?= e(format_qty($invoiceable)) ?></strong> <span class="muted"><?= e(unit_label($line['unit'])) ?></span></td>
            <td><?php if ($invoiceable > 0): ?><input name="lines[<?= $id ?>][quantity]" value="<?= e($value) ?>" inputmode="decimal" class="num" aria-label="<?= e(t('invoices.line.to_invoice')) ?>"><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td class="num nowrap"><?= e(format_money((int) $line['unit_price_minor'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint"><?= e(t($editing ? 'invoices.lines_hint_edit' : 'invoices.lines_hint_create')) ?></p>
    <?php endif; ?>
  </fieldset>
  <fieldset class="form-section">
    <legend><?= e(t('invoices.section_terms')) ?></legend>
    <div class="form-grid">
      <?php if ($editing): ?>
        <?= form_check('vat_applicable', t('quotations.vat_applicable', ['rate' => format_percent((int) $invoice['vat_rate_bp'])]), (bool) $invoice['vat_applicable'], t('quotations.vat_hint')) ?>
      <?php else: ?>
        <p class="hint span-2"><?= e(t($order['vat_applicable'] ? 'invoices.vat_from_order' : 'invoices.vat_exempt_from_order', ['rate' => format_percent((int) $order['vat_rate_bp'])])) ?></p>
      <?php endif; ?>
      <?= form_textarea('notes', t('invoices.notes'), $invoice['notes'] ?? '', ['span' => true, 'maxlength' => 2000, 'hint' => t('invoices.notes_hint')]) ?>
    </div>
  </fieldset>
  <?php if ($hasInvoiceable): ?><?= form_actions($cancel, t($editing ? 'common.save' : 'invoices.create_draft')) ?><?php endif; ?>
</form>
