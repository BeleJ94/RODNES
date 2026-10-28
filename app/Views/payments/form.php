<?php

/**
 * @var list<array{id: int, label: string}> $clients
 * @var array<string, mixed>|null $client
 * @var list<array<string, mixed>> $open
 * @var int $focusInvoice
 * @var list<string> $methods
 */

$methodOptions = [];
foreach ($methods as $m) {
    $methodOptions[$m] = t('payment_method.' . $m);
}
?>
<?= partial('partials/page-header', ['icon' => 'wallet', 'title' => t('payments.new'), 'meta' => e(t('payments.form_meta'))]) ?>
<form class="card form" method="get" action="<?= e(url('/payments/new')) ?>" data-autosubmit>
  <div class="form-grid">
    <?= form_select('client_id', t('rfqs.col.client'), array_column($clients, 'label', 'id'), $client['id'] ?? '', ['placeholder' => t('payments.choose_client'), 'required' => true]) ?>
  </div>
</form>
<?php if ($client !== null): ?>
<form method="post" action="<?= e(url('/payments')) ?>" class="card form form--sections section-gap">
  <?= csrf_field() ?>
  <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
  <fieldset class="form-section">
    <legend><?= e(t('payments.section_receipt')) ?></legend>
    <div class="form-grid">
      <?= form_input('received_on', t('payments.col.received_on'), date('Y-m-d'), ['type' => 'date', 'required' => true]) ?>
      <?= form_input('amount', t('payments.col.amount_usd'), '', ['required' => true, 'attrs' => ' inputmode="decimal" class="num" data-alloc-source']) ?>
      <?= form_select('method', t('payments.col.method'), $methodOptions, 'bank_transfer', ['required' => true]) ?>
      <?= form_input('bank_reference', t('payments.col.bank_reference'), '', ['maxlength' => 80, 'hint' => t('payments.bank_reference_hint')]) ?>
      <?= form_textarea('notes', t('invoices.notes'), '', ['span' => true, 'maxlength' => 2000]) ?>
    </div>
  </fieldset>
  <fieldset class="form-section">
    <legend><?= e(t('payments.section_allocation')) ?></legend>
    <?php if ($open === []): ?>
      <p class="muted"><?= e(t('payments.no_open_invoice')) ?></p>
    <?php else: ?>
      <?= partial('payments/_allocations', ['open' => $open, 'focusInvoice' => $focusInvoice, 'amountSelector' => '[data-alloc-source]']) ?>
    <?php endif; ?>
  </fieldset>
  <?= form_actions(url('/payments'), t('payments.record')) ?>
</form>
<?php endif; ?>
