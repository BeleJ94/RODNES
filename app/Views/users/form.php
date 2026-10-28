<?php

use App\Security\Role;

/** @var array<string, mixed> $user @var string $action @var string $cancel @var bool $isSelf */
$editing = isset($user['id']);
$current = old('role', (string) ($user['role'] ?? Role::Viewer->value));
?>
<?= partial('partials/page-header', ['icon' => $editing ? 'user-cog' : 'user-plus', 'title' => $title, 'meta' => $editing ? '' : e(t('users.new_meta'))]) ?>
<form method="post" action="<?= e($action) ?>" class="card form">
  <?= csrf_field() ?>
  <h2 class="um-subhead"><?= icon('id-card', 'title-icon') ?><?= e(t('users.section_identity')) ?></h2>
  <div class="form-grid">
    <?= form_input('name', t('users.name'), $user['name'] ?? '', ['required' => true, 'maxlength' => 120, 'attrs' => ' autocomplete="off"']) ?>
    <?= form_input('email', t('users.email'), $user['email'] ?? '', ['type' => 'email', 'required' => true, 'maxlength' => 190, 'attrs' => ' autocomplete="off"', 'hint' => t('users.email_hint')]) ?>
    <?= form_input('phone', t('users.phone'), $user['phone'] ?? '', ['type' => 'tel', 'maxlength' => 32]) ?>
    <?= form_select('locale', t('users.locale'), ['fr' => 'Français', 'en' => 'English'], $user['locale'] ?? 'fr', ['required' => true]) ?>
  </div>

  <h2 class="um-subhead"><?= icon('shield', 'title-icon') ?><?= e(t('users.section_role')) ?></h2>
  <?php if ($isSelf): ?><p class="hint"><?= icon('info') ?> <?= e(t('users.self_role_hint')) ?></p><?php endif; ?>
  <div class="role-picker" role="radiogroup" aria-label="<?= e(t('users.role')) ?>">
    <?php foreach (Role::cases() as $role): ?>
      <label class="role-option">
        <input type="radio" name="role" value="<?= e($role->value) ?>"<?= $current === $role->value ? ' checked' : '' ?> required>
        <span class="role-option__body">
          <span class="role-pill role-pill--<?= e($role->value) ?>"><?= e(t($role->labelKey())) ?></span>
          <span class="role-option__desc"><?= e(t('role_desc.' . $role->value)) ?></span>
        </span>
      </label>
    <?php endforeach; ?>
  </div>
  <?= field_error('role') ?>

  <?php if (!$editing): ?>
    <p class="um-callout"><?= icon('key-round') ?><span><?= e(t('users.temp_password_hint')) ?></span></p>
  <?php endif; ?>
  <?= form_actions($cancel, $editing ? '' : t('users.create_submit')) ?>
</form>
