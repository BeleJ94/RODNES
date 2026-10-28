<?php

/**
 * Saisie d'une RFQ : en-tête, contact, lignes à gauche ; document du client (dépôt + aperçu) à droite,
 * pour encoder les lignes en lisant la demande.
 *
 * @var array<string, mixed> $rfq
 * @var list<array<string, mixed>> $lines
 * @var list<array{id: int, label: string}> $clients
 * @var list<array<string, mixed>> $items
 * @var list<array{id: int, name: string}> $users
 * @var array<string, string> $units code → libellé (unités actives)
 * @var list<array<string, mixed>> $files pièces jointes déjà enregistrées (modification)
 * @var bool $canCreateItems l'utilisateur peut ajouter des articles au référentiel
 * @var array{mode: string, clients: array<int, array{buyer: string, email: string, days: int}>} $clientInfo
 * @var string $action
 * @var string $cancel
 */

$editing = isset($rfq['id']);
$itemNames = array_column($items, 'name', 'id');
$rows = old_rows('lines') ?? array_map(static fn (array $l): array => [
    'item_id' => (string) ($l['item_id'] ?? ''), 'item_name' => (string) ($itemNames[$l['item_id'] ?? 0] ?? ''), 'description' => $l['description'], 'quantity' => input_qty((int) $l['quantity_milli']),
    'unit' => $l['unit'], 'required_by' => (string) ($l['required_by'] ?? ''),
], $lines);
if ($rows === []) {
    $rows = [['item_id' => '', 'item_name' => '', 'description' => '', 'quantity' => '', 'unit' => 'u', 'required_by' => '']];
}
$dueAt = isset($rfq['due_at']) && $rfq['due_at'] ? str_replace(' ', 'T', substr((string) $rfq['due_at'], 0, 16)) : '';

