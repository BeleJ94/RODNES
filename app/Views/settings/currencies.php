<?php

/**
 * @var list<array<string, mixed>> $currencies
 * @var array<string, array{rate: int, date: ?string}> $latest
 * @var list<array<string, mixed>> $history
 */

$foreign = array_values(array_filter($currencies, static fn (array $c): bool => $c['code'] !== 'USD' && $c['is_active']));
$staleBefore = date('Y-m-d', strtotime('-7 days'));
?>
<?= partial('partials/page-header', ['icon' => 'coins', 'title' => t('settings.currencies_title'), 'meta' => e(t('settings.currencies_meta'))]) ?>
<div class="grid grid--main-side">
  <section class="card">
    <div class="card__title"><h2><?= icon('coins', 'title-icon') ?><?= e(t('settings.current_rates')) ?></h2></div>
    <table class="table" data-dt="compact">
      <thead><tr>
        <th scope="col"><?= e(t('settings.currency')) ?></th>
        <th scope="col" class="num"><?= e(t('settings.rate_per_usd')) ?></th>
        <th scope="col" class="num"><?= e(t('settings.usd_per_unit')) ?></th>
        <th scope="col"><?= e(t('settings.rate_date')) ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($currencies as $c): $rate = $latest[$c['code']] ?? null; $stale = $c['code'] !== 'USD' && ($rate === null || $rate['date'] < $staleBefore); ?>
        <tr>
          <td><strong><?= e($c['code']) ?></strong><br><span class="muted"><?= e($c['name']) ?></span></td>
          <td class="num"><?= $rate !== null ? e(format_rate($rate['rate'])) : '<span class="muted">—</span>' ?></td>
          <td class="num muted"><?= $rate !== null ? e(format_rate(intdiv(1_000_000_000_000, $rate['rate']))) : '' ?></td>
          <td><?php if ($c['code'] === 'USD'): ?><span class="muted"><?= e(t('settings.base_currency')) ?></span>
              <?php elseif ($rate === null): ?><?= badge(\App\Domain\Shared\Tone::Critical, t('settings.no_rate')) ?>
              <?php else: ?><span class="<?= $stale ? 'overdue' : '' ?>"><?= e(format_date($rate['date'])) ?></span><?php if ($stale): ?> <span class="chip chip--warn"><?= e(t('settings.stale')) ?></span><?php endif; ?><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="card">
    <div class="card__title"><h2><?= icon('plus', 'title-icon') ?><?= e(t('settings.add_rate')) ?></h2></div>
    <form method="post" action="<?= e(url('/settings/currencies/rates')) ?>" class="form">
      <?= csrf_field() ?>
      <?= form_select('currency_code', t('settings.currency'), array_combine(array_column($foreign, 'code'), array_map(static fn (array $c): string => $c['code'] . ' — ' . $c['name'], $foreign)), '', ['required' => true]) ?>
      <?= form_input('rate_date', t('settings.rate_date'), date('Y-m-d'), ['type' => 'date', 'required' => true]) ?>
      <?= form_input('rate', t('settings.rate_per_usd'), '', ['required' => true, 'attrs' => ' inputmode="decimal"', 'placeholder' => '18,25', 'hint' => t('settings.rate_hint')]) ?>
      <button type="submit" class="btn btn--primary btn--block"><?= icon('save') ?><span><?= e(t('common.save')) ?></span></button>
    </form>
  </section>
</div>

<section class="card section-gap">
  <div class="card__title"><h2><?= icon('history', 'title-icon') ?><?= e(t('settings.rate_history')) ?></h2></div>
  <table class="table">
    <thead><tr>
      <th scope="col" data-filter="date"><?= e(t('settings.rate_date')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('settings.currency')) ?></th>
      <th scope="col" class="num"><?= e(t('settings.rate_per_usd')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('common.by')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
      <tr>
        <td class="nowrap" data-order="<?= e($h['rate_date']) ?>"><?= e(format_date($h['rate_date'])) ?></td>
        <td><?= e($h['currency_code']) ?></td>
        <td class="num" data-order="<?= (int) $h['units_per_usd_micro'] ?>"><?= e(format_rate((int) $h['units_per_usd_micro'])) ?></td>
        <td class="muted"><?= e($h['created_by_name'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
