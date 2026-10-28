<?php

/**
 * Détail d'un indicateur ou d'une notification (chargé dans la fenêtre du tableau de bord).
 *
 * @var array{title: string, icon: string, page: string, columns: list<array{0: string, 1: string, 2: \Closure}>, rows: list<array<string, mixed>>, link: \Closure} $panel
 */
$xType = ['text' => 'text', 'badge' => 'text', 'money' => 'money', 'int' => 'int', 'percent' => 'percent', 'date' => 'date', 'datetime' => 'date'];
$today = date('Y-m-d');
?>
<div class="dash-panel" data-panel-title="<?= e($panel['title']) ?>" data-panel-page="<?= e($panel['page']) ?>" data-panel-count="<?= count($panel['rows']) ?>">
<?php if ($panel['rows'] === []): ?>
  <div class="empty-state empty-state--ok"><?= icon('circle-check') ?><p><?= e(t('dashboard.panel_empty')) ?></p></div>
<?php else: ?>
  <table class="table" data-page-length="10" data-export-title="<?= e($panel['title']) ?>">
    <thead><tr>
      <?php foreach ($panel['columns'] as $i => [$label, $type]):
          $currency = $type === 'money' && is_array(($panel['columns'][$i][2])($panel['rows'][0])); ?>
        <th scope="col"<?= in_array($type, ['money', 'int', 'percent'], true) ? ' class="num"' : '' ?> data-x="<?= e($xType[$type]) ?>"<?= $currency ? ' data-x-total="0"' : '' ?><?= $type === 'badge' ? ' data-filter="select"' : '' ?><?= ($type === 'date' || $type === 'datetime') && $i > 0 ? ' data-filter="date"' : '' ?>><?= e($label) ?></th>
      <?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($panel['rows'] as $row): ?>
      <tr class="row-link" tabindex="0" data-href="<?= e(($panel['link'])($row)) ?>">
        <?php foreach ($panel['columns'] as $i => [$label, $type, $value]):
            $v = $value($row);
            if ($type === 'badge'): ?>
          <td data-filter="<?= e($v[1]) ?>"><?= badge($v[0], $v[1]) ?></td>
        <?php elseif ($type === 'money'):
            [$amount, $cur] = is_array($v) ? $v : [$v, 'USD']; ?>
          <td class="num<?= $amount < 0 ? ' overdue' : '' ?>" data-order="<?= (int) $amount ?>"><?= e(format_money((int) $amount, $cur)) ?></td>
        <?php elseif ($type === 'percent'): ?>
          <td class="num" data-order="<?= $v ?? '' ?>"><?= $v !== null ? e(format_percent((int) $v)) : '<span class="muted">—</span>' ?></td>
        <?php elseif ($type === 'int'): ?>
          <td class="num" data-order="<?= (int) $v ?>"><?= (int) $v ?></td>
        <?php elseif ($type === 'date' || $type === 'datetime'): ?>
          <td data-order="<?= e((string) $v) ?>"><?= $v ? e($type === 'date' ? format_date($v) : format_datetime($v)) : '<span class="muted">—</span>' ?></td>
        <?php else: ?>
          <td><?= $i === 0 ? '<strong>' . e((string) $v) . '</strong>' : '<span class="clip">' . (e((string) $v) ?: '<span class="muted">—</span>') . '</span>' ?></td>
        <?php endif; endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
