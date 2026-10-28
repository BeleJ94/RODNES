<?php

use App\Services\UserService;

/** @var bool $forced mot de passe provisoire : écran imposé avant tout le reste (mise en page minimale) */
$field = static fn (string $name, string $label, string $autocomplete, string $hint = ''): string =>
    '<div class="field"><label for="f-' . $name . '" class="required">' . e($label) . '</label>'
    . '<div class="input-icon input-icon--lead">' . icon('lock', 'input-icon__lead')
    . '<input id="f-' . $name . '" name="' . $name . '" type="password" required maxlength="1024" autocomplete="' . $autocomplete . '"' . invalid($name)
    . ($name === 'new_password' ? ' minlength="' . UserService::MIN_PASSWORD . '" data-pw-new' : '') . ($name === 'new_password_confirm' ? ' data-pw-confirm' : '') . '>'
    . '<button type="button" class="icon-btn" data-toggle-password aria-controls="f-' . $name . '" aria-pressed="false" aria-label="' . e(t('auth.show_password')) . '">' . icon('eye') . '</button></div>'
    . ($hint !== '' ? '<p class="hint">' . $hint . '</p>' : '') . field_error($name) . '</div>';
?>
<?php if ($forced): ?>
<div class="pw-page">
  <div class="pw-card card">
    <span class="brand-mark"><img src="<?= e(asset('img/logo.png')) ?>" alt="" width="28" height="28"></span>
    <div>
      <h1><?= e(t('account.forced_title')) ?></h1>
      <p class="muted"><?= e(t('account.forced_text')) ?></p>
    </div>
    <?= partial('partials/flashes') ?>
<?php else: ?>
<?= partial('partials/page-header', ['icon' => 'key-round', 'title' => t('account.password_title'), 'meta' => e(t('account.password_meta'))]) ?>
<div class="pw-inline card">
<?php endif; ?>
    <form method="post" action="<?= e(url('/account/password')) ?>" class="form pw-form" data-no-ajax data-pw-form>
      <?= csrf_field() ?>
      <input type="text" name="username" value="<?= e(app(\App\Security\Auth::class)->user()?->email) ?>" autocomplete="username" hidden>
      <?= $field('current_password', t($forced ? 'account.temp_password' : 'account.current_password'), 'current-password') ?>
      <?= $field('new_password', t('account.new_password'), 'new-password') ?>
      <ul class="pw-rules" data-pw-rules aria-live="polite">
        <li data-rule="length"><?= icon('circle') ?><span><?= e(t('account.rule_length', ['n' => UserService::MIN_PASSWORD])) ?></span></li>
        <li data-rule="mix"><?= icon('circle') ?><span><?= e(t('account.rule_mix')) ?></span></li>
        <li data-rule="match"><?= icon('circle') ?><span><?= e(t('account.rule_match')) ?></span></li>
      </ul>
      <?= $field('new_password_confirm', t('account.confirm_password'), 'new-password') ?>
      <div class="form-actions">
        <?php if (!$forced): ?><a class="btn btn--ghost" href="<?= e(url('/account')) ?>"><?= e(t('common.cancel')) ?></a><?php endif; ?>
        <button type="submit" class="btn btn--primary<?= $forced ? ' btn--block' : '' ?>"><?= icon('check') ?><span><?= e(t('account.submit_password')) ?></span></button>
      </div>
    </form>
<?php if ($forced): ?>
    <form method="post" action="<?= e(url('/logout')) ?>" class="pw-logout">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn--ghost btn--sm"><?= icon('log-out') ?><span><?= e(t('auth.logout')) ?></span></button>
    </form>
  </div>
</div>
<?php else: ?>
</div>
<?php endif; ?>
