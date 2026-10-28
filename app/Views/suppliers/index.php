<?php /** @var list<array<string, mixed>> $rows */ ?>
<?= partial('partials/page-header', [
    'icon' => 'factory',
    'title' => t('suppliers.title'),
    'meta' => e(t('common.count', ['n' => count($rows)])),
    'actions' => can('suppliers.manage') ? '<a class="btn btn--primary" href="' . e(url('/suppliers/new')) . '">' . icon('plus') . '<span>' . e(t('suppliers.new')) . '</span></a>' : '',
]) ?>
<section class="card">
  <?php if ($rows === []): ?>
    <div class="empty-state"><?= icon('factory') ?><p><?= e(t('suppliers.empty')) ?></p></div>
  <?php else: ?>
  <table class="table">
    <thead><tr>
      <th scope="col"><?= e(t('common.code')) ?></th>
      <th scope="col"><?= e(t('common.name')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('suppliers.country')) ?></th>
      <th scope="col" data-priority="low"><?= e(t('common.contact')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('suppliers.col.currency')) ?></th>
      <th scope="col" class="num" data-filter="range" data-filter-scale="100" data-filter-label="<?= e(t('suppliers.col.answer_rate_pct')) ?>" data-x="percent"><?= e(t('suppliers.col.answer_rate')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('common.status')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $row): $requests = (int) $row['requests']; $rate = $requests > 0 ? intdiv((int) $row['answered'] * 10000, $requests) : null; ?>
      <tr class="row-link" tabindex="0" data-href="<?= e(url('/suppliers/' . $row['id'] . '/edit')) ?>">
        <td><strong><?= e($row['code']) ?></strong></td>
        <td><span class="clip"><?= e($row['name']) ?></span></td>
        <td><?= $row['country'] ? '<span class="tag">' . e($row['country']) . '</span>' : '<span class="muted">—</span>' ?></td>
        <td><span class="clip"><?= e($row['contact_name']) ?: '<span class="muted">—</span>' ?></span></td>
        <td><span class="tag"><?= e($row['currency_code']) ?></span></td>
        <td class="num" data-order="<?= $rate ?? '' ?>"><?= $rate !== null ? e(format_percent($rate)) . ' <span class="sub">' . (int) $row['answered'] . '/' . $requests . '</span>' : '<span class="muted">—</span>' ?></td>
        <td data-filter="<?= e(t($row['is_active'] ? 'common.active' : 'common.inactive')) ?>"><?= $row['is_active'] ? badge(\App\Domain\Shared\Tone::Ok, t('common.active')) : badge(\App\Domain\Shared\Tone::Neutral, t('common.inactive')) ?></td>
        <?= row_detail([
            t('detail.contact') => e((string) $row['contact_name']),
            t('detail.email') => $row['contact_email'] ? '<a href="mailto:' . e($row['contact_email']) . '">' . e($row['contact_email']) . '</a>' : null,
            t('detail.phone') => e((string) $row['contact_phone']),
            t('detail.terms') => e(t('common.days', ['n' => $row['payment_terms_days']])),
            t('detail.sourcing') => e(t('detail.offers_of', ['offers' => $row['answered'], 'requests' => $requests])),
        ], $row['notes'] ? '<p class="row-detail__note">' . e($row['notes']) . '</p>' : '') ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
