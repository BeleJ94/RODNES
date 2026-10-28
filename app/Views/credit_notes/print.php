<?php

use App\Domain\Shared\Money;

/**
 * Avoir remis au client.
 *
 * @var array<string, mixed> $note
 * @var array<string, mixed> $client
 * @var list<array<string, mixed>> $lines
 * @var array<string, string> $s paramètres société
 */
?>
<article class="doc">
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
      <h1><?= e(t('credit_notes.doc_title')) ?></h1>
      <p><strong><?= e($note['reference']) ?></strong></p>
      <p class="doc__muted"><?= e(t('quotations.doc_date', ['date' => format_date($note['issue_date'])])) ?></p>
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
      <p><?= e(t('credit_notes.doc_invoice', ['ref' => $note['invoice_reference']])) ?></p>
      <p class="doc__muted"><?= e(t('invoices.doc_your_po', ['ref' => $note['client_po_number']])) ?></p>
      <p class="doc__muted"><?= e(t('credit_notes.reason')) ?> : <?= e($note['reason']) ?></p>
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
      <tr><th colspan="4" class="num"><?= e(t('quotations.total.subtotal')) ?></th><td class="num"><?= e(format_money((int) $note['subtotal_minor'], 'USD')) ?></td></tr>
      <tr><th colspan="4" class="num"><?= e($note['vat_applicable'] ? t('quotations.total.vat_rate', ['rate' => format_percent((int) $note['vat_rate_bp'])]) : t('quotations.total.vat_exempt')) ?></th><td class="num"><?= e(format_money((int) $note['vat_minor'], 'USD')) ?></td></tr>
      <tr class="doc__total"><th colspan="4" class="num"><?= e(t('credit_notes.doc_total')) ?></th><td class="num"><?= e(format_money((int) $note['total_minor'], 'USD')) ?></td></tr>
    </tfoot>
  </table>

  <section class="doc__terms">
    <ul>
      <li><?= e(t('quotations.doc_currency')) ?></li>
      <li><?= e(t('credit_notes.doc_rule', ['ref' => $note['invoice_reference']])) ?></li>
    </ul>
    <?php if ($note['notes']): ?><p><?= nl2br(e($note['notes'])) ?></p><?php endif; ?>
  </section>
  <footer class="doc__foot"><?= e($s['company_name']) ?> · ETHAN</footer>
</article>
