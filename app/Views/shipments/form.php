<?php

/**
 * Création d'une expédition en page complète : choix du client, puis le même formulaire que la fenêtre des fiches PO.
 *
 * @var list<array{id: int, name: string}> $clients clients ayant quelque chose à expédier
 * @var array<string, mixed>|null $client
 * @var list<array<string, mixed>> $lines lignes restant à expédier
 * @var ?int $preselect PO fournisseur à cocher par défaut (sinon tout)
 */
?>
<?= partial('partials/page-header', ['icon' => 'truck', 'title' => $title, 'meta' => e(t('shipments.form_meta'))]) ?>

<form method="get" action="<?= e(url('/shipments/new')) ?>" class="card form" data-autosubmit>
  <div class="form-grid">
    <div class="field">
      <label for="pick-client" class="required"><?= e(t('rfqs.client')) ?></label>
      <select id="pick-client" name="client_id" required>
        <option value=""><?= e(t('common.choose')) ?></option>
        <?php foreach ($clients as $c): ?>
          <option value="<?= (int) $c['id'] ?>"<?= $client !== null && (int) $client['id'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="hint"><?= e($clients === [] ? t('shipments.nothing_to_ship') : t('shipments.pick_hint')) ?></p>
    </div>
  </div>
</form>

<?php if ($client !== null): ?>
<section class="card offer-page section-gap">
  <?= partial('shipments/_form', ['client' => $client, 'lines' => $lines, 'preselect' => $preselect, 'docTypes' => $docTypes, 'returnTo' => '', 'modal' => false]) ?>
</section>
<?php endif; ?>
