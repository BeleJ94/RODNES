<?php

use App\Domain\Invoicing\Receivables;

/**
 * Relevé de compte client imprimable.
 *
 * @var array<string, mixed> $client
 * @var array<string, string> $s
 * @var array<string, int> $aging
 * @var int $advances
 */
?>
<article class="doc">
  <header class="doc__head">
    <div>
      <?= doc_logo() ?>
      <p class="doc__company"><?= e($s['company_name']) ?></p>
      <p class="doc__muted"><?= e($s['company_address']) ?></p>
      <p class="doc__muted"><?= e(implode(' · ', array_filter([$s['company_phone'], $s['company_email']]))) ?></p>
    </div>
    <div class="doc__title">
      <h1><?= e(t('statements.doc_title')) ?></h1>
      <p class="doc__muted"><?= e(t('statements.period', ['from' => format_date($from), 'to' => format_date($to)])) ?></p>
      <p class="doc__muted"><?= e(t('quotations.doc_date', ['date' => format_date(date('Y-m-d'))])) ?></p>
    </div>
  </header>
  <section class="doc__parties">
    <div>
      <p class="doc__label"><?= e(t('statements.doc_client')) ?></p>
      <p><strong><?= e($client['name']) ?></strong> (<?= e($client['code']) ?>)</p>
      <p><?= e(trim($client['address'] . ' ' . $client['city'])) ?></p>
    </div>
    <div>
      <p class="doc__label"><?= e(t('statements.closing')) ?></p>
      <p><strong><?= e(format_money($closing, 'USD')) ?></strong></p>
    </div>
  </section>
  <?= partial('statements/_ledger', get_defined_vars() + ['links' => false, 'tableClass' => 'doc__table']) ?>
  <table class="doc__table">
    <thead><tr><?php foreach (Receivables::BUCKETS as $bucket): ?><th class="num"><?= e(t('aging.' . $bucket)) ?></th><?php endforeach; ?></tr></thead>
    <tbody><tr><?php foreach (Receivables::BUCKETS as $bucket): ?><td class="num"><?= e(format_money($aging[$bucket], 'USD')) ?></td><?php endforeach; ?></tr></tbody>
  </table>
  <section class="doc__terms">
    <?php if ($advances > 0): ?><p><?= e(t('statements.doc_advances', ['amount' => format_money($advances, 'USD')])) ?></p><?php endif; ?>
    <p><?= e(t('statements.doc_note')) ?></p>
    <?php if ($s['company_bank'] !== ''): ?>
      <p class="doc__label"><?= e(t('quotations.doc_bank')) ?></p>
      <p><?= nl2br(e($s['company_bank'])) ?></p>
    <?php endif; ?>
  </section>
  <footer class="doc__foot"><?= e($s['company_name']) ?> · ETHAN</footer>
</article>
