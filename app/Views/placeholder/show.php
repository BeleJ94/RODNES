<?php /** @var string $title @var string $icon */ ?>
<header class="page-header">
  <div class="page-title">
    <span class="page-title__icon"><?= icon($icon) ?></span>
    <div>
      <h1><?= e($title) ?></h1>
      <p class="page-meta"><?= e(t('placeholder.meta')) ?></p>
    </div>
  </div>
</header>
<section class="card">
  <div class="empty-state">
    <?= icon('construction') ?>
    <p><strong><?= e(t('placeholder.heading')) ?></strong></p>
    <p class="muted"><?= e(t('placeholder.text')) ?></p>
  </div>
</section>
