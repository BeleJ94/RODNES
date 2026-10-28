<?php /** @var array<string, mixed> $entity @var string $action @var string $cancel @var array<string, string> $units code → libellé */ ?>
<?= partial('partials/page-header', ['icon' => 'package', 'title' => $title]) ?>
<form method="post" action="<?= e($action) ?>" class="card form">
  <?= csrf_field() ?>
  <div class="form-grid">
    <?= form_input('code', t('common.code'), $entity['code'] ?? '', ['required' => true, 'maxlength' => 32]) ?>
    <?= form_input('name', t('items.name'), $entity['name'] ?? '', ['required' => true, 'maxlength' => 190]) ?>
    <?= form_input('manufacturer', t('items.manufacturer'), $entity['manufacturer'] ?? '', ['maxlength' => 80]) ?>
    <?= form_input('part_number', t('items.part_number'), $entity['part_number'] ?? '', ['maxlength' => 80]) ?>
    <?= form_select('unit', t('items.unit'), $units, $entity['unit'] ?? 'u', ['required' => true]) ?>
    <?= form_check('is_active', t('common.active'), (bool) ($entity['is_active'] ?? true)) ?>
    <?= form_textarea('description', t('items.description'), $entity['description'] ?? '', ['span' => true, 'maxlength' => 2000]) ?>
  </div>
  <?= form_actions($cancel) ?>
</form>
