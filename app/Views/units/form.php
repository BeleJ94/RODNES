<?php /** @var array<string, mixed> $entity @var string $action @var string $cancel */ $editing = isset($entity['id']); ?>
<?= partial('partials/page-header', ['icon' => 'ruler', 'title' => $title]) ?>
<form method="post" action="<?= e($action) ?>" class="card form">
  <?= csrf_field() ?>
  <div class="form-grid">
    <?php if ($editing): ?>
      <?= form_input('code', t('common.code'), $entity['code'], ['required' => true, 'maxlength' => 8, 'attrs' => ' readonly', 'hint' => t('units.code_locked')]) ?>
    <?php else: ?>
      <?= form_input('code', t('common.code'), '', ['required' => true, 'maxlength' => 8, 'attrs' => ' pattern="[a-z0-9]{1,8}" autocapitalize="off"', 'hint' => t('units.code_hint')]) ?>
    <?php endif; ?>
    <?= form_input('sort_order', t('units.sort_order'), $entity['sort_order'] ?? '100', ['type' => 'number', 'required' => true, 'attrs' => ' min="0" max="9999"', 'hint' => t('units.sort_hint')]) ?>
    <?= form_input('name', t('units.name'), $entity['name'] ?? '', ['required' => true, 'maxlength' => 40]) ?>
    <?= form_input('name_en', t('units.name_en'), $entity['name_en'] ?? '', ['required' => true, 'maxlength' => 40]) ?>
    <?= form_check('is_active', t('common.active'), (bool) ($entity['is_active'] ?? true), t('units.active_hint')) ?>
  </div>
  <?= form_actions($cancel) ?>
</form>
