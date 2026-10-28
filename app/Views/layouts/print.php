<?php

/** @var string $content */

use App\Core\I18n;
?><!doctype html>
<html lang="<?= e(app(I18n::class)->locale()) ?>">
<head>
<?= partial('partials/head', ['title' => $title ?? '']) ?>
</head>
<body class="layout-print<?= !empty($embed) ? ' layout-print--embed' : '' ?>">
<?php if (empty($embed)): ?>
<div class="print-toolbar">
  <a class="btn btn--ghost" href="<?= e($back ?? url('/')) ?>"><?= icon('arrow-left') ?><span><?= e(t('common.back')) ?></span></a>
  <button type="button" class="btn btn--primary" data-print><?= icon('printer') ?><span><?= e(t('common.print')) ?></span></button>
</div>
<?php endif; ?>
<main id="main" class="print-page">
  <?= $content ?>
</main>
<?= partial('partials/scripts') ?>
</body>
</html>
