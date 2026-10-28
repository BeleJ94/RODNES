<?php

/**
 * Création d'une expédition (fenêtre des fiches PO, ou page complète) : lignes à expédier et transport à gauche,
 * documents de transport (dépôt + aperçu) à droite.
 *
 * @var array<string, mixed> $client
 * @var list<array<string, mixed>> $lines lignes restant à expédier
 * @var ?int $preselect PO fournisseur à cocher par défaut (sinon tout)
 * @var string $returnTo fiche d'origine (retour en cas d'erreur), vide depuis la page
 * @var bool $modal true : dans la fenêtre (Annuler ferme la fenêtre)
 * @var list<array<string, mixed>> $docTypes types de document actifs (référentiel)
 */

$oldRows = old_rows('lines');
$destination = trim($client['name'] . (($lines[0]['delivery_site'] ?? '') !== '' ? ' — ' . $lines[0]['delivery_site'] : ''));
?>
<form method="post" action="<?= e(url('/shipments')) ?>" class="offer-form form" enctype="multipart/form-data"
      data-title="<?= e(t('shipments.new') . ' — ' . $client['name']) ?>" data-shipment-form>
  <?= csrf_field() ?>
  <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
  <?php if ($returnTo !== ''): ?><input type="hidden" name="return_to" value="<?= e($returnTo) ?>"><?php endif; ?>
  <div class="offer-layout">
    <div class="offer-layout__main">
      <h3 class="rfq-step"><span class="rfq-step__no">1</span><?= e(t('shipments.section_lines')) ?></h3>
      <?= field_error('lines') ?>
      <?php if ($lines === []): ?>
        <p class="muted"><?= e(t('shipments.nothing_for_client')) ?></p>
      <?php else: ?>
      <div class="table-scroll">
        <table class="table lines-table offer-lines" data-no-dt>
          <thead><tr>
            <th scope="col"><?= e(t('shipments.line.ship')) ?></th>
            <th scope="col"><?= e(t('shipments.line.orders')) ?></th>
            <th scope="col"><?= e(t('rfqs.line.description')) ?></th>
            <th scope="col" class="num"><?= e(t('shipments.line.remaining')) ?></th>
            <th scope="col" class="w-qty"><?= e(t('shipments.line.quantity')) ?></th>
          </tr></thead>
          <tbody>
          <?php foreach ($lines as $line):
              $id = (int) $line['id'];
              $remaining = (int) $line['ordered_milli'] - (int) $line['shipped_milli'];
              $checked = $oldRows !== null ? (($oldRows[$id]['ship'] ?? '') === '1') : ($preselect === null || $preselect === (int) $line['supplier_order_id']);
              $n = 'lines[' . $id . ']'; ?>
            <tr data-ship-line<?= $checked ? '' : ' class="is-excluded"' ?>>
              <td><label class="check"><input type="checkbox" name="<?= $n ?>[ship]" value="1" data-ship<?= $checked ? ' checked' : '' ?> aria-label="<?= e(t('shipments.line.ship')) ?>"></label></td>
              <td class="nowrap"><strong><?= e($line['supplier_order_reference']) ?></strong> <span class="muted"><?= e($line['supplier_name']) ?></span><br>
                <span class="muted"><?= e($line['client_order_reference']) ?> · <?= e(t('client_orders.their_po', ['ref' => $line['client_po_number']])) ?></span></td>
              <td><?= e($line['description']) ?><?php if ($line['expected_ready_on']): ?><br><span class="muted"><?= e(t('shipments.ready_on', ['date' => format_date($line['expected_ready_on'])])) ?></span><?php endif; ?></td>
              <td class="num nowrap muted"><?= e(format_qty($remaining)) ?> <?= e(unit_label($line['unit'])) ?></td>
              <td><input name="<?= $n ?>[quantity]" value="<?= e($oldRows[$id]['quantity'] ?? input_qty($remaining)) ?>" inputmode="decimal" class="num" aria-label="<?= e(t('shipments.line.quantity')) ?>"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="hint"><?= e(t('shipments.lines_hint')) ?></p>
      <?php endif; ?>

      <h3 class="rfq-step section-gap"><span class="rfq-step__no">2</span><?= e(t('shipments.section_transport')) ?></h3>
      <div class="form-grid form-grid--3">
        <?= form_select('carrier_type', t('shipments.carrier_type_label'), ['forwarder' => t('shipments.carrier_type.forwarder'), 'supplier' => t('shipments.carrier_type.supplier')], old('carrier_type', 'forwarder'), ['required' => true]) ?>
        <?= form_input('carrier_name', t('shipments.carrier_name'), '', ['maxlength' => 160, 'placeholder' => t('shipments.carrier_placeholder')]) ?>
        <?= form_input('tracking_reference', t('shipments.tracking_reference'), '', ['maxlength' => 80, 'hint' => t('shipments.tracking_hint')]) ?>
        <?= form_input('origin', t('shipments.origin'), '', ['maxlength' => 160, 'placeholder' => t('shipments.origin_placeholder')]) ?>
        <?= form_input('planned_pickup_on', t('shipments.planned_pickup_on'), '', ['type' => 'date']) ?>
        <?= form_input('eta', t('shipments.eta'), '', ['type' => 'date', 'hint' => t('shipments.eta_hint')]) ?>
        <?= form_input('destination', t('shipments.destination'), $destination, ['maxlength' => 255, 'span' => true]) ?>
        <?= form_textarea('notes', t('common.notes'), '', ['span' => true, 'maxlength' => 2000, 'rows' => 2]) ?>
      </div>

      <?php if ($docTypes !== []): ?>
      <h3 class="rfq-step section-gap"><span class="rfq-step__no">3</span><?= e(t('shipments.docs.title')) ?></h3>
      <p class="hint"><?= e(t('shipments.docs.create_hint')) ?></p>
      <input type="hidden" name="doc_required_sent" value="1">
      <?php $oldRequired = old_rows('doc_required'); $oldRefs = old_rows('doc_ref'); ?>
      <table class="table lines-table doc-req" data-no-dt>
        <thead><tr>
          <th scope="col" class="doc-req__check"><?= e(t('shipments.docs.required')) ?></th>
          <th scope="col"><?= e(t('shipments.docs.type')) ?></th>
          <th scope="col"><?= e(t('shipments.docs.reference')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($docTypes as $type): $tid = (int) $type['id'];
            $isRequired = $oldRequired !== null ? (($oldRequired[$tid] ?? '') === '1') : (bool) $type['is_required']; ?>
          <tr>
            <td><label class="check"><input type="checkbox" name="doc_required[<?= $tid ?>]" value="1"<?= $isRequired ? ' checked' : '' ?> aria-label="<?= e(t('shipments.docs.required') . ' — ' . \App\Repositories\DocTypeRepository::label($type)) ?>"></label></td>
            <td><?= e(\App\Repositories\DocTypeRepository::label($type)) ?></td>
            <td><input name="doc_ref[<?= $tid ?>]" value="<?= e(is_string($oldRefs[$tid] ?? null) ? $oldRefs[$tid] : '') ?>" maxlength="80" placeholder="<?= e(t('shipments.docs.reference_placeholder')) ?>" aria-label="<?= e(t('shipments.docs.reference') . ' — ' . \App\Repositories\DocTypeRepository::label($type)) ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <aside class="offer-layout__doc" data-doc-picker>
      <h3 class="rfq-step"><?= icon('paperclip') ?><?= e(t('shipments.files')) ?></h3>
      <div class="dropzone dropzone--compact<?= invalid('shipment_files') !== '' ? ' is-invalid' : '' ?>" data-dropzone>
        <input type="file" name="shipment_files[]" multiple class="dropzone__input" aria-label="<?= e(t('shipments.files')) ?>"
               accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx,.xls,.docx,.doc,.msg,.eml,.txt,.csv"<?= invalid('shipment_files') ?>>
        <div class="dropzone__body" aria-hidden="true">
          <?= icon('upload-cloud') ?>
          <strong><?= e(t('shipments.files_drop')) ?></strong>
          <span><?= e(t('rfqs.drop_browse')) ?></span>
        </div>
      </div>
      <p class="hint"><?= e(t('shipments.files_hint')) ?></p>
      <?= field_error('shipment_files') ?>
      <ul class="doc-chips" data-doc-chips></ul>
      <div class="doc-viewer doc-viewer--modal" data-doc-viewer>
        <div class="doc-viewer__empty"><?= icon('file-search') ?><p><?= e(t('shipments.viewer_empty')) ?></p></div>
      </div>
    </aside>
  </div>
  <div class="panel-modal__foot">
    <span class="muted" data-ship-count data-text="<?= e(t('shipments.selected_n')) ?>"></span>
    <?php if ($modal): ?>
      <button type="button" class="btn" data-close-dialog><?= e(t('common.cancel')) ?></button>
    <?php else: ?>
      <a class="btn" href="<?= e(url('/shipments')) ?>"><?= e(t('common.cancel')) ?></a>
    <?php endif; ?>
    <button type="submit" class="btn btn--primary"<?= $lines === [] ? ' disabled' : '' ?>><?= icon('truck') ?><span><?= e(t('shipments.save_new')) ?></span></button>
  </div>
</form>
