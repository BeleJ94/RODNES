<?php
// Un message flash est une clé de traduction, ou ['key' => …, 'params' => […]] pour un message paramétré.
foreach (['error' => 'circle-alert', 'success' => 'circle-check', 'info' => 'info'] as $type => $iconName):
    $message = flash($type);
    if (is_array($message) && is_string($message['key'] ?? null)) {
        $text = t($message['key'], is_array($message['params'] ?? null) ? $message['params'] : []);
    } elseif (is_string($message) && $message !== '') {
        $text = t($message);
    } else {
        continue;
    } ?>
  <div class="alert alert--<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>">
    <?= icon($iconName) ?><span><?= e($text) ?></span>
  </div>
<?php endforeach; ?>
