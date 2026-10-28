<?php

/**
 * PO client : en-tête et lignes à gauche ; bon de commande du client (dépôt + aperçu) à droite,
 * pour saisir le PO en le lisant. Création depuis une quotation (case « commandée » par ligne) ou modification.
 *
 * @var array<string, mixed> $order
 * @var array<string, mixed>|null $quotation
 * @var array<string, mixed>|null $rfq
 * @var list<array<string, mixed>> $lines
 * @var list<array<string, mixed>> $files bons de commande déjà enregistrés (modification)
 * @var bool $creating
 * @var string $action
 * @var string $cancel
 */

$oldRows = old_rows('lines');
$incoterms = \App\Controllers\SourcingController::INCOTERMS;
$step = static fn (int $n, string $icon, string $label, bool $required = false): string => '<h2 class="rfq-step"><span class="rfq-step__no">' . $n . '</span>' . icon($icon) . e($label)
    . ($required ? ' <span class="req" aria-hidden="true">*</span>' : '') . '</h2>';
$meta = '';
if ($quotation !== null) {
    $meta = e($quotation['client_name']) . ' · <a href="' . e(url('/quotations/' . $quotation['id'])) . '">' . e($quotation['reference'] . ' v' . $quotation['version']) . '</a>';
    if ($rfq !== null) {
        $meta .= ' · <a href="' . e(url('/rfqs/' . $rfq['id'])) . '">' . e($rfq['reference']) . '</a>'
            . (($rfq['client_reference'] ?? '') !== '' ? ' · <span class="client-ref">' . e($rfq['client_reference']) . '</span>' : '');
    }
}
?>
<?= partial('partials/page-header', ['icon' => 'file-check', 'title' => $title, 'meta' => $meta]) ?>
<?php if ($creating && $quotation !== null && $quotation['status'] === 'sent'): ?>
  <div class="alert alert--info"><?= icon('info') ?><span><?= e(t('client_orders.will_accept')) ?></span></div>
