<?php
/**
 * Connexion : à gauche, présentation d'ETHAN avec une illustration du métier (de la demande de prix du site minier
 * à la facture : sourcing, transport, livraison avec POD) ; à droite, le formulaire.
 */
$steps = [
    ['x' => 52, 'label' => t('auth.flow.rfq')],
    ['x' => 150, 'label' => t('auth.flow.sourcing')],
    ['x' => 258, 'label' => t('auth.flow.transport')],
    ['x' => 368, 'label' => t('auth.flow.delivery')],
    ['x' => 446, 'label' => t('auth.flow.invoice')],
];
?>
<div class="auth">
  <section class="auth__visual" aria-hidden="true">
    <div class="auth__visual-inner">
      <div class="auth__head">
        <span class="brand-mark brand-mark--lg"><img src="<?= e(asset('img/logo.png')) ?>" alt="" width="46" height="46"></span>
        <div>
          <p class="auth__brand">ETHAN</p>
          <p class="auth__kicker"><?= e(t('auth.kicker')) ?></p>
        </div>
      </div>
      <p class="auth__tagline"><?= e(t('auth.tagline')) ?></p>

      <svg class="auth__art" viewBox="0 0 480 270" role="presentation" focusable="false">
        <defs>
          <linearGradient id="la-sky" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".10"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></linearGradient>
          <linearGradient id="la-ore" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#FFC53D"/><stop offset="1" stop-color="#F08C1B"/></linearGradient>
        </defs>
        <rect x="6" y="6" width="468" height="258" rx="18" fill="url(#la-sky)" stroke="#fff" stroke-opacity=".12"/>
        <!-- collines du Katanga -->
        <path d="M6 196 C 70 168, 120 182, 180 170 S 300 150, 360 166 S 450 172, 474 160 L474 246 Q474 264 456 264 L24 264 Q6 264 6 246 Z" fill="#fff" fill-opacity=".05"/>
        <!-- route -->
        <path d="M18 222 C 120 210, 200 232, 300 218 S 430 212, 462 220" fill="none" stroke="#fff" stroke-opacity=".28" stroke-width="10" stroke-linecap="round"/>
        <path d="M18 222 C 120 210, 200 232, 300 218 S 430 212, 462 220" fill="none" stroke="#FFC53D" stroke-opacity=".7" stroke-width="1.6" stroke-dasharray="8 9"/>

        <!-- 1. demande de prix du site minier -->
        <g transform="translate(26 120)">
          <rect width="52" height="66" rx="6" fill="#fff"/>
          <path d="M38 0 v12 h14" fill="#E2E8F0"/>
          <rect x="9" y="12" width="22" height="5" rx="2" fill="#0E4D64"/>
          <rect x="9" y="25" width="34" height="3" rx="1.5" fill="#CBD5E1"/>
          <rect x="9" y="33" width="28" height="3" rx="1.5" fill="#CBD5E1"/>
          <rect x="9" y="41" width="32" height="3" rx="1.5" fill="#CBD5E1"/>
          <circle cx="40" cy="54" r="8" fill="#36C46A"/><path d="M36 54 l3 3 l5 -6" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </g>

        <!-- 2. fournisseurs (usine) -->
        <g transform="translate(118 132)">
          <path d="M0 58 V22 l16 10 V22 l16 10 V22 l16 10 V8 h10 v50 Z" fill="#fff" fill-opacity=".9"/>
          <rect x="48" y="0" width="8" height="20" fill="#fff" fill-opacity=".9"/>
          <rect x="6" y="40" width="9" height="9" rx="1.5" fill="#0E4D64"/><rect x="22" y="40" width="9" height="9" rx="1.5" fill="#0E4D64"/><rect x="38" y="40" width="9" height="9" rx="1.5" fill="#0E4D64"/>
          <circle cx="52" cy="-6" r="4" fill="#fff" fill-opacity=".35"/><circle cx="58" cy="-14" r="5" fill="#fff" fill-opacity=".22"/>
          <rect x="62" y="34" width="14" height="24" rx="2" fill="url(#la-ore)"/>
          <rect x="66" y="40" width="6" height="2" fill="#fff" fill-opacity=".7"/>
        </g>

        <!-- 3. transport (camion) -->
        <g class="auth__truck" transform="translate(222 176)">
          <rect x="0" y="0" width="58" height="30" rx="3" fill="#fff"/>
          <rect x="4" y="5" width="50" height="4" rx="2" fill="#0E4D64" fill-opacity=".85"/>
          <rect x="4" y="12" width="34" height="3" rx="1.5" fill="#CBD5E1"/>
          <path d="M60 8 h14 l9 11 v11 h-23 Z" fill="url(#la-ore)"/>
          <rect x="64" y="11" width="9" height="7" rx="1" fill="#0E4D64" fill-opacity=".6"/>
          <circle cx="14" cy="32" r="6" fill="#1F2937" stroke="#fff" stroke-width="2"/>
          <circle cx="44" cy="32" r="6" fill="#1F2937" stroke="#fff" stroke-width="2"/>
          <circle cx="72" cy="32" r="6" fill="#1F2937" stroke="#fff" stroke-width="2"/>
          <path d="M-18 10 h12 M-24 18 h16 M-16 26 h10" stroke="#fff" stroke-opacity=".45" stroke-width="2" stroke-linecap="round"/>
        </g>

        <!-- 4. site minier : chevalement, terrils, POD signé -->
        <g transform="translate(330 104)">
          <path d="M14 96 L34 18 L54 96" fill="none" stroke="#fff" stroke-width="4" stroke-linejoin="round"/>
          <path d="M22 66 h24 M27 44 h14" stroke="#fff" stroke-width="3"/>
          <circle cx="34" cy="16" r="10" fill="none" stroke="#fff" stroke-width="3"/>
          <circle cx="34" cy="16" r="2.5" fill="#fff"/>
          <path d="M34 26 V96" stroke="#fff" stroke-opacity=".5" stroke-width="1.5" stroke-dasharray="3 3"/>
          <path d="M44 96 L66 70 L90 96 Z" fill="url(#la-ore)"/>
          <path d="M70 96 L86 80 L104 96 Z" fill="#36C46A" fill-opacity=".85"/>
          <g transform="translate(54 -14)">
            <rect width="44" height="34" rx="6" fill="#fff"/>
            <text x="22" y="14" text-anchor="middle" font-size="9" font-weight="700" fill="#0E4D64">POD</text>
            <path d="M8 24 c5 -6 8 4 13 -1 s7 -4 10 0" fill="none" stroke="#0E4D64" stroke-width="1.6" stroke-linecap="round"/>
            <circle cx="40" cy="4" r="7" fill="#36C46A"/><path d="M37 4 l2 2 l4 -4" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </g>
        </g>

        <!-- 5. facture / encaissement -->
        <g transform="translate(424 140)">
          <circle cx="22" cy="22" r="22" fill="url(#la-ore)"/>
          <circle cx="22" cy="22" r="16" fill="none" stroke="#fff" stroke-opacity=".7" stroke-width="1.5"/>
          <text x="22" y="28" text-anchor="middle" font-size="17" font-weight="700" fill="#fff">$</text>
        </g>

        <!-- fil du processus -->
        <path d="M52 52 H446" stroke="#fff" stroke-opacity=".3" stroke-width="2" stroke-dasharray="2 6" stroke-linecap="round"/>
        <?php foreach ($steps as $i => $s): ?>
          <g transform="translate(<?= $s['x'] ?> 52)">
            <circle r="13" fill="#0B3A4B" stroke="<?= $i === 4 ? '#FFC53D' : '#fff' ?>" stroke-opacity=".9" stroke-width="2"/>
            <text y="4.5" text-anchor="middle" font-size="11" font-weight="700" fill="#fff"><?= $i + 1 ?></text>
            <text y="32" text-anchor="middle" font-size="10.5" font-weight="600" fill="#fff" fill-opacity=".9"><?= e($s['label']) ?></text>
          </g>
        <?php endforeach; ?>
      </svg>

      <ul class="auth__points">
        <li><?= icon('scale') ?><span><?= e(t('auth.point_compliance')) ?></span></li>
        <li><?= icon('truck') ?><span><?= e(t('auth.point_tracking')) ?></span></li>
        <li><?= icon('file-check') ?><span><?= e(t('auth.point_pod')) ?></span></li>
      </ul>
    </div>
  </section>

  <section class="auth__panel">
    <div class="auth__top"><?= partial('partials/lang-switch') ?></div>
    <div class="auth__card">
      <span class="brand-mark auth__mobile-mark"><img src="<?= e(asset('img/logo.png')) ?>" alt="" width="28" height="28"></span>
      <div>
        <h1><?= e(t('auth.heading')) ?></h1>
        <p class="muted"><?= e(t('auth.subheading')) ?></p>
      </div>
      <?= partial('partials/flashes') ?>
      <form method="post" action="<?= e(url('/login')) ?>" class="form auth__form" data-no-ajax data-login>
        <?= csrf_field() ?>
        <div class="field">
          <label for="email" class="required"><?= e(t('auth.email')) ?></label>
          <div class="input-icon input-icon--lead">
            <?= icon('mail', 'input-icon__lead') ?>
            <input id="email" name="email" type="email" autocomplete="username" required maxlength="190" value="<?= e(old('email')) ?>" placeholder="<?= e(t('auth.email_placeholder')) ?>" autofocus>
          </div>
        </div>
        <div class="field">
          <label for="password" class="required"><?= e(t('auth.password')) ?></label>
          <div class="input-icon input-icon--lead">
            <?= icon('lock', 'input-icon__lead') ?>
            <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="1024">
            <button type="button" class="icon-btn" data-toggle-password aria-controls="password" aria-pressed="false"
                    aria-label="<?= e(t('auth.show_password')) ?>" title="<?= e(t('auth.show_password')) ?>"><?= icon('eye') ?></button>
          </div>
          <p class="auth__caps" data-caps-warning hidden><?= icon('arrow-big-up') ?><?= e(t('auth.caps_lock')) ?></p>
        </div>
        <button type="submit" class="btn btn--primary btn--block btn--lg" data-login-submit data-busy-label="<?= e(t('auth.signing_in')) ?>"><?= icon('log-in') ?><span><?= e(t('auth.submit')) ?></span></button>
      </form>
      <p class="auth__secure"><?= icon('shield-check') ?><span><?= e(t('auth.secure')) ?></span></p>
    </div>
    <p class="auth__foot">ETHAN · built by BELEJ-CONSULTING</p>
  </section>
</div>
