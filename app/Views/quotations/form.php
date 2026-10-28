<?php

use App\Domain\Sales\Pricing;

/**
 * Préparation du devis : articles et prix, conditions, livraison à gauche ; résumé collant à droite
 * (totaux, marge face au seuil d'approbation, enregistrement). Calculs en direct (initQuotationForm).
 *
 * @var array<string, mixed> $q
 * @var array<string, mixed> $rfq
 * @var list<array<string, mixed>> $lines
 * @var array<int, list<array<string, mixed>>> $offersByLine rfq_line_id → offres converties
 * @var array<string, int|null> $totals
 * @var list<string> $incoterms
 * @var list<string> $transportModes
 * @var int $defaultMarginBp
 * @var int $thresholdBp seuil de marge sous lequel le devis demande une approbation
 * @var bool $approvalEnabled
 */

$oldRows = old_rows('lines');
$step = static fn (int $n, string $icon, string $label): string => '<h2 class="rfq-step"><span class="rfq-step__no">' . $n . '</span>' . icon($icon) . e($label) . '</h2>';
$meta = e($q['client_name'])
    . ' · <a href="' . e(url('/rfqs/' . $q['rfq_id'])) . '">' . e($q['rfq_reference']) . '</a>'
    . (($rfq['client_reference'] ?? '') !== '' ? ' · <span class="client-ref">' . e($rfq['client_reference']) . '</span>' : '');
$preview = '<a class="btn" href="' . e(url('/quotations/' . $q['id'] . '/print')) . '"'
    . preview_attrs('document', t('preview.title_quotation', ['ref' => $q['reference'] . ' v' . $q['version']]), t('preview.meta_client_document', ['client' => $q['client_name']])) . '>'
    . icon('eye') . '<span>' . e(t('quotations.edit_preview')) . '</span></a>';
