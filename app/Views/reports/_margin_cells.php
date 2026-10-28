<?php

/**
 * Cellules marge (facturé, coût réel, marge, % réel vs prévu, change) d'une ligne de rapport ou de PO.
 *
 * @var array<string, mixed> $m revenue, cost, margin, margin_bp, est_margin_bp?, fx_effect, provisional
 */

$gap = isset($m['est_margin_bp'], $m['margin_bp']) ? (int) $m['margin_bp'] - (int) $m['est_margin_bp'] : null;
?>
<td class="num nowrap" data-order="<?= (int) $m['revenue'] ?>"><?= e(format_money((int) $m['revenue'], 'USD')) ?></td>
<td class="num nowrap" data-order="<?= (int) $m['cost'] ?>"><?= e(format_money((int) $m['cost'], 'USD')) ?><?php if ((int) ($m['landed'] ?? 0) > 0): ?><br><span class="muted"><?= e(t('reports.incl_landed', ['amount' => format_money((int) $m['landed'], 'USD')])) ?></span><?php endif; ?><?php if ($m['provisional']): ?><br><span class="chip chip--warn"><?= e(t('reports.provisional')) ?></span><?php endif; ?></td>
<td class="num nowrap<?= (int) $m['margin'] < 0 ? ' overdue' : '' ?>" data-order="<?= (int) $m['margin'] ?>"><strong><?= e(format_money((int) $m['margin'], 'USD')) ?></strong></td>
<td class="num nowrap" data-order="<?= (int) ($m['margin_bp'] ?? 0) ?>"><?= $m['margin_bp'] !== null ? e(format_percent((int) $m['margin_bp'])) : '—' ?>
  <?php if ($gap !== null): ?><br><span class="<?= $gap < 0 ? 'overdue' : 'muted' ?>"><?= e(t('reports.vs_planned', ['planned' => format_percent((int) $m['est_margin_bp'])])) ?></span><?php endif; ?></td>
<td class="num nowrap<?= (int) $m['fx_effect'] > 0 ? ' overdue' : '' ?>" data-order="<?= (int) $m['fx_effect'] ?>"><?= (int) $m['fx_effect'] === 0 ? '<span class="muted">—</span>' : e(t((int) $m['fx_effect'] > 0 ? 'reports.fx_loss' : 'reports.fx_gain', ['amount' => format_money(abs((int) $m['fx_effect']), 'USD')])) ?></td>
