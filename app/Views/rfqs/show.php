<?php

use App\Domain\Sales\QuotationStatus;
use App\Domain\Sales\RfqStatus;
use App\Domain\Shared\Money;
use App\Domain\Sourcing\SupplierQuoteStatus;

/**
 * Fiche RFQ : référence client en tête, avancement (demande → prix fournisseurs → offres → devis → PO),
 * prochaine étape, puis le détail. Les demandes de prix se préparent dans une fenêtre (fournisseurs,
 * articles et quantités) et produisent un document « Demande de prix » par fournisseur.
 *
 * @var array<string, mixed> $rfq
 * @var RfqStatus $status
 * @var list<array<string, mixed>> $lines
 * @var list<array<string, mixed>> $requests
 * @var list<array<string, mixed>> $answered
 * @var array<int, array<int, array<string, mixed>>> $matrix rfq_line_id → supplier_quote_id → offre
 * @var array<int, int> $best rfq_line_id → id de ligne d'offre
 * @var list<array<string, mixed>> $availableSuppliers
 * @var list<array<string, mixed>> $quotations
 * @var bool $canQuote
 * @var list<array<string, mixed>> $history
 * @var list<array<string, mixed>> $files pièces jointes du client
 * @var list<array<string, mixed>> $clientOrders
 * @var array<int, int> $requestedCounts demande → nombre de lignes demandées
 * @var string $defaultResponseDue
 * @var array<string, mixed>|null $reopenOffer saisie d'offre à rouvrir dans la fenêtre (erreur de validation)
 */

$now = date('Y-m-d H:i:s');
$late = $status->isOpen() && $rfq['due_at'] !== null && $rfq['due_at'] < $now;
$canSource = can('sourcing.manage') && !$status->isClosed();
$activeQuote = null;
foreach ($quotations as $q) {
    if (!in_array($q['status'], ['rejected', 'superseded'], true)) {
        $activeQuote = $q;
        break;
    }
}
$pending = array_values(array_filter($requests, static fn (array $r): bool => $r['status'] === 'requested'));
$quoteSent = $activeQuote !== null && in_array($activeQuote['status'], ['sent', 'accepted'], true);

// Avancement : fait, en cours, à venir.
$steps = [
    ['inbox', t('rfqs.step.received'), format_date($rfq['received_on']), 'done'],
    ['send', t('rfqs.step.requests'), $requests === [] ? t('rfqs.step.none') : t('rfqs.step.requests_n', ['n' => count($requests)]), $requests !== [] ? 'done' : 'current'],
    ['inbox', t('rfqs.step.offers'), $answered === [] ? ($requests === [] ? '—' : t('rfqs.step.waiting')) : t('rfqs.step.offers_n', ['n' => count($answered), 'total' => count($requests)]), $answered !== [] ? 'done' : ($requests !== [] ? 'current' : 'todo')],
    ['file-text', t('rfqs.step.quotation'), $activeQuote ? $activeQuote['reference'] . ' v' . $activeQuote['version'] . ' · ' . t('quotation_status.' . $activeQuote['status']) : '—', $quoteSent ? 'done' : ($answered !== [] ? 'current' : 'todo')],
    ['file-check', t('rfqs.step.order'), $clientOrders !== [] ? (string) $clientOrders[0]['reference'] : '—', $clientOrders !== [] ? 'done' : ($quoteSent ? 'current' : 'todo')],
];
if ($status->isClosed() && $clientOrders === []) {
    $steps = array_map(static fn (array $s): array => $s[3] === 'done' ? $s : [$s[0], $s[1], $s[2], 'skipped'], $steps);
}

