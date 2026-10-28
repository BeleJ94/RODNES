<?php

/** @var list<array<string, mixed>> $rows solde par fournisseur et par devise */

$totalUsd = array_sum(array_column($rows, 'balance_usd'));
$actions = can('supplier_payments.manage') ? '<a class="btn btn--primary" href="' . e(url('/supplier-payments/new')) . '">' . icon('send-to-back') . '<span>' . e(t('supplier_payments.new')) . '</span></a>' : '';
?>
<?= partial('partials/page-header', [
    'icon' => 'book-open',
    'title' => t('supplier_statements.title'),
    'meta' => e(t('supplier_statements.meta', ['amount' => format_money($totalUsd, 'USD')])),
    'actions' => export_button() . ($actions),
]) ?>
<section class="card">
  <?php if ($rows === []): ?>
    <div class="empty-state"><?= icon('book-open') ?><p><?= e(t('supplier_statements.empty')) ?></p></div>
  <?php else: ?>
  <table class="table">
    <thead><tr>
      <th scope="col"><?= e(t('sourcing.supplier')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('supplier_payments.currency')) ?></th>
      <th scope="col" class="num" data-x="money" data-x-total="0" data-priority="low"><?= e(t('supplier_statements.col.invoiced')) ?></th>
      <th scope="col" class="num" data-x="money" data-x-total="0" data-priority="low"><?= e(t('supplier_statements.col.paid')) ?></th>
      <th scope="col" class="num" data-x="money" data-x-total="0"><?= e(t('statements.col.balance')) ?></th>
      <th scope="col" class="num" data-x="money" data-x-total="0"><?= e(t('invoices.kpi.overdue')) ?></th>
      <th scope="col" data-filter="date"><?= e(t('supplier_statements.col.next_due')) ?></th>
      <th scope="col" class="num" data-filter="range" data-filter-scale="100" data-filter-label="<?= e(t('supplier_invoices.col.balance_usd')) ?>" data-x="money"><?= e(t('supplier_invoices.col.balance_usd')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $cur = (string) $r['currency'];
        $balance = (int) $r['invoiced'] - (int) $r['paid']; ?>
      <tr class="row-link" tabindex="0" data-href="<?= e(url('/supplier-statements/' . $r['supplier_id'])) ?>">
        <td><strong><?= e($r['supplier_name']) ?></strong> <span class="sub"><?= e($r['supplier_code']) ?></span><?php if ($r['on_hold'] > 0): ?> <span class="chip chip--warn"><?= e(t('supplier_statements.on_hold_n', ['n' => $r['on_hold']])) ?></span><?php endif; ?></td>
        <td><span class="tag"><?= e($cur) ?></span></td>
        <td class="num" data-order="<?= (int) $r['invoiced'] ?>"><?= e(format_money((int) $r['invoiced'], $cur)) ?></td>
        <td class="num" data-order="<?= (int) $r['paid'] ?>"><?= e(format_money((int) $r['paid'], $cur)) ?></td>
        <td class="num" data-order="<?= $balance ?>"><strong><?= e(format_money($balance, $cur)) ?></strong><?php if ($balance < 0): ?> <span class="chip"><?= e(t('supplier_statements.advance')) ?></span><?php endif; ?></td>
        <td class="num<?= $r['overdue'] > 0 ? ' overdue' : '' ?>" data-order="<?= (int) $r['overdue'] ?>"><?= $r['overdue'] > 0 ? e(format_money((int) $r['overdue'], $cur)) : '<span class="muted">—</span>' ?></td>
        <td data-order="<?= e($r['next_due'] ?? '') ?>"><?= $r['next_due'] ? e(format_date($r['next_due'])) : '<span class="muted">—</span>' ?></td>
        <td class="num" data-order="<?= (int) $r['balance_usd'] ?>"><?= e(format_money((int) $r['balance_usd'], 'USD')) ?></td>
        <?= row_detail([
            t('detail.invoiced') => e(format_money((int) $r['invoiced'], $cur)),
            t('detail.paid') => e(format_money((int) $r['paid'], $cur)),
            t('detail.on_hold') => $r['on_hold'] > 0 ? e(t('supplier_statements.on_hold_n', ['n' => $r['on_hold']])) : null,
            t('detail.next_due') => e(format_date($r['next_due'])),
        ]) ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="hint"><?= e(t('supplier_statements.hint')) ?></p>
  <?php endif; ?>
</section>
