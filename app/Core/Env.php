<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lecture du fichier .env (format KEY=VALUE, lignes « # » ignorées).
 * Les variables d'environnement réelles priment sur le fichier.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            self::$vars[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value === false) {
            $value = self::$vars[$key] ?? null;
        }
        return $value ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        return $value === null ? $default : in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return $value !== null && preg_match('/^-?\d+$/', $value) ? (int) $value : $default;
    }
}
