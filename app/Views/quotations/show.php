<?php

use App\Domain\Sales\Pricing;
use App\Domain\Sales\QuotationStatus as S;
use App\Domain\Shared\Money;

/**
 * @var array<string, mixed> $q
 * @var S $status
 * @var list<array<string, mixed>> $lines
 * @var array<string, int|null> $totals
 * @var bool $approvalRequired
 * @var int $thresholdBp
 * @var list<array<string, mixed>> $versions
 * @var list<array<string, mixed>> $clientOrders
 * @var list<array<string, mixed>> $history
 * @var list<array<string, mixed>> $followupCards
 */

$action = static function (string $name, string $label, string $icon, string $class = 'btn', string $confirm = '') use ($q): string {
    return '<form method="post" action="' . e(url('/quotations/' . $q['id'] . '/status')) . '"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
        . csrf_field() . '<input type="hidden" name="action" value="' . e($name) . '">'
        . '<button type="submit" class="' . e($class) . '">' . icon($icon) . '<span>' . e($label) . '</span></button></form>';
};

$buttons = '<a class="btn" href="' . e(url('/quotations/' . $q['id'] . '/print')) . '"'
    . preview_attrs('document', t('preview.title_quotation', ['ref' => $q['reference'] . ' v' . $q['version']]), t('preview.meta_client_document', ['client' => $q['client_name']])) . '>'
    . icon('eye') . '<span>' . e(t('preview.document_button')) . '</span></a>';
if (can('quotations.manage')) {
    if ($status === S::Draft) {
        $buttons .= '<a class="btn" href="' . e(url('/quotations/' . $q['id'] . '/edit')) . '">' . icon('pencil') . '<span>' . e(t('common.edit')) . '</span></a>';
        $buttons .= $approvalRequired
            ? $action('send', t('quotations.action.submit_approval'), 'shield-check', 'btn btn--primary')
            : $action('send', t('quotations.action.mark_sent'), 'send', 'btn btn--primary', t('quotations.confirm_sent'));
    }
    if ($status === S::PendingApproval && can('quotations.approve')) {
        $buttons .= $action('reject_approval', t('quotations.action.reject_approval'), 'undo-2', 'btn');
        $buttons .= $action('approve', t('quotations.action.approve'), 'shield-check', 'btn btn--primary');
    }
    if ($status === S::Approved) {
        $buttons .= $action('reopen', t('quotations.action.reopen'), 'pencil', 'btn');
        $buttons .= $action('send', t('quotations.action.mark_sent'), 'send', 'btn btn--primary', t('quotations.confirm_sent'));
    }
    if ($status === S::Sent) {
        $buttons .= '<a class="btn" href="#lost">' . icon('thumbs-down') . '<span>' . e(t('quotations.action.decline')) . '</span></a>';
        $buttons .= $action('accept', t('quotations.action.accept'), 'thumbs-up', 'btn btn--primary', t('quotations.confirm_accept'));
    }
    if (($status === S::Sent || $status === S::Accepted) && can('client_orders.manage')) {
        $buttons .= '<a class="btn btn--primary" href="' . e(url('/quotations/' . $q['id'] . '/client-order/new')) . '">' . icon('file-check') . '<span>' . e(t('client_orders.register')) . '</span></a>';
    }
    if ($status->canBeRevised()) {
        $buttons .= '<form method="post" action="' . e(url('/quotations/' . $q['id'] . '/revise')) . '" data-confirm="' . e(t('quotations.confirm_revise')) . '">'
            . csrf_field() . '<button type="submit" class="btn">' . icon('copy-plus') . '<span>' . e(t('quotations.action.revise')) . '</span></button></form>';
    }
}
$expired = $status === S::Sent && $q['valid_until'] < date('Y-m-d');
$marginLow = $totals['margin_bp'] !== null && $totals['margin_bp'] < $thresholdBp;
?>
<?= partial('partials/page-header', [
    'icon' => 'file-text',
    'title' => $q['reference'] . ' v' . $q['version'],
    'meta' => badge($status->tone(), t($status->labelKey())) . ($expired ? ' <span class="chip chip--bad">' . e(t('quotations.expired')) . '</span>' : '')
        . ' · <a href="' . e(url('/clients/' . $q['client_id'])) . '">' . e($q['client_name']) . '</a>'
        . ' · <a href="' . e(url('/rfqs/' . $q['rfq_id'])) . '">' . e($q['rfq_reference']) . '</a>',
    'actions' => $buttons,
]) ?>
<?= doc_chain('quotation', (int) $q['id']) ?>

