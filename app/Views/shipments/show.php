<?php

use App\Domain\Logistics\ShipmentStatus as S;
use App\Domain\Shared\Fx;
use App\Domain\Shared\Tone;

/**
 * Fiche expédition, guidée par l'étape suivante : bandeau « que faire maintenant », frise cliquable,
 * jalons / POD / annulation en fenêtre ; marchandises, documents, POD et coûts à gauche,
 * détails et journal à droite.
 *
 * @var array<string, mixed> $shipment
 * @var S $status
 * @var list<array<string, mixed>> $lines
 * @var list<array<string, mixed>> $events
 * @var array<string, mixed>|null $pod
 * @var list<array<string, mixed>> $files documents fournis
 * @var list<array<string, mixed>> $docTypes référentiel des documents
 * @var list<int> $requiredTypes documents exigés
 * @var bool $reopenDoc fenêtre d'ajout de document à rouvrir
 * @var string $reopenModal fenêtre à rouvrir après une erreur : milestone, deliver, pod ou vide
 * @var bool $openNote POD tout juste généré : aperçu ouvert au chargement pour impression
 * @var list<array<string, mixed>>|null $costs
 * @var ?int $brokenEventId
 */

$steps = [S::Planned, S::PickedUp, S::InTransit, S::AtBorder, S::Cleared, S::Delivered];
$reached = [];
foreach ($events as $ev) {
    if ($ev['status_to'] !== null) {
        $reached[$ev['status_to']] = $ev['occurred_at'];
    }
}
$today = date('Y-m-d');
$late = !$status->isFinal() && $shipment['eta'] !== null && $shipment['eta'] < $today;
$canManage = can('shipments.manage');
$active = $canManage && !$status->isFinal();
$milestones = $status->nextMilestones();
$next = $milestones[0] ?? null;
// Livraison en deux temps : déclaration (POD généré pour signature), puis POD signé joint.
$canDeliver = $active && $status->canReceivePod();
$podPending = $canManage && $status === S::Delivered && $pod === null;
$docsMissing = (int) $shipment['docs_missing'];

// Compte à rebours jusqu'à l'ETA (jours calendaires).
$etaText = '';
if (!$status->isFinal() && $shipment['eta'] !== null) {
    $days = (int) round((strtotime((string) $shipment['eta']) - strtotime($today)) / 86400);
    $etaText = $days > 0 ? t('shipments.next.eta_in', ['n' => $days]) : ($days === 0 ? t('shipments.next.eta_today') : t('shipments.next.eta_late', ['n' => -$days]));
}

// Actions de l'en-tête : étape suivante, livraison, POD signé, annulation.
$milestoneBtn = static fn (S $to, string $class): string => '<button type="button" class="' . $class . '" data-open-dialog="milestone-modal" data-milestone="' . e($to->value) . '">'
    . icon($to->icon()) . '<span>' . e(t('shipments.next.confirm', ['step' => t($to->labelKey())])) . '</span></button>';
$deliverBtn = static fn (string $class): string => '<button type="button" class="' . $class . '" data-open-dialog="deliver-modal">' . icon('circle-check-big') . '<span>' . e(t('shipments.deliver.button')) . '</span></button>';
$podBtn = static fn (string $class): string => '<button type="button" class="' . $class . '" data-open-dialog="pod-modal">' . icon('file-signature') . '<span>' . e(t('shipments.deliver.attach')) . '</span></button>';
// Bon de livraison (POD) : généré à la déclaration de livraison, à imprimer et faire signer.
$noteIssued = $shipment['delivery_note_number'] !== null;
$noteNumber = (string) ($shipment['delivery_note_number'] ?? \App\Controllers\ShipmentController::deliveryNoteNumber((string) $shipment['reference']));
$noteUrl = url('/shipments/' . $shipment['id'] . '/delivery-note');
$noteLink = static fn (string $class, string $label, bool $auto = false): string => '<a class="' . $class . '" href="' . e($noteUrl) . '"'
    . preview_attrs('document', t('delivery_note.title') . ' ' . $noteNumber, $shipment['reference'] . ' · ' . $shipment['client_name'])
    . ($auto ? ' data-auto-preview' : '') . '>' . icon('printer') . '<span>' . e($label) . '</span></a>';
