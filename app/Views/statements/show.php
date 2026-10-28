<?php

use App\Domain\Invoicing\Receivables;

/**
 * @var array<string, mixed> $client
 * @var string $from @var string $to
 * @var list<array<string, mixed>> $open
 * @var array<string, int> $aging
 * @var int $advances
 */

$period = ['from' => $from, 'to' => $to];
$buttons = '<a class="btn" href="' . e(url('/statements/' . $client['id'] . '/print', $period)) . '"'
    . preview_attrs('document', t('statements.show_title', ['client' => $client['name']]), t('statements.period', ['from' => format_date($from), 'to' => format_date($to)])) . '>'
    . icon('eye') . '<span>' . e(t('preview.document_button')) . '</span></a>';
if (can('payments.manage')) {
    $buttons .= '<a class="btn btn--primary" href="' . e(url('/payments/new', ['client_id' => $client['id']])) . '">' . icon('wallet') . '<span>' . e(t('payments.record')) . '</span></a>';
}
?>
<?= partial('partials/page-header', [
    'icon' => 'scroll-text',
    'title' => $title,
    'meta' => '<a href="' . e(url('/clients/' . $client['id'])) . '">' . e($client['code']) . '</a> · ' . e(t('statements.period', ['from' => format_date($from), 'to' => format_date($to)])),
    'actions' => export_button() . ($buttons),
]) ?>
<section class="vitals">
  <?php foreach (Receivables::BUCKETS as $bucket): ?>
    <div class="vital<?= $bucket !== 'not_due' && $aging[$bucket] > 0 ? ' vital--alert' : '' ?>"><?= icon($bucket === 'not_due' ? 'calendar-check' : 'calendar-x') ?><span class="vital__label"><?= e(t('aging.' . $bucket)) ?></span><span class="vital__value"><?= e(format_money($aging[$bucket], 'USD')) ?></span></div>
  <?php endforeach; ?>
  <?php if ($advances > 0): ?>
    <div class="vital vital--warn"><?= icon('piggy-bank') ?><span class="vital__label"><?= e(t('statements.advances')) ?></span><span class="vital__value"><?= e(format_money($advances, 'USD')) ?></span></div>
  <?php endif; ?>
</section>
<section class="card section-gap">
  <form class="filters" method="get" action="<?= e(url('/statements/' . $client['id'])) ?>">
    <div class="filters__fields">
      <label class="filter"><span><?= e(t('statements.from')) ?></span><input type="date" name="from" value="<?= e($from) ?>"></label>
      <label class="filter"><span><?= e(t('statements.to')) ?></span><input type="date" name="to" value="<?= e($to) ?>"></label>
      <button type="submit" class="btn"><?= icon('filter') ?><span><?= e(t('statements.apply')) ?></span></button>
    </div>
  </form>
  <?= partial('statements/_ledger', get_defined_vars() + ['links' => true, 'tableClass' => 'table']) ?>
</section>
<?php if ($open !== []): ?>
<section class="card section-gap">
  <div class="card__title"><h2><?= icon('receipt', 'title-icon') ?><?= e(t('statements.open_invoices')) ?></h2></div>
  <?= partial('invoices/_table', ['rows' => $open, 'compact' => true, 'withClient' => false]) ?>
</section>
<?php endif; ?>
