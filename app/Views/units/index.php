<?php /** @var list<array<string, mixed>> $rows unités avec le nombre d'articles */ ?>
<?= partial('partials/page-header', [
    'icon' => 'ruler',
    'title' => t('units.title'),
    'meta' => e(t('units.meta')),
    'actions' => can('units.manage') ? '<a class="btn btn--primary" href="' . e(url('/units/new')) . '">' . icon('plus') . '<span>' . e(t('units.new')) . '</span></a>' : '',
]) ?>
<section class="card">
  <?php if ($rows === []): ?>
    <div class="empty-state"><?= icon('ruler') ?><p><?= e(t('units.empty')) ?></p></div>
  <?php else: ?>
  <table class="table">
    <thead><tr>
      <th scope="col"><?= e(t('common.code')) ?></th>
      <th scope="col"><?= e(t('units.name')) ?></th>
      <th scope="col"><?= e(t('units.name_en')) ?></th>
      <th scope="col" data-priority="low"><?= e(t('units.col.preview')) ?></th>
      <th scope="col" class="num" data-x="int"><?= e(t('units.col.items')) ?></th>
      <th scope="col" class="num" data-x="int" data-priority="low"><?= e(t('units.sort_order')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('common.status')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr<?= can('units.manage') ? ' class="row-link" tabindex="0" data-href="' . e(url('/units/' . $row['id'] . '/edit')) . '"' : '' ?>>
        <td><strong><?= e($row['code']) ?></strong></td>
        <td><?= e($row['name']) ?></td>
        <td><?= e($row['name_en']) ?></td>
        <td class="muted"><?= e(format_qty(12500)) ?> <?= e(unit_label($row['code'])) ?></td>
        <td class="num" data-order="<?= (int) $row['item_count'] ?>"><?= (int) $row['item_count'] ?></td>
        <td class="num" data-order="<?= (int) $row['sort_order'] ?>"><?= (int) $row['sort_order'] ?></td>
        <td data-filter="<?= e(t($row['is_active'] ? 'common.active' : 'common.inactive')) ?>"><?= $row['is_active'] ? badge(\App\Domain\Shared\Tone::Ok, t('common.active')) : badge(\App\Domain\Shared\Tone::Neutral, t('common.inactive')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
