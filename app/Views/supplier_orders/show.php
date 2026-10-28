<?php

use App\Domain\Orders\SupplierOrderStatus as S;
use App\Domain\Shared\Money;

/**
 * @var array<string, mixed> $order
 * @var array<string, mixed>|null $reopenInvoice saisie de facture à rouvrir dans la fenêtre (erreur de validation)
 * @var array<string, mixed>|null $reopenShipment saisie d'expédition à rouvrir dans la fenêtre
 * @var S $status
 * @var list<array<string, mixed>> $lines
 * @var int $totalUsd
 * @var list<array<string, mixed>> $history
 */

$post = static function (string $action, string $label, string $icon, string $class, string $confirm = '') use ($order): string {
    return '<form method="post" action="' . e(url('/supplier-orders/' . $order['id'] . '/status')) . '"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
        . csrf_field() . '<input type="hidden" name="action" value="' . e($action) . '">'
        . '<button type="submit" class="' . e($class) . '">' . icon($icon) . '<span>' . e($label) . '</span></button></form>';
};
$buttons = '<a class="btn" href="' . e(url('/supplier-orders/' . $order['id'] . '/print')) . '"'
    . preview_attrs('document', t('preview.title_purchase_order', ['ref' => $order['reference']]), t('preview.meta_supplier_document', ['supplier' => $order['supplier_name']])) . '>'
    . icon('eye') . '<span>' . e(t('supplier_orders.print')) . '</span></a>';
if (can('supplier_orders.manage')) {
    if ($status === S::Draft) {
        $buttons .= '<a class="btn" href="' . e(url('/supplier-orders/' . $order['id'] . '/edit')) . '">' . icon('pencil') . '<span>' . e(t('common.edit')) . '</span></a>';
        $buttons .= $post('send', t('supplier_orders.action.send'), 'send', 'btn btn--primary', t('supplier_orders.confirm_send'));
    }
}
if (($status === S::Sent || $status === S::Confirmed) && can('shipments.manage')) {
    $buttons .= '<a class="btn btn--primary" href="' . e(url('/shipments/new', ['client_id' => $order['client_id'], 'supplier_order_id' => $order['id']])) . '" data-remote-dialog="' . e(url('/shipments/new', ['client_id' => $order['client_id'], 'supplier_order_id' => $order['id'], 'return_to' => '/supplier-orders/' . $order['id'], 'fragment' => 1])) . '">'
        . icon('truck') . '<span>' . e(t('shipments.ship_po')) . '</span></a>';
}
if (($status === S::Sent || $status === S::Confirmed) && can('supplier_invoices.manage')) {
    $buttons .= '<a class="btn" href="' . e(url('/supplier-orders/' . $order['id'] . '/invoices/new')) . '" data-remote-dialog="' . e(url('/supplier-orders/' . $order['id'] . '/invoices/new', ['fragment' => 1])) . '">'
        . icon('file-input') . '<span>' . e(t('supplier_invoices.record')) . '</span></a>';
}
$today = date('Y-m-d');
$late = $order['expected_ready_on'] !== null && $order['expected_ready_on'] < $today && in_array($status, [S::Sent, S::Confirmed], true);
?>
<?= partial('partials/page-header', [
    'icon' => 'shopping-cart',
    'title' => (string) $order['reference'],
    'meta' => badge($status->tone(), t($status->labelKey())) . ' · ' . e($order['supplier_name'])
        . ' · <a href="' . e(url('/client-orders/' . $order['client_order_id'])) . '">' . e($order['client_order_reference']) . '</a> (' . e($order['client_name']) . ')',
    'actions' => $buttons,
]) ?>
<?= doc_chain('supplier_order', (int) $order['id']) ?>

<section class="vitals">
  <div class="vital"><?= icon('banknote') ?><span class="vital__label"><?= e(t('supplier_orders.col.amount')) ?></span><span class="vital__value"><?= e(format_money((int) $order['total'], $order['currency_code'])) ?></span></div>
  <div class="vital"><?= icon('coins') ?><span class="vital__label"><?= e(t($status === S::Draft ? 'supplier_orders.usd_estimate' : 'supplier_orders.usd_fixed')) ?></span><span class="vital__value"><?= e(format_money($totalUsd, 'USD')) ?><?php if ($order['currency_code'] !== 'USD'): ?> <span class="muted">@ <?= e(format_rate((int) $order['fx_units_per_usd_micro'])) ?></span><?php endif; ?></span></div>
  <div class="vital<?= $late ? ' vital--alert' : '' ?>"><?= icon('calendar-clock') ?><span class="vital__label"><?= e(t('supplier_orders.expected_ready_on')) ?></span><span class="vital__value"><?= e(format_date($order['expected_ready_on'])) ?: '—' ?></span></div>
  <div class="vital"><?= icon('map-pin') ?><span class="vital__label"><?= e(t('supplier_orders.delivery_address')) ?></span><span class="vital__value"><?= e($order['delivery_address'] ?: '—') ?></span></div>
  <div class="vital"><?= icon('ship') ?><span class="vital__label"><?= e(t('quotations.terms')) ?></span><span class="vital__value"><?= e(trim(($order['incoterm'] ?? '') . ' · ' . t('common.days', ['n' => $order['payment_terms_days']]), ' ·')) ?></span></div>
</section>

