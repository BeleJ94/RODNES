<?php

use App\Domain\Shared\Tone;
use App\Repositories\DocTypeRepository;

/**
 * Dossier documentaire de l'expédition : documents exigés (fournis / manquants), documents fournis avec
 * référence, date et aperçu du fichier ; ajout d'un document et choix des documents exigés en fenêtre.
 *
 * @var array<string, mixed> $shipment
 * @var list<array<string, mixed>> $files documents fournis (référence, date, fichier)
 * @var list<array<string, mixed>> $docTypes référentiel (actifs et inactifs)
 * @var list<int> $requiredTypes types exigés pour l'expédition
 * @var bool $canManage
 * @var bool $reopenDoc fenêtre d'ajout à rouvrir (erreur de saisie)
 */

$byType = [];
$others = [];
foreach ($files as $doc) {
    if ($doc['doc_type_id'] !== null) {
        $byType[(int) $doc['doc_type_id']][] = $doc;
    } else {
        $others[] = $doc;
    }
}
// Exigés d'abord, puis les autres types actifs ou déjà fournis.
$rows = [];
foreach ($docTypes as $type) {
    $tid = (int) $type['id'];
    $required = in_array($tid, $requiredTypes, true);
    if ($required || isset($byType[$tid]) || (int) $type['is_active'] === 1) {
        $rows[] = ['type' => $type, 'required' => $required, 'docs' => $byType[$tid] ?? []];
    }
}
usort($rows, static fn (array $a, array $b): int => [!$a['required'], (int) $a['type']['sort_order']] <=> [!$b['required'], (int) $b['type']['sort_order']]);
$requiredCount = count($requiredTypes);
$provided = count(array_filter($rows, static fn (array $r): bool => $r['required'] && $r['docs'] !== []));
$complete = $provided === $requiredCount;
$fileLink = static function (array $doc, array $shipment): string {
    if ($doc['file_id'] === null) {
        return '';
    }
    $kind = preview_kind((string) $doc['mime']);
    return '<a class="doc-file" href="' . e(url('/files/' . $doc['file_id'])) . '"'
        . ($kind !== null ? preview_attrs($kind, (string) $doc['original_name'], $shipment['reference'] . ' · ' . $shipment['client_name'], url('/files/' . $doc['file_id'], ['download' => 1])) : ' download')
        . '>' . icon($kind === 'image' ? 'image' : ($kind === 'pdf' ? 'file-text' : 'file')) . '<span>' . e($doc['original_name']) . '</span></a>';
};
?>
<section class="card section-gap" id="documents">
  <div class="card__title">
    <h2><?= icon('folder-check', 'title-icon') ?><?= e(t('shipments.docs.title')) ?></h2>
    <?= $requiredCount === 0 ? '<span class="muted">' . e(t('shipments.docs.none_required')) . '</span>'
        : badge($complete ? Tone::Ok : Tone::Warn, t('shipments.docs.progress', ['n' => $provided, 'total' => $requiredCount])) ?>
    <?php if ($canManage): ?>
      <div class="card__actions">
        <button type="button" class="btn btn--sm" data-open-dialog="doc-required-modal"><?= icon('list-checks') ?><span><?= e(t('shipments.docs.edit_required')) ?></span></button>
        <button type="button" class="btn btn--sm btn--primary" data-open-dialog="doc-add-modal"><?= icon('plus') ?><span><?= e(t('shipments.docs.add')) ?></span></button>
      </div>
    <?php endif; ?>
  </div>
  <?php if (!$complete): ?>
    <div class="alert alert--warn"><?= icon('folder-x') ?><span><?= e(t('shipments.docs.missing_alert', ['n' => $requiredCount - $provided])) ?></span></div>
  <?php endif; ?>
  <?php
  // Facultatifs non fournis : regroupés sur une ligne (proposés à l'ajout), pour garder le dossier lisible.
  $optional = array_values(array_filter($rows, static fn (array $r): bool => !$r['required'] && $r['docs'] === []));
  $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['required'] || $r['docs'] !== []));
  ?>
  <ul class="doc-list">
    <?php if ($rows === [] && $others === []): ?>
      <li class="doc-list__item"><p class="muted"><?= e(t('shipments.docs.empty')) ?></p></li>
    <?php endif; ?>
    <?php foreach ($rows as $row): $type = $row['type']; ?>
      <li class="doc-list__item<?= $row['docs'] !== [] ? ' is-provided' : ($row['required'] ? ' is-missing' : '') ?>">
        <span class="doc-list__state" aria-hidden="true"><?= icon($row['docs'] !== [] ? 'circle-check' : ($row['required'] ? 'circle-alert' : 'circle-dashed')) ?></span>
        <div class="doc-list__main">
          <p class="doc-list__name"><?= e(DocTypeRepository::label($type)) ?>
            <?= $row['required'] ? '<span class="doc-list__tag">' . e(t('doctypes.mandatory')) . '</span>' : '' ?></p>
          <?php if ($row['docs'] === []): ?>
            <p class="muted doc-list__status"><?= e(t($row['required'] ? 'shipments.docs.missing' : 'shipments.docs.not_provided')) ?></p>
          <?php else: foreach ($row['docs'] as $doc): ?>
            <div class="doc-list__doc">
              <?php if ($doc['reference']): ?><strong><?= e($doc['reference']) ?></strong><?php endif; ?>
              <?php if ($doc['document_date']): ?><span class="muted"><?= e(format_date($doc['document_date'])) ?></span><?php endif; ?>
              <?= $fileLink($doc, $shipment) ?>
              <span class="muted doc-list__by"><?= e(format_datetime($doc['created_at'])) ?><?= $doc['created_by_name'] ? ' · ' . e($doc['created_by_name']) : '' ?></span>
              <?php if ($canManage): ?>
                <form method="post" action="<?= e(url('/shipments/' . $shipment['id'] . '/documents/' . $doc['id'] . '/delete')) ?>" class="inline-form" data-confirm="<?= e(t('shipments.docs.confirm_remove')) ?>">
                  <?= csrf_field() ?>
                  <button type="submit" class="icon-btn" title="<?= e(t('common.delete')) ?>" aria-label="<?= e(t('common.delete')) ?>"><?= icon('trash-2') ?></button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
    <?php if ($others !== []): ?>
      <li class="doc-list__item is-provided">
        <span class="doc-list__state" aria-hidden="true"><?= icon('paperclip') ?></span>
        <div class="doc-list__main">
          <p class="doc-list__name"><?= e(t('shipments.docs.other')) ?></p>
          <?php foreach ($others as $doc): ?>
            <div class="doc-list__doc"><?= $fileLink($doc, $shipment) ?><span class="muted doc-list__by"><?= e(format_datetime($doc['created_at'])) ?><?= $doc['created_by_name'] ? ' · ' . e($doc['created_by_name']) : '' ?></span></div>
          <?php endforeach; ?>
        </div>
      </li>
    <?php endif; ?>
    <?php if ($optional !== []): ?>
      <li class="doc-list__item doc-list__optional">
        <span class="doc-list__state" aria-hidden="true"><?= icon('circle-dashed') ?></span>
        <p class="muted"><?= e(t('shipments.docs.optional_list', ['list' => implode(', ', array_map(static fn (array $r): string => DocTypeRepository::label($r['type']), $optional))])) ?></p>
      </li>
    <?php endif; ?>
  </ul>
