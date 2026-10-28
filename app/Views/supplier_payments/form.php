<?php

use App\Domain\Shared\Fx;

/**
 * @var list<array{id: int, label: string}> $suppliers
 * @var array<string, mixed>|null $supplier
 * @var list<string> $currencies
 * @var list<string> $payableCurrencies devises où le fournisseur a des factures bon à payer
 * @var string $currency
 * @var int $rate dernier taux connu de la devise
 * @var list<array<string, mixed>> $open
 * @var int|null $focusInvoice
 * @var list<string> $methods
 */

$methodOptions = [];
foreach ($methods as $m) {
    $methodOptions[$m] = t('payment_method.' . $m);
}
$currencyOptions = [];
foreach ($currencies as $code) {
    $currencyOptions[$code] = in_array($code, $payableCurrencies, true) ? t('supplier_payments.currency_payable', ['cur' => $code]) : $code;
}
?>
<?= partial('partials/page-header', ['icon' => 'send-to-back', 'title' => t('supplier_payments.new'), 'meta' => e(t('supplier_payments.form_meta'))]) ?>
<form class="card form" method="get" action="<?= e(url('/supplier-payments/new')) ?>" data-autosubmit>
  <div class="form-grid">
    <?= form_select('supplier_id', t('sourcing.supplier'), array_column($suppliers, 'label', 'id'), $supplier['id'] ?? '', ['placeholder' => t('supplier_payments.choose_supplier'), 'required' => true]) ?>
    <?php if ($supplier !== null): ?>
      <?= form_select('currency', t('supplier_payments.currency'), $currencyOptions, $currency, ['required' => true, 'hint' => t('supplier_payments.currency_hint')]) ?>
    <?php endif; ?>
  </div>
</form>
<?php if ($supplier !== null): ?>
<form method="post" action="<?= e(url('/supplier-payments')) ?>" class="card form form--sections section-gap">
  <?= csrf_field() ?>
  <input type="hidden" name="supplier_id" value="<?= (int) $supplier['id'] ?>">
  <input type="hidden" name="currency" value="<?= e($currency) ?>">
  <fieldset class="form-section">
    <legend><?= e(t('supplier_payments.section_payment', ['cur' => $currency])) ?></legend>
    <div class="form-grid">
      <?= form_input('paid_on', t('supplier_payments.col.paid_on'), date('Y-m-d'), ['type' => 'date', 'required' => true]) ?>
      <?= form_input('amount', t('supplier_payments.amount', ['cur' => $currency]), '', ['required' => true, 'attrs' => ' inputmode="decimal" class="num" data-alloc-source']) ?>
      <?php if ($currency === Fx::USD): ?>
        <input type="hidden" name="fx_rate" value="1">
      <?php else: ?>
        <?= form_input('fx_rate', t('supplier_invoices.fx_rate', ['cur' => $currency]), Fx::formatRate($rate), ['required' => true, 'attrs' => ' inputmode="decimal"', 'hint' => t('supplier_payments.fx_hint')]) ?>
      <?php endif; ?>
      <?= form_select('method', t('payments.col.method'), $methodOptions, 'bank_transfer', ['required' => true]) ?>
      <?= form_input('bank_reference', t('payments.col.bank_reference'), '', ['maxlength' => 80, 'hint' => t('payments.bank_reference_hint')]) ?>
      <?= form_textarea('notes', t('invoices.notes'), '', ['span' => true, 'maxlength' => 2000]) ?>
    </div>
  </fieldset>
  <fieldset class="form-section">
    <legend><?= e(t('supplier_payments.section_allocation')) ?></legend>
    <?php if ($open === []): ?>
      <p class="muted"><?= e(t('supplier_payments.no_payable', ['cur' => $currency])) ?></p>
    <?php else: ?>
      <?= partial('payments/_allocations', ['open' => $open, 'focusInvoice' => (int) $focusInvoice, 'amountSelector' => '[data-alloc-source]', 'currency' => $currency, 'basePath' => '/supplier-invoices/']) ?>
    <?php endif; ?>
  </fieldset>
  <?= form_actions(url('/supplier-payments'), t('supplier_payments.record')) ?>
</form>
<?php endif; ?>
