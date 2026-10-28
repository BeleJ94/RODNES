<?php

use App\Repositories\ActivityRepository;

/**
 * Entrées du journal : date, auteur, élément (lié à sa fiche), action, détail avant → après dépliable.
 *
 * @var list<array<string, mixed>> $rows @var bool $withUser
 */
$withUser ??= true;
?>
<table class="table activity-table" data-no-dt>
  <thead><tr>
    <th scope="col"><?= e(t('activity.col.date')) ?></th>
    <?php if ($withUser): ?><th scope="col"><?= e(t('activity.col.user')) ?></th><?php endif; ?>
    <th scope="col"><?= e(t('activity.col.entity')) ?></th>
    <th scope="col"><?= e(t('activity.col.action')) ?></th>
    <th scope="col"><?= e(t('activity.col.changes')) ?></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $changes = activity_changes($r['changes']);
      $link = ActivityRepository::link((string) $r['entity_type'], $r['entity_id'] !== null ? (int) $r['entity_id'] : null);
      $ip = ActivityRepository::ip($r['ip']); ?>
    <tr>
      <td class="nowrap"><?= e(format_datetime($r['created_at'])) ?></td>
      <?php if ($withUser): ?>
        <td><?= $r['user_id'] !== null && can('settings.users')
            ? '<a href="' . e(url('/settings/users/' . $r['user_id'])) . '">' . e($r['user_name']) . '</a>'
            : e($r['user_name'] ?? t('history.system')) ?></td>
      <?php endif; ?>
      <td>
        <span class="activity-entity"><?= e(activity_entity_label((string) $r['entity_type'])) ?></span>
        <?php if ($r['entity_id'] !== null && $r['entity_type'] !== 'auth'): ?>
          <?= $link !== null ? '<a href="' . e($link) . '">#' . (int) $r['entity_id'] . '</a>' : '<span class="muted">#' . (int) $r['entity_id'] . '</span>' ?>
        <?php endif; ?>
      </td>
      <td><span class="activity-action activity-action--<?= e(activity_action_tone((string) $r['action'])) ?>"><?= e(activity_action_label((string) $r['action'])) ?></span></td>
      <td class="activity-changes">
        <?php if ($changes === []): ?>
          <span class="muted"><?= $ip !== '' ? e(t('activity.from_ip', ['ip' => $ip])) : '—' ?></span>
        <?php else: ?>
          <details>
            <summary><?= e(t('activity.changes_count', ['n' => count($changes)])) ?> <span class="muted">· <?= e(implode(', ', array_slice(array_column($changes, 'field'), 0, 3))) ?><?= count($changes) > 3 ? '…' : '' ?></span></summary>
            <dl class="diff">
              <?php foreach ($changes as $c): ?>
                <div><dt><?= e($c['field']) ?></dt><dd><del><?= e($c['from']) ?></del><span aria-hidden="true">→</span><ins><?= e($c['to']) ?></ins></dd></div>
              <?php endforeach; ?>
            </dl>
            <?php if ($ip !== ''): ?><p class="muted small"><?= e(t('activity.from_ip', ['ip' => $ip])) ?></p><?php endif; ?>
          </details>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
