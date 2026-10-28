<?php

use App\Services\DocumentChain;

/**
 * @var array<string, list<array{id: int, ref: string, sub: string, url: string}>> $chain
 * @var string $type document courant
 * @var int $id
 */
?>
<nav class="doc-chain" aria-label="<?= e(t('chain.title')) ?>">
  <ol class="doc-chain__steps">
    <?php foreach (DocumentChain::STAGES as $stage => [$icon, $permission]):
        $docs = $chain[$stage] ?? [];
        $current = $stage === $type; ?>
      <li class="doc-chain__step<?= $docs !== [] ? ' is-done' : '' ?><?= $current ? ' is-current' : '' ?>">
        <span class="doc-chain__label"><?= icon($icon) ?><?= e(t('chain.' . $stage)) ?><?php if (count($docs) > 1): ?> <span class="doc-chain__count"><?= count($docs) ?></span><?php endif; ?></span>
        <?php if ($docs === []): ?>
          <span class="doc-chain__empty"><?= e(t('chain.none')) ?></span>
        <?php else: ?>
          <ul class="doc-chain__docs">
            <?php foreach ($docs as $doc):
                $here = $current && $doc['id'] === $id;
                $title = $doc['sub'] !== '' ? $doc['ref'] . ' · ' . $doc['sub'] : $doc['ref']; ?>
              <li>
                <?php if ($here): ?>
                  <span class="doc-chain__doc is-here" aria-current="page" title="<?= e($title) ?>"><?= e($doc['ref']) ?></span>
                <?php elseif (can($permission)): ?>
                  <a class="doc-chain__doc" href="<?= e($doc['url']) ?>" title="<?= e($title) ?>"<?= $stage === 'supplier_quote' ? ' data-remote-dialog="' . e($doc['url']) . '"' : '' ?>><?= e($doc['ref']) ?></a>
                <?php else: ?>
                  <span class="doc-chain__doc" title="<?= e($title) ?>"><?= e($doc['ref']) ?></span>
                <?php endif; ?>
                <?php if ($doc['sub'] !== ''): ?><span class="doc-chain__sub"><?= e($doc['sub']) ?></span><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
</nav>
