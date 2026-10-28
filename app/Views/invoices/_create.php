<?php

/**
 * Facturation d'un PO client (fenêtre de la fiche, ou page complète) : lignes livrées (POD) restant à facturer,
 * cochées et reprises en entier par défaut ; totaux en direct ; brouillon (proforma) ou émission immédiate.
 *
 * @var array<string, mixed> $order
 * @var list<array<string, mixed>> $lines lignes du PO avec invoiceable_milli
 * @var bool $modal true : dans la fenêtre (Annuler ferme la fenêtre)
 */

$oldRows = old_rows('lines');
$invoiceable = array_values(array_filter($lines, static fn (array $l): bool => (int) $l['invoiceable_milli'] > 0));
$others = count($lines) - count($invoiceable);
$mode = old('mode', 'draft');
$vatRate = (int) $order['vat_applicable'] ? (int) $order['vat_rate_bp'] : 0;
$title = t('invoices.modal.title', ['ref' => $order['reference']]) . ' · ' . $order['client_name'];
?>
<form method="post" action="<?= e(url('/client-orders/' . $order['id'] . '/invoices')) ?>" class="offer-form form inv-create" data-invoice-create
      data-title="<?= e($title) ?>" data-vat-rate="<?= $vatRate ?>" data-vat="<?= (int) $order['vat_applicable'] ?>">
  <?= csrf_field() ?>
  <div class="inv-create__body">
    <div class="inv-create__main">
      <p class="inv-create__intro"><?= icon('info') ?><span><?= e(t('invoices.modal.intro', ['po' => $order['client_po_number'], 'n' => (int) $order['payment_terms_days']])) ?></span></p>

      <div class="quote-step-head">
        <h3 class="rfq-step"><span class="rfq-step__no">1</span><?= e(t('invoices.modal.lines')) ?></h3>
        <?php if (count($invoiceable) > 1): ?>
          <label class="check inv-create__all"><input type="checkbox" data-inv-all checked><span><?= e(t('invoices.modal.all')) ?></span></label>
        <?php endif; ?>
      </div>
      <?= field_error('lines') ?>
      <?php if ($invoiceable === []): ?>
        <div class="empty-state"><?= icon('package-search') ?><p><?= e(t('invoices.nothing_delivered')) ?></p></div>
      <?php else: ?>
      <div class="table-scroll">
        <table class="table lines-table offer-lines inv-lines" data-no-dt>
          <thead><tr>
            <th scope="col" class="inv-lines__check"><span class="visually-hidden"><?= e(t('invoices.modal.include')) ?></span></th>
            <th scope="col"><?= e(t('rfqs.line.description')) ?></th>
            <th scope="col" class="num"><?= e(t('invoices.line.invoiceable')) ?></th>
            <th scope="col" class="w-qty"><?= e(t('invoices.line.to_invoice')) ?></th>
            <th scope="col" class="num"><?= e(t('quotations.line.price')) ?></th>
            <th scope="col" class="num"><?= e(t('quotations.line.total')) ?></th>
          </tr></thead>
          <tbody>
          <?php foreach ($invoiceable as $line):
              $id = (int) $line['id'];
              $max = (int) $line['invoiceable_milli'];
              $value = $oldRows[$id]['quantity'] ?? input_qty($max);
              $on = $oldRows === null || ($oldRows[$id]['quantity'] ?? '') !== ''; ?>
            <tr data-inv-line data-price="<?= (int) $line['unit_price_minor'] ?>" data-max="<?= $max ?>"<?= $on ? '' : ' class="is-excluded"' ?>>
              <td><label class="check"><input type="checkbox" data-inv-include<?= $on ? ' checked' : '' ?> aria-label="<?= e(t('invoices.modal.include') . ' — ' . $line['description']) ?>"></label></td>
              <td><?= e($line['description']) ?><?php if ((int) $line['invoiced_milli'] > 0): ?><br><span class="muted"><?= e(t('invoices.modal.already', ['qty' => format_qty((int) $line['invoiced_milli'])])) ?></span><?php endif; ?></td>
              <td class="num nowrap"><?= e(format_qty($max)) ?> <span class="muted"><?= e(unit_label($line['unit'])) ?></span></td>
              <td><input name="lines[<?= $id ?>][quantity]" value="<?= e($on ? $value : '') ?>" data-full="<?= e(input_qty($max)) ?>" inputmode="decimal" class="num" data-qty<?= $on ? '' : ' disabled' ?> aria-label="<?= e(t('invoices.line.to_invoice') . ' — ' . $line['description']) ?>"></td>
              <td class="num nowrap"><?= e(format_money((int) $line['unit_price_minor'])) ?></td>
              <td class="num nowrap" data-line-total></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="hint" data-inv-warn hidden><?= icon('triangle-alert') ?><?= e(t('invoices.modal.over')) ?></p>
      <?php if ($others > 0): ?><p class="hint"><?= e(t('invoices.modal.others', ['n' => $others])) ?></p><?php endif; ?>
      <?php endif; ?>

      <h3 class="rfq-step section-gap"><span class="rfq-step__no">2</span><?= e(t('invoices.modal.mode')) ?></h3>
      <div class="inv-mode">
        <label class="inv-mode__option">
          <input type="radio" name="mode" value="draft" data-inv-mode<?= $mode !== 'issue' ? ' checked' : '' ?>>
          <span><?= icon('file-pen') ?><strong><?= e(t('invoices.modal.mode_draft')) ?></strong><small><?= e(t('invoices.modal.mode_draft_hint')) ?></small></span>
        </label>
        <label class="inv-mode__option">
          <input type="radio" name="mode" value="issue" data-inv-mode<?= $mode === 'issue' ? ' checked' : '' ?>>
          <span><?= icon('stamp') ?><strong><?= e(t('invoices.modal.mode_issue')) ?></strong><small><?= e(t('invoices.modal.mode_issue_hint')) ?></small></span>
        </label>
      </div>
      <div class="inv-create__issue" data-inv-issue<?= $mode === 'issue' ? '' : ' hidden' ?>>
        <?= form_input('issue_date', t('invoices.modal.issue_date'), old('issue_date', date('Y-m-d')), ['type' => 'date', 'hint' => t('invoices.issue_hint', ['n' => (int) $order['payment_terms_days']])]) ?>
      </div>

      <details class="pod-simple__more"<?= old('notes') !== '' ? ' open' : '' ?>>
        <summary><?= icon('chevron-right') ?><?= e(t('invoices.modal.notes')) ?></summary>
        <?= form_textarea('notes', t('invoices.notes'), '', ['maxlength' => 2000, 'rows' => 2, 'hint' => t('invoices.notes_hint')]) ?>
      </details>
    </div>

    <aside class="inv-create__side">
      <p class="inv-sum__title"><?= icon('calculator') ?><?= e(t('invoices.modal.summary')) ?></p>
      <dl class="quote-summary__rows inv-sum">
        <div><dt><?= e(t('invoices.modal.lines_count')) ?></dt><dd data-inv-count></dd></div>
        <div><dt><?= e(t('quotations.total.subtotal')) ?></dt><dd data-inv-total="subtotal"></dd></div>
        <div><dt><?= e($vatRate > 0 ? t('quotations.doc.vat_rate', ['rate' => format_percent($vatRate)]) : t('quotations.doc.vat_exempt')) ?></dt><dd data-inv-total="vat"></dd></div>
        <div class="quote-summary__total"><dt><?= e(t('quotations.total.total')) ?></dt><dd data-inv-total="total"></dd></div>
      </dl>
      <p class="muted inv-sum__note"><?= e(t('invoices.modal.currency_note')) ?></p>
    </aside>
  </div>
  <div class="panel-modal__foot">
    <span class="muted"><?= e(t('invoices.modal.foot')) ?></span>
    <?php if ($modal): ?>
      <button type="button" class="btn" data-close-dialog><?= e(t('common.cancel')) ?></button>
    <?php else: ?>
      <a class="btn" href="<?= e(url('/client-orders/' . $order['id'])) ?>"><?= e(t('common.cancel')) ?></a>
    <?php endif; ?>
    <button type="submit" class="btn btn--primary"<?= $invoiceable === [] ? ' disabled' : '' ?> data-inv-submit
            data-label-draft="<?= e(t('invoices.modal.submit_draft')) ?>" data-label-issue="<?= e(t('invoices.modal.submit_issue')) ?>"><?= icon('receipt') ?><span><?= e(t($mode === 'issue' ? 'invoices.modal.submit_issue' : 'invoices.modal.submit_draft')) ?></span></button>
  </div>
</form>
