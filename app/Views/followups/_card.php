<?php

/**
 * Relances d'une pièce : prochaine échéance, formulaire « Relance faite », historique.
 *
 * @var string $kind quote | client_po | supplier_confirm | supplier_ready
 * @var int $subjectId
 * @var array<string, mixed>|null $next relance ouverte (FollowupService::nextFor)
 * @var list<array<string, mixed>> $history relances notées
 * @var bool $canManage
 */
$today = date('Y-m-d');
$late = $next !== null && $next['due_on'] < $today;
$back = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
?>
<section class="card section-gap" id="followups">
  <div class="card__title"><h2><?= icon('phone-call', 'title-icon') ?><?= e(t('followups.card_title.' . $kind)) ?></h2>
    <?php if ($next !== null): ?>
      <span class="chip <?= $late ? 'chip--bad' : ($next['due_on'] === $today ? 'chip--warn' : '') ?>"><?= e(t('followups.next', ['n' => $next['done'] + 1, 'planned' => $next['planned'], 'date' => format_date($next['due_on'])])) ?></span>
    <?php elseif ($history !== []): ?>
      <span class="chip"><?= e(t('followups.all_done')) ?></span>
    <?php endif; ?>
  </div>
  <?php if ($next !== null && $canManage): ?>
    <form method="post" action="<?= e(url('/followups')) ?>" class="form form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="kind" value="<?= e($kind) ?>">
      <input type="hidden" name="subject_id" value="<?= (int) $subjectId ?>">
      <input type="hidden" name="back" value="<?= e($back) ?>">
      <?= form_select('channel', t('followups.channel'), array_combine(\App\Services\FollowupService::CHANNELS, array_map(static fn (string $c): string => t('followups.channel_' . $c), \App\Services\FollowupService::CHANNELS)), 'phone', ['required' => true]) ?>
      <?= form_input('contacted_on', t('followups.contacted_on'), $today, ['type' => 'date', 'required' => true, 'attrs' => ' max="' . e($today) . '"']) ?>
      <?= form_textarea('comment', t('followups.comment'), '', ['span' => true, 'maxlength' => 500, 'hint' => t('followups.comment_hint.' . $kind)]) ?>
      <div class="span-2"><button type="submit" class="btn btn--primary"><?= icon('check') ?><span><?= e(t('followups.record')) ?></span></button></div>
    </form>
  <?php elseif ($next === null && $history === []): ?>
    <p class="muted"><?= e(t('followups.none_planned')) ?></p>
  <?php endif; ?>
  <?php if ($history !== []): ?>
    <ul class="timeline section-gap">
      <?php foreach ($history as $h): ?>
        <li><strong><?= e(format_date($h['contacted_on'])) ?></strong> · <?= e(t('followups.channel_' . $h['channel'])) ?>
          <span class="muted">· <?= e($h['created_by_name'] ?? '') ?></span>
          <?php if ($h['comment']): ?><br><?= nl2br(e($h['comment'])) ?><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
