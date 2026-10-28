<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/** Gabarits PHP dans app/Views ; toute sortie dynamique passe par e(). */
final class View
{
    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'app'): string
    {
        $content = $this->partial($template, $data);
        return $layout === null ? $content : $this->partial('layouts/' . $layout, $data + ['content' => $content]);
    }

    /** @param array<string, mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        if (!preg_match('~^[a-z0-9_-]+(/[a-z0-9_-]+)*$~', $template)) {
            throw new RuntimeException("Nom de vue invalide : {$template}");
        }
        $file = BASE_PATH . '/app/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Vue introuvable : {$template}");
        }
        $render = static function (string $__file, array $__data): void {
            extract($__data, EXTR_SKIP);
            require $__file;
        };
        ob_start();
        try {
            $render($file, $data);
            return (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
