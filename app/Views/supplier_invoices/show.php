<?php

use App\Domain\Purchasing\SupplierInvoiceStatus as S;
use App\Domain\Shared\Money;

/**
 * @var array<string, mixed> $invoice
 * @var S $status
 * @var list<array<string, mixed>> $lines
 * @var array<int, array<string, mixed>> $match lignes du PO (commandé, reçu, facturé hors cette facture) par id
 * @var list<array<string, mixed>> $payments
 * @var list<array<string, mixed>> $costLines lignes de frais (facture logistique)
 * @var list<array<string, mixed>> $history
 */

$cur = (string) $invoice['currency_code'];
$overdue = $status->isOpen() && $invoice['due_date'] < date('Y-m-d');
$byLine = [];
foreach ($invoice['variance_list'] as $v) {
    $byLine[(int) $v['line_no']][] = $v;
}
$buttons = '';
if ($invoice['file_id']) {
    $kind = preview_kind((string) $invoice['file_mime']);
    $buttons .= '<a class="btn" href="' . e(url('/files/' . $invoice['file_id'])) . '"'
        . ($kind ? preview_attrs($kind, t('supplier_invoices.file_title', ['ref' => $invoice['supplier_invoice_number']]), $invoice['supplier_name'] . ' · ' . $invoice['file_name'], url('/files/' . $invoice['file_id'], ['download' => 1])) : '')
        . '>' . icon('eye') . '<span>' . e(t('supplier_invoices.view_file')) . '</span></a>';
}
if ($status->isPayable() && can('supplier_payments.manage')) {
    $buttons .= '<a class="btn btn--primary" href="' . e(url('/supplier-payments/new', ['supplier_id' => $invoice['supplier_id'], 'currency' => $cur, 'invoice_id' => $invoice['id']])) . '">' . icon('send-to-back') . '<span>' . e(t('supplier_payments.pay')) . '</span></a>';
}
?>
<?= partial('partials/page-header', [
    'icon' => 'file-input',
    'title' => $invoice['reference'],
    'meta' => badge($status->tone(), t($status->labelKey())) . ($overdue ? ' <span class="chip chip--bad">' . e(t('invoices.overdue')) . '</span>' : '')
        . ' · ' . e(t('supplier_invoices.their_number', ['ref' => $invoice['supplier_invoice_number']]))
        . ' · <a href="' . e(url('/suppliers/' . $invoice['supplier_id'])) . '">' . e($invoice['supplier_name']) . '</a>'
        . ($invoice['kind'] === 'logistics'
            ? ' · <span class="chip">' . e(t('logistics.kind')) . '</span>'
            : ' · <a href="' . e(url('/supplier-orders/' . $invoice['supplier_order_id'])) . '">' . e($invoice['order_reference']) . '</a>'),
    'actions' => $buttons,
]) ?>
<?= doc_chain('supplier_invoice', (int) $invoice['id']) ?>

<?php if ($status === S::Cancelled): ?>
  <div class="alert alert--info"><?= icon('ban') ?><span><?= e(t('invoices.cancelled_info', ['reason' => $invoice['cancelled_reason'], 'date' => format_datetime($invoice['cancelled_at'])])) ?></span></div>
<?php elseif ($status === S::OnHold): ?>
  <div class="alert alert--error"><?= icon('file-warning') ?><span><?= e(t('supplier_invoices.on_hold_info', ['n' => count($invoice['variance_list'])])) ?></span></div>
<?php elseif ($invoice['approval_note']): ?>
  <div class="alert alert--info"><?= icon('badge-check') ?><span><?= e(t('supplier_invoices.approved_info', ['name' => $invoice['approved_by_name'] ?? '—', 'date' => format_datetime($invoice['approved_at']), 'note' => $invoice['approval_note']])) ?></span></div>
<?php else: ?>
  <div class="alert alert--success"><?= icon('circle-check') ?><span><?= e(t('supplier_invoices.matched_info')) ?></span></div>
