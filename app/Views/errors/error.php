<?php /** @var int $status @var string $title @var string $message @var ?string $debug */ ?>
<div class="error-page">
  <p class="error-page__code"><?= (int) $status ?></p>
  <h1><?= e($title) ?></h1>
  <p class="muted"><?= e($message) ?></p>
  <a class="btn btn--primary" href="<?= e(url('/')) ?>"><?= icon('house') ?><span><?= e(t('errors.back_home')) ?></span></a>
  <?php if (!empty($debug)): ?>
    <pre class="error-page__debug"><?= e($debug) ?></pre>
  <?php endif; ?>
</div>
