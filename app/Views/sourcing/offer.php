<?php

/**
 * Saisie de l'offre en page complète (lien direct) : même formulaire que la fenêtre de la fiche RFQ.
 *
 * @var array<string, mixed> $quote
 */
?>
<?= partial('partials/page-header', [
    'icon' => 'clipboard-pen',
    'title' => $title,
    'meta' => '<a href="' . e(url('/rfqs/' . $quote['rfq_id'])) . '">' . e($quote['rfq_reference']) . '</a>',
]) ?>
<section class="card offer-page">
  <?= partial('sourcing/_offer_form', get_defined_vars()) ?>
</section>
