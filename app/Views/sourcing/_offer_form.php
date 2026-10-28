<?php

/**
 * Saisie de l'offre d'un fournisseur (fenêtre de la fiche RFQ, ou page complète) :
 * conditions et prix unitaires à gauche, document de l'offre (dépôt + aperçu) à droite.
 *
 * @var array<string, mixed> $quote
 * @var list<array<string, mixed>> $lines lignes demandées au fournisseur
 * @var array<int, array<string, mixed>> $offered rfq_line_id → ligne d'offre existante
 * @var list<array<string, mixed>> $files fichiers déjà joints
 * @var list<string> $currencies
 * @var array<string, array{rate: int, date: ?string}> $rates
 * @var list<string> $incoterms
 */

$oldRows = old_rows('lines');
$rateHints = [];
foreach ($currencies as $code) {
    if ($code !== 'USD') {
        $rateHints[] = isset($rates[$code])
            ? t('sourcing.rate_hint', ['code' => $code, 'rate' => format_rate($rates[$code]['rate']), 'date' => format_date($rates[$code]['date'])])
            : t('sourcing.rate_missing', ['code' => $code]);
    }
}
$title = t('sourcing.offer_title', ['supplier' => (string) $quote['supplier_name']]) . ($quote['reference'] ? ' — ' . $quote['reference'] : '');
?>
<form method="post" action="<?= e(url('/sourcing/' . $quote['id'])) ?>" class="offer-form form" enctype="multipart/form-data" data-title="<?= e($title) ?>">
  <?= csrf_field() ?>
  <div class="offer-layout">
    <div class="offer-layout__main">
      <h3 class="rfq-step"><span class="rfq-step__no">1</span><?= e(t('sourcing.section_offer')) ?></h3>
      <div class="form-grid form-grid--3">
        <?= form_input('supplier_reference', t('sourcing.supplier_reference'), $quote['supplier_reference'] ?? '', ['maxlength' => 64]) ?>
        <?= form_input('received_on', t('sourcing.received_on'), $quote['received_on'] ?? date('Y-m-d'), ['type' => 'date', 'required' => true]) ?>
        <?= form_input('valid_until', t('sourcing.valid_until'), $quote['valid_until'] ?? '', ['type' => 'date']) ?>
        <?= form_select('currency_code', t('sourcing.currency'), array_combine($currencies, $currencies), $quote['currency_code'], ['required' => true, 'hint' => implode(' · ', $rateHints)]) ?>
        <?= form_select('incoterm', 'Incoterm', array_combine($incoterms, $incoterms), $quote['incoterm'] ?? '', ['placeholder' => '—']) ?>
        <?= form_input('lead_time_days', t('sourcing.lead_time_days'), $quote['lead_time_days'] ?? '', ['type' => 'number', 'attrs' => ' min="0" max="999"']) ?>
        <?= form_textarea('notes', t('common.notes'), $quote['notes'] ?? '', ['span' => true, 'maxlength' => 2000, 'rows' => 2]) ?>
      </div>

      <h3 class="rfq-step section-gap"><span class="rfq-step__no">2</span><?= e(t('sourcing.section_prices')) ?></h3>
      <?= field_error('lines') ?>
      <div class="table-scroll">
        <table class="table lines-table offer-lines" data-no-dt>
          <thead><tr>
            <th scope="col">#</th>
            <th scope="col"><?= e(t('rfqs.line.description')) ?></th>
            <th scope="col" class="num"><?= e(t('rfqs.line.quantity')) ?></th>
            <th scope="col" class="num"><?= e(t('sourcing.unit_price')) ?></th>
            <th scope="col" class="num"><?= e(t('sourcing.lead_time_days')) ?></th>
            <th scope="col"><?= e(t('sourcing.line_note')) ?></th>
          </tr></thead>
          <tbody>
          <?php foreach ($lines as $i => $line):
              $id = (int) $line['id'];
              $existing = $offered[$id] ?? null;
              $row = $oldRows[$id] ?? [
                  'price' => $existing !== null ? input_money((int) $existing['unit_price_minor']) : '',
                  'lead_time_days' => (string) ($existing['lead_time_days'] ?? ''),
                  'note' => (string) ($existing['note'] ?? ''),
              ];
              $qty = (int) ($line['requested_milli'] ?? $line['quantity_milli']); ?>
            <tr>
              <td class="muted"><?= $i + 1 ?></td>
              <td><?= e($line['description']) ?></td>
              <td class="num nowrap"><?= e(format_qty($qty)) ?> <span class="muted"><?= e(unit_label($line['unit'])) ?></span></td>
              <td><input name="lines[<?= $id ?>][price]" value="<?= e($row['price'] ?? '') ?>" inputmode="decimal" class="num" placeholder="0,00" aria-label="<?= e(t('sourcing.unit_price') . ' — ' . $line['description']) ?>"></td>
              <td><input name="lines[<?= $id ?>][lead_time_days]" value="<?= e($row['lead_time_days'] ?? '') ?>" type="number" min="0" max="999" class="num" aria-label="<?= e(t('sourcing.lead_time_days')) ?>"></td>
              <td><input name="lines[<?= $id ?>][note]" value="<?= e($row['note'] ?? '') ?>" maxlength="255" aria-label="<?= e(t('sourcing.line_note')) ?>"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="hint"><?= e(t('sourcing.prices_hint')) ?></p>
    </div>

    <aside class="offer-layout__doc" data-doc-picker>
      <h3 class="rfq-step"><?= icon('paperclip') ?><?= e(t('sourcing.offer_files')) ?></h3>
      <div class="dropzone dropzone--compact" data-dropzone>
        <input type="file" name="offer_files[]" multiple class="dropzone__input" aria-label="<?= e(t('sourcing.offer_files')) ?>"
               accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx,.xls,.docx,.doc,.msg,.eml,.txt,.csv"<?= invalid('offer_files') ?>>
        <div class="dropzone__body" aria-hidden="true">
          <?= icon('upload-cloud') ?>
          <strong><?= e(t('sourcing.offer_drop')) ?></strong>
          <span><?= e(t('rfqs.drop_browse')) ?></span>
        </div>
      </div>
      <ul class="doc-chips" data-doc-chips>
        <?php foreach ($files as $file): $kind = preview_kind((string) $file['mime']); ?>
          <li class="doc-chip" data-src="<?= e(url('/files/' . $file['file_id'])) ?>" data-kind="<?= e($kind ?? 'other') ?>" data-name="<?= e($file['original_name']) ?>" data-saved>
            <button type="button" class="doc-chip__open"><?= icon($kind === 'image' ? 'image' : ($kind === 'pdf' ? 'file-text' : 'file')) ?><span class="doc-chip__name"><?= e($file['original_name']) ?></span><span class="doc-chip__size"><?= e(format_bytes((int) $file['size_bytes'])) ?></span></button>
            <span class="chip chip--ok"><?= e(t('rfqs.file_saved')) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="doc-viewer doc-viewer--modal" data-doc-viewer>
        <div class="doc-viewer__empty"><?= icon('file-search') ?><p><?= e(t('sourcing.offer_viewer_empty')) ?></p></div>
      </div>
    </aside>
  </div>
  <div class="panel-modal__foot">
    <span class="muted"><?= e(t('sourcing.requested_lines_n', ['n' => count($lines)])) ?></span>
    <button type="button" class="btn" data-close-dialog><?= e(t('common.cancel')) ?></button>
    <button type="submit" class="btn btn--primary"><?= icon('check') ?><span><?= e(t('sourcing.offer_save')) ?></span></button>
  </div>
</form>
