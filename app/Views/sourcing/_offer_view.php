<?php

use App\Domain\Shared\Fx;
use App\Domain\Shared\Money;
use App\Domain\Sourcing\SupplierQuoteStatus;

/**
 * Consultation de l'offre d'un fournisseur (fenêtre) : conditions, prix et totaux à gauche,
 * document de l'offre à droite.
 *
 * @var array<string, mixed> $quote
 * @var list<array<string, mixed>> $lines lignes demandées
 * @var array<int, array<string, mixed>> $offered rfq_line_id → ligne d'offre
 * @var list<array<string, mixed>> $files
 * @var array<string, array{rate: int, date: ?string}> $rates
 */

$status = SupplierQuoteStatus::from((string) $quote['status']);
$cur = (string) $quote['currency_code'];
$total = 0;
$missing = 0;
foreach ($lines as $line) {
    $o = $offered[(int) $line['id']] ?? null;
    if ($o === null) {
        $missing++;
        continue;
    }
    $total += Money::lineAmount((int) ($line['requested_milli'] ?? $line['quantity_milli']), (int) $o['unit_price_minor']);
}
$rate = $rates[$cur]['rate'] ?? null;
$title = t('sourcing.offer_title', ['supplier' => (string) $quote['supplier_name']]) . ($quote['reference'] ? ' — ' . $quote['reference'] : '');
?>
<div class="offer-view" data-title="<?= e($title) ?>">
  <div class="offer-layout">
    <div class="offer-layout__main">
      <div class="offer-kpis">
        <div><span><?= e(t('common.status')) ?></span><?= badge($status->tone(), t($status->labelKey())) ?></div>
        <div><span><?= e(t('sourcing.offer_total')) ?></span><strong><?= e(format_money($total, $cur)) ?></strong><?php if ($cur !== 'USD' && $rate): ?><small>≈ <?= e(format_money(Fx::toUsd($total, (int) $rate), 'USD')) ?></small><?php endif; ?></div>
        <div><span><?= e(t('sourcing.offer_coverage')) ?></span><strong><?= count($lines) - $missing ?> / <?= count($lines) ?></strong><small><?= e(t('sourcing.offer_coverage_hint')) ?></small></div>
      </div>
      <dl class="details details--cols offer-terms">
        <div><dt><?= e(t('sourcing.supplier_reference')) ?></dt><dd><?= e($quote['supplier_reference'] ?: '—') ?></dd></div>
        <div><dt><?= e(t('sourcing.received_on')) ?></dt><dd><?= e(format_date($quote['received_on'])) ?: '—' ?></dd></div>
        <div><dt><?= e(t('sourcing.valid_until')) ?></dt><dd><?= e(format_date($quote['valid_until'])) ?: '—' ?></dd></div>
        <div><dt><?= e(t('sourcing.currency')) ?></dt><dd><?= e($cur) ?><?= $quote['incoterm'] ? ' · ' . e($quote['incoterm']) : '' ?></dd></div>
        <div><dt><?= e(t('sourcing.lead_time_days')) ?></dt><dd><?= $quote['lead_time_days'] !== null ? e(t('sourcing.lead_time', ['n' => $quote['lead_time_days']])) : '—' ?></dd></div>
      </dl>
      <?php if ($quote['notes']): ?><p class="note"><?= nl2br(e($quote['notes'])) ?></p><?php endif; ?>
      <table class="table" data-dt="compact" data-export-title="<?= e($title) ?>">
        <thead><tr>
          <th scope="col">#</th>
          <th scope="col"><?= e(t('rfqs.line.description')) ?></th>
          <th scope="col" class="num" data-x="qty"><?= e(t('rfqs.line.quantity')) ?></th>
          <th scope="col" class="num" data-x="money" data-x-total="0"><?= e(t('sourcing.unit_price')) ?></th>
          <th scope="col" class="num" data-x="money"><?= e(t('sourcing.offer_line_total')) ?></th>
          <th scope="col" class="num" data-x="int"><?= e(t('sourcing.lead_time_days')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($lines as $i => $line):
            $o = $offered[(int) $line['id']] ?? null;
            $qty = (int) ($line['requested_milli'] ?? $line['quantity_milli']);
            $amount = $o !== null ? Money::lineAmount($qty, (int) $o['unit_price_minor']) : null; ?>
          <tr>
            <td class="muted"><?= $i + 1 ?></td>
            <td><?= e($line['description']) ?><?php if ($o !== null && $o['note']): ?> <span class="sub"><?= e($o['note']) ?></span><?php endif; ?></td>
            <td class="num nowrap" data-order="<?= $qty ?>"><?= e(format_qty($qty)) ?> <span class="muted"><?= e(unit_label($line['unit'])) ?></span></td>
            <td class="num nowrap" data-order="<?= $o !== null ? (int) $o['unit_price_minor'] : '' ?>"><?= $o !== null ? e(format_money((int) $o['unit_price_minor'], $cur)) : '<span class="chip">' . e(t('sourcing.not_offered')) . '</span>' ?></td>
            <td class="num nowrap" data-order="<?= $amount ?? '' ?>"><?= $amount !== null ? '<strong>' . e(format_money($amount, $cur)) . '</strong>' : '—' ?></td>
            <td class="num" data-order="<?= $o['lead_time_days'] ?? '' ?>"><?= $o !== null && $o['lead_time_days'] !== null ? (int) $o['lead_time_days'] : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <aside class="offer-layout__doc" data-doc-picker>
      <h3 class="rfq-step"><?= icon('paperclip') ?><?= e(t('sourcing.offer_files')) ?></h3>
      <?php if ($files === []): ?>
        <div class="alert alert--warn"><?= icon('file-warning') ?><span><?= e(t('sourcing.offer_no_file')) ?></span></div>
      <?php endif; ?>
      <ul class="doc-chips" data-doc-chips>
        <?php foreach ($files as $file): $kind = preview_kind((string) $file['mime']); ?>
          <li class="doc-chip" data-src="<?= e(url('/files/' . $file['file_id'])) ?>" data-kind="<?= e($kind ?? 'other') ?>" data-name="<?= e($file['original_name']) ?>" data-saved>
            <button type="button" class="doc-chip__open"><?= icon($kind === 'image' ? 'image' : ($kind === 'pdf' ? 'file-text' : 'file')) ?><span class="doc-chip__name"><?= e($file['original_name']) ?></span><span class="doc-chip__size"><?= e(format_bytes((int) $file['size_bytes'])) ?></span></button>
            <a class="icon-btn" href="<?= e(url('/files/' . $file['file_id'], ['download' => 1])) ?>" title="<?= e(t('preview.download')) ?>" aria-label="<?= e(t('preview.download')) ?>"><?= icon('download') ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($files !== []): ?>
        <div class="doc-viewer doc-viewer--modal" data-doc-viewer><div class="doc-viewer__empty"><?= icon('file-search') ?></div></div>
      <?php endif; ?>
    </aside>
  </div>
  <div class="panel-modal__foot">
    <span class="muted"><?= e($quote['reference'] ? t('sourcing.offer_for_request', ['ref' => $quote['reference']]) : '') ?></span>
    <button type="button" class="btn" data-close-dialog><?= e(t('common.close')) ?></button>
    <?php if (can('sourcing.manage') && !in_array($quote['rfq_status'], ['won', 'lost', 'cancelled'], true)): ?>
      <button type="button" class="btn btn--primary" data-remote-dialog="<?= e(url('/sourcing/' . $quote['id'], ['fragment' => 1])) ?>"><?= icon('pencil') ?><span><?= e(t('sourcing.edit_offer')) ?></span></button>
    <?php endif; ?>
  </div>
</div>
