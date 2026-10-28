<?php

/** @var string $content */

use App\Core\I18n;
?><!doctype html>
<html lang="<?= e(app(I18n::class)->locale()) ?>">
<head>
<?= partial('partials/head', ['title' => $title ?? '']) ?>
</head>
<body class="layout-minimal">
<main id="main" class="minimal">
  <?= $content ?>
</main>
<?= partial('partials/scripts') ?>
</body>
</html>