<?php endif; ?>

<section class="vitals">
  <div class="vital"><?= icon('banknote') ?><span class="vital__label"><?= e(t('quotations.total.total')) ?></span><span class="vital__value"><?= e(format_money((int) $invoice['total_minor'], $cur)) ?></span></div>
  <div class="vital"><?= icon('coins') ?><span class="vital__label"><?= e(t('supplier_invoices.usd_value')) ?></span><span class="vital__value"><?= e(format_money((int) $invoice['total_usd'], 'USD')) ?><?php if ($cur !== 'USD'): ?> <span class="muted">@ <?= e(format_rate((int) $invoice['fx_units_per_usd_micro'])) ?></span><?php endif; ?></span></div>
  <div class="vital<?= $invoice['balance'] > 0 && $status !== S::OnHold ? ($overdue ? ' vital--alert' : ' vital--warn') : '' ?>"><?= icon('scale') ?><span class="vital__label"><?= e(t('invoices.col.balance')) ?></span><span class="vital__value"><?= e(format_money((int) $invoice['balance'], $cur)) ?></span></div>
  <div class="vital<?= $overdue ? ' vital--alert' : '' ?>"><?= icon('calendar-clock') ?><span class="vital__label"><?= e(t('invoices.col.due_date')) ?></span><span class="vital__value"><?= e(format_date($invoice['due_date'])) ?></span></div>
</section>