</section>

<?php if ($canManage): ?>
<dialog class="panel-modal" id="doc-add-modal" aria-labelledby="doc-add-title"<?= $reopenDoc ? ' data-auto-open' : '' ?>>
  <div class="panel-modal__head">
    <h2 id="doc-add-title" class="panel-modal__title"><?= e(t('shipments.docs.add') . ' — ' . $shipment['reference']) ?></h2>
    <button type="button" class="icon-btn" data-close-dialog aria-label="<?= e(t('common.close')) ?>"><?= icon('x') ?></button>
  </div>
  <form method="post" action="<?= e(url('/shipments/' . $shipment['id'] . '/documents')) ?>" class="offer-form form" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="offer-layout">
      <div class="offer-layout__main">
        <?php $choices = [];
        foreach (array_merge($rows, $optional) as $row) {
            if ((int) $row['type']['is_active'] === 1) {
                $choices[(string) $row['type']['id']] = DocTypeRepository::label($row['type']) . ($row['required'] && $row['docs'] === [] ? ' — ' . t('shipments.docs.missing') : '');
            }
        }
        $firstMissing = array_values(array_filter($rows, static fn (array $r): bool => $r['required'] && $r['docs'] === []))[0]['type']['id'] ?? ''; ?>
        <div class="form-grid">
          <?= form_select('doc_type_id', t('shipments.docs.type'), $choices, (string) $firstMissing, ['required' => true, 'span' => true]) ?>
          <?= form_input('reference', t('shipments.docs.reference'), '', ['maxlength' => 80, 'placeholder' => t('shipments.docs.reference_placeholder'), 'hint' => t('shipments.docs.reference_hint')]) ?>
          <?= form_input('document_date', t('shipments.docs.date'), '', ['type' => 'date']) ?>
        </div>
      </div>
      <aside class="offer-layout__doc" data-doc-picker>
        <h3 class="rfq-step"><?= icon('paperclip') ?><?= e(t('shipments.docs.file')) ?></h3>
        <div class="dropzone dropzone--compact<?= invalid('doc_file') !== '' ? ' is-invalid' : '' ?>" data-dropzone>
          <input type="file" name="doc_file" class="dropzone__input" aria-label="<?= e(t('shipments.docs.file')) ?>"
                 accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx,.xls,.docx,.doc,.msg,.eml,.txt,.csv">
          <div class="dropzone__body" aria-hidden="true">
            <?= icon('upload-cloud') ?>
            <strong><?= e(t('shipments.files_drop')) ?></strong>
            <span><?= e(t('rfqs.drop_browse')) ?></span>
          </div>
        </div>
        <ul class="doc-chips" data-doc-chips></ul>
        <div class="doc-viewer doc-viewer--modal" data-doc-viewer>
          <div class="doc-viewer__empty"><?= icon('file-search') ?><p><?= e(t('shipments.viewer_empty')) ?></p></div>
        </div>
      </aside>
    </div>
    <div class="panel-modal__foot">
      <span class="muted"><?= e(t('shipments.docs.add_hint')) ?></span>
      <button type="button" class="btn" data-close-dialog><?= e(t('common.cancel')) ?></button>
      <button type="submit" class="btn btn--primary"><?= icon('check') ?><span><?= e(t('shipments.docs.add')) ?></span></button>
    </div>
  </form>
