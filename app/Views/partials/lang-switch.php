<?php

use App\Core\I18n;
use App\Core\Request;

$current = app(I18n::class)->locale();
?>
<form method="post" action="<?= e(url('/locale')) ?>" class="lang-switch" aria-label="<?= e(t('common.language')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="back" value="<?= e(app(Request::class)->fullPath()) ?>">
  <?php foreach ((array) config('app.locales') as $locale): ?>
    <button type="submit" name="locale" value="<?= e($locale) ?>" class="lang-switch__btn" aria-pressed="<?= $locale === $current ? 'true' : 'false' ?>"><?= e(strtoupper($locale)) ?></button>
  <?php endforeach; ?>
</form>
