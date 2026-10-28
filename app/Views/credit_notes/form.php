<?php

use App\Domain\Invoicing\CreditNoteRules;

/**
 * Avoir sur une facture : quantité retournée ou manquante (prix facturé), ou remise (toute la quantité à un prix réduit).
 *
 * @var array<string, mixed> $invoice
 * @var list<array<string, mixed>> $lines lignes de facture avec montant et quantité déjà crédités
 */

$oldRows = old_rows('lines');
?>
<?= partial('partials/page-header', [
    'icon' => 'receipt-text',
    'title' => $title,
    'meta' => '<a href="' . e(url('/invoices/' . $invoice['id'])) . '">' . e($invoice['reference']) . '</a> · ' . e($invoice['client_name'])
        . ' · ' . e(t('credit_notes.invoice_balance', ['total' => format_money((int) $invoice['total'], 'USD'), 'balance' => format_money((int) $invoice['balance'], 'USD')])),
]) ?>
<form method="post" action="<?= e(url('/invoices/' . $invoice['id'] . '/credit-notes')) ?>" class="card form form--sections">
  <?= csrf_field() ?>
  <fieldset class="form-section">
    <legend><?= e(t('credit_notes.section_reason')) ?></legend>
    <div class="form-grid">
      <?= form_input('issue_date', t('invoices.col.issue_date'), date('Y-m-d'), ['type' => 'date', 'required' => true]) ?>
      <?= form_input('reason', t('credit_notes.reason'), '', ['required' => true, 'maxlength' => 255, 'hint' => t('credit_notes.reason_hint')]) ?>
      <?= form_check('is_return', t('credit_notes.is_return'), false, t('credit_notes.is_return_hint')) ?>
    </div>
  </fieldset>
  <fieldset class="form-section">
    <legend><?= e(t('credit_notes.section_lines')) ?></legend>
    <?= field_error('lines') ?>
    <div class="table-scroll">
      <table class="table lines-table" data-no-dt>
        <thead><tr>
          <th scope="col">#</th>
          <th scope="col" class="w-40"><?= e(t('rfqs.line.description')) ?></th>
          <th scope="col" class="num"><?= e(t('credit_notes.line.invoiced')) ?></th>
          <th scope="col" class="num"><?= e(t('credit_notes.line.creditable')) ?></th>
          <th scope="col" class="w-qty"><?= e(t('credit_notes.line.quantity')) ?></th>
          <th scope="col" class="w-qty"><?= e(t('credit_notes.line.price')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($lines as $l):
            $id = (int) $l['id'];
            $creditable = CreditNoteRules::creditable((int) $l['quantity_milli'], (int) $l['unit_price_minor'], (int) $l['credited_amount']); ?>
          <tr<?= $creditable === 0 ? ' class="is-muted"' : '' ?>>
            <td class="muted"><?= (int) $l['line_no'] ?></td>
            <td><?= e($l['description']) ?></td>
            <td class="num nowrap"><?= e(format_qty((int) $l['quantity_milli'])) ?> × <?= e(format_money((int) $l['unit_price_minor'])) ?></td>
            <td class="num nowrap"><?= e(format_money($creditable, 'USD')) ?></td>
            <td><?php if ($creditable > 0): ?><input name="lines[<?= $id ?>][quantity]" value="<?= e($oldRows[$id]['quantity'] ?? '') ?>" inputmode="decimal" class="num" aria-label="<?= e(t('credit_notes.line.quantity') . ' ' . $l['line_no']) ?>"><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td><?php if ($creditable > 0): ?><input name="lines[<?= $id ?>][price]" value="<?= e($oldRows[$id]['price'] ?? input_money((int) $l['unit_price_minor'])) ?>" inputmode="decimal" class="num" aria-label="<?= e(t('credit_notes.line.price') . ' ' . $l['line_no']) ?>"><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint"><?= e(t('credit_notes.lines_hint', ['vat' => $invoice['vat_applicable'] ? format_percent((int) $invoice['vat_rate_bp']) : t('quotations.total.vat_exempt')])) ?></p>
  </fieldset>
  <fieldset class="form-section">
    <legend><?= e(t('invoices.notes')) ?></legend>
    <div class="form-grid"><?= form_textarea('notes', t('invoices.notes'), '', ['span' => true, 'maxlength' => 2000, 'hint' => t('invoices.notes_hint')]) ?></div>
  </fieldset>
  <?= form_actions(url('/invoices/' . $invoice['id']), t('credit_notes.issue')) ?>
</form>
