<?php

/**
 * Facturation en page complète (lien direct) : même formulaire que la fenêtre de la fiche PO client.
 *
 * @var array<string, mixed> $order
 * @var list<array<string, mixed>> $lines
 */
?>
<?= partial('partials/page-header', [
    'icon' => 'receipt',
    'title' => $title,
    'meta' => '<a href="' . e(url('/client-orders/' . $order['id'])) . '">' . e($order['reference']) . '</a> · ' . e($order['client_name'])
        . ' · ' . e(t('client_orders.their_po', ['ref' => $order['client_po_number']])),
]) ?>
<section class="card offer-page">
  <?= partial('invoices/_create', ['order' => $order, 'lines' => $lines, 'modal' => false]) ?>
</section>