$shippingFilled = array_filter([$q['country_of_origin'] ?? null, $q['transport_mode'] ?? null, $q['estimated_ship_date'] ?? null, $q['carrier'] ?? null, $q['ship_to_address'] ?? null]) !== [];
?>
<?= partial('partials/page-header', ['icon' => 'file-text', 'title' => $title, 'meta' => $meta, 'actions' => $preview]) ?>
<form method="post" action="<?= e(url('/quotations/' . $q['id'])) ?>" class="quote-form form" data-quotation
      data-vat-rate="<?= (int) $q['vat_rate_bp'] ?>" data-threshold="<?= $approvalEnabled ? (int) $thresholdBp : '' ?>">
  <?= csrf_field() ?>
  <div class="quote-layout">
    <div class="quote-layout__main">
      <section class="card">
        <div class="quote-step-head">
          <?= $step(1, 'list', t('quotations.section_lines')) ?>
          <div class="bulk-margin">
            <label for="bulk-margin"><?= e(t('quotations.bulk_margin')) ?></label>
            <input id="bulk-margin" class="input--sm num" inputmode="decimal" value="<?= e(input_percent($defaultMarginBp)) ?>" data-bulk-margin>
            <span class="muted">%</span>
            <button type="button" class="btn btn--sm" data-apply-margin><?= icon('wand-sparkles') ?><span><?= e(t('quotations.bulk_margin_apply')) ?></span></button>
          </div>
        </div>
        <p class="hint"><?= e(t('quotations.lines_hint', ['margin' => format_percent($defaultMarginBp)])) ?></p>
        <?= field_error('lines') ?>
        <div class="table-scroll">
          <table class="table lines-table quote-lines" data-no-dt>
            <thead><tr>
              <th scope="col">#</th>
              <th scope="col"><?= e(t('rfqs.line.description')) ?> / <?= e(t('quotations.line.source')) ?></th>
              <th scope="col" class="w-qty"><?= e(t('rfqs.line.quantity')) ?></th>
              <th scope="col" class="w-avail"><?= e(t('quotations.line.availability')) ?></th>
              <th scope="col" class="w-money"><?= e(t('quotations.line.cost')) ?></th>
              <th scope="col" class="w-money"><?= e(t('quotations.line.extra')) ?></th>
              <th scope="col" class="w-pct"><?= e(t('quotations.line.margin')) ?></th>
              <th scope="col" class="w-money"><?= e(t('quotations.line.price')) ?></th>
              <th scope="col" class="num"><?= e(t('quotations.line.total')) ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($lines as $line):
                $id = (int) $line['id'];
                $offers = $line['rfq_line_id'] !== null ? ($offersByLine[(int) $line['rfq_line_id']] ?? []) : [];
                $row = $oldRows[$id] ?? [
                    'description' => $line['description'],
                    'quantity' => input_qty((int) $line['quantity_milli']),
                    'source' => (string) ($line['supplier_quote_line_id'] ?? ''),
                    'cost' => input_money((int) $line['cost_unit_usd_minor']),
                    'extra' => input_money((int) $line['extra_unit_usd_minor']),
                    'price' => input_money((int) $line['unit_price_minor']),
                    'availability' => (string) ($line['availability'] ?? ''),
                ];
                $margin = Pricing::marginBp((int) $line['cost_unit_usd_minor'] + (int) $line['extra_unit_usd_minor'], (int) $line['unit_price_minor']);
                $n = 'lines[' . $id . ']'; ?>
              <tr data-quote-line>
                <td class="muted"><?= (int) $line['line_no'] ?></td>
                <td><input name="<?= $n ?>[description]" value="<?= e($row['description'] ?? '') ?>" maxlength="500" required aria-label="<?= e(t('rfqs.line.description')) ?>">
                  <select name="<?= $n ?>[source]" class="quote-source" data-source aria-label="<?= e(t('quotations.line.source')) ?>">
                    <option value=""><?= e(t('quotations.line.manual_cost')) ?></option>
                    <?php foreach ($offers as $offer): ?>
                      <option value="<?= (int) $offer['id'] ?>" data-cost="<?= e(input_money((int) $offer['unit_usd_minor'])) ?>"<?= (string) $offer['id'] === (string) ($row['source'] ?? '') ? ' selected' : '' ?>>
                        <?= e($offer['supplier_name']) ?> — <?= e(format_money((int) $offer['unit_price_minor'], $offer['currency_code'])) ?><?= $offer['currency_code'] !== 'USD' ? ' (≈ ' . e(format_money($offer['unit_usd_minor'], 'USD')) . ')' : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td><span class="qty-cell"><input name="<?= $n ?>[quantity]" value="<?= e($row['quantity'] ?? '') ?>" inputmode="decimal" class="num" required data-qty aria-label="<?= e(t('rfqs.line.quantity')) ?>">
                    <span class="muted unit-suffix"><?= e(unit_label($line['unit'])) ?></span></span></td>
                <td><input name="<?= $n ?>[availability]" value="<?= e($row['availability'] ?? '') ?>" maxlength="40" placeholder="<?= e(t('quotations.availability_placeholder')) ?>" aria-label="<?= e(t('quotations.line.availability')) ?>"></td>
                <td><input name="<?= $n ?>[cost]" value="<?= e($row['cost'] ?? '') ?>" inputmode="decimal" class="num" data-cost aria-label="<?= e(t('quotations.line.cost')) ?>"></td>
                <td><input name="<?= $n ?>[extra]" value="<?= e($row['extra'] ?? '') ?>" inputmode="decimal" class="num" data-extra placeholder="0,00" aria-label="<?= e(t('quotations.line.extra')) ?>"></td>
                <td><input value="<?= e(input_percent($margin)) ?>" inputmode="decimal" class="num" data-margin aria-label="<?= e(t('quotations.line.margin')) ?>"></td>
                <td><input name="<?= $n ?>[price]" value="<?= e($row['price'] ?? '') ?>" inputmode="decimal" class="num" required data-price aria-label="<?= e(t('quotations.line.price')) ?>"></td>
                <td class="num nowrap" data-line-total></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="card section-gap">
        <?= $step(2, 'handshake', t('quotations.section_terms')) ?>
        <div class="form-grid form-grid--3">
          <?= form_input('valid_until', t('quotations.valid_until'), $q['valid_until'], ['type' => 'date', 'required' => true]) ?>
          <?= form_select('incoterm', 'Incoterm', array_combine($incoterms, $incoterms), $q['incoterm'] ?? '', ['placeholder' => '—']) ?>
          <?= form_input('delivery_terms', t('quotations.delivery_terms'), $q['delivery_terms'] ?? '', ['maxlength' => 255, 'placeholder' => t('quotations.delivery_terms_placeholder')]) ?>
          <?= form_input('payment_terms_days', t('common.payment_terms'), $q['payment_terms_days'], ['type' => 'number', 'required' => true, 'attrs' => ' min="0" max="365"', 'hint' => t('common.days_hint')]) ?>
          <?= form_select('payment_terms_basis', t('quotations.payment_basis_label'), ['delivery' => t('quotations.payment_basis.delivery'), 'invoice' => t('quotations.payment_basis.invoice')], $q['payment_terms_basis'] ?? 'delivery', ['required' => true]) ?>
          <?= form_check('vat_applicable', t('quotations.vat_applicable', ['rate' => format_percent((int) $q['vat_rate_bp'])]), (bool) $q['vat_applicable'], t('quotations.vat_hint')) ?>
          <?= form_textarea('notes', t('quotations.notes'), $q['notes'] ?? '', ['span' => true, 'maxlength' => 2000, 'rows' => 2, 'hint' => t('quotations.notes_hint')]) ?>
        </div>
      </section>

      <details class="card section-gap disclosure-card"<?= $shippingFilled ? ' open' : '' ?>>
        <summary class="quote-step-head"><?= $step(3, 'truck', t('quotations.section_shipping')) ?><span class="muted"><?= e(t('quotations.shipping_optional')) ?></span><?= icon('chevron-down', 'disclosure-card__chevron') ?></summary>
        <p class="hint"><?= e(t('quotations.shipping_hint')) ?></p>
        <div class="form-grid form-grid--3">
          <?= form_input('ship_to_name', t('quotations.ship_to_name'), $q['ship_to_name'] ?? '', ['maxlength' => 160]) ?>
          <?= form_input('ship_to_phone', t('quotations.ship_to_phone'), $q['ship_to_phone'] ?? '', ['maxlength' => 40, 'type' => 'tel']) ?>
          <?= form_input('country_of_origin', t('quotations.country_of_origin'), $q['country_of_origin'] ?? '', ['maxlength' => 80]) ?>
          <?= form_textarea('ship_to_address', t('quotations.ship_to_address'), $q['ship_to_address'] ?? '', ['span' => true, 'maxlength' => 500, 'rows' => 2]) ?>
          <?= form_input('estimated_ship_date', t('quotations.estimated_ship_date'), $q['estimated_ship_date'] ?? '', ['type' => 'date']) ?>
          <?= form_select('transport_mode', t('quotations.transport_mode'), array_combine($transportModes, array_map(static fn (string $m): string => t('quotations.transport.' . $m), $transportModes)), $q['transport_mode'] ?? '', ['placeholder' => '—']) ?>
          <?= form_input('carrier', t('quotations.carrier'), $q['carrier'] ?? '', ['maxlength' => 120]) ?>
          <?= form_input('packages_count', t('quotations.packages_count'), $q['packages_count'] ?? '', ['type' => 'number', 'attrs' => ' min="0" max="65000"']) ?>
          <?= form_input('gross_weight', t('quotations.gross_weight'), $q['gross_weight_milli'] !== null ? input_qty((int) $q['gross_weight_milli']) : '', ['attrs' => ' inputmode="decimal"']) ?>
          <?= form_input('net_weight', t('quotations.net_weight'), $q['net_weight_milli'] !== null ? input_qty((int) $q['net_weight_milli']) : '', ['attrs' => ' inputmode="decimal"']) ?>
        </div>
      </details>
    </div>

    <aside class="quote-layout__side">
      <section class="card quote-summary" aria-live="polite">
        <h2 class="rfq-step"><?= icon('calculator') ?><?= e(t('quotations.summary')) ?></h2>
        <dl class="quote-summary__rows">
          <div><dt><?= e(t('quotations.total.subtotal')) ?></dt><dd data-total="subtotal"></dd></div>
          <div><dt><?= e(t('quotations.total.vat')) ?></dt><dd data-total="vat"></dd></div>
          <div class="quote-summary__total"><dt><?= e(t('quotations.total.total')) ?></dt><dd data-total="total"></dd></div>
        </dl>
        <div class="quote-summary__margin">
          <div class="quote-summary__margin-head"><span><?= e(t('quotations.summary_margin')) ?></span><strong data-total="margin_pct"></strong></div>
          <div class="margin-meter" aria-hidden="true">
            <span class="margin-meter__bar" data-margin-bar></span>
            <?php if ($approvalEnabled): ?><span class="margin-meter__threshold" style="left: <?= min(100, max(0, intdiv((int) $thresholdBp, 50))) ?>%" title="<?= e(t('quotations.summary_threshold', ['rate' => format_percent((int) $thresholdBp)])) ?>"></span><?php endif; ?>
          </div>
          <p class="muted quote-summary__detail"><span data-total="margin_amount"></span> · <?= e(t('quotations.summary_cost')) ?> <span data-total="cost"></span></p>
        </div>
        <?php if ($approvalEnabled): ?>
          <div class="alert alert--warn quote-summary__alert" data-approval-alert hidden><?= icon('shield-alert') ?><span><?= e(t('quotations.summary_approval', ['rate' => format_percent((int) $thresholdBp)])) ?></span></div>
        <?php endif; ?>
        <p class="quote-summary__lines" data-low-lines data-low-text="<?= e(t('quotations.summary_low_lines')) ?>" data-ok-text="<?= e(t('quotations.summary_all_good')) ?>"></p>
        <div class="quote-summary__actions">
          <button type="submit" class="btn btn--primary btn--block"><?= icon('save') ?><span><?= e(t('common.save')) ?></span></button>
          <a class="btn btn--block" href="<?= e(url('/quotations/' . $q['id'])) ?>"><?= e(t('common.cancel')) ?></a>
        </div>
      </section>
    </aside>
  </div>
</form>
