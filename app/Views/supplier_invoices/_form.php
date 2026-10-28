<?php

use App\Domain\Shared\Fx;

/**
 * Saisie d'une facture fournisseur telle que reçue (fenêtre de la fiche PO fournisseur, ou page complète) :
 * en-tête et lignes face au PO et à la réception à gauche, document de la facture (dépôt + aperçu) à droite.
 *
 * @var array<string, mixed> $order
 * @var list<array<string, mixed>> $lines
 * @var bool $modal true : dans la fenêtre (Annuler ferme la fenêtre)
 */

$oldRows = old_rows('lines');
$cur = (string) $order['currency_code'];
$title = t('supplier_invoices.new_title') . ' — ' . $order['reference'] . ' · ' . $order['supplier_name'];
?>
<form method="post" action="<?= e(url('/supplier-orders/' . $order['id'] . '/invoices')) ?>" class="offer-form form" enctype="multipart/form-data"
      data-title="<?= e($title) ?>" data-supplier-invoice data-currency="<?= e($cur) ?>">
  <?= csrf_field() ?>
  <div class="offer-layout">
    <div class="offer-layout__main">
      <h3 class="rfq-step"><span class="rfq-step__no">1</span><?= e(t('supplier_invoices.section_header')) ?></h3>
      <div class="form-grid form-grid--3">
        <?= form_input('supplier_invoice_number', t('supplier_invoices.supplier_invoice_number'), '', ['required' => true, 'maxlength' => 64, 'hint' => t('supplier_invoices.number_hint')]) ?>
        <?= form_input('invoice_date', t('supplier_invoices.col.invoice_date'), date('Y-m-d'), ['type' => 'date', 'required' => true, 'hint' => t('supplier_invoices.due_hint', ['n' => $order['payment_terms_days']])]) ?>
        <?php if ($cur === Fx::USD): ?>
          <input type="hidden" name="fx_rate" value="1">
        <?php else: ?>
          <?= form_input('fx_rate', t('supplier_invoices.fx_rate', ['cur' => $cur]), Fx::formatRate((int) $order['fx_units_per_usd_micro']), ['required' => true, 'attrs' => ' inputmode="decimal"', 'hint' => t('supplier_invoices.fx_hint', ['rate' => format_rate((int) $order['fx_units_per_usd_micro'])])]) ?>
        <?php endif; ?>
        <?= form_input('vat', t('supplier_invoices.vat', ['cur' => $cur]), '', ['attrs' => ' inputmode="decimal" data-si-vat', 'hint' => t('supplier_invoices.vat_hint')]) ?>
        <?= form_textarea('notes', t('invoices.notes'), '', ['span' => true, 'maxlength' => 2000, 'rows' => 2]) ?>
      </div>

      <h3 class="rfq-step section-gap"><span class="rfq-step__no">2</span><?= e(t('supplier_invoices.section_lines')) ?></h3>
      <?= field_error('lines') ?>
      <div class="table-scroll">
        <table class="table lines-table offer-lines" data-no-dt>
          <thead><tr>
            <th scope="col">#</th>
            <th scope="col"><?= e(t('rfqs.line.description')) ?></th>
            <th scope="col" class="num"><?= e(t('invoices.line.ordered')) ?></th>
            <th scope="col" class="num"><?= e(t('supplier_invoices.line.received')) ?></th>
            <th scope="col" class="num"><?= e(t('invoices.line.invoiced')) ?></th>
            <th scope="col" class="num"><?= e(t('supplier_invoices.line.po_price', ['cur' => $cur])) ?></th>
            <th scope="col" class="w-qty"><?= e(t('supplier_invoices.line.quantity')) ?></th>
            <th scope="col" class="w-qty"><?= e(t('supplier_invoices.line.price', ['cur' => $cur])) ?></th>
          </tr></thead>
          <tbody>
          <?php foreach ($lines as $l):
              $id = (int) $l['id'];
              $suggested = max(0, (int) $l['received_milli'] - (int) $l['invoiced_milli']); ?>
            <tr data-si-line>
              <td class="muted"><?= (int) $l['line_no'] ?></td>
              <td><?= e($l['description']) ?></td>
              <td class="num nowrap muted"><?= e(format_qty((int) $l['ordered_milli'])) ?> <?= e(unit_label($l['unit'])) ?></td>
              <td class="num nowrap"><?= e(format_qty((int) $l['received_milli'])) ?></td>
              <td class="num nowrap muted"><?= e(format_qty((int) $l['invoiced_milli'])) ?></td>
              <td class="num nowrap"><?= e(format_money((int) $l['po_price_minor'])) ?></td>
              <td><input name="lines[<?= $id ?>][quantity]" value="<?= e($oldRows[$id]['quantity'] ?? ($suggested > 0 ? input_qty($suggested) : '')) ?>" inputmode="decimal" class="num" data-qty aria-label="<?= e(t('supplier_invoices.line.quantity') . ' ' . $l['line_no']) ?>"></td>
              <td><input name="lines[<?= $id ?>][price]" value="<?= e($oldRows[$id]['price'] ?? input_money((int) $l['po_price_minor'])) ?>" inputmode="decimal" class="num" data-price aria-label="<?= e(t('supplier_invoices.line.price', ['cur' => $cur]) . ' ' . $l['line_no']) ?>"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="hint"><?= e(t('supplier_invoices.lines_hint')) ?></p>
    </div>

    <aside class="offer-layout__doc" data-doc-picker>
      <h3 class="rfq-step"><?= icon('paperclip') ?><?= e(t('supplier_invoices.file')) ?></h3>
      <div class="dropzone dropzone--compact<?= invalid('invoice_file') !== '' ? ' is-invalid' : '' ?>" data-dropzone>
        <input type="file" id="f-invoice_file" name="invoice_file" class="dropzone__input" aria-label="<?= e(t('supplier_invoices.file')) ?>"
               accept="image/jpeg,image/png,image/webp,application/pdf"<?= invalid('invoice_file') ?>>
        <div class="dropzone__body" aria-hidden="true">
          <?= icon('upload-cloud') ?>
          <strong><?= e(t('supplier_invoices.file_drop')) ?></strong>
          <span><?= e(t('rfqs.drop_browse')) ?></span>
        </div>
      </div>
      <p class="hint"><?= e(t('supplier_invoices.file_hint')) ?></p>
      <?= field_error('invoice_file') ?>
      <ul class="doc-chips" data-doc-chips></ul>
      <div class="doc-viewer doc-viewer--modal" data-doc-viewer>
        <div class="doc-viewer__empty"><?= icon('file-search') ?><p><?= e(t('supplier_invoices.viewer_empty')) ?></p></div>
      </div>
    </aside>
  </div>
  <div class="panel-modal__foot">
    <span class="muted" data-si-total data-text="<?= e(t('supplier_invoices.total_entered')) ?>"></span>
    <?php if ($modal): ?>
      <button type="button" class="btn" data-close-dialog><?= e(t('common.cancel')) ?></button>
    <?php else: ?>
      <a class="btn" href="<?= e(url('/supplier-orders/' . $order['id'])) ?>"><?= e(t('common.cancel')) ?></a>
    <?php endif; ?>
    <button type="submit" class="btn btn--primary"><?= icon('check') ?><span><?= e(t('supplier_invoices.record')) ?></span></button>
  </div>
</form>
