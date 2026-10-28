<?php

/**
 * @var array<string, mixed> $supplier
 * @var array<string, array<string, mixed>> $ledgers devise → écritures et totaux
 * @var list<array<string, mixed>> $open
 */

$buttons = can('supplier_payments.manage')
    ? '<a class="btn btn--primary" href="' . e(url('/supplier-payments/new', ['supplier_id' => $supplier['id']])) . '">' . icon('send-to-back') . '<span>' . e(t('supplier_payments.new')) . '</span></a>'
    : '';
$hrefs = ['supplier_invoice' => '/supplier-invoices/', 'supplier_cancellation' => '/supplier-invoices/', 'supplier_payment' => '/supplier-payments/'];
?>
<?= partial('partials/page-header', [
    'icon' => 'book-open',
    'title' => $title,
    'meta' => '<a href="' . e(url('/suppliers/' . $supplier['id'])) . '">' . e($supplier['code']) . '</a> · ' . e(t('supplier_statements.show_meta')),
    'actions' => export_button() . ($buttons),
]) ?>
<?php if ($ledgers === []): ?>
  <section class="card"><div class="empty-state"><?= icon('book-open') ?><p><?= e(t('supplier_statements.empty')) ?></p></div></section>
<?php endif; ?>
<?php foreach ($ledgers as $cur => $ledger): ?>
<section class="card section-gap">
  <div class="card__title"><h2><?= icon('coins', 'title-icon') ?><?= e(t('supplier_statements.ledger_title', ['cur' => $cur])) ?></h2></div>
  <?= partial('statements/_ledger', $ledger + ['links' => true, 'tableClass' => 'table', 'currency' => (string) $cur, 'hrefs' => $hrefs]) ?>
</section>
<?php endforeach; ?>
<?php if ($open !== []): ?>
<section class="card section-gap">
  <div class="card__title"><h2><?= icon('file-input', 'title-icon') ?><?= e(t('supplier_statements.open_invoices')) ?></h2></div>
  <?= partial('supplier_invoices/_table', ['rows' => $open, 'compact' => true, 'withSupplier' => false]) ?>
</section>
<?php endif; ?>
