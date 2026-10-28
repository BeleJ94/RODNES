<?php

use App\Domain\Sales\Pricing;
use App\Domain\Sales\QuotationStatus;

/** @var list<array<string, mixed>> $rows @var string $status @var array<string, int> $kpis */

$options = ['' => t('quotations.filter_current'), 'expiring' => t('quotations.filter_expiring'), 'to_close' => t('quotations.filter_to_close')];
foreach (QuotationStatus::cases() as $case) {
    $options[$case->value] = t($case->labelKey());
}
$today = date('Y-m-d');
?>
<?= partial('partials/page-header', [
    'icon' => 'file-text',
    'title' => t('quotations.title'),
    'meta' => e(t('quotations.meta', ['sent' => $kpis['sent'], 'amount' => format_money($kpis['sent_amount'], 'USD')])),
    'actions' => export_button(),
]) ?>
<section class="card">
  <form class="filters" method="get" action="<?= e(url('/quotations')) ?>" data-autosubmit>
    <div class="filters__fields">
      <label class="filter"><span><?= e(t('filters.view')) ?></span>
        <select name="status">
          <?php foreach ($options as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= $status === (string) $value ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
  </form>
  <?php if ($rows === []): ?>
    <div class="empty-state"><?= icon('file-text') ?><p><?= e(t('quotations.empty')) ?></p></div>
  <?php else: ?>
  <table class="table">
    <thead><tr>
      <th scope="col"><?= e(t('quotations.col.reference')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('rfqs.col.client')) ?></th>
      <th scope="col" data-filter="select"><?= e(t('common.status')) ?></th>
      <th scope="col" class="num" data-filter="range" data-filter-scale="100" data-filter-label="<?= e(t('quotations.col.amount_usd')) ?>" data-x="money"><?= e(t('quotations.col.amount')) ?></th>
      <th scope="col" class="num" data-filter="range" data-filter-scale="100" data-filter-label="<?= e(t('quotations.col.margin_pct')) ?>" data-x="percent"><?= e(t('quotations.col.margin')) ?></th>
      <th scope="col" data-filter="date"><?= e(t('quotations.col.valid_until')) ?></th>
      <th scope="col" class="no-sort"><span class="visually-hidden"><?= e(t('common.actions')) ?></span></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $row):
        $qs = QuotationStatus::from($row['status']);
        $expired = $qs === QuotationStatus::Sent && $row['valid_until'] < $today;
        $margin = Pricing::marginBp((int) $row['cost'], (int) $row['subtotal']); ?>
      <tr class="row-link" tabindex="0" data-href="<?= e(url('/quotations/' . $row['id'])) ?>">
        <td><strong><?= e($row['reference']) ?></strong> <span class="tag">v<?= (int) $row['version'] ?></span></td>
        <td><span class="clip"><?= e($row['client_name']) ?></span></td>
        <td data-filter="<?= e(t($qs->labelKey())) ?>"><?= badge($qs->tone(), t($qs->labelKey())) ?><?php if ($expired): ?> <span class="chip chip--bad"><?= e(t('quotations.expired')) ?></span><?php endif; ?></td>
        <td class="num" data-order="<?= (int) $row['subtotal'] ?>"><?= e(format_money((int) $row['subtotal'], 'USD')) ?></td>
        <td class="num" data-order="<?= $margin ?? '' ?>"><?= e(format_percent($margin)) ?></td>
        <td class="<?= $expired ? 'overdue' : '' ?>" data-order="<?= e($row['valid_until']) ?>"><?= e(format_date($row['valid_until'])) ?></td>
        <td class="row-actions"><?= partial('partials/preview-button', [
            'href' => url('/quotations/' . $row['id'] . '/print'),
            'kind' => 'document',
            'title' => t('preview.title_quotation', ['ref' => $row['reference'] . ' v' . $row['version']]),
            'meta' => t('preview.meta_client_document', ['client' => $row['client_name']]),
        ]) ?></td>
        <?= row_detail([
            t('detail.rfq') => '<a href="' . e(url('/rfqs/' . $row['rfq_id'])) . '">' . e($row['rfq_reference']) . '</a>',
            t('detail.client_reference') => e((string) $row['client_reference']),
            t('detail.cost') => e(format_money((int) $row['cost'], 'USD')),
            t('detail.margin') => e(format_money((int) $row['subtotal'] - (int) $row['cost'], 'USD')),
            t('detail.terms') => e(trim(($row['incoterm'] ?? '') . ' · ' . t('common.days', ['n' => $row['payment_terms_days']]), ' ·')),
            t('detail.delivery') => e((string) $row['delivery_terms']),
            t('detail.sent_at') => e(format_datetime($row['sent_at'])),
            t('detail.decided_at') => e(format_datetime($row['decided_at'])),
            t('detail.approved_by') => e((string) $row['approved_by_name']),
            t('detail.lost_reason') => $row['lost_reason'] ? e(t('lost_reason.' . $row['lost_reason']) . ($row['lost_comment'] ? ' — ' . $row['lost_comment'] : '')) : null,
        ], $row['notes'] ? '<p class="row-detail__note">' . e($row['notes']) . '</p>' : '') ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
