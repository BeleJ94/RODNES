<?php

use App\Domain\Orders\ClientOrderStatus as S;
use App\Domain\Orders\SupplierOrderStatus;
use App\Domain\Sales\Pricing;
use App\Domain\Shared\Fx;
use App\Domain\Shared\Money;
use App\Domain\Shared\Tone;

/**
 * @var array<string, mixed> $order
 * @var list<array<string, mixed>> $files bon(s) de commande du client
 * @var array<string, mixed>|null $reopenShipment saisie d'expédition à rouvrir dans la fenêtre (erreur de validation)
 * @var array<string, mixed>|null $reopenInvoice facturation à rouvrir dans la fenêtre (erreur de validation)
 * @var S $status
 * @var list<array<string, mixed>> $lines
 * @var array<int, list<array<string, mixed>>> $allocations ligne client → lignes de PO fournisseurs
 * @var list<array<string, mixed>> $supplierOrders
 * @var list<array<string, mixed>> $shipments
 * @var list<array<string, mixed>> $invoices
 * @var bool $toInvoice des quantités livrées restent à facturer
 * @var list<array<string, mixed>> $history
 */

$subtotal = 0;
$estCost = 0;
$committed = 0;
$remainingTotal = 0;
foreach ($lines as $l) {
    $subtotal += Money::lineAmount((int) $l['quantity_milli'], (int) $l['unit_price_minor']);
    $estCost += Money::lineAmount((int) $l['quantity_milli'], (int) $l['est_unit_cost_minor'] + (int) $l['est_extra_unit_minor']);
    $remainingTotal += max(0, (int) $l['quantity_milli'] - (int) $l['allocated_milli']);
    foreach ($allocations[(int) $l['id']] ?? [] as $a) {
        $committed += Money::lineAmount((int) $a['quantity_milli'], $a['unit_usd_minor']);
    }
}
$vat = $order['vat_applicable'] ? Money::percentOf($subtotal, (int) $order['vat_rate_bp']) : 0;
$ordered = (int) $order['ordered_milli'];
$ratio = $ordered > 0 ? intdiv((int) $order['covered_milli'] * 100, $ordered) : 0;

$actions = '';
if (!$status->isFinal() && $remainingTotal > 0 && can('supplier_orders.manage')) {
    $actions .= '<a class="btn" href="' . e(url('/supplier-orders/new', ['client_order_id' => $order['id']])) . '">' . icon('plus') . '<span>' . e(t('supplier_orders.new')) . '</span></a>';
    $actions .= '<form method="post" action="' . e(url('/client-orders/' . $order['id'] . '/supplier-orders')) . '">' . csrf_field()
        . '<button type="submit" class="btn btn--primary">' . icon('wand-sparkles') . '<span>' . e(t('supplier_orders.prepare')) . '</span></button></form>';
}
if ($toInvoice && can('invoices.manage')) {
    $actions = '<a class="btn btn--primary" href="' . e(url('/client-orders/' . $order['id'] . '/invoices/new')) . '" data-remote-dialog="' . e(url('/client-orders/' . $order['id'] . '/invoices/new', ['fragment' => 1])) . '">' . icon('receipt') . '<span>' . e(t('invoices.invoice_order')) . '</span></a>' . $actions;
}
if (!$status->isFinal() && can('client_orders.manage')) {
    $actions .= '<a class="btn" href="' . e(url('/client-orders/' . $order['id'] . '/edit')) . '">' . icon('pencil') . '<span>' . e(t('common.edit')) . '</span></a>';
}
?>
<?= partial('partials/page-header', [
    'icon' => 'file-check',
    'title' => (string) $order['reference'],
    'meta' => badge($status->tone(), t($status->labelKey()))
        . ' · <span class="tag">' . e(t('client_orders.their_po', ['ref' => $order['client_po_number']])) . '</span>'
        . ' · <a href="' . e(url('/clients/' . $order['client_id'])) . '">' . e($order['client_name']) . '</a>'
        . ($order['rfq_id'] ? ' · <a href="' . e(url('/rfqs/' . $order['rfq_id'])) . '">' . e($order['rfq_reference']) . '</a>' : '')
        . ($order['quotation_id'] ? ' · <a href="' . e(url('/quotations/' . $order['quotation_id'])) . '">' . e($order['quotation_reference'] . ' v' . $order['quotation_version']) . '</a>' : ''),
    'actions' => $actions,
]) ?>
<?= doc_chain('client_order', (int) $order['id']) ?>

