<?php

/**
 * Saisie d'une facture fournisseur en page complète (lien direct) : même formulaire que la fenêtre de la fiche PO.
 *
 * @var array<string, mixed> $order
 * @var list<array<string, mixed>> $lines
 */
?>
<?= partial('partials/page-header', [
    'icon' => 'file-input',
    'title' => $title,
    'meta' => '<a href="' . e(url('/supplier-orders/' . $order['id'])) . '">' . e($order['reference']) . '</a> · ' . e($order['supplier_name']) . ' · ' . e($order['currency_code']),
]) ?>
<section class="card offer-page">
  <?= partial('supplier_invoices/_form', ['order' => $order, 'lines' => $lines, 'modal' => false]) ?>
</section>
