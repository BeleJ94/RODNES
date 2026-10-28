<?php

use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Shared\Money;

/**
 * @var array<string, mixed> $note
 * @var list<array<string, mixed>> $lines
 * @var list<array<string, mixed>> $allocations
 * @var list<array<string, mixed>> $open autres factures ouvertes du client
 * @var list<array<string, mixed>> $history
 */

$buttons = '<a class="btn" href="' . e(url('/credit-notes/' . $note['id'] . '/print')) . '"'
    . preview_attrs('document', t('credit_notes.preview_title', ['ref' => $note['reference']]), t('preview.meta_client_document', ['client' => $note['client_name']])) . '>'
    . icon('eye') . '<span>' . e(t('preview.document_button')) . '</span></a>';
?>
<?= partial('partials/page-header', [
    'icon' => 'receipt-text',
    'title' => $note['reference'],
    'meta' => '<a href="' . e(url('/clients/' . $note['client_id'])) . '">' . e($note['client_name']) . '</a>'
        . ' · <a href="' . e(url('/invoices/' . $note['invoice_id'])) . '">' . e(t('credit_notes.on_invoice', ['ref' => $note['invoice_reference']])) . '</a>'
        . ' · ' . e(format_date($note['issue_date'])) . ($note['is_return'] ? ' · <span class="chip">' . e(t('credit_notes.return_chip')) . '</span>' : ''),
    'actions' => $buttons,
]) ?>
<div class="alert alert--info"><?= icon('message-square-text') ?><span><?= e(t('credit_notes.reason')) ?> : <?= e($note['reason']) ?></span></div>

<section class="vitals">
  <div class="vital"><?= icon('receipt-text') ?><span class="vital__label"><?= e(t('quotations.total.total')) ?></span><span class="vital__value"><?= e(format_money((int) $note['total_minor'], 'USD')) ?></span></div>
  <div class="vital"><?= icon('link') ?><span class="vital__label"><?= e(t('payments.col.allocated')) ?></span><span class="vital__value"><?= e(format_money((int) $note['allocated_minor'], 'USD')) ?></span></div>
  <div class="vital<?= $note['unallocated'] > 0 ? ' vital--warn' : '' ?>"><?= icon('scale') ?><span class="vital__label"><?= e(t('credit_notes.col.unallocated')) ?></span><span class="vital__value"><?= e(format_money((int) $note['unallocated'], 'USD')) ?></span></div>
</section>

<section class="card section-gap">
  <div class="card__title"><h2><?= icon('list', 'title-icon') ?><?= e(t('credit_notes.section_lines')) ?></h2></div>
  <table class="table" data-dt="compact">
    <thead><tr>
      <th scope="col">#</th>
      <th scope="col"><?= e(t('rfqs.line.description')) ?></th>
      <th scope="col" class="num"><?= e(t('credit_notes.line.quantity')) ?></th>
      <th scope="col" class="num"><?= e(t('credit_notes.line.price')) ?></th>
      <th scope="col" class="num"><?= e(t('quotations.line.total')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $l): ?>
      <tr>
        <td class="muted"><?= (int) $l['line_no'] ?></td>
        <td><?= e($l['description']) ?><br><span class="muted"><?= e(t('credit_notes.invoiced_as', ['qty' => format_qty((int) $l['invoiced_milli']), 'price' => format_money((int) $l['invoiced_price_minor'])])) ?></span></td>
        <td class="num nowrap"><?= e(format_qty((int) $l['quantity_milli'])) ?> <span class="muted"><?= e(unit_label($l['unit'])) ?></span></td>
        <td class="num nowrap"><?= e(format_money((int) $l['unit_price_minor'])) ?></td>
        <td class="num nowrap"><strong><?= e(format_money(Money::lineAmount((int) $l['quantity_milli'], (int) $l['unit_price_minor']))) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><th colspan="4" scope="row" class="num"><?= e(t('quotations.total.subtotal')) ?></th><td class="num nowrap"><?= e(format_money((int) $note['subtotal_minor'], 'USD')) ?></td></tr>
      <tr><th colspan="4" scope="row" class="num"><?= e($note['vat_applicable'] ? t('quotations.total.vat_rate', ['rate' => format_percent((int) $note['vat_rate_bp'])]) : t('quotations.total.vat_exempt')) ?></th><td class="num nowrap"><?= e(format_money((int) $note['vat_minor'], 'USD')) ?></td></tr>
      <tr class="total-row"><th colspan="4" scope="row" class="num"><?= e(t('quotations.total.total')) ?></th><td class="num nowrap"><?= e(format_money((int) $note['total_minor'], 'USD')) ?></td></tr>
    </tfoot>
  </table>
  <?php if ($note['notes']): ?><p class="note"><?= nl2br(e($note['notes'])) ?></p><?php endif; ?>
</section>

<section class="card section-gap">
  <div class="card__title"><h2><?= icon('link', 'title-icon') ?><?= e(t('credit_notes.section_allocation')) ?></h2></div>
  <?php if ($allocations === []): ?>
    <p class="muted"><?= e(t('credit_notes.none_allocated')) ?></p>
  <?php else: ?>
  <table class="table" data-dt="compact">
    <thead><tr>
      <th scope="col"><?= e(t('invoices.col.reference')) ?></th>
      <th scope="col"><?= e(t('common.status')) ?></th>
      <th scope="col" class="num"><?= e(t('payments.col.allocated')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($allocations as $a):
        $st = InvoiceStatus::from($a['status']); ?>
      <tr class="row-link" tabindex="0" data-href="<?= e(url('/invoices/' . $a['invoice_id'])) ?>">
        <td><strong><?= e($a['reference']) ?></strong></td>
        <td><?= badge($st->tone(), t($st->labelKey())) ?></td>
        <td class="num nowrap"><?= e(format_money((int) $a['amount_minor'], 'USD')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<div class="grid grid--main-side section-gap">
  <?= partial('partials/history', ['history' => $history]) ?>
  <?php if ($note['unallocated'] > 0 && can('invoices.manage')): ?>
  <section class="card">
    <div class="card__title"><h2><?= icon('split', 'title-icon') ?><?= e(t('credit_notes.allocate_title')) ?></h2></div>
    <?php if ($open === []): ?>
      <p class="muted"><?= e(t('credit_notes.credit_info', ['amount' => format_money((int) $note['unallocated'], 'USD')])) ?></p>
    <?php else: ?>
    <form method="post" action="<?= e(url('/credit-notes/' . $note['id'] . '/allocate')) ?>" class="form">
      <?= csrf_field() ?>
      <input type="hidden" value="<?= e(input_money((int) $note['unallocated'])) ?>" data-alloc-remaining>
      <?= partial('payments/_allocations', ['open' => $open, 'focusInvoice' => 0, 'amountSelector' => '[data-alloc-remaining]']) ?>
      <button type="submit" class="btn btn--primary"><?= icon('check') ?><span><?= e(t('payments.allocate')) ?></span></button>
    </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
