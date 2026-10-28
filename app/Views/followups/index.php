<?php

/**
 * @var list<array<string, mixed>> $due relances échues ou du jour
 * @var list<array<string, mixed>> $upcoming relances des 14 prochains jours
 * @var list<string> $kinds types visibles @var string $kind @var bool $mine
 */

$today = date('Y-m-d');
$table = static function (array $rows) use ($today): string {
    ob_start(); ?>
    <table class="table" data-dt="compact">
      <thead><tr>
        <th scope="col" data-filter="date"><?= e(t('followups.col.due')) ?></th>
        <th scope="col" data-filter="select"><?= e(t('followups.col.kind')) ?></th>
        <th scope="col"><?= e(t('followups.col.document')) ?></th>
        <th scope="col" data-filter="select"><?= e(t('followups.col.party')) ?></th>
        <th scope="col" class="num"><?= e(t('followups.col.round')) ?></th>
        <th scope="col"><?= e(t('followups.col.last')) ?></th>
        <th scope="col" data-filter="select"><?= e(t('followups.col.owner')) ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $f): ?>
        <tr class="row-link" tabindex="0" data-href="<?= e($f['url'] . '#followups') ?>">
          <td class="nowrap" data-order="<?= e($f['due_on']) ?>"><?= e(format_date($f['due_on'])) ?>
            <?php if ($f['due_on'] < $today): ?> <span class="chip chip--bad"><?= e(t('followups.late', ['n' => $f['late_days']])) ?></span><?php elseif ($f['due_on'] === $today): ?> <span class="chip chip--warn"><?= e(t('followups.today')) ?></span><?php endif; ?></td>
          <td data-filter="<?= e(t('followups.kind.' . $f['kind'])) ?>"><?= e(t('followups.kind.' . $f['kind'])) ?>
             <span class="sub"><?= e(t('followups.since.' . $f['kind'], ['date' => format_date($f['start'])])) ?></span></td>
          <td class="nowrap"><a href="<?= e($f['url'] . '#followups') ?>"><strong><?= e($f['reference']) ?></strong></a>
            <?php if ($f['kind'] === 'quote' && $f['expires_on'] !== null): ?> <span class="<?= $f['expires_on'] < $today ? 'overdue' : 'sub' ?>"><?= e(t('followups.valid_until', ['date' => format_date($f['expires_on'])])) ?></span><?php endif; ?></td>
          <td><?= e($f['party']) ?></td>
          <td class="num nowrap"><?= (int) $f['done'] + 1 ?> / <?= (int) $f['planned'] ?></td>
          <td class="nowrap"><?= $f['last_on'] ? e(format_date($f['last_on'])) : '<span class="muted">—</span>' ?></td>
          <td><?= e($f['owner_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php return (string) ob_get_clean();
};
?>
<?= partial('partials/page-header', ['icon' => 'phone-call', 'title' => t('followups.title'), 'meta' => e(t('followups.meta', ['n' => count($due)]))]) ?>
<section class="card">
  <form class="filters" method="get" action="<?= e(url('/followups')) ?>" data-autosubmit>
    <div class="filters__fields">
      <label class="filter"><span><?= e(t('followups.col.kind')) ?></span>
        <select name="kind">
          <option value=""><?= e(t('filters.all')) ?></option>
          <?php foreach ($kinds as $k): ?><option value="<?= e($k) ?>"<?= $kind === $k ? ' selected' : '' ?>><?= e(t('followups.kind.' . $k)) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="filter"><span><?= e(t('followups.scope')) ?></span>
        <select name="scope">
          <option value=""><?= e(t('followups.scope_all')) ?></option>
          <option value="mine"<?= $mine ? ' selected' : '' ?>><?= e(t('followups.scope_mine')) ?></option>
        </select>
      </label>
    </div>
  </form>
  <div class="card__title"><h2><?= icon('bell-ring', 'title-icon') ?><?= e(t('followups.due_title')) ?></h2></div>
  <?php if ($due === []): ?>
    <div class="empty-state"><?= icon('check-check') ?><p><?= e(t('followups.empty_due')) ?></p></div>
  <?php else: ?>
    <?= $table($due) ?>
  <?php endif; ?>
</section>
<section class="card section-gap">
  <div class="card__title"><h2><?= icon('calendar-days', 'title-icon') ?><?= e(t('followups.upcoming_title')) ?></h2></div>
  <?php if ($upcoming === []): ?>
    <p class="muted"><?= e(t('followups.empty_upcoming')) ?></p>
  <?php else: ?>
    <?= $table($upcoming) ?>
  <?php endif; ?>
  <p class="hint"><?= e(t('followups.hint')) ?><?php if (can('settings.company')): ?> <a href="<?= e(url('/settings/company#delays')) ?>"><?= e(t('followups.settings_link')) ?></a><?php endif; ?></p>
</section>
