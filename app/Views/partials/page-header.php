<?php /** @var string $icon @var string $title @var string $meta @var string $actions HTML déjà sûr */ ?>
<header class="page-header">
  <div class="page-title">
    <span class="page-title__icon"><?= icon($icon) ?></span>
    <div>
      <h1><?= e($title) ?></h1>
      <?php if (($meta ?? '') !== ''): ?><p class="page-meta"><?= $meta ?></p><?php endif; ?>
    </div>
  </div>
  <?php if (($actions ?? '') !== ''): ?><div class="page-actions"><?= $actions ?></div><?php endif; ?>
</header>
