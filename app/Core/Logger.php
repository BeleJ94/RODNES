<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/** Journal technique en lignes JSON : storage/logs/ethan-AAAA-MM-JJ.log. */
final class Logger
{
    public function __construct(private string $dir)
    {
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        if (($context['exception'] ?? null) instanceof Throwable) {
            $e = $context['exception'];
            $context['exception'] = [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'at' => $e->getFile() . ':' . $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ];
        }
        $line = (string) json_encode(
            ['ts' => date('c'), 'level' => $level, 'message' => $message, 'context' => $context],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
        if (is_dir($this->dir) && is_writable($this->dir)) {
            file_put_contents($this->dir . '/ethan-' . date('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } else {
            error_log($line);
        }
    }
}