$actions = '';
if ($podPending) {
    $actions .= $noteLink('btn', t('delivery_note.print'), $openNote) . $podBtn('btn btn--primary');
} elseif ($noteIssued) {
    $actions .= $noteLink('btn', t('delivery_note.button'));
}
if ($active) {
    // L'action principale suit l'étape : jalon suivant, puis déclaration de livraison une fois dédouanée.
    $deliverFirst = $status === S::Cleared;
    if ($next !== null && !$deliverFirst) {
        $actions .= $milestoneBtn($next, 'btn btn--primary');
    }
    if ($canDeliver) {
        $actions .= $deliverBtn($deliverFirst ? 'btn btn--primary' : 'btn');
    }
    if ($status->canBeCancelled()) {
        $actions .= '<button type="button" class="btn btn--ghost btn--danger-text" data-open-dialog="cancel-modal">' . icon('ban') . '<span>' . e(t('shipments.cancel_title')) . '</span></button>';
    }
}
?>
<?= partial('partials/page-header', [
    'icon' => 'truck',
    'title' => (string) $shipment['reference'],
    'meta' => badge($status->tone(), t($status->labelKey()))
        . ($podPending ? ' ' . badge(\App\Domain\Shared\Tone::Warn, t('shipments.deliver.pod_pending_badge')) : '')
        . ' · <a href="' . e(url('/clients/' . $shipment['client_id'])) . '">' . e($shipment['client_name']) . '</a>'
        . ($shipment['tracking_reference'] ? ' · <span class="client-ref">' . e($shipment['tracking_reference']) . '</span>' : ''),
    'actions' => $actions,
]) ?>
<?= doc_chain('shipment', (int) $shipment['id']) ?>

<?php if ($brokenEventId !== null): ?>
  <div class="alert alert--error"><?= icon('shield-x') ?><span><?= e(t('shipments.journal_broken', ['id' => $brokenEventId])) ?></span></div>
<?php endif; ?>

<?php if ($status !== S::Cancelled): ?>
<ol class="track" aria-label="<?= e(t('shipments.progress')) ?>">
  <?php foreach ($steps as $step):
      $done = isset($reached[$step->value]);
      $current = $step === $status;
      // Étapes à venir : cliquables (jalon ; la dernière déclare la livraison et génère le POD).
      $clickable = $active && !$done && ($step === S::Delivered ? $canDeliver : in_array($step, $milestones, true));
      $attrs = $step === S::Delivered ? ' data-open-dialog="deliver-modal"' : ' data-open-dialog="milestone-modal" data-milestone="' . e($step->value) . '"'; ?>
    <li class="track__step<?= $done ? ' is-done' : '' ?><?= $current ? ' is-current' : '' ?><?= $clickable ? ' is-clickable' : '' ?>"<?= $current ? ' aria-current="step"' : '' ?>>
      <?php if ($clickable): ?>
        <button type="button" class="track__icon"<?= $attrs ?> title="<?= e($step === S::Delivered ? t('shipments.deliver.button') : t('shipments.next.confirm', ['step' => t($step->labelKey())])) ?>"><?= icon($step->icon()) ?></button>
      <?php else: ?>
        <span class="track__icon"><?= icon($step->icon()) ?></span>
      <?php endif; ?>
      <span class="track__label"><?= e(t($step->labelKey())) ?></span>
      <span class="track__date"><?= $done ? e(format_datetime($reached[$step->value])) : ($step === S::Delivered && $shipment['eta'] !== null && !$status->isFinal() ? e(t('shipments.next.eta_short', ['date' => format_date($shipment['eta'])])) : '') ?></span>
      <?php if ($step === S::Delivered && $status === S::Delivered): ?>
        <span class="track__sub<?= $pod === null ? ' is-warn' : '' ?>"><?= e(t($pod === null ? 'shipments.deliver.pod_pending_badge' : 'shipments.deliver.pod_signed_badge')) ?></span>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
</ol>
<?php endif; ?>