</dialog>

<dialog class="panel-modal panel-modal--narrow" id="doc-required-modal" aria-labelledby="doc-required-title">
  <div class="panel-modal__head">
    <h2 id="doc-required-title" class="panel-modal__title"><?= e(t('shipments.docs.edit_required')) ?></h2>
    <button type="button" class="icon-btn" data-close-dialog aria-label="<?= e(t('common.close')) ?>"><?= icon('x') ?></button>
  </div>
  <form method="post" action="<?= e(url('/shipments/' . $shipment['id'] . '/documents/required')) ?>" class="form">
    <?= csrf_field() ?>
    <div class="panel-modal__body">
      <p class="hint"><?= e(t('shipments.docs.required_hint')) ?></p>
      <?php foreach ($docTypes as $type): if ((int) $type['is_active'] !== 1 && !in_array((int) $type['id'], $requiredTypes, true)) { continue; } ?>
        <label class="check doc-req-line"><input type="checkbox" name="required[<?= (int) $type['id'] ?>]" value="1"<?= in_array((int) $type['id'], $requiredTypes, true) ? ' checked' : '' ?>>
          <span><?= e(DocTypeRepository::label($type)) ?><?= $type['is_required'] ? ' <span class="muted">· ' . e(t('shipments.docs.default_required')) . '</span>' : '' ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="panel-modal__foot">
      <button type="button" class="btn" data-close-dialog><?= e(t('common.cancel')) ?></button>
      <button type="submit" class="btn btn--primary"><?= icon('check') ?><span><?= e(t('common.save')) ?></span></button>
    </div>
  </form>
</dialog>
<?php endif; ?>