<section class="card section-gap">
  <div class="card__title"><h2><?= icon('list', 'title-icon') ?><?= e(t('supplier_orders.section_lines')) ?></h2></div>
  <table class="table" data-dt="compact">
    <thead><tr>
      <th scope="col">#</th>
      <th scope="col"><?= e(t('rfqs.line.description')) ?></th>
      <th scope="col" class="num"><?= e(t('rfqs.line.quantity')) ?></th>
      <th scope="col" class="num"><?= e(t('supplier_orders.line.unit_price')) ?></th>
      <th scope="col" class="num"><?= e(t('quotations.line.total')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $l): ?>
      <tr>
        <td class="muted"><?= (int) $l['line_no'] ?></td>
        <td><?= e($l['description']) ?><br><span class="muted"><?= e(t('supplier_orders.for_client_line', ['n' => $l['client_line_no']])) ?></span></td>
        <td class="num nowrap"><?= e(format_qty((int) $l['quantity_milli'])) ?> <span class="muted"><?= e(unit_label($l['unit'])) ?></span></td>
        <td class="num nowrap"><?= e(format_money((int) $l['unit_price_minor'], $order['currency_code'])) ?></td>
        <td class="num nowrap"><strong><?= e(format_money(Money::lineAmount((int) $l['quantity_milli'], (int) $l['unit_price_minor']))) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($order['notes']): ?><p class="note"><?= nl2br(e($order['notes'])) ?></p><?php endif; ?>
</section>

<?php if (can('supplier_invoices.view')): ?>
<section class="card section-gap" id="supplier-invoices">
  <div class="card__title"><h2><?= icon('file-input', 'title-icon') ?><?= e(t('supplier_invoices.title')) ?></h2></div>
  <?php if ($supplierInvoices === []): ?>
    <p class="muted"><?= e(t('supplier_invoices.none_for_order')) ?></p>
  <?php else: ?>
    <?= partial('supplier_invoices/_table', ['rows' => $supplierInvoices, 'compact' => true, 'withSupplier' => false]) ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php foreach ($followupCards as $card): ?>
  <?= partial('followups/_card', $card) ?>
<?php endforeach; ?>

<div class="grid grid--main-side section-gap">
  <?= partial('partials/history', ['history' => $history, 'statusPrefix' => 'supplier_order_status.']) ?>
  <?php if (can('supplier_orders.manage') && $status !== S::Cancelled): ?>
  <section class="card">
    <?php if ($status === S::Sent): ?>
      <div class="card__title"><h2><?= icon('badge-check', 'title-icon') ?><?= e(t('supplier_orders.confirm_title')) ?></h2></div>
      <form method="post" action="<?= e(url('/supplier-orders/' . $order['id'] . '/status')) ?>" class="form">
        <?= csrf_field() ?><input type="hidden" name="action" value="confirm">
        <div class="field">
          <label for="confirm-ref"><?= e(t('supplier_orders.supplier_reference')) ?></label>
          <input id="confirm-ref" name="note" maxlength="64" value="<?= e($order['supplier_reference'] ?? '') ?>">
          <p class="hint"><?= e(t('supplier_orders.supplier_reference_hint')) ?></p>
        </div>
        <button type="submit" class="btn btn--primary"><?= icon('badge-check') ?><span><?= e(t('supplier_orders.action.confirm')) ?></span></button>
      </form>
    <?php endif; ?>
    <div class="card__title<?= $status === S::Sent ? ' section-gap' : '' ?>"><h2><?= icon('ban', 'title-icon title-icon--bad') ?><?= e(t('supplier_orders.cancel_title')) ?></h2></div>
    <form method="post" action="<?= e(url('/supplier-orders/' . $order['id'] . '/status')) ?>" class="form" data-confirm="<?= e(t('supplier_orders.confirm_cancel')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
      <div class="field">
        <label for="cancel-reason"><?= e(t('rfqs.close_reason')) ?></label>
        <input id="cancel-reason" name="note" maxlength="255">
      </div>
      <button type="submit" class="btn btn--danger"><?= icon('ban') ?><span><?= e(t('supplier_orders.action.cancel')) ?></span></button>
    </form>
  </section>
  <?php endif; ?>
</div>

<?php
// Fenêtre rouverte après une erreur de saisie : facture fournisseur ou expédition.
$reopen = $reopenInvoice !== null
    ? ['title' => t('supplier_invoices.new_title') . ' — ' . $order['reference'] . ' · ' . $order['supplier_name'], 'html' => partial('supplier_invoices/_form', $reopenInvoice)]
    : ($reopenShipment !== null ? ['title' => t('shipments.new') . ' — ' . $order['client_name'], 'html' => partial('shipments/_form', $reopenShipment)] : null);
?>
<dialog class="panel-modal remote-modal" id="remote-modal" aria-labelledby="remote-modal-title"<?= $reopen !== null ? ' data-auto-open' : '' ?>>
  <div class="panel-modal__head">
    <h2 id="remote-modal-title" class="panel-modal__title"><?= $reopen !== null ? e($reopen['title']) : '' ?></h2>
    <button type="button" class="icon-btn" data-close-dialog aria-label="<?= e(t('common.close')) ?>"><?= icon('x') ?></button>
  </div>
  <div class="remote-modal__body" data-remote-body><?= $reopen !== null ? $reopen['html'] : '' ?></div>
</dialog>
