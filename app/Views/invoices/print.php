<?php

use App\Domain\Shared\Money;

/**
 * Facture remise au client (brouillon : proforma sans numéro).
 *
 * @var array<string, mixed> $invoice
 * @var array<string, mixed> $client
 * @var list<array<string, mixed>> $lines
 * @var array<string, string> $s paramètres société
 */
$draft = $invoice['status'] === 'draft';
$cancelled = $invoice['status'] === 'cancelled';
?>
<article class="doc<?= $draft || $cancelled ? ' doc--watermark' : '' ?>"<?= $draft ? ' data-watermark="' . e(t('invoices.doc_proforma')) . '"' : ($cancelled ? ' data-watermark="' . e(t('invoice_status.cancelled')) . '"' : '') ?>>
  <header class="doc__head">
    <div>
      <?= doc_logo() ?>
      <p class="doc__company"><?= e($s['company_name']) ?></p>
      <p class="doc__muted"><?= e($s['company_address']) ?></p>
      <p class="doc__muted"><?= e(implode(' · ', array_filter([$s['company_phone'], $s['company_email']]))) ?></p>
      <p class="doc__muted"><?= e(implode(' · ', array_filter([
          $s['company_rccm'] !== '' ? 'RCCM ' . $s['company_rccm'] : '',
          $s['company_idnat'] !== '' ? 'Id. Nat. ' . $s['company_idnat'] : '',
          $s['company_nif'] !== '' ? 'NIF ' . $s['company_nif'] : '',
      ]))) ?></p>
    </div>
    <div class="doc__title">
      <h1><?= e(t($draft ? 'invoices.doc_title_proforma' : 'invoices.doc_title')) ?></h1>
      <p><strong><?= e($invoice['reference'] ?? '—') ?></strong></p>
      <p class="doc__muted"><?= e(t('quotations.doc_date', ['date' => format_date($invoice['issue_date'] ?? date('Y-m-d'))])) ?></p>
      <?php if ($invoice['due_date']): ?><p class="doc__muted"><?= e(t('invoices.doc_due', ['date' => format_date($invoice['due_date'])])) ?></p><?php endif; ?>
    </div>
  </header>

  <section class="doc__parties">
    <div>
      <p class="doc__label"><?= e(t('invoices.doc_bill_to')) ?></p>
      <p><strong><?= e($client['name']) ?></strong></p>
      <p><?= e(trim($client['address'] . ' ' . $client['city'])) ?></p>
      <p class="doc__muted"><?= e(implode(' · ', array_filter([
          $client['rccm'] ? 'RCCM ' . $client['rccm'] : '',
          $client['id_nat'] ? 'Id. Nat. ' . $client['id_nat'] : '',
          $client['nif'] ? 'NIF ' . $client['nif'] : '',
      ]))) ?></p>
    </div>
    <div>
      <p class="doc__label"><?= e(t('quotations.doc_reference')) ?></p>
      <p><?= e(t('invoices.doc_your_po', ['ref' => $invoice['client_po_number']])) ?></p>
      <p class="doc__muted"><?= e(t('invoices.doc_our_ref', ['ref' => $invoice['order_reference']])) ?></p>
    </div>
  </section>

  <table class="doc__table">
    <thead><tr>
      <th>#</th>
      <th><?= e(t('rfqs.line.description')) ?></th>
      <th class="num"><?= e(t('rfqs.line.quantity')) ?></th>
      <th class="num"><?= e(t('quotations.doc_unit_price')) ?></th>
      <th class="num"><?= e(t('quotations.line.total')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $l): ?>
      <tr>
        <td><?= (int) $l['line_no'] ?></td>
        <td><?= e($l['description']) ?></td>
        <td class="num"><?= e(format_qty((int) $l['quantity_milli'])) ?> <?= e(unit_label($l['unit'])) ?></td>
        <td class="num"><?= e(format_money((int) $l['unit_price_minor'])) ?></td>
        <td class="num"><?= e(format_money(Money::lineAmount((int) $l['quantity_milli'], (int) $l['unit_price_minor']))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><th colspan="4" class="num"><?= e(t('quotations.total.subtotal')) ?></th><td class="num"><?= e(format_money((int) $invoice['subtotal'], 'USD')) ?></td></tr>
      <tr><th colspan="4" class="num"><?= e($invoice['vat_applicable'] ? t('quotations.total.vat_rate', ['rate' => format_percent((int) $invoice['vat_rate_bp'])]) : t('quotations.total.vat_exempt')) ?></th><td class="num"><?= e(format_money((int) $invoice['vat'], 'USD')) ?></td></tr>
      <tr class="doc__total"><th colspan="4" class="num"><?= e(t('invoices.doc_total_due')) ?></th><td class="num"><?= e(format_money((int) $invoice['total'], 'USD')) ?></td></tr>
    </tfoot>
  </table>

  <section class="doc__terms">
    <p class="doc__label"><?= e(t('quotations.doc_terms')) ?></p>
    <ul>
      <li><?= e(t('quotations.doc_currency')) ?></li>
      <li><?= e(t('quotations.doc_payment', ['n' => $invoice['payment_terms_days']])) ?></li>
      <?php if (!$invoice['vat_applicable']): ?><li><?= e(t('invoices.doc_vat_exempt')) ?></li><?php endif; ?>
      <li><?= e(t('invoices.doc_reference_rule', ['ref' => $invoice['reference'] ?? $invoice['order_reference']])) ?></li>
    </ul>
    <?php if ($invoice['notes']): ?><p><?= nl2br(e($invoice['notes'])) ?></p><?php endif; ?>
    <?php if ($s['company_bank'] !== ''): ?>
      <p class="doc__label"><?= e(t('quotations.doc_bank')) ?></p>
      <p><?= nl2br(e($s['company_bank'])) ?></p>
    <?php endif; ?>
  </section>
  <footer class="doc__foot"><?= e($s['company_name']) ?> · ETHAN</footer>
</article>