// Prochaine étape : une seule action mise en avant.
$next = null;
if ($status->isClosed()) {
    $next = ['archive', t('rfqs.next.closed', ['status' => t($status->labelKey())]), null];
} elseif ($requests === []) {
    $next = ['send', t('rfqs.next.request'), $canSource ? '<button type="button" class="btn btn--primary" data-open-dialog="sourcing-modal">' . icon('send') . '<span>' . e(t('sourcing.request')) . '</span></button>' : null];
} elseif ($answered === [] && $pending !== []) {
    $next = ['hourglass', t('rfqs.next.wait_offers', ['n' => count($pending)]), $canSource ? '<a class="btn btn--primary" href="' . e(url('/sourcing/' . $pending[0]['id'])) . '" data-remote-dialog="' . e(url('/sourcing/' . $pending[0]['id'], ['fragment' => 1])) . '">' . icon('clipboard-pen') . '<span>' . e(t('rfqs.next.enter_offer', ['supplier' => $pending[0]['supplier_name']])) . '</span></a>' : null];
} elseif ($canQuote && can('quotations.manage')) {
    $next = ['file-plus', t('rfqs.next.quote', ['n' => count($answered)]), '<form method="post" action="' . e(url('/rfqs/' . $rfq['id'] . '/quotations')) . '">' . csrf_field() . '<button type="submit" class="btn btn--primary">' . icon('file-plus') . '<span>' . e(t('quotations.create')) . '</span></button></form>'];
} elseif ($activeQuote !== null) {
    $next = ['file-text', t('rfqs.next.follow_quote', ['ref' => $activeQuote['reference'] . ' v' . $activeQuote['version'], 'status' => t('quotation_status.' . $activeQuote['status'])]), '<a class="btn btn--primary" href="' . e(url('/quotations/' . $activeQuote['id'])) . '">' . icon('arrow-right') . '<span>' . e(t('rfqs.next.open_quote')) . '</span></a>'];
}

$actions = '';
if ($status->isOpen() && can('rfqs.manage')) {
    $actions .= '<a class="btn" href="' . e(url('/rfqs/' . $rfq['id'] . '/edit')) . '">' . icon('pencil') . '<span>' . e(t('common.edit')) . '</span></a>';
}
?>
<?= partial('partials/page-header', [
    'icon' => 'inbox',
    'title' => (string) $rfq['reference'],
    'meta' => badge($status->tone(), t($status->labelKey())) . ' · <a href="' . e(url('/clients/' . $rfq['client_id'])) . '">' . e($rfq['client_name']) . '</a>',
    'actions' => $actions,
]) ?>
<?= doc_chain('rfq', (int) $rfq['id']) ?>

<section class="rfq-hero" aria-label="<?= e(t('rfqs.summary')) ?>">
  <div class="rfq-hero__ref">
    <span class="rfq-hero__label"><?= e(t('rfqs.client_reference')) ?></span>
    <?php if ($rfq['client_reference']): ?>
      <strong class="rfq-hero__value"><?= e($rfq['client_reference']) ?></strong>
    <?php else: ?>
      <span class="muted"><?= e(t('rfqs.no_client_reference')) ?></span>
    <?php endif; ?>
  </div>
  <div class="rfq-hero__item"><span class="rfq-hero__label"><?= icon('calendar') ?><?= e(t('rfqs.received_on')) ?></span><span><?= e(format_date($rfq['received_on'])) ?></span></div>
  <div class="rfq-hero__item<?= $late ? ' is-late' : '' ?>"><span class="rfq-hero__label"><?= icon('alarm-clock') ?><?= e(t('rfqs.due_at')) ?></span><span><?= $rfq['due_at'] ? e(format_datetime($rfq['due_at'])) : '—' ?><?php if ($late): ?> <span class="chip chip--bad"><?= e(t('rfqs.late')) ?></span><?php endif; ?></span></div>
  <div class="rfq-hero__item"><span class="rfq-hero__label"><?= icon('user-check') ?><?= e(t('rfqs.owner')) ?></span><span><?= e($rfq['owner_name'] ?? '—') ?></span></div>
</section>

<ol class="stepper" aria-label="<?= e(t('rfqs.progress')) ?>">
  <?php foreach ($steps as $i => [$stepIcon, $label, $detail, $state]): ?>
    <li class="stepper__step is-<?= e($state) ?>"<?= $state === 'current' ? ' aria-current="step"' : '' ?>>
      <span class="stepper__dot"><?= $state === 'done' ? icon('check') : (string) ($i + 1) ?></span>
      <span class="stepper__text"><strong><?= e($label) ?></strong><span><?= e($detail) ?></span></span>
    </li>
  <?php endforeach; ?>
</ol>

