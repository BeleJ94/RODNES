<?php

/**
 * @var string $from @var string $to
 * @var array<string, int> $counts pièces de la période par feuille
 */

$lastMonth = strtotime('first day of last month');
$presets = [
    t('export.preset.last_month') => [date('Y-m-01', $lastMonth), date('Y-m-t', $lastMonth)],
    t('export.preset.this_month') => [date('Y-m-01'), date('Y-m-t')],
    t('export.preset.this_year') => [date('Y-01-01'), date('Y-12-31')],
];
?>
<?= partial('partials/page-header', ['icon' => 'file-spreadsheet', 'title' => t('export.title'), 'meta' => e(t('export.meta'))]) ?>
<section class="card">
  <form class="filters" method="get" action="<?= e(url('/exports')) ?>">
    <div class="filters__fields">
      <label class="filter"><span><?= e(t('statements.from')) ?></span><input type="date" name="from" value="<?= e($from) ?>" required></label>
      <label class="filter"><span><?= e(t('statements.to')) ?></span><input type="date" name="to" value="<?= e($to) ?>" required></label>
      <button type="submit" class="btn"><?= icon('filter') ?><span><?= e(t('statements.apply')) ?></span></button>
    </div>
  </form>
  <p class="muted"><?= implode(' · ', array_map(static fn (string $label, array $p): string => '<a href="' . e(url('/exports', ['from' => $p[0], 'to' => $p[1]])) . '">' . e($label) . '</a>', array_keys($presets), $presets)) ?></p>
  <table class="table" data-no-dt>
    <thead><tr>
      <th scope="col"><?= e(t('export.col.sheet')) ?></th>
      <th scope="col"><?= e(t('export.col.content')) ?></th>
      <th scope="col" class="num"><?= e(t('export.col.documents')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach (['sales', 'purchases', 'receipts', 'disbursements'] as $sheet): ?>
      <tr>
        <td><strong><?= e(t('export.sheet.' . $sheet)) ?></strong></td>
        <td><?= e(t('export.sheet_hint.' . $sheet)) ?></td>
        <td class="num"><?= (int) $counts[$sheet] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="form-actions">
    <a class="btn btn--primary" href="<?= e(url('/exports', ['from' => $from, 'to' => $to, 'format' => 'xlsx'])) ?>" download><?= icon('download') ?><span><?= e(t('export.download_pack', ['from' => format_date($from), 'to' => format_date($to)])) ?></span></a>
  </div>
  <p class="hint"><?= e(t('export.pack_hint')) ?></p>
</section>
