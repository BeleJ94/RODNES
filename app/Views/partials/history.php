<?php /** @var list<array<string, mixed>> $history @var string $statusPrefix ex. « rfq_status. » */ ?>
<section class="card">
  <div class="card__title"><h2><?= icon('history', 'title-icon') ?><?= e(t('history.title')) ?></h2></div>
  <?php if ($history === []): ?>
    <p class="muted"><?= e(t('history.empty')) ?></p>
  <?php else: ?>
    <ol class="timeline">
      <?php foreach ($history as $h):
          $changes = $h['changes'] ? (json_decode((string) $h['changes'], true) ?: []) : [];
          $status = $changes['status']['to'] ?? null; ?>
        <li>
          <p class="timeline__head"><strong><?= e(t('history.action.' . $h['action'])) ?></strong>
            <?php if (is_string($status) && isset($statusPrefix)): ?> → <?= e(t($statusPrefix . $status)) ?><?php endif; ?></p>
          <p class="timeline__meta"><?= e(format_datetime($h['created_at'])) ?> · <?= e($h['user_name'] ?? t('history.system')) ?></p>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</section>