<?php if ($status->isFinal() && $order['closed_reason']): ?>
  <div class="alert alert--info"><?= icon('info') ?><span><?= e(t('rfqs.closed_reason', ['reason' => $order['closed_reason']])) ?></span></div>
<?php endif; ?>

<section class="vitals">
  <div class="vital"><?= icon('banknote') ?><span class="vital__label"><?= e(t('quotations.total.subtotal')) ?></span><span class="vital__value"><?= e(format_money($subtotal, 'USD')) ?></span></div>
  <div class="vital"><?= icon('receipt') ?><span class="vital__label"><?= e(t('quotations.total.total')) ?></span><span class="vital__value"><?= e(format_money($subtotal + $vat, 'USD')) ?></span></div>
  <div class="vital<?= $ratio < 100 && !$status->isFinal() ? ' vital--warn' : '' ?>"><?= icon('shopping-cart') ?><span class="vital__label"><?= e(t('client_orders.col.coverage')) ?></span><span class="vital__value"><?= $ratio ?> %</span></div>
  <div class="vital"><?= icon('calculator') ?><span class="vital__label"><?= e(t('client_orders.est_margin')) ?></span><span class="vital__value"><?= e(format_percent(Pricing::marginBp($estCost, $subtotal))) ?> · <?= e(format_money($subtotal - $estCost, 'USD')) ?></span></div>
  <div class="vital"><?= icon('hand-coins') ?><span class="vital__label"><?= e(t('client_orders.committed')) ?></span><span class="vital__value"><?= e(format_money($committed, 'USD')) ?></span></div>
  <div class="vital"><?= icon('calendar') ?><span class="vital__label"><?= e(t('client_orders.required_by')) ?></span><span class="vital__value"><?= e(format_date($order['required_by'])) ?: '—' ?></span></div>
</section>

