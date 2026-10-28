<?php

use App\Domain\Purchasing\SupplierInvoiceStatus;
use App\Domain\Shared\Fx;

/**
 * @var array<string, mixed> $payment
 * @var list<array<string, mixed>> $allocations
 * @var list<array<string, mixed>> $open
 * @var list<array<string, mixed>> $history
 */

$cur = (string) $payment['currency_code'];
$fx = (int) $payment['fx_units_per_usd_micro'];
?>
<?= partial('partials/page-header', [
    'icon' => 'send-to-back',
    'title' => $payment['reference'],
    'meta' => '<a href="' . e(url('/suppliers/' . $payment['supplier_id'])) . '">' . e($payment['supplier_name']) . '</a> · '
        . e(t('payment_method.' . $payment['method'])) . ' · ' . e(format_date($payment['paid_on'])),
    'actions' => '<a class="btn" href="' . e(url('/supplier-statements/' . $payment['supplier_id'])) . '">' . icon('scroll-text') . '<span>' . e(t('statements.view')) . '</span></a>',
]) ?>
<section class="vitals">
  <div class="vital"><?= icon('banknote') ?><span class="vital__label"><?= e(t('payments.col.amount')) ?></span><span class="vital__value"><?= e(format_money((int) $payment['amount_minor'], $cur)) ?></span></div>
  <div class="vital"><?= icon('coins') ?><span class="vital__label"><?= e(t('supplier_invoices.usd_value')) ?></span><span class="vital__value"><?= e(format_money((int) $payment['amount_usd'], 'USD')) ?><?php if ($cur !== 'USD'): ?> <span class="muted">@ <?= e(format_rate($fx)) ?></span><?php endif; ?></span></div>
  <div class="vital<?= $payment['unallocated'] > 0 ? ' vital--warn' : '' ?>"><?= icon('scale') ?><span class="vital__label"><?= e(t('supplier_payments.col.advance')) ?></span><span class="vital__value"><?= e(format_money((int) $payment['unallocated'], $cur)) ?></span></div>
  <div class="vital"><?= icon('landmark') ?><span class="vital__label"><?= e(t('payments.col.bank_reference')) ?></span><span class="vital__value"><?= e($payment['bank_reference'] ?: '—') ?></span></div>
</section>
<?php if ($payment['notes']): ?><p class="note"><?= nl2br(e($payment['notes'])) ?></p><?php endif; ?>

<section class="card section-gap">
  <div class="card__title"><h2><?= icon('link', 'title-icon') ?><?= e(t('supplier_payments.section_allocation')) ?></h2></div>
  <?php if ($allocations === []): ?>
    <p class="muted"><?= e(t('payments.none_allocated')) ?></p>
  <?php else: ?>
  <table class="table" data-dt="compact">
    <thead><tr>
      <th scope="col"><?= e(t('supplier_invoices.col.reference')) ?></th>
      <th scope="col"><?= e(t('common.status')) ?></th>
      <th scope="col" class="num"><?= e(t('payments.col.allocated')) ?></th>
      <th scope="col" class="num"><?= e(t('supplier_payments.col.at_invoice_rate')) ?></th>
      <th scope="col" class="num"><?= e(t('supplier_payments.col.at_payment_rate')) ?></th>
      <th scope="col" class="num"><?= e(t('supplier_payments.col.fx_result')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($allocations as $a):
        $st = SupplierInvoiceStatus::from($a['status']);
        $atInvoice = Fx::toUsd((int) $a['amount_minor'], (int) $a['invoice_fx']);
        $atPayment = Fx::toUsd((int) $a['amount_minor'], $fx);
        $result = $atInvoice - $atPayment; ?>
      <tr class="row-link" tabindex="0" data-href="<?= e(url('/supplier-invoices/' . $a['invoice_id'])) ?>">
        <td><strong><?= e($a['reference']) ?></strong><br><span class="muted"><?= e(t('supplier_invoices.their_number', ['ref' => $a['supplier_invoice_number']])) ?></span></td>
        <td><?= badge($st->tone(), t($st->labelKey())) ?></td>
        <td class="num nowrap" data-order="<?= (int) $a['amount_minor'] ?>"><strong><?= e(format_money((int) $a['amount_minor'], $cur)) ?></strong></td>
        <td class="num nowrap"><?= e(format_money($atInvoice, 'USD')) ?></td>
        <td class="num nowrap"><?= e(format_money($atPayment, 'USD')) ?></td>
        <td class="num nowrap<?= $result < 0 ? ' overdue' : '' ?>" data-order="<?= $result ?>"><?= $result === 0 ? '<span class="muted">—</span>' : e(($result > 0 ? '+' : '') . format_money($result, 'USD')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="hint"><?= e(t('supplier_payments.fx_result_hint')) ?></p>
  <?php endif; ?>
</section>

<div class="grid grid--main-side section-gap">
  <?= partial('partials/history', ['history' => $history]) ?>
  <?php if ($payment['unallocated'] > 0 && can('supplier_payments.manage')): ?>
  <section class="card">
    <div class="card__title"><h2><?= icon('split', 'title-icon') ?><?= e(t('payments.allocate_rest')) ?></h2></div>
    <?php if ($open === []): ?>
      <p class="muted"><?= e(t('supplier_payments.advance_info', ['amount' => format_money((int) $payment['unallocated'], $cur)])) ?></p>
    <?php else: ?>
    <form method="post" action="<?= e(url('/supplier-payments/' . $payment['id'] . '/allocate')) ?>" class="form">
      <?= csrf_field() ?>
      <input type="hidden" value="<?= e(input_money((int) $payment['unallocated'])) ?>" data-alloc-remaining>
      <?= partial('payments/_allocations', ['open' => $open, 'focusInvoice' => 0, 'amountSelector' => '[data-alloc-remaining]', 'currency' => $cur, 'basePath' => '/supplier-invoices/']) ?>
      <button type="submit" class="btn btn--primary"><?= icon('check') ?><span><?= e(t('payments.allocate')) ?></span></button>
    </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
