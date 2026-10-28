<?php

/**
 * @var array<string, mixed> $payment
 * @var int $remaining
 * @var list<array<string, mixed>> $allocations
 * @var list<array<string, mixed>> $open
 * @var list<array<string, mixed>> $history
 */
?>
<?= partial('partials/page-header', [
    'icon' => 'wallet',
    'title' => $payment['reference'],
    'meta' => '<a href="' . e(url('/clients/' . $payment['client_id'])) . '">' . e($payment['client_name']) . '</a> · '
        . e(t('payment_method.' . $payment['method'])) . ' · ' . e(format_date($payment['received_on'])),
    'actions' => '<a class="btn" href="' . e(url('/statements/' . $payment['client_id'])) . '">' . icon('scroll-text') . '<span>' . e(t('statements.view')) . '</span></a>',
]) ?>
<section class="vitals">
  <div class="vital"><?= icon('banknote') ?><span class="vital__label"><?= e(t('payments.col.amount')) ?></span><span class="vital__value"><?= e(format_money((int) $payment['amount_minor'], 'USD')) ?></span></div>
  <div class="vital"><?= icon('link') ?><span class="vital__label"><?= e(t('payments.col.allocated')) ?></span><span class="vital__value"><?= e(format_money((int) $payment['allocated_minor'], 'USD')) ?></span></div>
  <div class="vital<?= $remaining > 0 ? ' vital--warn' : '' ?>"><?= icon('scale') ?><span class="vital__label"><?= e(t('payments.col.unallocated')) ?></span><span class="vital__value"><?= e(format_money($remaining, 'USD')) ?></span></div>
  <div class="vital"><?= icon('landmark') ?><span class="vital__label"><?= e(t('payments.col.bank_reference')) ?></span><span class="vital__value"><?= e($payment['bank_reference'] ?: '—') ?></span></div>
</section>
<?php if ($payment['notes']): ?><p class="note"><?= nl2br(e($payment['notes'])) ?></p><?php endif; ?>

<section class="card section-gap">
  <div class="card__title"><h2><?= icon('link', 'title-icon') ?><?= e(t('payments.section_allocation')) ?></h2></div>
  <?php if ($allocations === []): ?>
    <p class="muted"><?= e(t('payments.none_allocated')) ?></p>
  <?php else: ?>
  <table class="table" data-dt="compact">
    <thead><tr>
      <th scope="col"><?= e(t('invoices.col.reference')) ?></th>
      <th scope="col"><?= e(t('invoices.col.issue_date')) ?></th>
      <th scope="col"><?= e(t('common.status')) ?></th>
      <th scope="col" class="num"><?= e(t('invoices.col.total')) ?></th>
      <th scope="col" class="num"><?= e(t('payments.col.allocated')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($allocations as $a):
        $st = \App\Domain\Invoicing\InvoiceStatus::from($a['status']); ?>
      <tr class="row-link" tabindex="0" data-href="<?= e(url('/invoices/' . $a['invoice_id'])) ?>">
        <td><strong><?= e($a['reference']) ?></strong></td>
        <td data-order="<?= e($a['issue_date']) ?>"><?= e(format_date($a['issue_date'])) ?></td>
        <td><?= badge($st->tone(), t($st->labelKey())) ?></td>
        <td class="num nowrap" data-order="<?= (int) $a['total_minor'] ?>"><?= e(format_money((int) $a['total_minor'], 'USD')) ?></td>
        <td class="num nowrap" data-order="<?= (int) $a['amount_minor'] ?>"><strong><?= e(format_money((int) $a['amount_minor'], 'USD')) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<div class="grid grid--main-side section-gap">
  <?= partial('partials/history', ['history' => $history]) ?>
  <?php if ($remaining > 0 && can('payments.manage')): ?>
  <section class="card">
    <div class="card__title"><h2><?= icon('split', 'title-icon') ?><?= e(t('payments.allocate_rest')) ?></h2></div>
    <?php if ($open === []): ?>
      <p class="muted"><?= e(t('payments.advance_info', ['amount' => format_money($remaining, 'USD')])) ?></p>
    <?php else: ?>
    <form method="post" action="<?= e(url('/payments/' . $payment['id'] . '/allocate')) ?>" class="form">
      <?= csrf_field() ?>
      <input type="hidden" value="<?= e(input_money($remaining)) ?>" data-alloc-remaining>
      <?= partial('payments/_allocations', ['open' => $open, 'focusInvoice' => 0, 'amountSelector' => '[data-alloc-remaining]']) ?>
      <button type="submit" class="btn btn--primary"><?= icon('check') ?><span><?= e(t('payments.allocate')) ?></span></button>
    </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
