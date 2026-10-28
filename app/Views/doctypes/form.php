<?php /** @var array<string, mixed> $entity @var string $action @var string $cancel */ $editing = isset($entity['id']); ?>
<?= partial('partials/page-header', ['icon' => 'folder-check', 'title' => $title]) ?>
<form method="post" action="<?= e($action) ?>" class="card form">
  <?= csrf_field() ?>
  <div class="form-grid">
    <?php if ($editing): ?>
      <?= form_input('code', t('common.code'), $entity['code'], ['required' => true, 'maxlength' => 24, 'attrs' => ' readonly', 'hint' => t('doctypes.code_locked')]) ?>
    <?php else: ?>
      <?= form_input('code', t('common.code'), '', ['required' => true, 'maxlength' => 24, 'attrs' => ' pattern="[A-Za-z0-9_]{1,24}" autocapitalize="characters"', 'hint' => t('doctypes.code_hint')]) ?>
    <?php endif; ?>
    <?= form_input('sort_order', t('doctypes.sort_order'), $entity['sort_order'] ?? '100', ['type' => 'number', 'required' => true, 'attrs' => ' min="0" max="9999"', 'hint' => t('units.sort_hint')]) ?>
    <?= form_input('name', t('doctypes.name'), $entity['name'] ?? '', ['required' => true, 'maxlength' => 80]) ?>
    <?= form_input('name_en', t('doctypes.name_en'), $entity['name_en'] ?? '', ['required' => true, 'maxlength' => 80]) ?>
    <?= form_check('is_required', t('doctypes.required_default'), (bool) ($entity['is_required'] ?? false), t('doctypes.required_hint')) ?>
    <?= form_check('is_active', t('common.active'), (bool) ($entity['is_active'] ?? true), t('doctypes.active_hint')) ?>
  </div>
  <?= form_actions($cancel) ?>
</form>
