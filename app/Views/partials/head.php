<?php /** @var string $title */ ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(($title ?? '') !== '' ? $title . ' · ETHAN' : 'ETHAN') ?></title>
<link rel="icon" href="<?= e(asset('img/favicon-32.png')) ?>" type="image/png" sizes="32x32">
<link rel="icon" href="<?= e(asset('img/favicon-64.png')) ?>" type="image/png" sizes="64x64">
<link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/datatables/dataTables.dataTables.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/sweetalert2/sweetalert2.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
