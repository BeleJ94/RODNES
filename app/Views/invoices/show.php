<?php

use App\Domain\Invoicing\InvoiceStatus as S;
use App\Domain\Shared\Money;

/**
 * @var array<string, mixed> $invoice
 * @var S $status
 * @var list<array<string, mixed>> $lines
 * @var list<array<string, mixed>> $payments
 * @var list<array<string, mixed>> $creditNotes
 * @var list<array<string, mixed>> $history
 */

$title = $invoice['reference'] ?? t('invoices.draft_title');
$overdue = $status->isOpen() && $invoice['due_date'] < date('Y-m-d');
$buttons = '<a class="btn" href="' . e(url('/invoices/' . $invoice['id'] . '/print')) . '"'
    . preview_attrs('document', t('preview.title_invoice', ['ref' => $title]), t('preview.meta_client_document', ['client' => $invoice['client_name']])) . '>'
    . icon('eye') . '<span>' . e(t($status === S::Draft ? 'invoices.preview_proforma' : 'preview.document_button')) . '</span></a>';
if ($status === S::Draft && can('invoices.manage')) {
    $buttons .= '<a class="btn" href="' . e(url('/invoices/' . $invoice['id'] . '/edit')) . '">' . icon('pencil') . '<span>' . e(t('common.edit')) . '</span></a>';
}
if ($status !== S::Draft && $status !== S::Cancelled && can('invoices.manage')) {
    $buttons .= '<a class="btn" href="' . e(url('/invoices/' . $invoice['id'] . '/credit-notes/new')) . '">' . icon('receipt-text') . '<span>' . e(t('credit_notes.new')) . '</span></a>';
}
if ($status->isOpen() && can('payments.manage')) {
    $buttons .= '<a class="btn btn--primary" href="' . e(url('/payments/new', ['client_id' => $invoice['client_id'], 'invoice_id' => $invoice['id']])) . '">' . icon('wallet') . '<span>' . e(t('payments.record')) . '</span></a>';
}
?>
<?= partial('partials/page-header', [
    'icon' => 'receipt',
    'title' => $title,
    'meta' => badge($status->tone(), t($status->labelKey())) . ($overdue ? ' <span class="chip chip--bad">' . e(t('invoices.overdue')) . '</span>' : '')
        . ' · <a href="' . e(url('/clients/' . $invoice['client_id'])) . '">' . e($invoice['client_name']) . '</a>'
        . ' · <a href="' . e(url('/client-orders/' . $invoice['client_order_id'])) . '">' . e($invoice['order_reference']) . '</a> (' . e(t('client_orders.their_po', ['ref' => $invoice['client_po_number']])) . ')',
    'actions' => $buttons,
]) ?>
<?= doc_chain('client_invoice', (int) $invoice['id']) ?>

<?php if ($status === S::Cancelled): ?>
  <div class="alert alert--info"><?= icon('ban') ?><span><?= e(t('invoices.cancelled_info', ['reason' => $invoice['cancelled_reason'], 'date' => format_datetime($invoice['cancelled_at'])])) ?></span></div>
<?php elseif ($status === S::Draft): ?>
  <div class="alert alert--info"><?= icon('info') ?><span><?= e(t('invoices.draft_info')) ?></span></div>
<?php endif; ?>

<section class="vitals">
  <div class="vital"><?= icon('banknote') ?><span class="vital__label"><?= e(t('quotations.total.subtotal')) ?></span><span class="vital__value"><?= e(format_money((int) $invoice['subtotal'], 'USD')) ?></span></div>
  <div class="vital"><?= icon('receipt') ?><span class="vital__label"><?= e(t('quotations.total.total')) ?></span><span class="vital__value"><?= e(format_money((int) $invoice['total'], 'USD')) ?></span></div>
  <div class="vital"><?= icon('wallet') ?><span class="vital__label"><?= e(t('invoices.paid')) ?></span><span class="vital__value"><?= e(format_money((int) $invoice['paid'], 'USD')) ?><?php if ((int) $invoice['credited'] > 0): ?> <span class="muted"><?= e(t('credit_notes.plus_credited', ['amount' => format_money((int) $invoice['credited'], 'USD')])) ?></span><?php endif; ?></span></div>
  <div class="vital<?= $invoice['balance'] > 0 ? ($overdue ? ' vital--alert' : ' vital--warn') : '' ?>"><?= icon('scale') ?><span class="vital__label"><?= e(t('invoices.col.balance')) ?></span><span class="vital__value"><?= e(format_money((int) $invoice['balance'], 'USD')) ?></span></div>
  <div class="vital<?= $overdue ? ' vital--alert' : '' ?>"><?= icon('calendar-clock') ?><span class="vital__label"><?= e(t('invoices.col.due_date')) ?></span><span class="vital__value"><?= $invoice['due_date'] ? e(format_date($invoice['due_date'])) : e(t('invoices.due_after', ['n' => $invoice['payment_terms_days']])) ?></span></div>
</section>

