<?php /** @var array<string, mixed> $entity @var string $action @var string $cancel */ ?>
<?= partial('partials/page-header', ['icon' => 'building-2', 'title' => $title]) ?>
<form method="post" action="<?= e($action) ?>" class="card form form--sections">
  <?= csrf_field() ?>
  <fieldset class="form-section">
    <legend><?= e(t('clients.section_identity')) ?></legend>
    <div class="form-grid">
      <?= form_input('code', t('common.code'), $entity['code'] ?? '', ['required' => true, 'maxlength' => 32, 'hint' => t('clients.code_hint')]) ?>
      <?= form_input('name', t('clients.name'), $entity['name'] ?? '', ['required' => true, 'maxlength' => 160]) ?>
      <?= form_input('customer_number', t('clients.customer_number'), $entity['customer_number'] ?? '', ['maxlength' => 40, 'hint' => t('clients.customer_number_hint')]) ?>
      <?= form_input('rccm', 'RCCM', $entity['rccm'] ?? '', ['maxlength' => 64]) ?>
      <?= form_input('nif', t('common.nif'), $entity['nif'] ?? '', ['maxlength' => 64]) ?>
      <?= form_input('id_nat', t('common.id_nat'), $entity['id_nat'] ?? '', ['maxlength' => 64]) ?>
      <?= form_input('city', t('common.city'), $entity['city'] ?? '', ['maxlength' => 80]) ?>
      <?= form_input('address', t('common.address'), $entity['address'] ?? '', ['maxlength' => 255, 'span' => true]) ?>
    </div>
  </fieldset>
  <fieldset class="form-section">
    <legend><?= e(t('clients.section_contact')) ?></legend>
    <div class="form-grid">
      <?= form_input('contact_name', t('common.contact_name'), $entity['contact_name'] ?? '', ['maxlength' => 120]) ?>
      <?= form_input('contact_email', t('common.email'), $entity['contact_email'] ?? '', ['type' => 'email', 'maxlength' => 190]) ?>
      <?= form_input('contact_phone', t('common.phone'), $entity['contact_phone'] ?? '', ['type' => 'tel', 'maxlength' => 32]) ?>
    </div>
  </fieldset>
  <fieldset class="form-section">
    <legend><?= e(t('clients.section_billing')) ?></legend>
    <div class="form-grid">
      <?= form_input('payment_terms_days', t('common.payment_terms'), $entity['payment_terms_days'] ?? '30', ['type' => 'number', 'required' => true, 'attrs' => ' min="0" max="365"', 'hint' => t('common.days_hint')]) ?>
      <?= form_input('rfq_response_days', t('clients.rfq_response_days'), $entity['rfq_response_days'] ?? '', ['type' => 'number', 'attrs' => ' min="1" max="60"', 'placeholder' => t('clients.default_value', ['n' => setting('rfq_response_days')]), 'hint' => t('clients.delay_override_hint')]) ?>
      <?= form_input('quote_validity_days', t('clients.quote_validity_days'), $entity['quote_validity_days'] ?? '', ['type' => 'number', 'attrs' => ' min="1" max="365"', 'placeholder' => t('clients.default_value', ['n' => setting('quote_validity_days')]), 'hint' => t('clients.validity_override_hint')]) ?>
      <?= form_check('vat_applicable', t('clients.vat_applicable'), (bool) ($entity['vat_applicable'] ?? true), t('clients.vat_hint')) ?>
      <?= form_check('is_active', t('common.active'), (bool) ($entity['is_active'] ?? true)) ?>
      <?= form_textarea('notes', t('common.notes'), $entity['notes'] ?? '', ['span' => true, 'maxlength' => 2000]) ?>
    </div>
  </fieldset>
  <?= form_actions($cancel) ?>
</form>
