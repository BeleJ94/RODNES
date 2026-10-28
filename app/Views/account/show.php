<?php

use App\Repositories\ActivityRepository;

/**
 * @var array<string, mixed> $user
 * @var list<array<string, mixed>> $activity mes dernières actions
 * @var list<array<string, mixed>> $logins mes dernières connexions
 */
?>
<?= partial('partials/page-header', [
    'icon' => 'circle-user',
    'title' => t('account.title'),
    'meta' => '<span class="role-pill role-pill--' . e($user['role']) . '">' . e(t('role.' . $user['role'])) . '</span> <span class="muted">' . e($user['email']) . '</span>',
    'actions' => '<a class="btn" href="' . e(url('/account/password')) . '">' . icon('key-round') . '<span>' . e(t('account.change_password')) . '</span></a>',
]) ?>
<div class="grid grid--main-side">
  <div class="stack">
    <form method="post" action="<?= e(url('/account')) ?>" class="card form">
      <?= csrf_field() ?>
      <div class="card__title"><h2><?= icon('id-card', 'title-icon') ?><?= e(t('account.profile')) ?></h2></div>
      <div class="form-grid">
        <?= form_input('name', t('users.name'), $user['name'], ['required' => true, 'maxlength' => 120, 'attrs' => ' autocomplete="name"']) ?>
        <?= form_input('phone', t('users.phone'), $user['phone'] ?? '', ['type' => 'tel', 'maxlength' => 32, 'attrs' => ' autocomplete="tel"']) ?>
        <?= form_select('locale', t('users.locale'), ['fr' => 'Français', 'en' => 'English'], $user['locale'], ['required' => true]) ?>
        <div class="field"><label><?= e(t('users.email')) ?></label><input value="<?= e($user['email']) ?>" readonly><p class="hint"><?= e(t('account.email_hint')) ?></p></div>
      </div>
      <div class="form-actions"><button type="submit" class="btn btn--primary"><?= icon('save') ?><span><?= e(t('common.save')) ?></span></button></div>
    </form>

    <section class="card">
      <div class="card__title"><h2><?= icon('activity', 'title-icon') ?><?= e(t('account.my_activity')) ?></h2></div>
      <?php if ($activity === []): ?>
        <div class="empty-state"><?= icon('activity') ?><p><?= e(t('users.no_activity')) ?></p></div>
      <?php else: ?>
        <?= partial('activity/_table', ['rows' => $activity, 'withUser' => false]) ?>
      <?php endif; ?>
    </section>
  </div>

  <div class="stack">
    <section class="card">
      <div class="card__title"><h2><?= icon('shield-check', 'title-icon') ?><?= e(t('users.security')) ?></h2></div>
      <dl class="details">
        <div><dt><?= e(t('users.role')) ?></dt><dd><?= e(t('role.' . $user['role'])) ?><br><span class="muted small"><?= e(t('role_desc.' . $user['role'])) ?></span></dd></div>
        <div><dt><?= e(t('users.password_changed')) ?></dt><dd><?= e(format_datetime($user['password_changed_at'])) ?: '—' ?></dd></div>
      </dl>
      <p class="muted small"><?= e(t('account.security_hint')) ?></p>
    </section>

    <section class="card">
      <div class="card__title"><h2><?= icon('log-in', 'title-icon') ?><?= e(t('users.logins')) ?></h2></div>
      <?php if ($logins === []): ?>
        <p class="muted"><?= e(t('users.no_logins')) ?></p>
      <?php else: ?>
        <ul class="login-list">
          <?php foreach ($logins as $l): $ip = ActivityRepository::ip($l['ip']); ?>
            <li class="<?= $l['success'] ? '' : 'is-failed' ?>">
              <?= icon($l['success'] ? 'circle-check' : 'circle-x') ?>
              <span><?= e(format_datetime($l['created_at'])) ?><small><?= e($l['success'] ? t('activity.login_ok') : t('activity.login_failed')) ?><?= $ip !== '' ? ' · ' . e($ip) : '' ?></small></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="muted small"><?= e(t('account.logins_hint')) ?></p>
      <?php endif; ?>
    </section>
  </div>
</div>
