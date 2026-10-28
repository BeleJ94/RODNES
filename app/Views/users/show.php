<?php

use App\Domain\Shared\Tone;
use App\Repositories\ActivityRepository;

/**
 * @var array<string, mixed> $user
 * @var list<array<string, mixed>> $activity actions de l'utilisateur
 * @var list<array<string, mixed>> $history modifications du compte
 * @var list<array<string, mixed>> $logins connexions récentes
 * @var string|null $tempPassword mot de passe provisoire, affiché une seule fois
 */
$id = (int) $user['id'];
$isSelf = $id === app(\App\Security\Auth::class)->user()?->id;
$status = !$user['is_active'] ? [Tone::Neutral, t('common.inactive')] : ($user['must_change_password'] ? [Tone::Warn, t('users.status_pending')] : [Tone::Ok, t('common.active')]);
$post = static fn (string $path, string $icon, string $label, string $confirm, string $class = 'btn'): string =>
    '<form method="post" action="' . e(url('/settings/users/' . $id . $path)) . '" data-confirm="' . e($confirm) . '">' . csrf_field()
    . '<button type="submit" class="' . $class . '">' . icon($icon) . '<span>' . e($label) . '</span></button></form>';
?>
<?= partial('partials/page-header', [
    'icon' => 'user',
    'title' => (string) $user['name'],
    'meta' => '<span class="role-pill role-pill--' . e($user['role']) . '">' . e(t('role.' . $user['role'])) . '</span> ' . badge($status[0], $status[1])
        . ($isSelf ? ' <span class="tag">' . e(t('users.you')) . '</span>' : ''),
    'actions' => '<a class="btn btn--ghost" href="' . e(url('/settings/users')) . '">' . icon('arrow-left') . '<span>' . e(t('users.back')) . '</span></a>'
        . '<a class="btn btn--primary" href="' . e(url('/settings/users/' . $id . '/edit')) . '">' . icon('pencil') . '<span>' . e(t('common.edit')) . '</span></a>',
]) ?>

<?php if (is_string($tempPassword) && $tempPassword !== ''): ?>
  <section class="card temp-pass" role="status">
    <div class="temp-pass__icon"><?= icon('key-round') ?></div>
    <div class="temp-pass__body">
      <h2><?= e(t('users.temp_title')) ?></h2>
      <p><?= e(t('users.temp_text', ['email' => $user['email']])) ?></p>
      <div class="temp-pass__value">
        <code data-copy-source><?= e($tempPassword) ?></code>
        <button type="button" class="btn btn--sm" data-copy data-copied-label="<?= e(t('users.copied')) ?>"><?= icon('copy') ?><span><?= e(t('users.copy')) ?></span></button>
      </div>
      <p class="muted small"><?= e(t('users.temp_once')) ?></p>
    </div>
  </section>
<?php endif; ?>

<div class="grid grid--main-side">
  <div class="stack">
    <section class="card">
      <div class="card__title"><h2><?= icon('activity', 'title-icon') ?><?= e(t('users.recent_activity')) ?></h2>
        <?php if (can('activity.view')): ?><a class="btn btn--ghost btn--sm" href="<?= e(url('/settings/activity', ['user' => $id])) ?>"><?= icon('history') ?><span><?= e(t('users.full_log')) ?></span></a><?php endif; ?></div>
      <?php if ($activity === []): ?>
        <div class="empty-state"><?= icon('activity') ?><p><?= e(t('users.no_activity')) ?></p></div>
      <?php else: ?>
        <?= partial('activity/_table', ['rows' => $activity, 'withUser' => false]) ?>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card__title"><h2><?= icon('shield-alert', 'title-icon') ?><?= e(t('users.security')) ?></h2></div>
      <div class="sec-actions">
        <div class="sec-action">
          <div><strong><?= e(t('users.reset_title')) ?></strong><p class="muted small"><?= e(t('users.reset_text')) ?></p></div>
          <?= $post('/reset-password', 'key-round', t('users.reset'), t('users.reset_confirm', ['name' => $user['name']])) ?>
        </div>
        <div class="sec-action">
          <div><strong><?= e(t('users.revoke_title')) ?></strong><p class="muted small"><?= e(t('users.revoke_text')) ?></p></div>
          <?= $post('/revoke-sessions', 'log-out', t('users.revoke'), t('users.revoke_confirm', ['name' => $user['name']])) ?>
        </div>
        <div class="sec-action">
          <?php if ($user['is_active']): ?>
            <div><strong><?= e(t('users.deactivate_title')) ?></strong><p class="muted small"><?= e(t($isSelf ? 'users.error_self_deactivate' : 'users.deactivate_text')) ?></p></div>
            <?= $isSelf ? '' : $post('/deactivate', 'user-x', t('users.deactivate'), t('users.deactivate_confirm', ['name' => $user['name']]), 'btn btn--danger') ?>
          <?php else: ?>
            <div><strong><?= e(t('users.activate_title')) ?></strong><p class="muted small"><?= e(t('users.activate_text')) ?></p></div>
            <?= $post('/activate', 'user-check', t('users.activate'), t('users.activate_confirm', ['name' => $user['name']]), 'btn btn--primary') ?>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </div>

  <div class="stack">
    <section class="card">
      <div class="card__title"><h2><?= icon('id-card', 'title-icon') ?><?= e(t('common.details')) ?></h2></div>
      <dl class="details">
        <div><dt><?= e(t('users.email')) ?></dt><dd><a href="mailto:<?= e($user['email']) ?>"><?= e($user['email']) ?></a></dd></div>
        <div><dt><?= e(t('users.phone')) ?></dt><dd><?= e($user['phone']) ?: '—' ?></dd></div>
        <div><dt><?= e(t('users.role')) ?></dt><dd><?= e(t('role.' . $user['role'])) ?><br><span class="muted small"><?= e(t('role_desc.' . $user['role'])) ?></span></dd></div>
        <div><dt><?= e(t('users.locale')) ?></dt><dd><?= $user['locale'] === 'en' ? 'English' : 'Français' ?></dd></div>
        <div><dt><?= e(t('users.last_login')) ?></dt><dd><?= $user['last_login_at'] ? e(format_datetime($user['last_login_at'])) : e(t('users.never')) ?></dd></div>
        <div><dt><?= e(t('users.password_changed')) ?></dt><dd><?= e(format_datetime($user['password_changed_at'])) ?: '—' ?></dd></div>
        <div><dt><?= e(t('users.created')) ?></dt><dd><?= e(format_datetime($user['created_at'])) ?><?= $user['created_by_name'] ? ' · ' . e($user['created_by_name']) : '' ?></dd></div>
      </dl>
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
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card__title"><h2><?= icon('history', 'title-icon') ?><?= e(t('users.account_history')) ?></h2></div>
      <?php if ($history === []): ?>
        <p class="muted"><?= e(t('history.empty')) ?></p>
      <?php else: ?>
        <ol class="timeline">
          <?php foreach ($history as $h): ?>
            <li>
              <p class="timeline__head"><strong><?= e(activity_action_label((string) $h['action'])) ?></strong></p>
              <p class="timeline__meta"><?= e(format_datetime($h['created_at'])) ?> · <?= e($h['user_name'] ?? t('history.system')) ?></p>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
    </section>
  </div>
</div>