<section class="card section-gap">
  <div class="card__title"><h2><?= icon('list', 'title-icon') ?><?= e(t('invoices.section_lines')) ?></h2></div>
  <table class="table" data-dt="compact">
    <thead><tr>
      <th scope="col">#</th>
      <th scope="col"><?= e(t('rfqs.line.description')) ?></th>
      <th scope="col" class="num"><?= e(t('rfqs.line.quantity')) ?></th>
      <th scope="col" class="num"><?= e(t('quotations.line.price')) ?></th>
      <th scope="col" class="num"><?= e(t('quotations.line.total')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $l): ?>
      <tr>
        <td class="muted"><?= (int) $l['line_no'] ?></td>
        <td><?= e($l['description']) ?><br><span class="muted"><?= e(t('invoices.from_order_line', ['n' => $l['order_line_no']])) ?></span></td>
        <td class="num nowrap"><?= e(format_qty((int) $l['quantity_milli'])) ?> <span class="muted"><?= e(unit_label($l['unit'])) ?></span></td>
        <td class="num nowrap"><?= e(format_money((int) $l['unit_price_minor'])) ?></td>
        <td class="num nowrap"><strong><?= e(format_money(Money::lineAmount((int) $l['quantity_milli'], (int) $l['unit_price_minor']))) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><th colspan="4" scope="row" class="num"><?= e(t('quotations.total.subtotal')) ?></th><td class="num nowrap"><?= e(format_money((int) $invoice['subtotal'], 'USD')) ?></td></tr>
      <tr><th colspan="4" scope="row" class="num"><?= e($invoice['vat_applicable'] ? t('quotations.total.vat_rate', ['rate' => format_percent((int) $invoice['vat_rate_bp'])]) : t('quotations.total.vat_exempt')) ?></th><td class="num nowrap"><?= e(format_money((int) $invoice['vat'], 'USD')) ?></td></tr>
      <tr class="total-row"><th colspan="4" scope="row" class="num"><?= e(t('quotations.total.total')) ?></th><td class="num nowrap"><?= e(format_money((int) $invoice['total'], 'USD')) ?></td></tr>
    </tfoot>
  </table>
  <?php if ($invoice['notes']): ?><p class="note"><?= nl2br(e($invoice['notes'])) ?></p><?php endif; ?>
</section>

<?php if ($creditNotes !== []): ?>
<section class="card section-gap" id="credit-notes">
  <div class="card__title"><h2><?= icon('receipt-text', 'title-icon') ?><?= e(t('credit_notes.title')) ?></h2></div>
  <?= partial('credit_notes/_table', ['rows' => $creditNotes, 'compact' => true, 'withClient' => false]) ?>
</section>
<?php endif; ?>

<?php if ($status !== S::Draft): ?>
<section class="card section-gap" id="payments">
  <div class="card__title"><h2><?= icon('wallet', 'title-icon') ?><?= e(t('payments.title')) ?></h2></div>
  <?php if ($payments === []): ?>
    <p class="muted"><?= e(t('invoices.no_payment')) ?></p>
  <?php else: ?>
    <table class="table" data-dt="compact">
      <thead><tr>
        <th scope="col"><?= e(t('payments.col.reference')) ?></th>
        <th scope="col"><?= e(t('payments.col.received_on')) ?></th>
        <th scope="col"><?= e(t('payments.col.method')) ?></th>
        <th scope="col" class="num"><?= e(t('payments.col.allocated')) ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($payments as $p): ?>
        <tr class="row-link" tabindex="0" data-href="<?= e(url('/payments/' . $p['id'])) ?>">
          <td><strong><?= e($p['reference']) ?></strong><?php if ($p['bank_reference']): ?><br><span class="muted"><?= e($p['bank_reference']) ?></span><?php endif; ?></td>
          <td class="nowrap" data-order="<?= e($p['received_on']) ?>"><?= e(format_date($p['received_on'])) ?></td>
          <td><?= e(t('payment_method.' . $p['method'])) ?></td>
          <td class="num nowrap" data-order="<?= (int) $p['allocated'] ?>"><?= e(format_money((int) $p['allocated'], 'USD')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php endif; ?>

<div class="grid grid--main-side section-gap">
  <?= partial('partials/history', ['history' => $history, 'statusPrefix' => 'invoice_status.']) ?>
  <?php if (can('invoices.manage') && ($status === S::Draft || ($status === S::Issued && (int) $invoice['paid'] === 0 && $creditNotes === []))): ?>
  <section class="card">
    <?php if ($status === S::Draft): ?>
      <div class="card__title"><h2><?= icon('stamp', 'title-icon') ?><?= e(t('invoices.issue_title')) ?></h2></div>
      <form method="post" action="<?= e(url('/invoices/' . $invoice['id'] . '/issue')) ?>" class="form" data-confirm="<?= e(t('invoices.confirm_issue')) ?>">
        <?= csrf_field() ?>
        <?= form_input('issue_date', t('invoices.col.issue_date'), date('Y-m-d'), ['type' => 'date', 'required' => true, 'hint' => t('invoices.issue_hint', ['n' => $invoice['payment_terms_days']])]) ?>
        <button type="submit" class="btn btn--primary"><?= icon('stamp') ?><span><?= e(t('invoices.issue')) ?></span></button>
      </form>
      <form method="post" action="<?= e(url('/invoices/' . $invoice['id'] . '/delete')) ?>" class="form section-gap" data-confirm="<?= e(t('invoices.confirm_delete')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--ghost"><?= icon('trash-2') ?><span><?= e(t('invoices.delete_draft')) ?></span></button>
      </form>
    <?php else: ?>
      <div class="card__title"><h2><?= icon('ban', 'title-icon title-icon--bad') ?><?= e(t('invoices.cancel_title')) ?></h2></div>
      <form method="post" action="<?= e(url('/invoices/' . $invoice['id'] . '/cancel')) ?>" class="form" data-confirm="<?= e(t('invoices.confirm_cancel')) ?>">
        <?= csrf_field() ?>
        <div class="field"><label for="cancel-reason" class="required"><?= e(t('rfqs.close_reason')) ?></label><input id="cancel-reason" name="reason" maxlength="255" required></div>
        <p class="hint"><?= e(t('invoices.cancel_hint')) ?></p>
        <button type="submit" class="btn btn--danger"><?= icon('ban') ?><span><?= e(t('invoices.cancel_title')) ?></span></button>
      </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
