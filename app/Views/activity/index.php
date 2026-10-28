<?php

use App\Repositories\ActivityRepository;

/**
 * @var string $tab actions | logins
 * @var array{user: int|null, entity: string, action: string, from: string, to: string, q: string} $filters
 * @var list<array<string, mixed>> $rows @var int $total @var int $page @var int $pages
 * @var list<array<string, mixed>> $logins @var bool $failedOnly
 * @var array<string, list<string>> $catalog actions présentes, par type d'entité
 * @var list<array<string, mixed>> $users
 * @var array{today: int, week: int, users_today: int, failed_today: int} $kpis
 */
$query = array_filter(['tab' => $tab === 'logins' ? 'logins' : null] + $filters + ['failed' => $failedOnly ? '1' : null], static fn ($v): bool => $v !== null && $v !== '');
$actions = $filters['entity'] !== '' ? ($catalog[$filters['entity']] ?? []) : array_values(array_unique(array_merge(...array_values($catalog ?: [[]]))));
sort($actions);
$entities = array_keys($catalog);
usort($entities, static fn (string $a, string $b): int => strcmp(activity_entity_label($a), activity_entity_label($b)));
$today = date('Y-m-d');
$presets = [
    'today' => [$today, $today],
    '7d' => [date('Y-m-d', strtotime('-6 days')), $today],
    '30d' => [date('Y-m-d', strtotime('-29 days')), $today],
];
$filtered = $filters['user'] !== null || $filters['entity'] !== '' || $filters['action'] !== '' || $filters['from'] !== '' || $filters['to'] !== '' || $filters['q'] !== '' || $failedOnly;
?>
<?= partial('partials/page-header', [
    'icon' => 'history',
    'title' => t('activity.title'),
    'meta' => e(t('activity.meta')),
    'actions' => '<a class="btn btn--ghost" href="' . e(url('/settings/activity', $query + ['format' => 'xlsx'])) . '" download>' . icon('file-spreadsheet') . '<span>' . e(t('export.button')) . '</span></a>',
]) ?>
<section class="kpis kpis--compact">
  <a class="kpi kpi--button" href="<?= e(url('/settings/activity', ['from' => $today, 'to' => $today])) ?>"><span class="kpi__label"><?= e(t('activity.kpi.today')) ?></span><span class="kpi__value"><?= (int) $kpis['today'] ?></span><span class="kpi__hint"><?= e(t('activity.kpi.today_hint', ['n' => $kpis['users_today']])) ?></span></a>
  <a class="kpi kpi--button" href="<?= e(url('/settings/activity', ['from' => $presets['7d'][0], 'to' => $today])) ?>"><span class="kpi__label"><?= e(t('activity.kpi.week')) ?></span><span class="kpi__value"><?= (int) $kpis['week'] ?></span><span class="kpi__hint"><?= e(t('activity.kpi.week_hint')) ?></span></a>
  <a class="kpi kpi--button<?= $kpis['failed_today'] > 0 ? ' kpi--warn' : '' ?>" href="<?= e(url('/settings/activity', ['tab' => 'logins', 'failed' => '1', 'from' => $today, 'to' => $today])) ?>"><span class="kpi__label"><?= e(t('activity.kpi.failed')) ?></span><span class="kpi__value"><?= (int) $kpis['failed_today'] ?></span><span class="kpi__hint"><?= e(t('activity.kpi.failed_hint')) ?></span></a>
</section>