<?php if ($status === S::Draft && $approvalRequired): ?>
  <div class="alert alert--info"><?= icon('shield-alert') ?><span><?= e(t('quotations.approval_needed', ['threshold' => format_percent($thresholdBp)])) ?></span></div>
<?php endif; ?>
<?php if ($status === S::PendingApproval): ?>
  <div class="alert alert--info"><?= icon('hourglass') ?><span><?= e(t('quotations.awaiting_approval')) ?></span></div>
<?php endif; ?>

<section class="vitals">
  <div class="vital"><?= icon('banknote') ?><span class="vital__label"><?= e(t('quotations.total.subtotal')) ?></span><span class="vital__value"><?= e(format_money($totals['subtotal'], 'USD')) ?></span></div>
  <div class="vital"><?= icon('receipt') ?><span class="vital__label"><?= e(t('quotations.total.total')) ?></span><span class="vital__value"><?= e(format_money($totals['total'], 'USD')) ?></span></div>
  <div class="vital<?= $marginLow ? ' vital--warn' : '' ?>"><?= icon('trending-up') ?><span class="vital__label"><?= e(t('quotations.col.margin')) ?></span><span class="vital__value"><?= e(format_percent($totals['margin_bp'])) ?> · <?= e(format_money($totals['margin'], 'USD')) ?></span></div>
  <div class="vital<?= $expired ? ' vital--alert' : '' ?>"><?= icon('calendar-clock') ?><span class="vital__label"><?= e(t('quotations.valid_until')) ?></span><span class="vital__value"><?= e(format_date($q['valid_until'])) ?></span></div>
  <div class="vital"><?= icon('ship') ?><span class="vital__label"><?= e(t('quotations.terms')) ?></span><span class="vital__value"><?= e(trim(($q['incoterm'] ?? '') . ' · ' . t('common.days', ['n' => $q['payment_terms_days']]), ' ·')) ?></span></div>
</section>

<section class="card section-gap">
  <div class="card__title"><h2><?= icon('list', 'title-icon') ?><?= e(t('quotations.section_lines')) ?></h2></div>
  <div class="table-scroll">
  <table class="table" data-dt="compact">
    <thead><tr>
      <th scope="col">#</th>
      <th scope="col"><?= e(t('rfqs.line.description')) ?></th>
      <th scope="col" class="num"><?= e(t('rfqs.line.quantity')) ?></th>
      <th scope="col"><?= e(t('quotations.line.source')) ?></th>
      <th scope="col" class="num"><?= e(t('quotations.line.unit_cost')) ?></th>
      <th scope="col" class="num"><?= e(t('quotations.line.price')) ?></th>
      <th scope="col" class="num"><?= e(t('quotations.line.margin')) ?></th>
      <th scope="col" class="num"><?= e(t('quotations.line.total')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $l):
        $unitCost = (int) $l['cost_unit_usd_minor'] + (int) $l['extra_unit_usd_minor'];
        $lineMargin = Pricing::marginBp($unitCost, (int) $l['unit_price_minor']); ?>
      <tr>
        <td class="muted"><?= (int) $l['line_no'] ?></td>
        <td><?= e($l['description']) ?></td>
        <td class="num nowrap"><?= e(format_qty((int) $l['quantity_milli'])) ?> <span class="muted"><?= e(unit_label($l['unit'])) ?></span></td>
        <td><?= $l['supplier_name'] ? e($l['supplier_name']) . '<br><span class="muted">' . e(format_money((int) $l['cost_unit_minor'], $l['cost_currency'])) . ($l['cost_currency'] !== 'USD' ? ' @ ' . e(format_rate((int) $l['fx_units_per_usd_micro'])) : '') . '</span>' : '<span class="muted">' . e(t('quotations.line.manual_cost')) . '</span>' ?></td>
        <td class="num nowrap"><?= e(format_money($unitCost)) ?><?php if ((int) $l['extra_unit_usd_minor'] > 0): ?><br><span class="muted"><?= e(t('quotations.line.incl_extra', ['amount' => format_money((int) $l['extra_unit_usd_minor'])])) ?></span><?php endif; ?></td>
        <td class="num nowrap"><?= e(format_money((int) $l['unit_price_minor'])) ?></td>
        <td class="num<?= $lineMargin !== null && $lineMargin < $thresholdBp ? ' warn-text' : '' ?>"><?= e(format_percent($lineMargin)) ?></td>
        <td class="num nowrap"><strong><?= e(format_money(Money::lineAmount((int) $l['quantity_milli'], (int) $l['unit_price_minor']))) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><th colspan="7" scope="row" class="num"><?= e(t('quotations.total.subtotal')) ?></th><td class="num nowrap"><?= e(format_money($totals['subtotal'], 'USD')) ?></td></tr>
      <tr><th colspan="7" scope="row" class="num"><?= e($q['vat_applicable'] ? t('quotations.total.vat_rate', ['rate' => format_percent((int) $q['vat_rate_bp'])]) : t('quotations.total.vat_exempt')) ?></th><td class="num nowrap"><?= e(format_money($totals['vat'], 'USD')) ?></td></tr>
      <tr class="total-row"><th colspan="7" scope="row" class="num"><?= e(t('quotations.total.total')) ?></th><td class="num nowrap"><?= e(format_money($totals['total'], 'USD')) ?></td></tr>
    </tfoot>
  </table>
  </div>
  <?php if ($q['notes']): ?><p class="note"><?= nl2br(e($q['notes'])) ?></p><?php endif; ?>
