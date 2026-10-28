<?php /** @var array<string, string> $s */ ?>
<?= partial('partials/page-header', ['icon' => 'building', 'title' => t('settings.company_title'), 'meta' => e(t('settings.company_meta'))]) ?>
<form method="post" action="<?= e(url('/settings/company')) ?>" class="card form form--sections" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <fieldset class="form-section">
    <legend><?= e(t('settings.section_identity')) ?></legend>
    <div class="form-grid">
      <?= form_input('company_name', t('settings.company_name'), $s['company_name'], ['required' => true, 'maxlength' => 160]) ?>
      <?= form_input('company_rccm', 'RCCM', $s['company_rccm'], ['maxlength' => 64]) ?>
      <?= form_input('company_nif', t('common.nif'), $s['company_nif'], ['maxlength' => 64]) ?>
      <?= form_input('company_idnat', t('common.id_nat'), $s['company_idnat'], ['maxlength' => 64]) ?>
      <?= form_input('company_phone', t('common.phone'), $s['company_phone'], ['maxlength' => 64]) ?>
      <?= form_input('company_email', t('common.email'), $s['company_email'], ['type' => 'email', 'maxlength' => 190]) ?>
      <?= form_input('company_address', t('common.address'), $s['company_address'], ['maxlength' => 255, 'span' => true]) ?>
      <?= form_input('company_website', t('settings.company_website'), $s['company_website'], ['maxlength' => 120, 'placeholder' => 'www.exemple.com']) ?>
      <div class="field">
        <label for="f-company_logo_file"><?= e(t('settings.company_logo')) ?></label>
        <?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt="<?= e(t('settings.company_logo')) ?>" class="logo-preview"><?php endif; ?>
        <input type="file" id="f-company_logo_file" name="company_logo_file" accept=".png,.jpg,.jpeg,.webp"<?= invalid('company_logo_file') ?>>
        <p class="hint"><?= e(t('settings.company_logo_hint')) ?></p>
        <?= field_error('company_logo_file') ?>
        <?php if ($logo !== ''): ?><label class="check"><input type="checkbox" name="company_logo_remove" value="1"><span><?= e(t('settings.company_logo_remove')) ?></span></label><?php endif; ?>
      </div>
      <?= form_textarea('company_bank', t('settings.company_bank'), $s['company_bank'], ['span' => true, 'maxlength' => 500, 'hint' => t('settings.company_bank_hint')]) ?>
    </div>
  </fieldset>
  <fieldset class="form-section">
    <legend><?= e(t('settings.section_sales')) ?></legend>
    <div class="form-grid">
      <?= form_input('vat_rate_bp', t('settings.vat_rate'), input_percent((int) $s['vat_rate_bp']), ['required' => true, 'attrs' => ' inputmode="decimal"', 'hint' => t('settings.vat_rate_hint')]) ?>
      <?= form_select('quote_payment_basis', t('settings.quote_payment_basis'), ['delivery' => t('quotations.payment_basis.delivery'), 'invoice' => t('quotations.payment_basis.invoice')], $s['quote_payment_basis'], ['required' => true]) ?>
      <?= form_textarea('quote_certification', t('settings.quote_certification'), $s['quote_certification'] !== '' ? $s['quote_certification'] : t('quotations.doc_certification_default'), ['span' => true, 'maxlength' => 600, 'rows' => 2, 'hint' => t('settings.quote_certification_hint')]) ?>
      <?= form_input('default_margin_bp', t('settings.default_margin'), input_percent((int) $s['default_margin_bp']), ['required' => true, 'attrs' => ' inputmode="decimal"', 'hint' => t('settings.default_margin_hint')]) ?>
    </div>
  </fieldset>
  <fieldset class="form-section" id="delays">
    <legend><?= e(t('settings.section_delays')) ?></legend>
    <p class="hint"><?= e(t('settings.delays_intro')) ?></p>
    <div class="form-grid">
      <?= form_select('delay_mode', t('settings.delay_mode'), ['business' => t('settings.delay_mode_business'), 'calendar' => t('settings.delay_mode_calendar')], $s['delay_mode'], ['required' => true, 'hint' => t('settings.delay_mode_hint')]) ?>
      <?= form_input('rfq_response_days', t('settings.rfq_response_days'), $s['rfq_response_days'], ['type' => 'number', 'required' => true, 'attrs' => ' min="1" max="60"', 'hint' => t('settings.rfq_response_hint')]) ?>
      <?= form_input('quote_validity_days', t('settings.quote_validity'), $s['quote_validity_days'], ['type' => 'number', 'required' => true, 'attrs' => ' min="1" max="365"', 'hint' => t('settings.quote_validity_hint')]) ?>
      <?= form_input('quote_followup_days', t('settings.quote_followup_days'), $s['quote_followup_days'], ['maxlength' => 40, 'placeholder' => '5, 10', 'hint' => t('settings.quote_followup_hint')]) ?>
      <?= form_input('client_po_followup_days', t('settings.client_po_followup_days'), $s['client_po_followup_days'], ['maxlength' => 40, 'placeholder' => '3, 7', 'hint' => t('settings.client_po_followup_hint')]) ?>
      <?= form_input('sourcing_reminder_days', t('settings.sourcing_reminder'), $s['sourcing_reminder_days'], ['type' => 'number', 'required' => true, 'attrs' => ' min="1" max="60"', 'hint' => t('settings.sourcing_reminder_hint')]) ?>
      <?= form_input('supplier_confirm_followup_days', t('settings.supplier_confirm_followup_days'), $s['supplier_confirm_followup_days'], ['maxlength' => 40, 'placeholder' => '2, 4', 'hint' => t('settings.supplier_confirm_followup_hint')]) ?>
      <?= form_input('supplier_ready_check_days', t('settings.supplier_ready_check_days'), $s['supplier_ready_check_days'], ['type' => 'number', 'required' => true, 'attrs' => ' min="0" max="30"', 'hint' => t('settings.supplier_ready_check_hint')]) ?>
    </div>
  </fieldset>
  <fieldset class="form-section">
    <legend><?= e(t('settings.section_approval')) ?></legend>
    <div class="form-grid">
      <?= form_check('margin_approval_enabled', t('settings.margin_approval_enabled'), $s['margin_approval_enabled'] === '1', t('settings.margin_approval_hint')) ?>
      <?= form_input('margin_approval_threshold_bp', t('settings.margin_threshold'), input_percent((int) $s['margin_approval_threshold_bp']), ['required' => true, 'attrs' => ' inputmode="decimal"', 'hint' => t('settings.margin_threshold_hint')]) ?>
      <?= form_input('supplier_price_tolerance_bp', t('settings.price_tolerance'), input_percent((int) $s['supplier_price_tolerance_bp']), ['required' => true, 'attrs' => ' inputmode="decimal"', 'hint' => t('settings.price_tolerance_hint')]) ?>
    </div>
  </fieldset>
  <?= form_actions(url('/')) ?>
</form>
