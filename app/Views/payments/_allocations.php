<?php

/**
 * Saisie de la répartition d'un encaissement sur les factures ouvertes (alloc[id][amount]).
 *
 * @var list<array<string, mixed>> $open
 * @var int $focusInvoice
 * @var string $amountSelector champ du montant à répartir (bouton « Répartir automatiquement »)
 * @var string $currency devise des factures (USD côté client)
 * @var string $basePath lien des factures (/invoices/ ou /supplier-invoices/)
 */
$oldAlloc = old_rows('alloc');
$today = date('Y-m-d');
$currency ??= 'USD';
$basePath ??= '/invoices/';
?>
<?= field_error('alloc') ?>
<div class="table-scroll">
  <table class="table lines-table" data-alloc data-alloc-amount="<?= e($amountSelector) ?>" data-alloc-currency="<?= e($currency) ?>">
    <thead><tr>
      <th scope="col"><?= e(t('invoices.col.reference')) ?></th>
      <th scope="col"><?= e(t('invoices.col.due_date')) ?></th>
      <th scope="col" class="num"><?= e(t('invoices.col.total')) ?></th>
      <th scope="col" class="num"><?= e(t('invoices.col.balance')) ?></th>
      <th scope="col" class="w-qty"><?= e(t('payments.col.allocate')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($open as $i):
        $id = (int) $i['id'];
        $value = $oldAlloc[$id]['amount'] ?? ($focusInvoice === $id ? input_money((int) $i['balance']) : ''); ?>
      <tr>
        <td class="nowrap"><a href="<?= e(url($basePath . $id)) ?>"><strong><?= e($i['reference']) ?></strong></a><br><span class="muted"><?= e($i['order_reference']) ?></span></td>
        <td class="nowrap<?= $i['due_date'] < $today ? ' overdue' : '' ?>"><?= e(format_date($i['due_date'])) ?></td>
        <td class="num nowrap"><?= e(format_money((int) ($i['total'] ?? $i['total_minor']), $currency)) ?></td>
        <td class="num nowrap"><strong><?= e(format_money((int) $i['balance'], $currency)) ?></strong></td>
        <td><input name="alloc[<?= $id ?>][amount]" value="<?= e($value) ?>" inputmode="decimal" class="num" data-alloc-balance="<?= (int) $i['balance'] ?>" aria-label="<?= e(t('payments.col.allocate') . ' ' . $i['reference']) ?>"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <th colspan="4" scope="row" class="num"><?= e(t('payments.allocated_total')) ?></th>
      <td class="num nowrap"><strong data-alloc-total>—</strong></td>
    </tr></tfoot>
  </table>
</div>
<div class="form-inline">
  <button type="button" class="btn" data-alloc-auto><?= icon('wand-sparkles') ?><span><?= e(t('payments.auto_allocate')) ?></span></button>
  <p class="hint"><?= e(t('payments.alloc_hint')) ?></p>
</div>