<?php if ($files !== []): ?>
<section class="card section-gap co-files">
  <div class="card__title"><h2><?= icon('paperclip', 'title-icon') ?><?= e(t('client_orders.po_file')) ?></h2></div>
  <ul class="doc-chips">
    <?php foreach ($files as $file): $kind = preview_kind((string) $file['mime']); $src = url('/files/' . $file['file_id']); ?>
      <li class="doc-chip">
        <a class="doc-chip__open" href="<?= e($src) ?>"<?= $kind !== null ? preview_attrs($kind, (string) $file['original_name'], $order['client_name'] . ' · ' . $order['client_po_number'], url('/files/' . $file['file_id'], ['download' => 1])) : ' download' ?>>
          <?= icon($kind === 'image' ? 'image' : ($kind === 'pdf' ? 'file-text' : 'file')) ?><span class="doc-chip__name"><?= e($file['original_name']) ?></span><span class="doc-chip__size"><?= e(format_bytes((int) $file['size_bytes'])) ?></span>
        </a>
        <span class="muted"><?= e(format_datetime($file['created_at'])) ?><?= $file['created_by_name'] ? ' · ' . e($file['created_by_name']) : '' ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section class="card section-gap">
  <div class="card__title"><h2><?= icon('list', 'title-icon') ?><?= e(t('client_orders.section_lines')) ?></h2></div>
  <div class="table-scroll">
  <table class="table" data-dt="compact">
    <thead><tr>
      <th scope="col">#</th>
      <th scope="col"><?= e(t('rfqs.line.description')) ?></th>
      <th scope="col" class="num"><?= e(t('rfqs.line.quantity')) ?></th>
      <th scope="col" class="num"><?= e(t('quotations.line.price')) ?></th>
      <th scope="col" class="num"><?= e(t('quotations.line.total')) ?></th>
      <th scope="col"><?= e(t('client_orders.line.purchasing')) ?></th>
      <th scope="col" class="num"><?= e(t('client_orders.line.to_order')) ?></th>
      <th scope="col" class="num"><?= e(t('client_orders.line.delivered')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $l):
        $remaining = max(0, (int) $l['quantity_milli'] - (int) $l['allocated_milli']);
        $deviation = $l['quoted_qty_milli'] !== null && ((int) $l['quoted_qty_milli'] !== (int) $l['quantity_milli'] || (int) $l['quoted_price_minor'] !== (int) $l['unit_price_minor']); ?>
      <tr>
        <td class="muted"><?= (int) $l['line_no'] ?></td>
        <td><?= e($l['description']) ?>
          <?php if ($deviation): ?><br><span class="chip chip--warn" title="<?= e(t('client_orders.deviation_hint', ['qty' => format_qty((int) $l['quoted_qty_milli']), 'price' => format_money((int) $l['quoted_price_minor'])])) ?>"><?= e(t('client_orders.deviation')) ?></span>
            <span class="muted"><?= e(t('client_orders.deviation_hint', ['qty' => format_qty((int) $l['quoted_qty_milli']), 'price' => format_money((int) $l['quoted_price_minor'])])) ?></span><?php endif; ?></td>
        <td class="num nowrap"><?= e(format_qty((int) $l['quantity_milli'])) ?> <span class="muted"><?= e(unit_label($l['unit'])) ?></span></td>
        <td class="num nowrap"><?= e(format_money((int) $l['unit_price_minor'])) ?></td>
        <td class="num nowrap"><?= e(format_money(Money::lineAmount((int) $l['quantity_milli'], (int) $l['unit_price_minor']))) ?></td>
        <td>
          <?php if (($allocations[(int) $l['id']] ?? []) === []): ?>
            <span class="muted"><?= $l['default_supplier_name'] ? e(t('client_orders.planned_supplier', ['name' => $l['default_supplier_name']])) : e(t('client_orders.no_supplier')) ?></span>
          <?php else: foreach ($allocations[(int) $l['id']] as $a): $as = SupplierOrderStatus::from($a['status']); ?>
            <div class="alloc"><a href="<?= e(url('/supplier-orders/' . $a['supplier_order_id'])) ?>"><?= e($a['reference']) ?></a>
              <?= e(format_qty((int) $a['quantity_milli'])) ?> × <?= e(format_money((int) $a['unit_price_minor'], $a['currency_code'])) ?>
              <span class="muted"><?= e($a['supplier_name']) ?></span> <?= badge($as->tone(), t($as->labelKey())) ?></div>
          <?php endforeach; endif; ?>
        </td>
        <td class="num nowrap<?= $remaining > 0 && !$status->isFinal() ? ' warn-text' : '' ?>"><?= $remaining > 0 ? e(format_qty($remaining)) : icon('check', 'ok-icon') ?></td>
        <td class="num nowrap"><?php $delivered = (int) $l['delivered_milli']; ?>
          <?= $delivered >= (int) $l['quantity_milli'] ? icon('check', 'ok-icon') . ' ' . e(format_qty($delivered)) : ($delivered > 0 ? e(format_qty($delivered)) : '<span class="muted">—</span>') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="card section-gap" id="supplier-orders">
  <div class="card__title"><h2><?= icon('shopping-cart', 'title-icon') ?><?= e(t('supplier_orders.title')) ?></h2></div>
  <?php if ($supplierOrders === []): ?>
    <p class="muted"><?= e(t('supplier_orders.none_for_order')) ?></p>
  <?php else: ?>
    <?= partial('supplier_orders/_table', ['rows' => $supplierOrders, 'withClient' => false, 'compact' => true]) ?>
  <?php endif; ?>
</section>