$lineRow = static function (string $index, array $row) use ($units, $itemNames): string {
    $n = 'lines[' . $index . ']';
    $html = '<tr class="line" data-line>';
    $html .= '<td class="line__no" data-line-no></td>';
    // Article : recherche dans le référentiel ; un nom inconnu devient un nouvel article à l'enregistrement.
    $itemId = (string) ($row['item_id'] ?? '');
    $itemName = (string) ($row['item_name'] ?? '') !== '' ? (string) $row['item_name'] : (string) ($itemNames[$itemId] ?? '');
    $html .= '<td class="combo-cell"><div class="combo" data-item-combo>'
        . '<input type="text" name="' . e($n) . '[item_name]" value="' . e($itemName) . '" maxlength="190" class="combo__input" data-item-input autocomplete="off" spellcheck="false"'
        . ' role="combobox" aria-autocomplete="list" aria-expanded="false" placeholder="' . e(t('rfqs.item_search_placeholder')) . '" aria-label="' . e(t('rfqs.line.item')) . '">'
        . '<input type="hidden" name="' . e($n) . '[item_id]" value="' . e($itemId) . '" data-item-id>'
        . '<span class="combo__state" data-item-state aria-live="polite"></span></div></td>';
    $html .= '<td><input name="' . e($n) . '[description]" value="' . e($row['description'] ?? '') . '" maxlength="500" data-line-description aria-label="' . e(t('rfqs.line.description')) . '"></td>';
    $html .= '<td><input name="' . e($n) . '[quantity]" value="' . e($row['quantity'] ?? '') . '" inputmode="decimal" class="num" data-line-qty aria-label="' . e(t('rfqs.line.quantity')) . '"></td>';
    $html .= '<td><select name="' . e($n) . '[unit]" data-line-unit aria-label="' . e(t('rfqs.line.unit')) . '">';
    foreach ($units as $unit => $unitLabel) {
        $html .= '<option value="' . e($unit) . '"' . ($unit === ($row['unit'] ?? 'u') ? ' selected' : '') . '>' . e($unitLabel) . '</option>';
    }
    $html .= '</select></td>';
    $html .= '<td><input type="date" name="' . e($n) . '[required_by]" value="' . e($row['required_by'] ?? '') . '" aria-label="' . e(t('rfqs.line.required_by')) . '"></td>';
    $html .= '<td><button type="button" class="icon-btn icon-btn--danger" data-remove-line title="' . e(t('rfqs.line.remove')) . '" aria-label="' . e(t('rfqs.line.remove')) . '">' . icon('trash-2') . '</button></td>';
    return $html . '</tr>';
};
$step = static fn (int $n, string $icon, string $label): string => '<h2 class="rfq-step"><span class="rfq-step__no">' . $n . '</span>' . icon($icon) . e($label) . '</h2>';
?>
<?= partial('partials/page-header', ['icon' => 'inbox', 'title' => $title, 'meta' => e(t($editing ? 'rfqs.form_meta_edit' : 'rfqs.form_meta'))]) ?>
<form method="post" action="<?= e($action) ?>" class="rfq-form form" enctype="multipart/form-data" data-rfq-form>
  <?= csrf_field() ?>
  <div class="rfq-layout">
    <div class="rfq-layout__main">
      <section class="card">
        <?= $step(1, 'building-2', t('rfqs.section_header')) ?>
        <div class="form-grid form-grid--3">
          <?= form_select('client_id', t('rfqs.client'), array_column($clients, 'label', 'id'), $rfq['client_id'] ?? '', ['required' => true, 'placeholder' => t('common.choose'), 'span' => true]) ?>
          <?= form_input('client_reference', t('rfqs.client_reference'), $rfq['client_reference'] ?? '', ['maxlength' => 64, 'hint' => t('rfqs.client_reference_hint')]) ?>
          <?= form_input('received_on', t('rfqs.received_on'), $rfq['received_on'] ?? '', ['type' => 'date', 'required' => true]) ?>
          <?= form_input('due_at', t('rfqs.due_at'), $dueAt, ['type' => 'datetime-local']) ?>
        </div>
        <p class="due-preview" data-due-preview hidden><?= icon('alarm-clock') ?><span></span></p>
      </section>

      <section class="card section-gap">
        <?= $step(2, 'user-round', t('rfqs.section_contact')) ?>
        <div class="form-grid form-grid--3">
          <?= form_input('buyer_name', t('rfqs.buyer_name'), $rfq['buyer_name'] ?? '', ['maxlength' => 120]) ?>
          <?= form_input('buyer_email', t('rfqs.buyer_email'), $rfq['buyer_email'] ?? '', ['type' => 'email', 'maxlength' => 190]) ?>
          <?= form_select('owner_id', t('rfqs.owner'), array_column($users, 'name', 'id'), $rfq['owner_id'] ?? '', ['placeholder' => '—']) ?>
          <?= form_input('delivery_site', t('rfqs.delivery_site'), $rfq['delivery_site'] ?? '', ['maxlength' => 160]) ?>
          <?= form_textarea('notes', t('common.notes'), $rfq['notes'] ?? '', ['span' => true, 'maxlength' => 2000, 'rows' => 2]) ?>
        </div>
        <p class="hint" data-prefill-hint hidden><?= icon('sparkles') ?><?= e(t('rfqs.prefilled')) ?></p>
      </section>

      <section class="card section-gap">
        <div class="rfq-step-row"><?= $step(3, 'list', t('rfqs.section_lines')) ?><span class="chip" data-lines-count></span></div>
        <?= field_error('lines') ?>
        <div class="table-scroll">
          <table class="table lines-table" data-lines>
            <thead><tr>
              <th scope="col">#</th>
              <th scope="col"><?= e(t('rfqs.line.item')) ?></th>
              <th scope="col"><?= e(t('rfqs.line.description')) ?> *</th>
              <th scope="col" class="w-qty" title="<?= e(t('rfqs.line.quantity')) ?>"><?= e(t('rfqs.line.qty_short')) ?> *</th>
              <th scope="col"><?= e(t('rfqs.line.unit')) ?></th>
              <th scope="col"><?= e(t('rfqs.line.required_by')) ?></th>
              <th scope="col"><span class="visually-hidden"><?= e(t('common.actions')) ?></span></th>
            </tr></thead>
            <tbody data-lines-body>
              <?php foreach (array_values($rows) as $i => $row): ?><?= $lineRow((string) $i, $row) ?><?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <template data-line-template><?= $lineRow('__i__', ['unit' => 'u']) ?></template>
        <div class="rfq-lines-foot">
          <button type="button" class="btn btn--sm" data-add-line><?= icon('plus') ?><span><?= e(t('rfqs.line.add')) ?></span></button>
          <span class="muted"><?= icon('keyboard') ?><?= e(t('rfqs.lines_keyboard')) ?></span>
        </div>
        <p class="hint"><?= e(t($canCreateItems ? 'rfqs.lines_hint_create' : 'rfqs.lines_hint')) ?></p>
        <?= json_script('rfq-items', ['canCreate' => $canCreateItems, 'items' => array_map(static fn (array $i): array => [
            'id' => (int) $i['id'], 'code' => $i['code'], 'name' => $i['name'], 'unit' => $i['unit'], 'unitLabel' => unit_label($i['unit']),
            'maker' => (string) $i['manufacturer'], 'part' => (string) $i['part_number'],
        ], $items)]) ?>
      </section>
    </div>

    <aside class="rfq-layout__doc">
      <section class="card doc-panel" aria-labelledby="doc-panel-title">
        <h2 class="rfq-step" id="doc-panel-title"><?= icon('paperclip') ?><?= e(t('rfqs.section_files')) ?><?= !$editing ? ' <span class="req" aria-hidden="true">*</span>' : '' ?></h2>
        <div class="dropzone<?= invalid('attachments') !== '' ? ' is-invalid' : '' ?>" data-dropzone>
          <input type="file" id="f-attachments" name="attachments[]" multiple<?= !$editing ? ' required' : '' ?> class="dropzone__input" aria-label="<?= e(t('rfqs.section_files')) ?>"
                 accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx,.xls,.docx,.doc,.msg,.eml,.txt,.csv" aria-describedby="dropzone-hint"<?= invalid('attachments') ?>>
          <div class="dropzone__body" aria-hidden="true">
            <?= icon('upload-cloud') ?>
            <strong><?= e(t($editing ? 'rfqs.drop_more' : 'rfqs.drop_title')) ?></strong>
            <span><?= e(t('rfqs.drop_browse')) ?></span>
          </div>
        </div>
        <p class="hint" id="dropzone-hint"><?= e(t('rfqs.attachments_hint')) ?></p>
        <?= field_error('attachments') ?>
        <ul class="doc-chips" data-doc-chips>
          <?php foreach ($files as $file): $kind = preview_kind((string) $file['mime']); ?>
            <li class="doc-chip" data-src="<?= e(url('/files/' . $file['file_id'])) ?>" data-kind="<?= e($kind ?? 'other') ?>" data-name="<?= e($file['original_name']) ?>" data-saved>
              <button type="button" class="doc-chip__open"><?= icon($kind === 'image' ? 'image' : ($kind === 'pdf' ? 'file-text' : 'file')) ?><span class="doc-chip__name"><?= e($file['original_name']) ?></span><span class="doc-chip__size"><?= e(format_bytes((int) $file['size_bytes'])) ?></span></button>
              <span class="chip chip--ok"><?= e(t('rfqs.file_saved')) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <div class="doc-viewer" data-doc-viewer>
          <div class="doc-viewer__empty"><?= icon('file-search') ?><p><?= e(t('rfqs.viewer_empty')) ?></p></div>
        </div>
      </section>
    </aside>
  </div>

  <div class="rfq-bar">
    <span class="rfq-bar__summary" data-rfq-summary></span>
    <a class="btn" href="<?= e($cancel) ?>"><?= e(t('common.cancel')) ?></a>
    <button type="submit" class="btn btn--primary"><?= icon('check') ?><span><?= e(t($editing ? 'common.save' : 'rfqs.save_new')) ?></span></button>
  </div>
  <?= json_script('rfq-form-data', $clientInfo + [
      'defaultDays' => (int) setting('rfq_response_days'),
      'i18n' => [
          'due' => t('rfqs.due_computed'), 'lines' => t('rfqs.lines_count_n'), 'files' => t('rfqs.files_count_n'),
          'remove' => t('common.delete'), 'noPreview' => t('rfqs.viewer_no_preview'), 'newFile' => t('rfqs.file_new'),
          'days_business' => t('tracking.days_business'), 'days_calendar' => t('tracking.days_calendar'),
      ],
  ]) ?>
</form>
