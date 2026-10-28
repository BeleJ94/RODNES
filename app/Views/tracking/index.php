<?php

use App\Services\SalesTrackingService;

/**
 * @var list<array<string, mixed>> $rows @var array<string, mixed> $summary
 * @var array{client_id: ?int, from: ?string, to: ?string, stage: string} $filters
 * @var list<array{id: int, label: string}> $clients @var string $mode
 */

// Liens de l'entonnoir : mêmes client / période, étape changée (ou retirée si déjà active).
$base = array_filter(['client_id' => $filters['client_id'], 'from' => $filters['from'], 'to' => $filters['to']]);
$stageIcons = ['to_answer' => 'inbox', 'awaiting_client' => 'send', 'awaiting_po' => 'file-clock', 'won' => 'trophy', 'lost' => 'thumbs-down', 'cancelled' => 'ban'];
?>
<?= partial('partials/page-header', ['icon' => 'route', 'title' => t('tracking.title'), 'meta' => e(t('tracking.meta')), 'actions' => export_button()]) ?>
<?= partial('tracking/_summary', ['summary' => $summary, 'mode' => $mode, 'withReasons' => false]) ?>

<section class="card">
  <nav class="funnel" aria-label="<?= e(t('tracking.col.stage')) ?>">
    <a class="funnel__step<?= $filters['stage'] === '' ? ' is-active' : '' ?>" href="<?= e(url('/tracking', $base)) ?>"<?= $filters['stage'] === '' ? ' aria-current="true"' : '' ?>>
      <span class="funnel__label"><?= e(t('tracking.all_stages')) ?></span>
      <span class="funnel__count"><?= (int) $summary['rfqs'] ?></span>
    </a>
    <?php foreach (SalesTrackingService::STAGES as $stage):
        $s = $summary['stages'][$stage];
        if ($stage === 'cancelled' && $s['n'] === 0) {
            continue;
        }
        $active = $filters['stage'] === $stage; ?>
      <a class="funnel__step funnel__step--<?= e($stage) ?><?= $active ? ' is-active' : '' ?>" href="<?= e(url('/tracking', $base + ($active ? [] : ['stage' => $stage]))) ?>"<?= $active ? ' aria-current="true"' : '' ?>>
        <span class="funnel__label"><?= icon($stageIcons[$stage]) ?><?= e(t('tracking.stage.' . $stage)) ?></span>
        <span class="funnel__count"><?= (int) $s['n'] ?></span>
        <span class="funnel__amount"><?= $s['amount'] > 0 ? e(format_money((int) $s['amount'], 'USD')) : '&nbsp;' ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <form class="filters filters--inline" method="get" action="<?= e(url('/tracking')) ?>" data-autosubmit>
    <?php if ($filters['stage'] !== ''): ?><input type="hidden" name="stage" value="<?= e($filters['stage']) ?>"><?php endif; ?>
    <div class="filters__fields">
      <label class="filter"><span><?= e(t('rfqs.col.client')) ?></span>
        <select name="client_id">
          <option value=""><?= e(t('filters.all')) ?></option>
          <?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $filters['client_id'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['label']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="filter"><span><?= e(t('tracking.received_from')) ?></span><input type="date" name="from" value="<?= e($filters['from'] ?? '') ?>"></label>
      <label class="filter"><span><?= e(t('statements.to')) ?></span><input type="date" name="to" value="<?= e($filters['to'] ?? '') ?>"></label>
    </div>
    <?php if ($base !== [] || $filters['stage'] !== ''): ?>
      <a class="btn btn--ghost btn--sm" href="<?= e(url('/tracking')) ?>"><?= icon('rotate-ccw') ?><span><?= e(t('filters.reset')) ?></span></a>
    <?php endif; ?>
  </form>

  <?php if ($rows === []): ?>
    <div class="empty-state"><?= icon('route') ?><p><?= e(t('tracking.empty')) ?></p></div>
  <?php else: ?>
    <?= partial('tracking/_table', ['rows' => $rows, 'mode' => $mode, 'columnFilters' => false]) ?>
  <?php endif; ?>

  <?php if ($summary['reasons'] !== []): ?>
    <div class="reasons">
      <span class="reasons__title"><?= icon('thumbs-down') ?><?= e(t('tracking.reasons')) ?></span>
      <?php foreach ($summary['reasons'] as $key => $n): ?>
        <span class="chip"><?= e(t('lost_reason.' . $key)) ?> · <?= (int) $n ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <p class="hint"><?= e(t('tracking.hint', ['unit' => t('tracking.days_' . $mode)])) ?></p>
</section>
