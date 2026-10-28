<?php

/**
 * Demande de prix remise au fournisseur : articles et quantités demandés, date de réponse attendue.
 * Ni prix de vente ni nom du client final : seul le lieu de livraison est indiqué.
 *
 * @var array<string, mixed> $quote demande (supplier_quotes)
 * @var array<string, mixed> $rfq
 * @var array<string, mixed> $supplier
 * @var list<array<string, mixed>> $lines lignes demandées
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
      <h1><?= e(t('sourcing.doc_title')) ?></h1>
      <p><strong><?= e($quote['reference'] ?? '—') ?></strong></p>
      <p class="doc__muted"><?= e(t('quotations.doc_date', ['date' => format_date($quote['requested_on'])])) ?></p>
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
      <p class="doc__label"><?= e(t('sourcing.doc_reply')) ?></p>
      <p><strong><?= $quote['response_due'] ? e(t('sourcing.doc_reply_by', ['date' => format_date($quote['response_due'])])) : e(t('sourcing.doc_reply_asap')) ?></strong></p>
      <?php if ($rfq['delivery_site']): ?><p class="doc__muted"><?= e(t('sourcing.doc_delivery', ['site' => $rfq['delivery_site']])) ?></p><?php endif; ?>
    </div>
  </section>

  <?php if ($quote['message']): ?><p class="doc__intro"><?= nl2br(e($quote['message'])) ?></p><?php else: ?><p class="doc__intro"><?= e(t('sourcing.doc_intro')) ?></p><?php endif; ?>

  <table class="doc__table">
    <thead><tr>
      <th>#</th>
      <th><?= e(t('rfqs.line.description')) ?></th>
      <th><?= e(t('sourcing.doc_reference')) ?></th>
      <th class="num"><?= e(t('rfqs.line.quantity')) ?></th>
      <th><?= e(t('rfqs.line.required_by')) ?></th>
      <th class="num"><?= e(t('sourcing.doc_unit_price')) ?></th>
      <th class="num"><?= e(t('sourcing.doc_lead_time')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $i => $l): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= e($l['description']) ?></td>
        <td><?= e(trim(implode(' ', array_filter([$l['manufacturer'] ?? '', $l['part_number'] ?? ''])))) ?: '—' ?></td>
        <td class="num"><?= e(format_qty((int) $l['requested_milli'])) ?> <?= e(unit_label($l['unit'])) ?></td>
        <td><?= e(format_date($l['required_by'])) ?: '—' ?></td>
        <td class="num doc__blank"></td>
        <td class="num doc__blank"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <section class="doc__terms">
    <p class="doc__label"><?= e(t('sourcing.doc_expected')) ?></p>
    <ul>
      <li><?= e(t('sourcing.doc_expect_price', ['currency' => $quote['currency_code']])) ?></li>
      <li><?= e(t('sourcing.doc_expect_lead')) ?></li>
      <li><?= e(t('sourcing.doc_expect_validity')) ?></li>
      <li><?= e(t('sourcing.doc_reference_rule', ['ref' => $quote['reference'] ?? ''])) ?></li>
    </ul>
  </section>
  <footer class="doc__foot"><?= e($s['company_name']) ?> · ETHAN</footer>
</article>
