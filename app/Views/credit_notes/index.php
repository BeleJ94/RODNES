<?php

/** @var list<array<string, mixed>> $rows @var string $viewFilter */
?>
<?= partial('partials/page-header', ['icon' => 'receipt-text', 'title' => t('credit_notes.title'), 'meta' => e(t('credit_notes.meta')), 'actions' => export_button()]) ?>
<section class="card">
  <form class="filters" method="get" action="<?= e(url('/credit-notes')) ?>" data-autosubmit>
    <div class="filters__fields">
      <label class="filter"><span><?= e(t('filters.view')) ?></span>
        <select name="view">
          <option value=""><?= e(t('filters.all')) ?></option>
          <option value="unallocated"<?= $viewFilter === 'unallocated' ? ' selected' : '' ?>><?= e(t('credit_notes.filter_unallocated')) ?></option>
        </select>
      </label>
    </div>
  </form>
  <?php if ($rows === []): ?>
    <div class="empty-state"><?= icon('receipt-text') ?><p><?= e(t('credit_notes.empty')) ?></p></div>
  <?php else: ?>
    <?= partial('credit_notes/_table', ['rows' => $rows]) ?>
  <?php endif; ?>
</section>
