<?php /** @var list<array<string, mixed>> $rows */ ?>
<?= partial('partials/page-header', [
    'icon' => 'building-2',
    'title' => t('clients.title'),
    'meta' => e(t('common.count', ['n' => count($rows)])),
    'actions' => can('clients.manage') ? '<a class="btn btn--primary" href="' . e(url('/clients/new')) . '">' . icon('plus') . '<span>' . e(t('clients.new')) . '</span></a>' : '',
]) ?>
<section class="card">
  <?php if ($rows === []): ?>
    <div class="empty-state"><?= icon('building-2') ?><p><?= e(t('clients.empty')) ?></p></div>
  <?php else: ?>
  <table class="table">
    <thead><tr>
      <th scope="col"><?= e(t('common.code')) ?></th>
      <th scope="col"><?= e(t('common.name')) ?></th>
      <th scope="col" data-filter="select" data-priority="low"><?= e(t('common.city')) ?></th>
      <th scope="col" data-priority="low"><?= e(t('common.contact')) ?></th>
      <th scope="col" class="num" data-filter="range" data-filter-label="<?= e(t('clients.col.payment_terms_days')) ?>" data-x="int"><?= e(t('clients.col.payment_terms')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('clients.col.vat')) ?></th>
      <th scope="col" class="num" data-x="text"><?= e(t('clients.col.rfqs')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('common.status')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr class="row-link" tabindex="0" data-href="<?= e(url('/clients/' . $row['id'])) ?>">
        <td><strong><?= e($row['code']) ?></strong></td>
        <td><span class="clip"><?= e($row['name']) ?></span></td>
        <td><?= e($row['city']) ?: '<span class="muted">—</span>' ?></td>
        <td><span class="clip"><?= e($row['contact_name']) ?: '<span class="muted">—</span>' ?></span></td>
        <td class="num" data-order="<?= (int) $row['payment_terms_days'] ?>"><?= e(t('common.days', ['n' => $row['payment_terms_days']])) ?></td>
        <td data-filter="<?= e($row['vat_applicable'] ? t('clients.vat_yes') : t('clients.vat_exempt')) ?>"><?= $row['vat_applicable'] ? e(t('common.yes')) : '<span class="chip">' . e(t('clients.vat_exempt')) . '</span>' ?></td>
        <td class="num" data-order="<?= (int) $row['open_rfqs'] ?>"><?= (int) $row['open_rfqs'] ?> / <?= (int) $row['total_rfqs'] ?></td>
        <td data-filter="<?= e(t($row['is_active'] ? 'common.active' : 'common.inactive')) ?>"><?= $row['is_active'] ? badge(\App\Domain\Shared\Tone::Ok, t('common.active')) : badge(\App\Domain\Shared\Tone::Neutral, t('common.inactive')) ?></td>
        <?= row_detail([
            t('detail.contact') => e((string) $row['contact_name']),
            t('detail.email') => $row['contact_email'] ? '<a href="mailto:' . e($row['contact_email']) . '">' . e($row['contact_email']) . '</a>' : null,
            t('detail.phone') => e((string) $row['contact_phone']),
            t('detail.address') => e(trim($row['address'] . ' ' . $row['city'])),
            'RCCM' => e((string) $row['rccm']),
            t('common.nif') => e((string) $row['nif']),
            t('common.id_nat') => e((string) $row['id_nat']),
            t('clients.rfq_response_days') => $row['rfq_response_days'] !== null ? e((string) $row['rfq_response_days']) : null,
            t('clients.quote_validity_days') => $row['quote_validity_days'] !== null ? e((string) $row['quote_validity_days']) : null,
        ], $row['notes'] ? '<p class="row-detail__note">' . e($row['notes']) . '</p>' : '') ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
