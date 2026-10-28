<?php /** @var list<array<string, mixed>> $rows */ ?>
<?= partial('partials/page-header', [
    'icon' => 'package',
    'title' => t('items.title'),
    'meta' => e(t('common.count', ['n' => count($rows)])),
    'actions' => can('items.manage') ? '<a class="btn btn--primary" href="' . e(url('/items/new')) . '">' . icon('plus') . '<span>' . e(t('items.new')) . '</span></a>' : '',
]) ?>
<section class="card">
  <?php if ($rows === []): ?>
    <div class="empty-state"><?= icon('package') ?><p><?= e(t('items.empty')) ?></p></div>
  <?php else: ?>
  <table class="table">
    <thead><tr>
      <th scope="col"><?= e(t('common.code')) ?></th>
      <th scope="col"><?= e(t('items.name')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('items.manufacturer')) ?></th>
      <th scope="col" data-priority="low"><?= e(t('detail.part_number')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('items.unit')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('common.status')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr class="row-link" tabindex="0" data-href="<?= e(url('/items/' . $row['id'] . '/edit')) ?>">
        <td><strong><?= e($row['code']) ?></strong></td>
        <td><span class="clip"><?= e($row['name']) ?></span></td>
        <td><?= e($row['manufacturer']) ?: '<span class="muted">—</span>' ?></td>
        <td><?= e($row['part_number']) ?: '<span class="muted">—</span>' ?></td>
        <td><?= e(unit_label($row['unit'])) ?></td>
        <td data-filter="<?= e(t($row['is_active'] ? 'common.active' : 'common.inactive')) ?>"><?= $row['is_active'] ? badge(\App\Domain\Shared\Tone::Ok, t('common.active')) : badge(\App\Domain\Shared\Tone::Neutral, t('common.inactive')) ?></td>
        <?= row_detail([t('detail.description') => e((string) ($row['description'] ?? ''))]) ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
