<?php

use App\Domain\Shared\Tone;

/** @var list<array<string, mixed>> $rows @var array{total: int, active: int, admins: int, never: int, pending: int} $kpis */
$me = app(\App\Security\Auth::class)->user()?->id;
?>
<?= partial('partials/page-header', [
    'icon' => 'users',
    'title' => t('users.title'),
    'meta' => e(t('users.meta', ['active' => $kpis['active'], 'total' => $kpis['total']])),
    'actions' => export_button() . '<a class="btn btn--primary" href="' . e(url('/settings/users/new')) . '">' . icon('user-plus') . '<span>' . e(t('users.new')) . '</span></a>',
]) ?>
<section class="kpis kpis--compact">
  <div class="kpi kpi--ok"><span class="kpi__label"><?= e(t('users.kpi.active')) ?></span><span class="kpi__value"><?= (int) $kpis['active'] ?></span><span class="kpi__hint"><?= e(t('users.kpi.active_hint', ['n' => $kpis['total'] - $kpis['active']])) ?></span></div>
  <div class="kpi"><span class="kpi__label"><?= e(t('users.kpi.admins')) ?></span><span class="kpi__value"><?= (int) $kpis['admins'] ?></span><span class="kpi__hint"><?= e(t('users.kpi.admins_hint')) ?></span></div>
  <div class="kpi<?= $kpis['pending'] > 0 ? ' kpi--warn' : '' ?>"><span class="kpi__label"><?= e(t('users.kpi.pending')) ?></span><span class="kpi__value"><?= (int) $kpis['pending'] ?></span><span class="kpi__hint"><?= e(t('users.kpi.pending_hint')) ?></span></div>
  <div class="kpi"><span class="kpi__label"><?= e(t('users.kpi.never')) ?></span><span class="kpi__value"><?= (int) $kpis['never'] ?></span><span class="kpi__hint"><?= e(t('users.kpi.never_hint')) ?></span></div>
</section>
<section class="card">
  <table class="table">
    <thead><tr>
      <th scope="col"><?= e(t('users.name')) ?></th>
      <th scope="col" data-priority="low"><?= e(t('users.email')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('users.role')) ?></th>
      <th scope="col" data-x="date"><?= e(t('users.last_login')) ?></th>
      <th scope="col" class="num" data-x="int" data-priority="low"><?= e(t('users.col.actions_30d')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('common.status')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $u):
        $status = !$u['is_active'] ? [Tone::Neutral, t('common.inactive')] : ($u['must_change_password'] ? [Tone::Warn, t('users.status_pending')] : [Tone::Ok, t('common.active')]); ?>
      <tr class="row-link<?= $u['is_active'] ? '' : ' row--muted' ?>" tabindex="0" data-href="<?= e(url('/settings/users/' . $u['id'])) ?>">
        <td>
          <span class="user-cell">
            <span class="avatar avatar--sm" aria-hidden="true"><?= e(user_initials((string) $u['name'])) ?></span>
            <span><strong><?= e($u['name']) ?></strong><?php if ((int) $u['id'] === $me): ?> <span class="tag"><?= e(t('users.you')) ?></span><?php endif; ?></span>
          </span>
        </td>
        <td><?= e($u['email']) ?></td>
        <td data-filter="<?= e(t('role.' . $u['role'])) ?>"><span class="role-pill role-pill--<?= e($u['role']) ?>"><?= e(t('role.' . $u['role'])) ?></span></td>
        <td data-order="<?= e((string) $u['last_login_at']) ?>"><?= $u['last_login_at'] ? e(format_datetime($u['last_login_at'])) : '<span class="muted">' . e(t('users.never')) . '</span>' ?></td>
        <td class="num" data-order="<?= (int) $u['actions_30d'] ?>"><?= (int) $u['actions_30d'] ?></td>
        <td data-filter="<?= e($status[1]) ?>"><?= badge($status[0], $status[1]) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