<?php if ($margin !== null && $margin['total']['revenue'] > 0): ?>
<section class="card section-gap" id="margin">
  <div class="card__title"><h2><?= icon('trending-up', 'title-icon') ?><?= e(t('reports.order_title')) ?></h2></div>
  <table class="table" data-dt="compact">
    <thead><tr>
      <th scope="col">#</th>
      <th scope="col"><?= e(t('rfqs.line.description')) ?></th>
      <th scope="col" class="num"><?= e(t('invoices.line.invoiced')) ?></th>
      <th scope="col" class="num"><?= e(t('reports.col.revenue')) ?></th>
      <th scope="col" class="num"><?= e(t('reports.col.cost')) ?></th>
      <th scope="col" class="num"><?= e(t('reports.col.margin')) ?></th>
      <th scope="col" class="num"><?= e(t('reports.col.margin_pct')) ?></th>
      <th scope="col" class="num"><?= e(t('reports.col.fx')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($margin['lines'] as $ml): if ((int) $ml['invoiced_milli'] === 0) { continue; } ?>
      <tr>
        <td class="muted"><?= (int) $ml['line_no'] ?></td>
        <td><?= e($ml['description']) ?></td>
        <td class="num nowrap"><?= e(format_qty((int) $ml['invoiced_milli'])) ?></td>
        <?= partial('reports/_margin_cells', ['m' => $ml + ['margin_bp' => \App\Domain\Sales\Pricing::marginBp((int) $ml['cost'], (int) $ml['revenue']), 'est_margin_bp' => \App\Domain\Sales\Pricing::marginBp((int) $ml['est_cost'], (int) $ml['revenue'])]]) ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr class="total-row">
      <th colspan="3" scope="row" class="num"><?= e(t('reports.total')) ?></th>
      <?= partial('reports/_margin_cells', ['m' => $margin['total']]) ?>
    </tr></tfoot>
  </table>
  <p class="hint"><?= e(t('reports.hint')) ?></p>
</section>
<?php endif; ?>

<?php if (can('invoices.view')): ?>
<section class="card section-gap" id="invoices">
  <div class="card__title"><h2><?= icon('receipt', 'title-icon') ?><?= e(t('invoices.title')) ?></h2></div>
  <?php if ($invoices === []): ?>
    <p class="muted"><?= e(t('invoices.none_for_order')) ?></p>
  <?php else: ?>
    <?= partial('invoices/_table', ['rows' => $invoices, 'compact' => true, 'withClient' => false]) ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="card section-gap" id="shipments">
  <div class="card__title"><h2><?= icon('truck', 'title-icon') ?><?= e(t('shipments.title')) ?></h2>
    <?php if (!$status->isFinal() && can('shipments.manage') && $supplierOrders !== []): ?>
      <a class="btn btn--sm" href="<?= e(url('/shipments/new', ['client_id' => $order['client_id']])) ?>" data-remote-dialog="<?= e(url('/shipments/new', ['client_id' => $order['client_id'], 'return_to' => '/client-orders/' . $order['id'], 'fragment' => 1])) ?>"><?= icon('plus') ?><span><?= e(t('shipments.new')) ?></span></a>
    <?php endif; ?></div>
  <?php if ($shipments === []): ?>
    <p class="muted"><?= e(t('shipments.none_for_order')) ?></p>
  <?php else: ?>
    <?= partial('shipments/_table', ['rows' => $shipments, 'compact' => true]) ?>
  <?php endif; ?>
</section>

<div class="grid grid--main-side section-gap">
  <?= partial('partials/history', ['history' => $history, 'statusPrefix' => 'client_order_status.']) ?>
  <?php if (!$status->isFinal() && can('client_orders.manage')): ?>
  <section class="card">
    <div class="card__title"><h2><?= icon('archive', 'title-icon') ?><?= e(t('client_orders.finish_title')) ?></h2></div>
    <form method="post" action="<?= e(url('/client-orders/' . $order['id'] . '/finish')) ?>" class="form" data-confirm="<?= e(t('client_orders.finish_confirm')) ?>">
      <?= csrf_field() ?>
      <div class="field">
        <label for="finish-status"><?= e(t('rfqs.close_as')) ?></label>
        <select id="finish-status" name="status">
          <option value="closed"><?= e(t('client_order_status.closed')) ?></option>
          <option value="cancelled"><?= e(t('client_order_status.cancelled')) ?></option>
        </select>
      </div>
      <div class="field">
        <label for="finish-reason"><?= e(t('rfqs.close_reason')) ?></label>
        <input id="finish-reason" name="reason" maxlength="255">
      </div>
      <button type="submit" class="btn"><?= icon('archive') ?><span><?= e(t('rfqs.close_submit')) ?></span></button>
    </form>
  </section>
  <?php endif; ?>
</div>

<?php
// Fenêtre rouverte après une erreur de saisie : facturation ou expédition.
$reopen = $reopenInvoice !== null
    ? ['title' => t('invoices.modal.title', ['ref' => $order['reference']]) . ' · ' . $order['client_name'], 'html' => partial('invoices/_create', $reopenInvoice)]
    : ($reopenShipment !== null ? ['title' => t('shipments.new') . ' — ' . $order['client_name'], 'html' => partial('shipments/_form', $reopenShipment)] : null);
?>
<dialog class="panel-modal remote-modal" id="remote-modal" aria-labelledby="remote-modal-title"<?= $reopen !== null ? ' data-auto-open' : '' ?>>
  <div class="panel-modal__head">
    <h2 id="remote-modal-title" class="panel-modal__title"><?= $reopen !== null ? e($reopen['title']) : '' ?></h2>
    <button type="button" class="icon-btn" data-close-dialog aria-label="<?= e(t('common.close')) ?>"><?= icon('x') ?></button>
  </div>
  <div class="remote-modal__body" data-remote-body><?= $reopen !== null ? $reopen['html'] : '' ?></div>
</dialog>
