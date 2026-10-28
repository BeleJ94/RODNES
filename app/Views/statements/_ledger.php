<?php

/**
 * Écritures du relevé (partagé écran / impression).
 *
 * @var list<array<string, mixed>> $lines
 * @var int $opening @var int $closing @var int $debits @var int $credits
 * @var string $from @var bool $links @var string $tableClass
 * @var string $currency devise du relevé (USD côté client)
 * @var array<string, string> $hrefs type d'écriture → chemin de la pièce
 */
$currency ??= 'USD';
$hrefs ??= ['invoice' => '/invoices/', 'cancellation' => '/invoices/', 'payment' => '/payments/', 'credit_note' => '/credit-notes/'];
?>
<table class="<?= e($tableClass) ?>"<?= $links ? ' data-dt="compact"' : '' ?>>
  <thead><tr>
    <th scope="col"><?= e(t('statements.col.date')) ?></th>
    <th scope="col"><?= e(t('statements.col.document')) ?></th>
    <th scope="col"><?= e(t('statements.col.detail')) ?></th>
    <th scope="col" class="num"><?= e(t('statements.col.debit')) ?></th>
    <th scope="col" class="num"><?= e(t('statements.col.credit')) ?></th>
    <th scope="col" class="num"><?= e(t('statements.col.balance')) ?></th>
  </tr></thead>
  <tbody>
    <tr>
      <td class="nowrap" data-order="<?= e($from) ?>"><?= e(format_date($from)) ?></td>
      <td colspan="1"><strong><?= e(t('statements.opening')) ?></strong></td>
      <td></td><td></td><td></td>
      <td class="num nowrap"><strong><?= e(format_money($opening, $currency)) ?></strong></td>
    </tr>
  <?php foreach ($lines as $l):
      $href = $hrefs[$l['type']] . $l['id']; ?>
    <tr>
      <td class="nowrap" data-order="<?= e($l['date']) ?>"><?= e(format_date($l['date'])) ?></td>
      <td class="nowrap"><?= $links ? '<a href="' . e(url($href)) . '">' . e($l['reference']) . '</a>' : e($l['reference']) ?></td>
      <td><?= e(t('statements.type.' . $l['type'])) ?><?php if (in_array($l['type'], ['invoice', 'supplier_invoice'], true) && $l['note']): ?> · <span class="muted"><?= e(t('invoices.doc_due', ['date' => format_date($l['note'])])) ?></span><?php elseif ($l['note']): ?> · <span class="muted"><?= e($l['note']) ?></span><?php endif; ?></td>
      <td class="num nowrap"><?= $l['debit'] > 0 ? e(format_money((int) $l['debit'], $currency)) : '' ?></td>
      <td class="num nowrap"><?= $l['credit'] > 0 ? e(format_money((int) $l['credit'], $currency)) : '' ?></td>
      <td class="num nowrap"><?= e(format_money((int) $l['balance'], $currency)) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr class="total-row">
      <th colspan="3" scope="row" class="num"><?= e(t('statements.closing')) ?></th>
      <td class="num nowrap"><?= e(format_money($debits, $currency)) ?></td>
      <td class="num nowrap"><?= e(format_money($credits, $currency)) ?></td>
      <td class="num nowrap"><strong><?= e(format_money($closing, $currency)) ?></strong></td>
    </tr>
  </tfoot>
</table>
