<?php

use App\Domain\Shared\Tone;

/** @var list<array<string, mixed>> $rows types de document avec le nombre de documents fournis */
?>
<?= partial('partials/page-header', [
    'icon' => 'folder-check',
    'title' => t('doctypes.title'),
    'meta' => e(t('doctypes.meta')),
    'actions' => can('doctypes.manage') ? '<a class="btn btn--primary" href="' . e(url('/doctypes/new')) . '">' . icon('plus') . '<span>' . e(t('doctypes.new')) . '</span></a>' : '',
]) ?>
<section class="card">
  <?php if ($rows === []): ?>
    <div class="empty-state"><?= icon('folder-check') ?><p><?= e(t('doctypes.empty')) ?></p></div>
  <?php else: ?>
  <table class="table">
    <thead><tr>
      <th scope="col"><?= e(t('common.code')) ?></th>
      <th scope="col"><?= e(t('doctypes.name')) ?></th>
      <th scope="col" data-priority="low"><?= e(t('doctypes.name_en')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('doctypes.required')) ?></th>
      <th scope="col" class="num" data-x="int"><?= e(t('doctypes.col.docs')) ?></th>
      <th scope="col" class="num" data-x="int" data-priority="low"><?= e(t('doctypes.sort_order')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('common.status')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr<?= can('doctypes.manage') ? ' class="row-link" tabindex="0" data-href="' . e(url('/doctypes/' . $row['id'] . '/edit')) . '"' : '' ?>>
        <td><strong><?= e($row['code']) ?></strong></td>
        <td><?= e($row['name']) ?></td>
        <td><?= e($row['name_en']) ?></td>
        <td data-filter="<?= e(t($row['is_required'] ? 'doctypes.mandatory' : 'doctypes.optional')) ?>"><?= $row['is_required'] ? badge(Tone::Warn, t('doctypes.mandatory')) : '<span class="muted">' . e(t('doctypes.optional')) . '</span>' ?></td>
        <td class="num" data-order="<?= (int) $row['doc_count'] ?>"><?= (int) $row['doc_count'] ?></td>
        <td class="num" data-order="<?= (int) $row['sort_order'] ?>"><?= (int) $row['sort_order'] ?></td>
        <td data-filter="<?= e(t($row['is_active'] ? 'common.active' : 'common.inactive')) ?>"><?= $row['is_active'] ? badge(Tone::Ok, t('common.active')) : badge(Tone::Neutral, t('common.inactive')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
