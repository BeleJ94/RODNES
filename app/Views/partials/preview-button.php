<?php
/**
 * Bouton-icône d'aperçu pour une ligne de tableau (le clic n'ouvre pas la fiche de la ligne).
 *
 * @var string $href  @var string $kind  @var string $title  @var string $meta  @var ?string $download
 */
?>
<a class="icon-btn" href="<?= e($href) ?>"<?= preview_attrs($kind, $title, $meta ?? '', $download ?? null) ?>
   title="<?= e(t('preview.button')) ?>" aria-label="<?= e(t('preview.button_for', ['title' => $title])) ?>"><?= icon('eye') ?></a>