<?php // Bandeau « étape suivante » ?>
<section class="next-step next-step--<?= $status === S::Cancelled ? 'neutral' : ($podPending ? 'warn' : ($status === S::Delivered ? 'ok' : ($late ? 'bad' : 'progress'))) ?>">
  <span class="next-step__icon"><?= icon($status === S::Cancelled ? 'ban' : ($podPending ? 'file-signature' : ($status === S::Delivered ? 'circle-check-big' : ($status === S::Cleared ? 'circle-check-big' : ($next?->icon() ?? 'truck'))))) ?></span>
  <div class="next-step__body">
    <?php if ($status === S::Cancelled): ?>
      <p class="next-step__title"><?= e(t('shipments.next.cancelled')) ?></p>
    <?php elseif ($podPending): ?>
      <p class="next-step__title"><?= e(t('shipments.deliver.pending_title', ['ref' => $noteNumber])) ?></p>
      <ol class="next-step__steps">
        <li class="is-done"><?= e(t('shipments.deliver.step_declared', ['date' => format_datetime($shipment['delivered_at'])])) ?></li>
        <li><?= e(t('shipments.deliver.step_sign')) ?></li>
        <li><?= e(t('shipments.deliver.step_attach')) ?></li>
      </ol>
    <?php elseif ($status === S::Delivered): ?>
      <p class="next-step__title"><?= e(t('shipments.delivered_on', ['date' => format_date($shipment['delivered_at'])])) ?></p>
      <p class="next-step__hint"><?= e(t($docsMissing > 0 ? 'shipments.next.delivered_docs' : 'shipments.next.delivered_ok', ['n' => $docsMissing])) ?></p>
    <?php else: $target = $status === S::Cleared ? 'deliver' : ($next?->value ?? 'deliver'); ?>
      <p class="next-step__title"><?= e(t('shipments.next.title', ['step' => $target === 'deliver' ? t('shipments.next.step_deliver') : t($next->labelKey())])) ?></p>
      <p class="next-step__hint"><?= e(t('shipments.next.hint.' . $target)) ?><?= $etaText !== '' ? ' <strong class="' . ($late ? 'overdue' : '') . '">' . e($etaText) . '</strong>' : '' ?></p>
      <?php if ($docsMissing > 0 && in_array($status, [S::InTransit, S::AtBorder, S::Cleared], true)): ?>
        <p class="next-step__warn"><?= icon('folder-x') ?><a href="#documents"><?= e(t('shipments.next.docs_missing', ['n' => $docsMissing])) ?></a></p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php if ($podPending): ?>
    <div class="next-step__actions"><?= $noteLink('btn', t('delivery_note.print')) ?><?= $podBtn('btn btn--primary') ?></div>
  <?php elseif ($active): ?>
    <div class="next-step__actions">
      <?php if ($status === S::Cleared || $next === null): ?>
        <?= $canDeliver ? $deliverBtn('btn btn--primary') : '' ?>
      <?php else: ?>
        <?= $milestoneBtn($next, 'btn btn--primary') ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<div class="ship-layout">
  <div class="ship-layout__main">
    <section class="card">
      <div class="card__title"><h2><?= icon('package', 'title-icon') ?><?= e(t('shipments.section_lines')) ?></h2><span class="muted"><?= e(t('shipments.lines_n', ['n' => count($lines)])) ?></span></div>
      <div class="table-scroll">
      <table class="table" data-dt="compact">
        <thead><tr>
          <th scope="col"><?= e(t('shipments.line.orders')) ?></th>
          <th scope="col"><?= e(t('rfqs.line.description')) ?></th>
          <th scope="col" class="num"><?= e(t('shipments.line.shipped')) ?></th>
          <th scope="col" class="num"><?= e(t('shipments.line.received')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($lines as $l): $short = $l['received_milli'] !== null && (int) $l['received_milli'] < (int) $l['quantity_milli']; ?>
          <tr>
            <td class="nowrap"><a href="<?= e(url('/supplier-orders/' . $l['supplier_order_id'])) ?>"><?= e($l['supplier_order_reference']) ?></a> <span class="muted"><?= e($l['supplier_name']) ?></span><br>
              <a href="<?= e(url('/client-orders/' . $l['client_order_id'])) ?>"><?= e($l['client_order_reference']) ?></a> <span class="muted"><?= e(t('client_orders.their_po', ['ref' => $l['client_po_number']])) ?></span></td>
            <td><?= e($l['description']) ?></td>
            <td class="num nowrap"><?= e(format_qty((int) $l['quantity_milli'])) ?> <span class="muted"><?= e(unit_label($l['unit'])) ?></span></td>
            <td class="num nowrap<?= $short ? ' warn-text' : '' ?>"><?= $l['received_milli'] === null ? '<span class="muted">—</span>' : e(format_qty((int) $l['received_milli'])) . ($short ? '<br><span class="chip chip--warn">' . e(t('shipments.shortage')) . '</span>' : '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </section>

    <?= partial('shipments/_documents', ['shipment' => $shipment, 'files' => $files, 'docTypes' => $docTypes, 'requiredTypes' => $requiredTypes, 'canManage' => $canManage, 'reopenDoc' => $reopenDoc]) ?>

    <?php if ($pod !== null): ?>
    <section class="card section-gap" id="pod">
      <div class="card__title"><h2><?= icon('file-check', 'title-icon') ?><?= e(t('shipments.pod_title')) ?></h2><?= badge(Tone::Ok, t('shipments.pod_sealed')) ?>
        <div class="card__actions"><?= $noteLink('btn btn--sm', t('delivery_note.button_blank')) ?></div></div>
      <?php
        $podKind = preview_kind((string) $pod['mime']);
        $podPreview = $podKind === null ? '' : preview_attrs(
            $podKind,
            t('preview.title_pod', ['ref' => $shipment['reference']]),
            $pod['original_name'] . ' · ' . format_bytes((int) $pod['size_bytes']) . ' · ' . t('preview.meta_pod', ['name' => $pod['receiver_name'], 'date' => format_datetime($pod['received_at'])]),
            url('/files/' . $pod['file_id'], ['download' => 1]),
        ); ?>
      <div class="pod">
        <?php if ($podKind === 'image'): ?>
          <a href="<?= e(url('/files/' . $pod['file_id'])) ?>" class="pod__thumb"<?= $podPreview ?> aria-label="<?= e(t('preview.open_pod')) ?>"><img src="<?= e(url('/files/' . $pod['file_id'])) ?>" alt="<?= e(t('shipments.pod_image_alt')) ?>"><span class="pod__zoom"><?= icon('eye') ?></span></a>
        <?php elseif ($podKind === 'pdf'): ?>
          <a href="<?= e(url('/files/' . $pod['file_id'])) ?>" class="pod__thumb pod__thumb--pdf"<?= $podPreview ?>><?= icon('file-text') ?><span><?= e(t('preview.open_pod')) ?></span></a>
        <?php endif; ?>
        <dl class="details">
          <div><dt><?= e(t('shipments.received_at')) ?></dt><dd><?= e(format_datetime($pod['received_at'])) ?></dd></div>
          <div><dt><?= e(t('shipments.receiver_name')) ?></dt><dd><?= e($pod['receiver_name']) ?><?= $pod['receiver_reference'] ? ' · ' . e($pod['receiver_reference']) : '' ?></dd></div>
          <div><dt><?= e(t('shipments.pod_file')) ?></dt><dd><a href="<?= e(url('/files/' . $pod['file_id'])) ?>"<?= $podPreview ?>><?= icon('paperclip') ?> <?= e($pod['original_name']) ?></a> <span class="muted"><?= e(format_bytes((int) $pod['size_bytes'])) ?></span>
            · <a href="<?= e(url('/files/' . $pod['file_id'], ['download' => 1])) ?>"><?= e(t('preview.download')) ?></a><br><span class="muted">SHA-256 <?= e(substr((string) $pod['sha256'], 0, 16)) ?>…</span></dd></div>
          <div><dt><?= e(t('common.by')) ?></dt><dd><?= e($pod['created_by_name'] ?? '—') ?></dd></div>
        </dl>
      </div>
      <?php if ($pod['notes']): ?><p class="note"><?= nl2br(e($pod['notes'])) ?></p><?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($costs !== null): ?>
    <section class="card section-gap" id="costs">
      <div class="card__title"><h2><?= icon('receipt', 'title-icon') ?><?= e(t('logistics.shipment_title')) ?></h2>
        <?php if ($status !== S::Cancelled && can('supplier_invoices.manage')): ?>
          <a class="btn btn--sm" href="<?= e(url('/supplier-invoices/logistics/new', ['shipment_id' => $shipment['id']])) ?>"><?= icon('plus') ?><span><?= e(t('logistics.record')) ?></span></a>
        <?php endif; ?>
      </div>
      <?php if ($costs === []): ?>
        <p class="muted"><?= e(t('logistics.none_for_shipment')) ?></p>
      <?php else: ?>
      <div class="table-scroll">
      <table class="table" data-dt="compact">
        <thead><tr>
          <th scope="col"><?= e(t('supplier_invoices.col.reference')) ?></th>
          <th scope="col"><?= e(t('logistics.supplier')) ?></th>
          <th scope="col"><?= e(t('logistics.col.type')) ?></th>
          <th scope="col" class="num"><?= e(t('logistics.col.amount')) ?></th>
          <th scope="col" class="num"><?= e(t('supplier_invoices.usd_value')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($costs as $c): ?>
          <tr class="row-link" tabindex="0" data-href="<?= e(url('/supplier-invoices/' . $c['invoice_id'])) ?>">
            <td class="nowrap"><strong><?= e($c['reference']) ?></strong><br><span class="muted"><?= e(t('supplier_invoices.their_number', ['ref' => $c['supplier_invoice_number']])) ?></span></td>
            <td><?= e($c['supplier_name']) ?></td>
            <td><?= e(t('cost_type.' . $c['cost_type'])) ?><?php if ($c['description']): ?><br><span class="muted"><?= e($c['description']) ?></span><?php endif; ?></td>
            <td class="num nowrap"><?= e(format_money((int) $c['amount_minor'], $c['currency_code'])) ?></td>
            <td class="num nowrap"><?= e(format_money(Fx::toUsd((int) $c['amount_minor'], (int) $c['fx_units_per_usd_micro']), 'USD')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  </div>

  <aside class="ship-layout__side">
    <section class="card">
      <div class="card__title"><h2><?= icon('info', 'title-icon') ?><?= e(t('shipments.details')) ?></h2></div>
      <dl class="ship-facts">
        <div><dt><?= icon('building') ?><?= e(t('shipments.col.carrier')) ?></dt><dd><?= e($shipment['carrier_name'] ?: '—') ?><span class="muted"><?= e(t('shipments.carrier_type.' . $shipment['carrier_type'])) ?></span></dd></div>
        <div><dt><?= icon('scan-barcode') ?><?= e(t('shipments.tracking_reference')) ?></dt><dd><?= $shipment['tracking_reference'] ? '<strong>' . e($shipment['tracking_reference']) . '</strong>' : '<span class="muted">—</span>' ?></dd></div>
        <div><dt><?= icon('map-pin') ?><?= e(t('shipments.origin')) ?></dt><dd><?= e($shipment['origin'] ?: '—') ?></dd></div>
        <div><dt><?= icon('flag') ?><?= e(t('shipments.destination')) ?></dt><dd><?= e($shipment['destination'] ?: '—') ?></dd></div>
        <div><dt><?= icon('calendar') ?><?= e(t('shipments.planned_pickup_on')) ?></dt><dd><?= e(format_date($shipment['planned_pickup_on'])) ?: '—' ?></dd></div>
        <div class="<?= $late ? 'is-late' : '' ?>"><dt><?= icon('calendar-clock') ?><?= e(t('shipments.eta')) ?></dt><dd><?= e(format_date($shipment['eta'])) ?: '—' ?><?= $etaText !== '' ? '<span class="' . ($late ? 'overdue' : 'muted') . '">' . e($etaText) . '</span>' : '' ?></dd></div>
        <?php if ($shipment['delivered_at']): ?><div><dt><?= icon('circle-check-big') ?><?= e(t('detail.delivered')) ?></dt><dd><?= e(format_datetime($shipment['delivered_at'])) ?></dd></div><?php endif; ?>
      </dl>
      <?php if ($shipment['notes']): ?><p class="note"><?= nl2br(e($shipment['notes'])) ?></p><?php endif; ?>
    </section>

    <section class="card section-gap">
      <div class="card__title"><h2><?= icon('route', 'title-icon') ?><?= e(t('shipments.journal')) ?></h2>
        <?= $brokenEventId === null ? badge(Tone::Ok, t('shipments.journal_ok')) : badge(Tone::Critical, t('shipments.journal_altered')) ?></div>
      <ol class="timeline">
        <?php foreach (array_reverse($events) as $ev): ?>
          <li>
            <p class="timeline__head"><strong><?= e($ev['type'] === 'pod' ? t('shipments.event.pod_signed') : ($ev['type'] === 'delivered' ? t('shipments.event.delivered') : ($ev['status_to'] ? t('shipment_status.' . $ev['status_to']) : t('shipments.event.' . $ev['type'])))) ?></strong>
              <?= $ev['location'] ? ' · ' . e($ev['location']) : '' ?></p>
            <p class="timeline__meta"><?= e(format_datetime($ev['occurred_at'])) ?> · <?= e($ev['user_name'] ?? t('history.system')) ?>
              <?php if (substr((string) $ev['recorded_at'], 0, 16) !== substr((string) $ev['occurred_at'], 0, 16) && $ev['type'] !== 'created'): ?> · <?= e(t('shipments.recorded_at', ['date' => format_datetime($ev['recorded_at'])])) ?><?php endif; ?></p>
            <?php if ($ev['note']): ?><p class="timeline__meta"><?= e($ev['note']) ?></p><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    </section>
  </aside>
</div>

<?php if ($active && $milestones !== []): ?>
<dialog class="panel-modal panel-modal--narrow" id="milestone-modal" aria-labelledby="milestone-title"<?= $reopenModal === 'milestone' ? ' data-auto-open' : '' ?>>
  <div class="panel-modal__head">
    <h2 id="milestone-title" class="panel-modal__title"><?= e(t('shipments.add_milestone') . ' — ' . $shipment['reference']) ?></h2>
    <button type="button" class="icon-btn" data-close-dialog aria-label="<?= e(t('common.close')) ?>"><?= icon('x') ?></button>
  </div>
  <form method="post" action="<?= e(url('/shipments/' . $shipment['id'] . '/milestones')) ?>" class="form">
    <?= csrf_field() ?>
    <div class="panel-modal__body">
      <fieldset class="milestone-pick">
        <legend><?= e(t('shipments.milestone')) ?></legend>
        <?php $oldStatus = old('status', $next?->value ?? ''); foreach ($milestones as $m): ?>
          <label class="milestone-pick__option"><input type="radio" name="status" value="<?= e($m->value) ?>" required<?= $oldStatus === $m->value ? ' checked' : '' ?>>
            <span><?= icon($m->icon()) ?><?= e(t($m->labelKey())) ?></span></label>
        <?php endforeach; ?>
        <?= field_error('status') ?>
      </fieldset>
      <div class="form-grid">
        <?= form_input('occurred_at', t('shipments.occurred_at'), date('Y-m-d\TH:i'), ['type' => 'datetime-local', 'required' => true]) ?>
        <?= form_input('location', t('shipments.location'), '', ['maxlength' => 160, 'placeholder' => t('shipments.location_placeholder')]) ?>
        <?= form_input('note', t('shipments.note'), '', ['maxlength' => 500, 'span' => true]) ?>
      </div>
    </div>
    <div class="panel-modal__foot">
      <button type="button" class="btn" data-close-dialog><?= e(t('common.cancel')) ?></button>
      <button type="submit" class="btn btn--primary"><?= icon('map-pinned') ?><span><?= e(t('shipments.add_milestone')) ?></span></button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($canDeliver): ?>
<dialog class="panel-modal panel-modal--narrow" id="deliver-modal" aria-labelledby="deliver-title"<?= $reopenModal === 'deliver' ? ' data-auto-open' : '' ?>>
  <div class="panel-modal__head">
    <h2 id="deliver-title" class="panel-modal__title"><?= e(t('shipments.deliver.button') . ' — ' . $shipment['reference']) ?></h2>
    <button type="button" class="icon-btn" data-close-dialog aria-label="<?= e(t('common.close')) ?>"><?= icon('x') ?></button>
  </div>
  <form method="post" action="<?= e(url('/shipments/' . $shipment['id'] . '/deliver')) ?>" class="form">
    <?= csrf_field() ?>
    <div class="panel-modal__body">
      <div class="pod-howto">
        <ol>
          <li><strong><?= e(t('shipments.deliver.howto_declare')) ?></strong></li>
          <li><?= e(t('shipments.deliver.howto_generated', ['ref' => $noteNumber])) ?></li>
          <li><?= e(t('shipments.deliver.howto_then')) ?></li>
        </ol>
      </div>
      <div class="form-grid section-gap">
        <?= form_input('delivered_at', t('shipments.deliver.delivered_at'), date('Y-m-d\TH:i'), ['type' => 'datetime-local', 'required' => true]) ?>
        <?= form_input('location', t('shipments.location'), (string) ($shipment['destination'] ?? ''), ['maxlength' => 160]) ?>
        <?= form_input('note', t('shipments.note'), '', ['maxlength' => 500, 'span' => true]) ?>
      </div>
    </div>
    <div class="panel-modal__foot">
      <button type="button" class="btn" data-close-dialog><?= e(t('common.cancel')) ?></button>
      <button type="submit" class="btn btn--primary"><?= icon('file-output') ?><span><?= e(t('shipments.deliver.submit')) ?></span></button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($podPending): ?>
<dialog class="panel-modal panel-modal--pod" id="pod-modal" aria-labelledby="pod-title"<?= $reopenModal === 'pod' ? ' data-auto-open' : '' ?>>
  <div class="panel-modal__head">
    <h2 id="pod-title" class="panel-modal__title"><?= e(t('shipments.deliver.attach') . ' — ' . $noteNumber) ?></h2>
    <button type="button" class="icon-btn" data-close-dialog aria-label="<?= e(t('common.close')) ?>"><?= icon('x') ?></button>
  </div>
  <form method="post" action="<?= e(url('/shipments/' . $shipment['id'] . '/pod')) ?>" class="offer-form form" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <?php
    // Saisie simple : le fichier signé et le réceptionnaire ; les quantités ne s'ouvrent qu'en cas d'écart,
    // la date, le n° de bon de réception et les réserves restent repliés.
    $oldReceived = old_rows('received');
    $allReceived = $oldReceived === null || array_reduce($lines, static fn (bool $ok, array $l): bool => $ok && ($oldReceived[(int) $l['id']]['quantity'] ?? '') === input_qty((int) $l['quantity_milli']), true);
    $moreOpen = old('receiver_reference') !== '' || old('notes') !== '';
    ?>
    <div class="pod-simple">
      <div class="pod-simple__form">
        <p class="pod-simple__intro"><?= e(t('shipments.pod_simple.intro', ['ref' => $noteNumber, 'date' => format_datetime($shipment['delivered_at'])])) ?> <?= $noteLink('link-btn', t('delivery_note.print')) ?></p>

        <div class="pod-simple__step">
          <span class="rfq-step__no">1</span>
          <div class="pod-simple__body" data-doc-picker>
            <p class="pod-simple__label"><?= e(t('shipments.pod_simple.file')) ?> <span class="req" aria-hidden="true">*</span></p>
            <div class="dropzone<?= invalid('pod_file') !== '' ? ' is-invalid' : '' ?>" data-dropzone>
              <input type="file" id="f-pod_file" name="pod_file" class="dropzone__input" required aria-label="<?= e(t('shipments.pod_simple.file')) ?>"
                     accept="image/jpeg,image/png,image/webp,application/pdf"<?= invalid('pod_file') ?>>
              <div class="dropzone__body" aria-hidden="true">
                <?= icon('camera') ?>
                <strong><?= e(t('shipments.pod_simple.drop')) ?></strong>
                <span><?= e(t('shipments.pod_simple.drop_hint')) ?></span>
              </div>
            </div>
            <?= field_error('pod_file') ?>
            <ul class="doc-chips" data-doc-chips></ul>
            <div class="doc-viewer doc-viewer--pod" data-doc-viewer>
              <div class="doc-viewer__empty"><?= icon('file-search') ?><p><?= e(t('shipments.pod_viewer_empty')) ?></p></div>
            </div>
          </div>
        </div>

        <div class="pod-simple__step">
          <span class="rfq-step__no">2</span>
          <div class="pod-simple__body">
            <?= form_input('receiver_name', t('shipments.pod_simple.receiver'), '', ['required' => true, 'maxlength' => 120, 'placeholder' => t('shipments.pod_simple.receiver_placeholder')]) ?>
          </div>
        </div>

        <div class="pod-simple__step">
          <span class="rfq-step__no">3</span>
          <div class="pod-simple__body" data-pod-qty>
            <label class="check pod-simple__all"><input type="checkbox" data-pod-all<?= $allReceived ? ' checked' : '' ?>>
              <span><strong><?= e(t('shipments.pod_simple.all_received')) ?></strong><br><span class="muted"><?= e(t('shipments.pod_simple.all_received_hint', ['n' => count($lines)])) ?></span></span></label>
            <?= field_error('received') ?>
            <div class="pod-simple__qty" data-pod-table<?= $allReceived ? ' hidden' : '' ?>>
              <table class="table lines-table offer-lines" data-no-dt>
                <thead><tr><th scope="col"><?= e(t('rfqs.line.description')) ?></th><th scope="col" class="num"><?= e(t('shipments.line.shipped')) ?></th><th scope="col" class="w-qty"><?= e(t('shipments.line.received')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($lines as $l): $full = input_qty((int) $l['quantity_milli']); ?>
                  <tr>
                    <td><?= e($l['description']) ?></td>
                    <td class="num nowrap muted"><?= e(format_qty((int) $l['quantity_milli'])) ?> <?= e(unit_label($l['unit'])) ?></td>
                    <td><input name="received[<?= (int) $l['id'] ?>][quantity]" value="<?= e($oldReceived[(int) $l['id']]['quantity'] ?? $full) ?>" data-full="<?= e($full) ?>" inputmode="decimal" class="num" aria-label="<?= e(t('shipments.line.received') . ' — ' . $l['description']) ?>"></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
              <p class="hint"><?= e(t('shipments.pod_quantities_hint')) ?></p>
            </div>
          </div>
        </div>

        <details class="pod-simple__more"<?= $moreOpen ? ' open' : '' ?>>
          <summary><?= icon('chevron-right') ?><?= e(t('shipments.pod_simple.more')) ?></summary>
          <div class="form-grid">
            <?= form_input('received_at', t('shipments.received_at'), str_replace(' ', 'T', substr((string) $shipment['delivered_at'], 0, 16)), ['type' => 'datetime-local', 'required' => true]) ?>
            <?= form_input('receiver_reference', t('shipments.receiver_reference'), '', ['maxlength' => 80, 'hint' => t('shipments.receiver_reference_hint')]) ?>
            <?= form_textarea('notes', t('shipments.pod_notes'), '', ['maxlength' => 2000, 'rows' => 2, 'span' => true]) ?>
          </div>
        </details>
      </div>
    </div>
    <div class="panel-modal__foot">
      <span class="muted"><?= e(t('shipments.pod_simple.foot')) ?></span>
      <button type="button" class="btn" data-close-dialog><?= e(t('common.cancel')) ?></button>
      <button type="submit" class="btn btn--primary"><?= icon('file-check') ?><span><?= e(t('shipments.deliver.attach')) ?></span></button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($active && $status->canBeCancelled()): ?>
<dialog class="panel-modal panel-modal--narrow" id="cancel-modal" aria-labelledby="cancel-title">
  <div class="panel-modal__head">
    <h2 id="cancel-title" class="panel-modal__title"><?= e(t('shipments.cancel_title') . ' — ' . $shipment['reference']) ?></h2>
    <button type="button" class="icon-btn" data-close-dialog aria-label="<?= e(t('common.close')) ?>"><?= icon('x') ?></button>
  </div>
  <form method="post" action="<?= e(url('/shipments/' . $shipment['id'] . '/cancel')) ?>" class="form">
    <?= csrf_field() ?>
    <div class="panel-modal__body">
      <div class="alert alert--warn"><?= icon('triangle-alert') ?><span><?= e(t('shipments.confirm_cancel')) ?></span></div>
      <div class="field"><label for="cancel-reason"><?= e(t('rfqs.close_reason')) ?></label><input id="cancel-reason" name="reason" maxlength="255"></div>
    </div>
    <div class="panel-modal__foot">
      <button type="button" class="btn" data-close-dialog><?= e(t('common.cancel')) ?></button>
      <button type="submit" class="btn btn--danger"><?= icon('ban') ?><span><?= e(t('shipments.cancel_title')) ?></span></button>
    </div>
  </form>
</dialog>
<?php endif; ?>