</section>

<?php if ($status === S::Rejected && $q['lost_reason']): ?>
  <div class="alert alert--info section-gap"><?= icon('thumbs-down') ?><span><?= e(t('quotations.lost_info', ['reason' => t('lost_reason.' . $q['lost_reason']), 'date' => format_date($q['decided_at'])])) ?><?= $q['lost_comment'] ? ' — ' . e($q['lost_comment']) : '' ?></span></div>
<?php endif; ?>
<?php foreach ($followupCards as $card): ?>
  <?= partial('followups/_card', $card) ?>
<?php endforeach; ?>
<?php if ($status === S::Sent && can('quotations.manage')): ?>
<section class="card section-gap" id="lost">
  <div class="card__title"><h2><?= icon('thumbs-down', 'title-icon') ?><?= e(t('quotations.lost_title')) ?></h2></div>
  <form method="post" action="<?= e(url('/quotations/' . $q['id'] . '/status')) ?>" class="form form-grid" data-confirm="<?= e(t('quotations.confirm_decline')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="decline">
    <?= form_select('lost_reason', t('quotations.lost_reason'), array_combine(\App\Services\QuotationService::LOST_REASONS, array_map(static fn (string $r): string => t('lost_reason.' . $r), \App\Services\QuotationService::LOST_REASONS)), '', ['required' => true, 'placeholder' => t('common.choose')]) ?>
    <?= form_input('lost_comment', t('quotations.lost_comment'), '', ['maxlength' => 255, 'hint' => t('quotations.lost_comment_hint')]) ?>
    <div class="span-2"><button type="submit" class="btn"><?= icon('thumbs-down') ?><span><?= e(t('quotations.action.decline')) ?></span></button></div>
  </form>
</section>
<?php endif; ?>

<div class="grid grid--main-side section-gap">
  <?= partial('partials/history', ['history' => $history, 'statusPrefix' => 'quotation_status.']) ?>
  <section class="card">
    <div class="card__title"><h2><?= icon('git-branch', 'title-icon') ?><?= e(t('quotations.versions')) ?></h2></div>
    <ul class="version-list">
      <?php foreach ($versions as $v): $vs = S::from($v['status']); ?>
        <li<?= (int) $v['id'] === (int) $q['id'] ? ' aria-current="true"' : '' ?>>
          <a href="<?= e(url('/quotations/' . $v['id'])) ?>">v<?= (int) $v['version'] ?></a>
          <?= badge($vs->tone(), t($vs->labelKey())) ?>
          <span class="muted"><?= e(format_datetime($v['created_at'])) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($clientOrders !== []): ?>
      <p class="doc__label section-gap"><?= e(t('client_orders.title')) ?></p>
      <ul class="version-list">
        <?php foreach ($clientOrders as $co): $cs = \App\Domain\Orders\ClientOrderStatus::from($co['status']); ?>
          <li><a href="<?= e(url('/client-orders/' . $co['id'])) ?>"><?= e($co['reference']) ?></a> <span class="muted"><?= e($co['client_po_number']) ?></span> <?= badge($cs->tone(), t($cs->labelKey())) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if ($q['approved_by_name']): ?><p class="hint"><?= e(t('quotations.approved_by', ['name' => $q['approved_by_name'], 'date' => format_datetime($q['approved_at'])])) ?></p><?php endif; ?>
  </section>
</div>