<section class="card">
  <nav class="seg-tabs" aria-label="<?= e(t('activity.title')) ?>">
    <a href="<?= e(url('/settings/activity', array_diff_key($query, ['tab' => 1, 'failed' => 1]))) ?>"<?= $tab === 'actions' ? ' aria-current="page"' : '' ?>><?= icon('list') ?><span><?= e(t('activity.tab_actions')) ?></span></a>
    <a href="<?= e(url('/settings/activity', ['tab' => 'logins'] + array_intersect_key($query, ['from' => 1, 'to' => 1, 'q' => 1]))) ?>"<?= $tab === 'logins' ? ' aria-current="page"' : '' ?>><?= icon('log-in') ?><span><?= e(t('activity.tab_logins')) ?></span></a>
  </nav>

  <form class="filters" method="get" action="<?= e(url('/settings/activity')) ?>" data-autosubmit>
    <?php if ($tab === 'logins'): ?><input type="hidden" name="tab" value="logins"><?php endif; ?>
    <div class="filters__fields">
      <?php if ($tab === 'actions'): ?>
        <label class="filter"><span><?= e(t('activity.col.user')) ?></span>
          <select name="user">
            <option value=""><?= e(t('filters.all')) ?></option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int) $u['id'] ?>"<?= $filters['user'] === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?><?= $u['is_active'] ? '' : ' (' . e(t('common.inactive')) . ')' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="filter"><span><?= e(t('activity.col.entity')) ?></span>
          <select name="entity">
            <option value=""><?= e(t('filters.all')) ?></option>
            <?php foreach ($entities as $type): ?>
              <option value="<?= e($type) ?>"<?= $filters['entity'] === $type ? ' selected' : '' ?>><?= e(activity_entity_label($type)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="filter"><span><?= e(t('activity.col.action')) ?></span>
          <select name="action">
            <option value=""><?= e(t('filters.all')) ?></option>
            <?php foreach ($actions as $a): ?>
              <option value="<?= e($a) ?>"<?= $filters['action'] === $a ? ' selected' : '' ?>><?= e(activity_action_label($a)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php else: ?>
        <label class="filter filter--check"><input type="checkbox" name="failed" value="1"<?= $failedOnly ? ' checked' : '' ?>><span><?= e(t('activity.failed_only')) ?></span></label>
      <?php endif; ?>
      <label class="filter"><span><?= e(t('activity.from')) ?></span><input type="date" name="from" value="<?= e($filters['from']) ?>" max="<?= e($today) ?>"></label>
      <label class="filter"><span><?= e(t('activity.to')) ?></span><input type="date" name="to" value="<?= e($filters['to']) ?>" max="<?= e($today) ?>"></label>
      <label class="filter filter--grow"><span><?= e(t($tab === 'logins' ? 'activity.search_email' : 'activity.search')) ?></span><input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="<?= e(t($tab === 'logins' ? 'activity.search_email_ph' : 'activity.search_ph')) ?>"></label>
    </div>
    <div class="filters__presets">
      <?php foreach ($presets as $key => [$from, $to]): ?>
        <a class="preset<?= $filters['from'] === $from && $filters['to'] === $to ? ' is-active' : '' ?>" href="<?= e(url('/settings/activity', ['from' => $from, 'to' => $to] + $query)) ?>"><?= e(t('activity.preset.' . $key)) ?></a>
      <?php endforeach; ?>
      <?php if ($filtered): ?><a class="preset preset--clear" href="<?= e(url('/settings/activity', $tab === 'logins' ? ['tab' => 'logins'] : [])) ?>"><?= icon('x') ?><?= e(t('activity.clear')) ?></a><?php endif; ?>
    </div>
  </form>

  <?php if ($tab === 'actions'): ?>
    <p class="activity-count muted"><?= e(t('activity.count', ['n' => number_format($total, 0, ',', "\u{202F}")])) ?></p>
    <?php if ($rows === []): ?>
      <div class="empty-state"><?= icon('history') ?><p><?= e(t($filtered ? 'activity.empty_filtered' : 'activity.empty')) ?></p></div>
    <?php else: ?>
      <div class="table-scroll"><?= partial('activity/_table', ['rows' => $rows]) ?></div>
      <?php if ($pages > 1): ?>
        <nav class="pager" aria-label="<?= e(t('activity.pages')) ?>">
          <?php if ($page > 1): ?><a class="btn btn--sm" href="<?= e(url('/settings/activity', ['page' => $page - 1] + $query)) ?>"><?= icon('chevron-left') ?><span><?= e(t('activity.newer')) ?></span></a><?php endif; ?>
          <span class="muted"><?= e(t('activity.page_of', ['page' => $page, 'pages' => $pages])) ?></span>
          <?php if ($page < $pages): ?><a class="btn btn--sm" href="<?= e(url('/settings/activity', ['page' => $page + 1] + $query)) ?>"><span><?= e(t('activity.older')) ?></span><?= icon('chevron-right') ?></a><?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  <?php else: ?>
    <?php if ($logins === []): ?>
      <div class="empty-state"><?= icon('log-in') ?><p><?= e(t('activity.no_logins')) ?></p></div>
    <?php else: ?>
      <table class="table" data-no-dt>
        <thead><tr>
          <th scope="col"><?= e(t('activity.col.date')) ?></th>
          <th scope="col"><?= e(t('users.email')) ?></th>
          <th scope="col"><?= e(t('activity.col.user')) ?></th>
          <th scope="col"><?= e(t('activity.col.result')) ?></th>
          <th scope="col"><?= e(t('activity.col.ip')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($logins as $l): ?>
          <tr<?= $l['success'] ? '' : ' class="row--alert"' ?>>
            <td class="nowrap"><?= e(format_datetime($l['created_at'])) ?></td>
            <td><?= e($l['email']) ?></td>
            <td><?= $l['user_id'] !== null && can('settings.users') ? '<a href="' . e(url('/settings/users/' . $l['user_id'])) . '">' . e($l['user_name']) . '</a>' : ($l['user_name'] !== null ? e($l['user_name']) : '<span class="muted">' . e(t('activity.unknown_account')) . '</span>') ?></td>
            <td><?= $l['success'] ? badge(\App\Domain\Shared\Tone::Ok, t('activity.login_ok')) : badge(\App\Domain\Shared\Tone::Critical, t('activity.login_failed')) ?></td>
            <td class="nowrap"><?= e(ActivityRepository::ip($l['ip'])) ?: '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="muted small"><?= e(t('activity.logins_limit')) ?></p>
    <?php endif; ?>
  <?php endif; ?>
</section>
