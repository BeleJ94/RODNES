<?php

/**
 * @var array<string, mixed> $client
 * @var list<array<string, mixed>> $rows suivi commercial (une ligne par RFQ)
 * @var array<string, mixed> $summary @var string $mode @var array{rfq: int, validity: int} $delays
 */
$actions = (can('rfqs.manage') ? '<a class="btn" href="' . e(url('/rfqs/new', ['client_id' => $client['id']])) . '">' . icon('inbox') . '<span>' . e(t('rfqs.new')) . '</span></a>' : '')
    . (can('clients.manage') ? '<a class="btn btn--primary" href="' . e(url('/clients/' . $client['id'] . '/edit')) . '">' . icon('pencil') . '<span>' . e(t('common.edit')) . '</span></a>' : '');
?>
<?= partial('partials/page-header', [
    'icon' => 'building-2',
    'title' => (string) $client['name'],
    'meta' => '<span class="tag">' . e($client['code']) . '</span> ' . ($client['is_active'] ? '' : badge(\App\Domain\Shared\Tone::Neutral, t('common.inactive'))),
    'actions' => $actions,
]) ?>
<?php if (can('rfqs.view')): ?>
<?= partial('tracking/_summary', ['summary' => $summary, 'mode' => $mode]) ?>
<?php endif; ?>
<?php if (can('rfqs.view')): ?>
  <section class="card">
    <div class="card__title"><h2><?= icon('route', 'title-icon') ?><?= e(t('tracking.client_title')) ?></h2>
      <a class="btn btn--ghost btn--sm" href="<?= e(url('/tracking', ['client_id' => $client['id'], 'format' => 'xlsx'])) ?>" download><?= icon('file-spreadsheet') ?><span><?= e(t('export.button')) ?></span></a></div>
    <?php if ($rows === []): ?>
      <div class="empty-state"><?= icon('inbox') ?><p><?= e(t('rfqs.empty')) ?></p></div>
    <?php else: ?>
      <?= partial('tracking/_table', ['rows' => $rows, 'withClient' => false, 'mode' => $mode]) ?>
    <?php endif; ?>
  </section>
<?php endif; ?>
  <section class="card section-gap">
    <div class="card__title"><h2><?= icon('info', 'title-icon') ?><?= e(t('common.details')) ?></h2></div>
    <dl class="details details--cols">
      <div><dt>RCCM</dt><dd><?= e($client['rccm']) ?: '—' ?></dd></div>
      <div><dt><?= e(t('common.nif')) ?></dt><dd><?= e($client['nif']) ?: '—' ?></dd></div>
      <div><dt><?= e(t('common.id_nat')) ?></dt><dd><?= e($client['id_nat']) ?: '—' ?></dd></div>
      <div><dt><?= e(t('common.address')) ?></dt><dd><?= e(trim($client['address'] . ' ' . $client['city'])) ?: '—' ?></dd></div>
      <div><dt><?= e(t('common.contact')) ?></dt><dd><?= e($client['contact_name']) ?: '—' ?><br><span class="muted"><?= e($client['contact_email']) ?> <?= e($client['contact_phone']) ?></span></dd></div>
      <div><dt><?= e(t('clients.rfq_response_days')) ?></dt><dd><?= e(t('tracking.days_count_' . $mode, ['n' => $delays['rfq']])) ?><?= $client['rfq_response_days'] === null ? ' <span class="muted">' . e(t('clients.default_marker')) . '</span>' : '' ?></dd></div>
      <div><dt><?= e(t('clients.quote_validity_days')) ?></dt><dd><?= e(t('common.days', ['n' => $delays['validity']])) ?><?= $client['quote_validity_days'] === null ? ' <span class="muted">' . e(t('clients.default_marker')) . '</span>' : '' ?></dd></div>
      <div><dt><?= e(t('common.payment_terms')) ?></dt><dd><?= e(t('common.days', ['n' => $client['payment_terms_days']])) ?></dd></div>
      <div><dt><?= e(t('clients.col.vat')) ?></dt><dd><?= $client['vat_applicable'] ? e(t('clients.vat_yes')) : e(t('clients.vat_exempt')) ?></dd></div>
    </dl>
    <?php if ($client['notes']): ?><p class="note"><?= nl2br(e($client['notes'])) ?></p><?php endif; ?>
  </section>