<?php endif; ?>
<form method="post" action="<?= e($action) ?>" class="form co-form" enctype="multipart/form-data" data-client-order>
  <?= csrf_field() ?>
  <div class="rfq-layout">
    <div class="rfq-layout__main">
      <section class="card">
        <?= $step(1, 'file-check', t('client_orders.section_header')) ?>
        <div class="form-grid form-grid--3">
          <?= form_input('client_po_number', t('client_orders.client_po_number'), $order['client_po_number'] ?? '', ['required' => true, 'maxlength' => 64, 'hint' => t('client_orders.client_po_hint'), 'attrs' => ' autofocus']) ?>
          <?= form_input('received_on', t('client_orders.received_on'), $order['received_on'] ?? '', ['type' => 'date', 'required' => true]) ?>
          <?= form_input('required_by', t('client_orders.required_by'), $order['required_by'] ?? '', ['type' => 'date']) ?>
          <?= form_input('delivery_site', t('rfqs.delivery_site'), $order['delivery_site'] ?? '', ['maxlength' => 160]) ?>
          <?= form_select('incoterm', 'Incoterm', array_combine($incoterms, $incoterms), $order['incoterm'] ?? '', ['placeholder' => '—']) ?>
          <?= form_input('payment_terms_days', t('common.payment_terms'), $order['payment_terms_days'] ?? '30', ['type' => 'number', 'required' => true, 'attrs' => ' min="0" max="365"', 'hint' => t('common.days_hint')]) ?>
          <?= form_check('vat_applicable', t('client_orders.vat_applicable'), (bool) ($order['vat_applicable'] ?? true)) ?>
          <?= form_textarea('notes', t('common.notes'), $order['notes'] ?? '', ['span' => true, 'maxlength' => 2000, 'rows' => 2]) ?>
        </div>
      </section>

      <section class="card section-gap">
        <?= $step(2, 'list', t('client_orders.section_lines')) ?>
        <p class="hint"><?= e(t($creating ? 'client_orders.lines_hint_create' : 'client_orders.lines_hint_edit')) ?></p>
        <?= field_error('lines') ?>
        <div class="table-scroll">
          <table class="table lines-table co-lines" data-no-dt>
            <thead><tr>
              <?php if ($creating): ?><th scope="col" class="co-lines__check"><label class="check"><input type="checkbox" data-include-all checked aria-label="<?= e(t('client_orders.include_all')) ?>"></label></th><?php endif; ?>
              <th scope="col">#</th>
              <th scope="col" class="w-40"><?= e(t('rfqs.line.description')) ?></th>
              <th scope="col" class="num"><?= e(t('client_orders.line.quoted')) ?></th>
              <th scope="col" class="w-qty"><?= e(t('rfqs.line.quantity')) ?></th>
              <th scope="col" class="w-money"><?= e(t('quotations.line.price')) ?></th>
              <th scope="col" class="num"><?= e(t('quotations.line.total')) ?></th>
              <?php if (!$creating): ?><th scope="col" class="num"><?= e(t('client_orders.line.allocated')) ?></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($lines as $line):
                $id = (int) $line['id'];
                $quotedQty = $creating ? (int) $line['quantity_milli'] : (int) ($line['quoted_qty_milli'] ?? 0);
                $quotedPrice = $creating ? (int) $line['unit_price_minor'] : (int) ($line['quoted_price_minor'] ?? 0);
                $row = $oldRows[$id] ?? [
                    'include' => '1',
                    'description' => $line['description'],
                    'quantity' => input_qty((int) $line['quantity_milli']),
                    'price' => input_money((int) $line['unit_price_minor']),
                ];
                $included = $oldRows === null || ($row['include'] ?? '') === '1';
                $n = 'lines[' . $id . ']'; ?>
              <tr data-co-line data-quoted-qty="<?= $quotedQty ?>" data-quoted-price="<?= $quotedPrice ?>"<?= $creating && !$included ? ' class="is-excluded"' : '' ?>>
                <?php if ($creating): ?>
                  <td><label class="check"><input type="checkbox" name="<?= $n ?>[include]" value="1" data-include<?= $included ? ' checked' : '' ?> aria-label="<?= e(t('client_orders.line.ordered')) ?>"></label></td>
                <?php endif; ?>
                <td class="muted"><?= (int) $line['line_no'] ?></td>
                <td><input name="<?= $n ?>[description]" value="<?= e($row['description'] ?? '') ?>" maxlength="500" required aria-label="<?= e(t('rfqs.line.description')) ?>"></td>
                <td class="num nowrap muted"><?= $quotedQty > 0 ? e(format_qty($quotedQty)) . ' × ' . e(format_money($quotedPrice)) : '—' ?></td>
                <td><span class="qty-cell"><input name="<?= $n ?>[quantity]" value="<?= e($row['quantity'] ?? '') ?>" inputmode="decimal" class="num" required data-qty aria-label="<?= e(t('rfqs.line.quantity')) ?>"><span class="muted unit-suffix"><?= e(unit_label($line['unit'])) ?></span></span></td>
                <td><input name="<?= $n ?>[price]" value="<?= e($row['price'] ?? '') ?>" inputmode="decimal" class="num" required data-price aria-label="<?= e(t('quotations.line.price')) ?>"></td>
                <td class="num nowrap"><span data-line-total></span><span class="co-diff" data-line-diff hidden><?= icon('triangle-alert') ?><span><?= e(t('client_orders.differs')) ?></span></span></td>
                <?php if (!$creating): ?><td class="num muted"><?= e(format_qty((int) $line['allocated_milli'])) ?></td><?php endif; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <aside class="rfq-layout__doc">
      <section class="card doc-panel" data-doc-picker aria-labelledby="po-doc-title">
        <h2 class="rfq-step" id="po-doc-title"><?= icon('paperclip') ?><?= e(t('client_orders.po_file')) ?><?= $creating ? ' <span class="req" aria-hidden="true">*</span>' : '' ?></h2>
        <div class="dropzone<?= invalid('po_files') !== '' ? ' is-invalid' : '' ?>" data-dropzone>
          <input type="file" id="f-po_files" name="po_files[]" multiple<?= $creating ? ' required' : '' ?> class="dropzone__input" aria-label="<?= e(t('client_orders.po_file')) ?>"
                 accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx,.xls,.docx,.doc,.msg,.eml,.txt,.csv" aria-describedby="po-drop-hint"<?= invalid('po_files') ?>>
          <div class="dropzone__body" aria-hidden="true">
            <?= icon('upload-cloud') ?>
            <strong><?= e(t($creating ? 'client_orders.po_drop' : 'client_orders.po_drop_more')) ?></strong>
            <span><?= e(t('rfqs.drop_browse')) ?></span>
          </div>
        </div>
        <p class="hint" id="po-drop-hint"><?= e(t('client_orders.po_file_hint')) ?></p>
        <?= field_error('po_files') ?>
        <ul class="doc-chips" data-doc-chips>
          <?php foreach ($files as $file): $kind = preview_kind((string) $file['mime']); ?>
            <li class="doc-chip" data-src="<?= e(url('/files/' . $file['file_id'])) ?>" data-kind="<?= e($kind ?? 'other') ?>" data-name="<?= e($file['original_name']) ?>" data-saved>
              <button type="button" class="doc-chip__open"><?= icon($kind === 'image' ? 'image' : ($kind === 'pdf' ? 'file-text' : 'file')) ?><span class="doc-chip__name"><?= e($file['original_name']) ?></span><span class="doc-chip__size"><?= e(format_bytes((int) $file['size_bytes'])) ?></span></button>
              <span class="chip chip--ok"><?= e(t('rfqs.file_saved')) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <div class="doc-viewer" data-doc-viewer>
          <div class="doc-viewer__empty"><?= icon('file-search') ?><p><?= e(t('client_orders.po_viewer_empty')) ?></p></div>
        </div>
      </section>
    </aside>
  </div>

  <div class="rfq-bar">
    <span class="rfq-bar__summary" data-co-summary
          data-text="<?= e(t('client_orders.summary')) ?>" data-text-diff="<?= e(t('client_orders.summary_diff')) ?>"></span>
    <a class="btn" href="<?= e($cancel) ?>"><?= e(t('common.cancel')) ?></a>
    <button type="submit" class="btn btn--primary"><?= icon('check') ?><span><?= e(t($creating ? 'client_orders.save_new' : 'common.save')) ?></span></button>
  </div>
</form>