<?php if ($invoice['kind'] === 'logistics'): ?>
<section class="card section-gap">
  <div class="card__title"><h2><?= icon('truck', 'title-icon') ?><?= e(t('logistics.section_lines')) ?></h2></div>
  <table class="table" data-dt="compact">
    <thead><tr>
      <th scope="col">#</th>
      <th scope="col"><?= e(t('logistics.col.shipment')) ?></th>
      <th scope="col"><?= e(t('logistics.col.type')) ?></th>
      <th scope="col"><?= e(t('rfqs.line.description')) ?></th>
      <th scope="col" class="num"><?= e(t('logistics.col.amount')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($costLines as $c): ?>
      <tr class="row-link" tabindex="0" data-href="<?= e(url('/shipments/' . $c['shipment_id'])) ?>">
        <td class="muted"><?= (int) $c['line_no'] ?></td>
        <td class="nowrap"><strong><?= e($c['shipment_reference']) ?></strong><br><span class="muted"><?= e($c['client_name']) ?></span></td>
        <td><?= e(t('cost_type.' . $c['cost_type'])) ?></td>
        <td><?= $c['description'] ? e($c['description']) : '<span class="muted">—</span>' ?></td>
        <td class="num nowrap"><?= e(format_money((int) $c['amount_minor'], $cur)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><th colspan="4" scope="row" class="num"><?= e(t('quotations.total.subtotal')) ?></th><td class="num nowrap"><?= e(format_money((int) $invoice['subtotal_minor'], $cur)) ?></td></tr>
      <tr><th colspan="4" scope="row" class="num"><?= e(t('supplier_invoices.vat', ['cur' => $cur])) ?></th><td class="num nowrap"><?= e(format_money((int) $invoice['vat_minor'], $cur)) ?></td></tr>
      <tr class="total-row"><th colspan="4" scope="row" class="num"><?= e(t('quotations.total.total')) ?></th><td class="num nowrap"><?= e(format_money((int) $invoice['total_minor'], $cur)) ?></td></tr>
    </tfoot>
  </table>
  <p class="hint"><?= e(t('logistics.allocation_hint')) ?></p>
  <?php if ($invoice['notes']): ?><p class="note"><?= nl2br(e($invoice['notes'])) ?></p><?php endif; ?>
</section>
<?php else: ?>
<section class="card section-gap">
  <div class="card__title"><h2><?= icon('git-compare', 'title-icon') ?><?= e(t('supplier_invoices.section_match')) ?></h2></div>
  <table class="table" data-dt="compact">
    <thead><tr>
      <th scope="col">#</th>
      <th scope="col"><?= e(t('rfqs.line.description')) ?></th>
      <th scope="col" class="num"><?= e(t('invoices.line.ordered')) ?></th>
      <th scope="col" class="num"><?= e(t('supplier_invoices.line.received')) ?></th>
      <th scope="col" class="num"><?= e(t('supplier_invoices.line.quantity')) ?></th>
      <th scope="col" class="num"><?= e(t('supplier_invoices.line.po_price', ['cur' => $cur])) ?></th>
      <th scope="col" class="num"><?= e(t('supplier_invoices.line.price', ['cur' => $cur])) ?></th>
      <th scope="col" class="num"><?= e(t('quotations.line.total')) ?></th>
      <th scope="col"><?= e(t('supplier_invoices.col.match')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $l):
        $po = $match[(int) $l['supplier_order_line_id']] ?? null;
        $issues = $status === S::Cancelled ? [] : ($byLine[(int) $l['po_line_no']] ?? []); ?>
      <tr>
        <td class="muted"><?= (int) $l['po_line_no'] ?></td>
        <td><?= e($l['description']) ?></td>
        <td class="num nowrap muted"><?= e(format_qty((int) $l['ordered_milli'])) ?></td>
        <td class="num nowrap"><?= $po ? e(format_qty((int) $po['received_milli'])) : '—' ?></td>
        <td class="num nowrap"><strong><?= e(format_qty((int) $l['quantity_milli'])) ?></strong> <span class="muted"><?= e(unit_label($l['unit'])) ?></span></td>
        <td class="num nowrap muted"><?= e(format_money((int) $l['po_unit_price_minor'])) ?></td>
        <td class="num nowrap<?= (int) $l['unit_price_minor'] > (int) $l['po_unit_price_minor'] ? ' warn-text' : '' ?>"><?= e(format_money((int) $l['unit_price_minor'])) ?></td>
        <td class="num nowrap"><?= e(format_money(Money::lineAmount((int) $l['quantity_milli'], (int) $l['unit_price_minor']))) ?></td>
        <td><?php if ($issues === []): ?><span class="chip chip--ok"><?= e(t('supplier_invoices.match_ok')) ?></span><?php else: foreach ($issues as $v): ?>
          <span class="chip chip--bad"><?= e(t('supplier_invoices.variance.' . $v['type'], [
              'expected' => $v['type'] === 'price_above' ? format_money((int) $v['expected']) : format_qty((int) $v['expected']),
              'actual' => $v['type'] === 'price_above' ? format_money((int) $v['actual']) : format_qty((int) $v['actual']),
          ])) ?></span>
        <?php endforeach; endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><th colspan="7" scope="row" class="num"><?= e(t('quotations.total.subtotal')) ?></th><td class="num nowrap"><?= e(format_money((int) $invoice['subtotal_minor'], $cur)) ?></td><td></td></tr>
      <tr><th colspan="7" scope="row" class="num"><?= e(t('supplier_invoices.vat', ['cur' => $cur])) ?></th><td class="num nowrap"><?= e(format_money((int) $invoice['vat_minor'], $cur)) ?></td><td></td></tr>
      <tr class="total-row"><th colspan="7" scope="row" class="num"><?= e(t('quotations.total.total')) ?></th><td class="num nowrap"><?= e(format_money((int) $invoice['total_minor'], $cur)) ?></td><td></td></tr>
    </tfoot>
  </table>
  <?php if ($invoice['notes']): ?><p class="note"><?= nl2br(e($invoice['notes'])) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($payments !== []): ?>
<section class="card section-gap">
  <div class="card__title"><h2><?= icon('send-to-back', 'title-icon') ?><?= e(t('supplier_payments.title')) ?></h2></div>
  <table class="table" data-dt="compact">
    <thead><tr>
      <th scope="col"><?= e(t('payments.col.reference')) ?></th>
      <th scope="col"><?= e(t('supplier_payments.col.paid_on')) ?></th>
      <th scope="col"><?= e(t('payments.col.method')) ?></th>
      <th scope="col" class="num"><?= e(t('payments.col.allocated')) ?></th>
      <th scope="col" class="num"><?= e(t('supplier_payments.col.rate')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
      <tr class="row-link" tabindex="0" data-href="<?= e(url('/supplier-payments/' . $p['id'])) ?>">
        <td><strong><?= e($p['reference']) ?></strong><?php if ($p['bank_reference']): ?><br><span class="muted"><?= e($p['bank_reference']) ?></span><?php endif; ?></td>
        <td class="nowrap" data-order="<?= e($p['paid_on']) ?>"><?= e(format_date($p['paid_on'])) ?></td>
        <td><?= e(t('payment_method.' . $p['method'])) ?></td>
        <td class="num nowrap"><?= e(format_money((int) $p['allocated'], $cur)) ?></td>
        <td class="num nowrap"><?= e(format_rate((int) $p['fx_units_per_usd_micro'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<div class="grid grid--main-side section-gap">
  <?= partial('partials/history', ['history' => $history, 'statusPrefix' => 'supplier_invoice_status.']) ?>
  <?php if ($status === S::OnHold || ($status->canCancel() && can('supplier_invoices.manage'))): ?>
  <section class="card">
    <?php if ($status === S::OnHold && can('supplier_invoices.manage')): ?>
      <div class="card__title"><h2><?= icon('refresh-cw', 'title-icon') ?><?= e(t('supplier_invoices.recheck_title')) ?></h2></div>
      <form method="post" action="<?= e(url('/supplier-invoices/' . $invoice['id'] . '/recheck')) ?>" class="form">
        <?= csrf_field() ?>
        <p class="hint"><?= e(t('supplier_invoices.recheck_hint')) ?></p>
        <button type="submit" class="btn"><?= icon('refresh-cw') ?><span><?= e(t('supplier_invoices.recheck')) ?></span></button>
      </form>
    <?php endif; ?>
    <?php if ($status === S::OnHold && can('supplier_invoices.approve')): ?>
      <div class="card__title section-gap"><h2><?= icon('badge-check', 'title-icon') ?><?= e(t('supplier_invoices.approve_title')) ?></h2></div>
      <form method="post" action="<?= e(url('/supplier-invoices/' . $invoice['id'] . '/approve')) ?>" class="form" data-confirm="<?= e(t('supplier_invoices.confirm_approve')) ?>">
        <?= csrf_field() ?>
        <div class="field"><label for="approve-note" class="required"><?= e(t('supplier_invoices.approval_note')) ?></label><input id="approve-note" name="note" maxlength="255" required></div>
        <p class="hint"><?= e(t('supplier_invoices.approve_hint')) ?></p>
        <button type="submit" class="btn btn--primary"><?= icon('badge-check') ?><span><?= e(t('supplier_invoices.approve')) ?></span></button>
      </form>
    <?php endif; ?>
    <?php if ($status->canCancel() && can('supplier_invoices.manage')): ?>
      <div class="card__title section-gap"><h2><?= icon('ban', 'title-icon title-icon--bad') ?><?= e(t('invoices.cancel_title')) ?></h2></div>
      <form method="post" action="<?= e(url('/supplier-invoices/' . $invoice['id'] . '/cancel')) ?>" class="form" data-confirm="<?= e(t('invoices.confirm_cancel')) ?>">
        <?= csrf_field() ?>
        <div class="field"><label for="cancel-reason" class="required"><?= e(t('rfqs.close_reason')) ?></label><input id="cancel-reason" name="reason" maxlength="255" required></div>
        <p class="hint"><?= e(t('supplier_invoices.cancel_hint')) ?></p>
        <button type="submit" class="btn btn--danger"><?= icon('ban') ?><span><?= e(t('invoices.cancel_title')) ?></span></button>
      </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
