<?php

use App\Domain\Shared\Money;

/**
 * Bon de commande remis au fournisseur : aucun prix de vente, client final limité à l'adresse de livraison.
 *
 * @var array<string, mixed> $order
 * @var array<string, mixed> $supplier
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
      <h1><?= e(t('supplier_orders.doc_title')) ?></h1>
      <p><strong><?= e($order['reference']) ?></strong></p>
      <p class="doc__muted"><?= e(t('quotations.doc_date', ['date' => format_date($order['issued_on'] ?? date('Y-m-d'))])) ?></p>
    </div>
  </header>

  <section class="doc__parties">
    <div>
      <p class="doc__label"><?= e(t('supplier_orders.doc_supplier')) ?></p>
      <p><strong><?= e($supplier['name']) ?></strong></p>
      <?php if ($supplier['contact_name']): ?><p><?= e(t('quotations.doc_attention', ['name' => $supplier['contact_name']])) ?></p><?php endif; ?>
      <p><?= e(implode(' · ', array_filter([$supplier['contact_email'], $supplier['contact_phone']]))) ?></p>
    </div>
    <div>
      <p class="doc__label"><?= e(t('supplier_orders.doc_ship_to')) ?></p>
      <p><strong><?= e($order['delivery_address']) ?></strong></p>
      <p class="doc__muted"><?= e(t('supplier_orders.doc_direct')) ?></p>
    </div>
  </section>

  <table class="doc__table">
    <thead><tr>
      <th>#</th>
      <th><?= e(t('rfqs.line.description')) ?></th>
      <th class="num"><?= e(t('rfqs.line.quantity')) ?></th>
      <th class="num"><?= e(t('quotations.doc_unit_price')) ?></th>
      <th class="num"><?= e(t('supplier_orders.doc_amount')) ?></th>
    </tr></thead>
    <tbody>
    <?php $total = 0; foreach ($lines as $l): $amount = Money::lineAmount((int) $l['quantity_milli'], (int) $l['unit_price_minor']); $total += $amount; ?>
      <tr>
        <td><?= (int) $l['line_no'] ?></td>
        <td><?= e($l['description']) ?></td>
        <td class="num"><?= e(format_qty((int) $l['quantity_milli'])) ?> <?= e(unit_label($l['unit'])) ?></td>
        <td class="num"><?= e(format_money((int) $l['unit_price_minor'])) ?></td>
        <td class="num"><?= e(format_money($amount)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="doc__total"><th colspan="4" class="num"><?= e(t('supplier_orders.doc_total', ['currency' => $order['currency_code']])) ?></th><td class="num"><?= e(format_money($total, $order['currency_code'])) ?></td></tr>
    </tfoot>
  </table>

  <section class="doc__terms">
    <p class="doc__label"><?= e(t('quotations.doc_terms')) ?></p>
    <ul>
      <li><?= e(t('supplier_orders.doc_currency', ['currency' => $order['currency_code']])) ?></li>
      <?php if ($order['incoterm']): ?><li><?= e(t('quotations.doc_incoterm', ['incoterm' => $order['incoterm']])) ?></li><?php endif; ?>
      <?php if ($order['expected_ready_on']): ?><li><?= e(t('supplier_orders.doc_ready', ['date' => format_date($order['expected_ready_on'])])) ?></li><?php endif; ?>
      <li><?= e(t('quotations.doc_payment', ['n' => $order['payment_terms_days']])) ?></li>
      <li><?= e(t('supplier_orders.doc_reference_rule', ['ref' => $order['reference']])) ?></li>
    </ul>
    <?php if ($order['notes']): ?><p><?= nl2br(e($order['notes'])) ?></p><?php endif; ?>
  </section>
  <footer class="doc__foot"><?= e($s['company_name']) ?> · ETHAN</footer>
</article>