<?php if ($next !== null): ?>
  <div class="next-step<?= $status->isClosed() ? ' next-step--closed' : '' ?>">
    <?= icon($next[0], 'next-step__icon') ?>
    <div class="next-step__text"><span class="next-step__label"><?= e(t('rfqs.next.title')) ?></span><span><?= e($next[1]) ?></span></div>
    <?php if ($next[2] !== null): ?><div class="next-step__action"><?= $next[2] ?></div><?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($status->isClosed() && $rfq['closed_reason']): ?>
  <div class="alert alert--info"><?= icon('info') ?><span><?= e(t('rfqs.closed_reason', ['reason' => $rfq['closed_reason']])) ?></span></div>
<?php endif; ?>

<div class="rfq-show">
  <div class="rfq-show__main">
    <section class="card">
      <div class="card__title"><h2><?= icon('list', 'title-icon') ?><?= e(t('rfqs.section_lines')) ?></h2><span class="chip"><?= e(t('rfqs.lines_count_n', ['n' => count($lines)])) ?></span></div>
      <table class="table" data-dt="compact">
        <thead><tr>
          <th scope="col">#</th>
          <th scope="col"><?= e(t('rfqs.line.description')) ?></th>
          <th scope="col"><?= e(t('detail.part_number')) ?></th>
          <th scope="col" class="num" data-x="qty"><?= e(t('rfqs.line.quantity')) ?></th>
          <th scope="col" data-x="date"><?= e(t('rfqs.line.required_by')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($lines as $line): ?>
          <tr>
            <td class="muted"><?= (int) $line['line_no'] ?></td>
            <td><?= e($line['description']) ?><?php if ($line['item_code']): ?> <span class="sub"><?= e($line['item_code']) ?></span><?php endif; ?></td>
            <td class="muted"><?= e(trim(($line['manufacturer'] ?? '') . ' ' . ($line['part_number'] ?? ''))) ?: '—' ?></td>
            <td class="num nowrap" data-order="<?= (int) $line['quantity_milli'] ?>"><?= e(format_qty((int) $line['quantity_milli'])) ?> <span class="muted"><?= e(unit_label($line['unit'])) ?></span></td>
            <td class="nowrap" data-order="<?= e((string) $line['required_by']) ?>"><?= e(format_date($line['required_by'])) ?: '<span class="muted">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <section class="card section-gap" id="sourcing">
      <div class="card__title"><h2><?= icon('search', 'title-icon') ?><?= e(t('sourcing.title')) ?></h2>
        <?php if ($canSource && $availableSuppliers !== []): ?><button type="button" class="btn btn--sm<?= $requests === [] ? ' btn--primary' : '' ?>" data-open-dialog="sourcing-modal"><?= icon('send') ?><span><?= e(t('sourcing.request')) ?></span></button><?php endif; ?></div>
      <?php if ($requests === []): ?>
        <div class="empty-state"><?= icon('send') ?><p><?= e(t('sourcing.none')) ?></p></div>
      <?php else: ?>
        <table class="table" data-dt="compact">
          <thead><tr>
            <th scope="col"><?= e(t('sourcing.col.request')) ?></th>
            <th scope="col"><?= e(t('sourcing.supplier')) ?></th>
            <th scope="col"><?= e(t('common.status')) ?></th>
            <th scope="col" data-x="date"><?= e(t('sourcing.col.reply_by')) ?></th>
            <th scope="col" class="no-sort"><span class="visually-hidden"><?= e(t('common.actions')) ?></span></th>
          </tr></thead>
          <tbody>
          <?php foreach ($requests as $req): $sqStatus = SupplierQuoteStatus::from($req['status']); $overdue = $sqStatus === SupplierQuoteStatus::Requested && $req['response_due'] && $req['response_due'] < date('Y-m-d'); ?>
            <tr>
              <td class="nowrap"><strong><?= e($req['reference'] ?? '—') ?></strong> <span class="sub"><?= e(t('sourcing.lines_n', ['n' => $requestedCounts[(int) $req['id']] ?? count($lines)])) ?></span></td>
              <td><span class="clip"><?= e($req['supplier_name']) ?></span> <span class="tag"><?= e($req['currency_code']) ?></span></td>
              <td><?= badge($sqStatus->tone(), t($sqStatus->labelKey())) ?></td>
              <td class="<?= $overdue ? 'overdue' : '' ?>" data-order="<?= e((string) $req['response_due']) ?>"><?= $req['response_due'] ? e(format_date($req['response_due'])) : '<span class="muted">—</span>' ?></td>
              <td class="row-actions">
                <?php if ($req['reference']): ?><?= partial('partials/preview-button', [
                    'href' => url('/sourcing/' . $req['id'] . '/print'),
                    'kind' => 'document',
                    'title' => t('sourcing.doc_title') . ' ' . $req['reference'],
                    'meta' => (string) $req['supplier_name'],
                ]) ?><?php endif; ?>
                <?php if ($sqStatus === SupplierQuoteStatus::Received): ?>
                  <a class="btn btn--sm" href="<?= e(url('/sourcing/' . $req['id'] . '/view')) ?>" data-remote-dialog="<?= e(url('/sourcing/' . $req['id'] . '/view')) ?>"><?= icon('eye') ?><span><?= e(t('sourcing.view_offer')) ?></span></a>
                <?php endif; ?>
                <?php if ($canSource): ?>
                  <a class="btn btn--sm<?= $sqStatus === SupplierQuoteStatus::Received ? ' btn--ghost' : '' ?>" href="<?= e(url('/sourcing/' . $req['id'])) ?>" data-remote-dialog="<?= e(url('/sourcing/' . $req['id'], ['fragment' => 1])) ?>"><?= icon($sqStatus === SupplierQuoteStatus::Received ? 'pencil' : 'clipboard-pen') ?><span><?= e(t($sqStatus === SupplierQuoteStatus::Received ? 'sourcing.edit_offer' : 'sourcing.enter_offer')) ?></span></a>
                  <?php if ($sqStatus === SupplierQuoteStatus::Requested): ?>
                    <form method="post" action="<?= e(url('/sourcing/' . $req['id'] . '/decline')) ?>" data-confirm="<?= e(t('sourcing.confirm_decline')) ?>">
                      <?= csrf_field() ?>
                      <button type="submit" class="btn btn--sm btn--ghost"><?= icon('x') ?><span><?= e(t('sourcing.declined_action')) ?></span></button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <?= row_detail([
                  t('sourcing.requested_on') => e(format_date($req['requested_on'])),
                  t('sourcing.terms') => $sqStatus === SupplierQuoteStatus::Received ? e(trim($req['currency_code'] . ' ' . ($req['incoterm'] ?? '') . ($req['lead_time_days'] !== null ? ' · ' . t('sourcing.lead_time', ['n' => $req['lead_time_days']]) : ''))) : null,
                  t('sourcing.supplier_reference') => e((string) $req['supplier_reference']),
                  t('sourcing.received_on') => e(format_date($req['received_on'])),
                  t('sourcing.valid_until') => e(format_date($req['valid_until'])),
              ], $req['message'] ? '<p class="row-detail__note">' . e($req['message']) . '</p>' : '') ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <?php if ($canSource && $availableSuppliers === [] && $requests !== []): ?><p class="hint"><?= e(t('sourcing.all_requested')) ?></p><?php endif; ?>
    </section>

    <?php if ($answered !== []): ?>
    <section class="card section-gap" id="comparison">
      <div class="card__title"><h2><?= icon('scale', 'title-icon') ?><?= e(t('sourcing.comparison')) ?></h2><span class="hint"><?= e(t('sourcing.comparison_hint')) ?></span></div>
      <div class="table-scroll">
        <table class="table comparison" data-dt="compact">
          <thead><tr>
            <th scope="col"><?= e(t('rfqs.line.description')) ?></th>
            <?php foreach ($answered as $req): ?><th scope="col" class="num"><a href="<?= e(url('/sourcing/' . $req['id'] . '/view')) ?>" data-remote-dialog="<?= e(url('/sourcing/' . $req['id'] . '/view')) ?>"><?= e($req['supplier_name']) ?></a><br><span class="muted"><?= e($req['currency_code']) ?></span></th><?php endforeach; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($lines as $line): ?>
            <tr>
              <td><?= e($line['description']) ?><br><span class="muted"><?= e(format_qty((int) $line['quantity_milli'])) ?> <?= e(unit_label($line['unit'])) ?></span></td>
              <?php foreach ($answered as $req): $offer = $matrix[(int) $line['id']][(int) $req['id']] ?? null; ?>
                <?php if ($offer === null): ?>
                  <td class="num muted">—</td>
                <?php else: $isBest = ($best[(int) $line['id']] ?? null) === (int) $offer['id']; ?>
                  <td class="num<?= $isBest ? ' best' : '' ?>">
                    <?= e(format_money((int) $offer['unit_price_minor'], $offer['currency_code'])) ?>
                    <?php if ($offer['currency_code'] !== 'USD'): ?><br><span class="muted">≈ <?= e(format_money($offer['unit_usd_minor'], 'USD')) ?></span><?php endif; ?>
                    <br><span class="muted"><?= e(t('sourcing.line_total', ['amount' => format_money(Money::lineAmount((int) $line['quantity_milli'], $offer['unit_usd_minor']), 'USD')])) ?></span>
                    <?php if ($isBest): ?><br><span class="chip chip--ok"><?= e(t('sourcing.best')) ?></span><?php endif; ?>
                  </td>
                <?php endif; ?>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endif; ?>

    <section class="card section-gap" id="quotations">
      <div class="card__title"><h2><?= icon('file-text', 'title-icon') ?><?= e(t('quotations.title')) ?></h2></div>
      <?php if ($quotations === []): ?>
        <p class="muted"><?= e(t('quotations.none_for_rfq')) ?></p>
      <?php else: ?>
        <table class="table" data-dt="compact">
          <thead><tr>
            <th scope="col"><?= e(t('quotations.col.reference')) ?></th>
            <th scope="col"><?= e(t('common.status')) ?></th>
            <th scope="col" class="num" data-x="money"><?= e(t('quotations.col.amount')) ?></th>
            <th scope="col" class="num" data-x="percent"><?= e(t('quotations.col.margin')) ?></th>
            <th scope="col" data-x="date"><?= e(t('quotations.col.valid_until')) ?></th>
            <th scope="col" class="no-sort"><span class="visually-hidden"><?= e(t('common.actions')) ?></span></th>
          </tr></thead>
          <tbody>
          <?php foreach ($quotations as $q): $qs = QuotationStatus::from($q['status']); $margin = \App\Domain\Sales\Pricing::marginBp((int) $q['cost'], (int) $q['subtotal']); ?>
            <tr class="row-link" tabindex="0" data-href="<?= e(url('/quotations/' . $q['id'])) ?>">
              <td><strong><?= e($q['reference']) ?></strong> <span class="tag">v<?= (int) $q['version'] ?></span></td>
              <td><?= badge($qs->tone(), t($qs->labelKey())) ?></td>
              <td class="num" data-order="<?= (int) $q['subtotal'] ?>"><?= e(format_money((int) $q['subtotal'], 'USD')) ?></td>
              <td class="num" data-order="<?= $margin ?? '' ?>"><?= e(format_percent($margin)) ?></td>
              <td class="nowrap" data-order="<?= e($q['valid_until']) ?>"><?= e(format_date($q['valid_until'])) ?></td>
              <td class="row-actions"><?= partial('partials/preview-button', [
                  'href' => url('/quotations/' . $q['id'] . '/print'),
                  'kind' => 'document',
                  'title' => t('preview.title_quotation', ['ref' => $q['reference'] . ' v' . $q['version']]),
                  'meta' => t('preview.meta_client_document', ['client' => $q['client_name']]),
              ]) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <?php if ($clientOrders !== []): ?>
    <section class="card section-gap" id="orders">
      <div class="card__title"><h2><?= icon('file-check', 'title-icon') ?><?= e(t('client_orders.title')) ?></h2></div>
      <?= partial('client_orders/_table', ['rows' => $clientOrders, 'compact' => true]) ?>
    </section>
    <?php endif; ?>
  </div>

  <aside class="rfq-show__side">
    <section class="card">
      <div class="card__title"><h2><?= icon('building-2', 'title-icon') ?><?= e(t('rfqs.section_client')) ?></h2></div>
      <dl class="details">
        <div><dt><?= e(t('rfqs.client')) ?></dt><dd><a href="<?= e(url('/clients/' . $rfq['client_id'])) ?>"><?= e($rfq['client_name']) ?></a></dd></div>
        <div><dt><?= e(t('rfqs.client_reference')) ?></dt><dd><?= $rfq['client_reference'] ? '<strong>' . e($rfq['client_reference']) . '</strong>' : '—' ?></dd></div>
        <div><dt><?= e(t('rfqs.buyer_name')) ?></dt><dd><?= e($rfq['buyer_name'] ?: '—') ?><?php if ($rfq['buyer_email']): ?><br><a href="mailto:<?= e($rfq['buyer_email']) ?>"><?= e($rfq['buyer_email']) ?></a><?php endif; ?></dd></div>
        <div><dt><?= e(t('rfqs.delivery_site')) ?></dt><dd><?= e($rfq['delivery_site'] ?: '—') ?></dd></div>
      </dl>
      <?php if ($rfq['notes']): ?><p class="note"><?= nl2br(e($rfq['notes'])) ?></p><?php endif; ?>
    </section>

    <section class="card section-gap" id="files">
      <div class="card__title"><h2><?= icon('paperclip', 'title-icon') ?><?= e(t('rfqs.section_files')) ?></h2>
        <?php if (can('rfqs.manage') && $status->isOpen()): ?><a class="link-btn" href="<?= e(url('/rfqs/' . $rfq['id'] . '/edit') . '#f-attachments') ?>"><?= e(t('rfqs.attachments_add')) ?></a><?php endif; ?></div>
      <?php if ($files === []): ?>
        <div class="alert alert--warn"><?= icon('file-warning') ?><span><?= e(t('rfqs.no_attachment')) ?></span></div>
      <?php else: ?>
        <ul class="file-list">
          <?php foreach ($files as $file): $kind = preview_kind((string) $file['mime']); ?>
            <li>
              <?= icon($kind === 'image' ? 'image' : ($kind === 'pdf' ? 'file-text' : 'file')) ?>
              <a href="<?= e(url('/files/' . $file['file_id'])) ?>"<?= $kind !== null ? preview_attrs($kind, (string) $file['original_name'], $rfq['client_name'] . ' · ' . $rfq['reference'], url('/files/' . $file['file_id'], ['download' => 1])) : ' download' ?>><?= e($file['original_name']) ?></a>
              <span class="muted"><?= e(format_bytes((int) $file['size_bytes'])) ?> · <?= e(format_date($file['created_at'])) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <?php if (!$status->isClosed() && can('rfqs.manage')): ?>
    <details class="card section-gap disclosure-card">
      <summary class="card__title"><h2><?= icon('archive', 'title-icon') ?><?= e(t('rfqs.close_title')) ?></h2><?= icon('chevron-down', 'disclosure-card__chevron') ?></summary>
      <form method="post" action="<?= e(url('/rfqs/' . $rfq['id'] . '/close')) ?>" class="form" data-confirm="<?= e(t('rfqs.close_confirm')) ?>">
        <?= csrf_field() ?>
        <div class="field">
          <label for="close-status"><?= e(t('rfqs.close_as')) ?></label>
          <select id="close-status" name="status">
            <option value="lost"><?= e(t('rfq_status.lost')) ?></option>
            <option value="cancelled"><?= e(t('rfq_status.cancelled')) ?></option>
          </select>
        </div>
        <div class="field">
          <label for="close-reason"><?= e(t('rfqs.close_reason')) ?></label>
          <input id="close-reason" name="reason" maxlength="255" placeholder="<?= e(t('rfqs.close_reason_placeholder')) ?>">
        </div>
        <button type="submit" class="btn"><?= icon('archive') ?><span><?= e(t('rfqs.close_submit')) ?></span></button>
      </form>
    </details>
    <?php endif; ?>

    <div class="section-gap"><?= partial('partials/history', ['history' => $history, 'statusPrefix' => 'rfq_status.']) ?></div>
  </aside>
</div>

<?php if ($canSource && $availableSuppliers !== []): ?>
<dialog class="panel-modal sourcing-modal" id="sourcing-modal" aria-labelledby="sourcing-modal-title">
  <form method="post" action="<?= e(url('/rfqs/' . $rfq['id'] . '/sourcing')) ?>" class="sourcing-form" data-sourcing-form>
    <?= csrf_field() ?>
    <div class="panel-modal__head">
      <h2 id="sourcing-modal-title" class="panel-modal__title"><?= e(t('sourcing.modal_title', ['ref' => $rfq['reference']])) ?></h2>
      <button type="button" class="icon-btn" data-close-dialog aria-label="<?= e(t('common.close')) ?>"><?= icon('x') ?></button>
    </div>
    <div class="panel-modal__body sourcing-modal__body">
      <fieldset class="sourcing-block">
        <legend><span class="rfq-step__no">1</span><?= e(t('sourcing.modal_suppliers')) ?></legend>
        <input type="search" class="sourcing-search" placeholder="<?= e(t('sourcing.modal_search')) ?>" data-supplier-search aria-label="<?= e(t('sourcing.modal_search')) ?>">
        <div class="supplier-picks" data-supplier-list>
          <?php foreach ($availableSuppliers as $s): ?>
            <label class="supplier-pick" data-name="<?= e(mb_strtolower($s['name'] . ' ' . $s['code'] . ' ' . ($s['country'] ?? ''))) ?>">
              <input type="checkbox" name="supplier_ids[]" value="<?= (int) $s['id'] ?>">
              <span class="supplier-pick__name" data-label><?= e($s['name']) ?></span>
              <span class="supplier-pick__meta"><span class="tag"><?= e($s['currency_code']) ?></span><?= $s['country'] ? ' ' . e($s['country']) : '' ?><?= $s['contact_email'] ? ' · ' . e($s['contact_email']) : '' ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <fieldset class="sourcing-block">
        <legend><span class="rfq-step__no">2</span><?= e(t('sourcing.modal_lines')) ?></legend>
        <table class="table sourcing-lines" data-no-dt>
          <thead><tr>
            <th scope="col"><input type="checkbox" checked data-check-all aria-label="<?= e(t('sourcing.modal_all')) ?>"></th>
            <th scope="col"><?= e(t('rfqs.line.description')) ?></th>
            <th scope="col" class="num"><?= e(t('sourcing.modal_qty')) ?></th>
            <th scope="col"><?= e(t('rfqs.line.unit')) ?></th>
          </tr></thead>
          <tbody>
          <?php foreach ($lines as $line): ?>
            <tr>
              <td><input type="checkbox" name="lines[<?= (int) $line['id'] ?>][selected]" value="1" checked data-line-check aria-label="<?= e($line['description']) ?>"></td>
              <td><?= e($line['description']) ?><?php if ($line['item_code']): ?> <span class="sub"><?= e($line['item_code']) ?></span><?php endif; ?></td>
              <td class="num"><input name="lines[<?= (int) $line['id'] ?>][quantity]" value="<?= e(input_qty((int) $line['quantity_milli'])) ?>" inputmode="decimal" class="num input--sm" aria-label="<?= e(t('sourcing.modal_qty')) ?>"></td>
              <td class="muted"><?= e(unit_label($line['unit'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </fieldset>
      <fieldset class="sourcing-block">
        <legend><span class="rfq-step__no">3</span><?= e(t('sourcing.modal_terms')) ?></legend>
        <div class="form-grid">
          <?= form_input('response_due', t('sourcing.response_due'), $defaultResponseDue, ['type' => 'date', 'required' => true, 'hint' => t('sourcing.response_due_hint')]) ?>
          <?= form_textarea('message', t('sourcing.message'), t('sourcing.doc_intro'), ['rows' => 3, 'maxlength' => 2000, 'hint' => t('sourcing.message_hint')]) ?>
        </div>
      </fieldset>
    </div>
    <div class="panel-modal__foot">
      <span class="muted" data-sourcing-summary></span>
      <button type="button" class="btn" data-close-dialog><?= e(t('common.cancel')) ?></button>
      <button type="submit" class="btn btn--primary"><?= icon('file-output') ?><span><?= e(t('sourcing.modal_submit')) ?></span></button>
    </div>
  </form>
</dialog>
<?= json_script('sourcing-data', ['summary' => t('sourcing.modal_summary'), 'noSupplier' => t('sourcing.error_no_supplier'), 'noLine' => t('sourcing.error_no_line')]) ?>
<?php endif; ?>

<dialog class="panel-modal remote-modal" id="remote-modal" aria-labelledby="remote-modal-title"<?= $reopenOffer !== null ? ' data-auto-open' : '' ?>>
  <div class="panel-modal__head">
    <h2 id="remote-modal-title" class="panel-modal__title"><?= $reopenOffer !== null ? e(t('sourcing.offer_title', ['supplier' => (string) $reopenOffer['quote']['supplier_name']])) : '' ?></h2>
    <button type="button" class="icon-btn" data-close-dialog aria-label="<?= e(t('common.close')) ?>"><?= icon('x') ?></button>
  </div>
  <div class="remote-modal__body" data-remote-body><?= $reopenOffer !== null ? partial('sourcing/_offer_form', $reopenOffer) : '' ?></div>
</dialog>