<?php /** @var array<string, mixed> $entity @var string $action @var string $cancel @var list<string> $currencies */ ?>
<?= partial('partials/page-header', ['icon' => 'factory', 'title' => $title]) ?>
<form method="post" action="<?= e($action) ?>" class="card form form--sections">
  <?= csrf_field() ?>
  <fieldset class="form-section">
    <legend><?= e(t('suppliers.section_identity')) ?></legend>
    <div class="form-grid">
      <?= form_input('code', t('common.code'), $entity['code'] ?? '', ['required' => true, 'maxlength' => 32]) ?>
      <?= form_input('name', t('suppliers.name'), $entity['name'] ?? '', ['required' => true, 'maxlength' => 160]) ?>
      <?= form_input('country', t('suppliers.country'), $entity['country'] ?? '', ['maxlength' => 2, 'hint' => t('suppliers.country_hint')]) ?>
      <?= form_select('currency_code', t('suppliers.currency'), array_combine($currencies, $currencies), $entity['currency_code'] ?? 'USD', ['required' => true, 'hint' => t('suppliers.currency_hint')]) ?>
    </div>
  </fieldset>
  <fieldset class="form-section">
    <legend><?= e(t('suppliers.section_contact')) ?></legend>
    <div class="form-grid">
      <?= form_input('contact_name', t('common.contact_name'), $entity['contact_name'] ?? '', ['maxlength' => 120]) ?>
      <?= form_input('contact_email', t('common.email'), $entity['contact_email'] ?? '', ['type' => 'email', 'maxlength' => 190]) ?>
      <?= form_input('contact_phone', t('common.phone'), $entity['contact_phone'] ?? '', ['type' => 'tel', 'maxlength' => 32]) ?>
      <?= form_input('payment_terms_days', t('common.payment_terms'), $entity['payment_terms_days'] ?? '30', ['type' => 'number', 'required' => true, 'attrs' => ' min="0" max="365"', 'hint' => t('common.days_hint')]) ?>
      <?= form_check('is_active', t('common.active'), (bool) ($entity['is_active'] ?? true)) ?>
      <?= form_textarea('notes', t('common.notes'), $entity['notes'] ?? '', ['span' => true, 'maxlength' => 2000]) ?>
    </div>
  </fieldset>
  <?= form_actions($cancel) ?>
</form>
