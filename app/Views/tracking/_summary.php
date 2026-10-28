<?php

/** @var array<string, mixed> $summary indicateurs (SalesTrackingService::summary) @var string $mode jours ouvrés / calendaires */
$s = $summary;
$unit = t('tracking.days_' . $mode);
?>
<section class="kpis kpis--compact">
  <div class="kpi<?= $s['late_to_answer'] > 0 ? ' kpi--alert' : '' ?>"><span class="kpi__label"><?= e(t('tracking.kpi.answered')) ?></span>
    <span class="kpi__value"><?= (int) $s['answered'] ?> / <?= (int) $s['rfqs'] ?></span>
    <span class="kpi__hint"><?= e(t('tracking.kpi.to_answer', ['n' => $s['to_answer'], 'late' => $s['late_to_answer']])) ?></span></div>
  <div class="kpi"><span class="kpi__label"><?= e(t('tracking.kpi.on_time')) ?></span>
    <span class="kpi__value"><?= $s['on_time_base'] > 0 ? e(format_percent(intdiv($s['on_time'] * 10000, $s['on_time_base']))) : '—' ?></span>
    <span class="kpi__hint"><?= e($s['avg_response_days'] !== null ? t('tracking.kpi.avg_response', ['n' => str_replace('.', is_en() ? '.' : ',', (string) $s['avg_response_days']), 'unit' => $unit]) : t('tracking.kpi.no_answer')) ?></span></div>
  <div class="kpi"><span class="kpi__label"><?= e(t('tracking.kpi.conversion')) ?></span>
    <span class="kpi__value"><?= $s['conversion_bp'] !== null ? e(format_percent((int) $s['conversion_bp'])) : '—' ?></span>
    <span class="kpi__hint"><?= e(t('tracking.kpi.won_lost', ['won' => $s['won'], 'lost' => $s['lost'], 'pending' => $s['awaiting']])) ?></span></div>
  <div class="kpi"><span class="kpi__label"><?= e(t('tracking.kpi.won_amount')) ?></span>
    <span class="kpi__value"><?= e(format_money((int) $s['won_amount'], 'USD')) ?></span>
    <span class="kpi__hint"><?= e(t('tracking.kpi.lost_amount', ['amount' => format_money((int) $s['lost_amount'], 'USD')])) ?></span></div>
</section>
<?php if (($withReasons ?? true) && $s['reasons'] !== []): ?>
  <p class="muted"><?= e(t('tracking.reasons')) ?> <?= e(implode(' · ', array_map(static fn (string $k, int $n): string => t('lost_reason.' . $k) . ' (' . $n . ')', array_keys($s['reasons']), $s['reasons']))) ?></p>
<?php endif; ?>
