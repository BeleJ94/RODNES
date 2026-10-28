<?php

/** @var string $content */
/** @var string $title */

use App\Core\I18n;
use App\Core\Request;
use App\Security\Auth;
use App\Services\AlertService;

$user = app(Auth::class)->user();
$alertService = app(AlertService::class);
$alerts = $alertService->all();
$alertTotal = $alertService->total();
$navBadges = $alertService->countsByNav();
$currentPath = app(Request::class)->path;
$menu = require BASE_PATH . '/config/menu.php';
?><!doctype html>
<html lang="<?= e(app(I18n::class)->locale()) ?>">
<head>
<?= partial('partials/head', ['title' => $title ?? '']) ?>
</head>
<body class="layout-app">
<a class="skip-link" href="#main"><?= e(t('common.skip_to_content')) ?></a>
<div class="shell">
  <aside class="sidebar" id="sidebar" aria-label="<?= e(t('nav.aria')) ?>">
    <a class="sidebar__brand" href="<?= e(url('/')) ?>">
      <span class="brand-mark" aria-hidden="true"><img src="<?= e(asset('img/logo.png')) ?>" alt="" width="28" height="28"></span>
      <span class="brand-text"><strong>ETHAN</strong><small><?= e(setting('company_name')) ?></small></span>
    </a>
    <nav class="nav">
      <?php foreach ($menu as $section):
          $items = array_values(array_filter($section['items'], static fn (array $item): bool => can($item['perm'])));
          if ($items === []) {
              continue;
          }
          $isActive = static fn (array $item): bool => $item['path'] === '/' ? $currentPath === '/' : str_starts_with($currentPath, $item['path']);
          $sectionActive = (bool) array_filter($items, $isActive);
          $sectionBadge = array_sum(array_map(static fn (array $item): int => $navBadges[$item['path']] ?? 0, $items));
          $key = substr($section['section'], strlen('nav.section.'));
          $links = '';
          foreach ($items as $item) {
              $badge = $navBadges[$item['path']] ?? 0;
              $links .= '<a class="nav__link" href="' . e(url($item['path'])) . '"' . ($isActive($item) ? ' aria-current="page"' : '') . '>'
                  . icon($item['icon']) . '<span class="nav__label">' . e(t($item['label'])) . '</span>'
                  . ($badge > 0 ? '<span class="nav__badge">' . (int) $badge . '</span>' : '') . '</a>';
          }
          ?>
        <?php if (!empty($section['pinned'])): ?>
          <div class="nav__pinned" aria-label="<?= e(t($section['section'])) ?>"><?= $links ?></div>
        <?php else: ?>
          <details class="nav__group<?= $sectionActive ? ' is-current' : '' ?>" data-nav-section="<?= e($key) ?>"<?= $sectionActive || empty($section['collapsed']) ? ' open' : '' ?>>
            <summary class="nav__section">
              <?php if (isset($section['step'])): ?><span class="nav__step" aria-hidden="true"><?= (int) $section['step'] ?></span><?php else: ?><?= icon($section['icon'] ?? 'folder', 'nav__section-icon') ?><?php endif; ?>
              <span class="nav__section-label"><?= e(t($section['section'])) ?></span>
              <?php if ($sectionBadge > 0): ?><span class="nav__sum" title="<?= e(t('nav.section_alerts', ['n' => $sectionBadge])) ?>"><?= (int) $sectionBadge ?></span><?php endif; ?>
              <?= icon('chevron-down', 'nav__chevron') ?>
            </summary>
            <div class="nav__items"><?= $links ?></div>
          </details>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar__foot">
      <p class="sidebar__credit">ETHAN · built by BELEJ-CONSULTING</p>
    </div>
  </aside>
  <div class="sidebar-backdrop" data-sidebar-close></div>

  <div class="main-col">
    <header class="topbar">
      <button class="icon-btn topbar__menu" type="button" data-toggle-sidebar aria-controls="sidebar" aria-expanded="false"
              aria-label="<?= e(t('nav.open_menu')) ?>" title="<?= e(t('nav.open_menu')) ?>"><?= icon('menu') ?></button>
      <span class="topbar__title"><?= e($title ?? '') ?></span>
      <div class="topbar__right">
        <?= partial('partials/lang-switch') ?>
        <div class="notif">
          <button type="button" class="icon-btn notif__toggle" data-notif-toggle aria-haspopup="true" aria-expanded="false"
                  aria-label="<?= e(t('alerts.bell', ['n' => $alertTotal])) ?>" title="<?= e(t('alerts.title')) ?>">
            <?= icon('bell') ?>
            <?php if ($alertTotal > 0): ?><span class="notif__badge"><?= $alertTotal > 99 ? '99+' : (int) $alertTotal ?></span><?php endif; ?>
          </button>
          <div class="notif__panel" data-notif-panel hidden>
            <p class="notif__head"><?= e(t('alerts.title')) ?></p>
            <?php if ($alerts === []): ?>
              <p class="empty-state empty-state--ok"><?= icon('circle-check') ?><span><?= e(t('alerts.none')) ?></span></p>
            <?php else: ?>
              <?php foreach ($alerts as $alert): ?>
                <a class="notif__item notif__item--<?= e($alert['level']) ?>" href="<?= e($alert['url']) ?>">
                  <?= icon($alert['icon']) ?><span><?= e($alert['label']) ?></span>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="user-chip">
          <a class="user-chip__link" href="<?= e(url('/account')) ?>" title="<?= e(t('account.title')) ?>">
            <span class="avatar" aria-hidden="true"><?= e($user?->initials()) ?></span>
            <span class="user-chip__text"><strong><?= e($user?->name) ?></strong><small><?= e($user ? t($user->role->labelKey()) : '') ?></small></span>
          </a>
          <form method="post" action="<?= e(url('/logout')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="icon-btn" aria-label="<?= e(t('auth.logout')) ?>" title="<?= e(t('auth.logout')) ?>"><?= icon('log-out') ?></button>
          </form>
        </div>
      </div>
    </header>
    <main id="main" class="content" tabindex="-1">
      <?= partial('partials/flashes') ?>
      <?= $content ?>
    </main>
  </div>
</div>
<?= partial('partials/scripts') ?>
</body>
</html>
