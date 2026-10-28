<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $files = [],
        private readonly array $server = [],
    ) {
    }

    public static function fromGlobals(string $basePath): self
    {
        $path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
        $base = rtrim($basePath, '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            '/' . trim($path, '/'),
            $_GET,
            $_POST,
            $_FILES,
            $_SERVER,
        );
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    /** Valeur scalaire nettoyée ; un tableau injecté (champ[]=…) donne la valeur par défaut. */
    public function str(string $key, string $default = ''): string
    {
        $value = $this->input($key);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * Tableau de sous-tableaux (lignes de formulaire lines[0][champ]…), corps de requête uniquement.
     * Toute entrée qui n'est pas un tableau de scalaires est écartée.
     *
     * Les clés d'origine sont conservées (lines[42][price] → 42).
     *
     * @return array<int|string, array<string, string>>
     */
    public function rows(string $key): array
    {
        $value = $this->body[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[$index] = array_map(
                static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '',
                array_filter($row, static fn (mixed $k): bool => is_string($k), ARRAY_FILTER_USE_KEY),
            );
        }
        return $rows;
    }

    /** @return array<string, mixed> corps de requête (POST) */
    public function body(): array
    {
        return $this->body;
    }

    /** Valeur brute non retouchée, corps de requête uniquement (mots de passe). */
    public function secret(string $key): string
    {
        $value = $this->body[$key] ?? null;
        return is_string($value) ? $value : '';
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->input($key);
        return is_scalar($value) && preg_match('/^-?\d{1,18}$/', (string) $value) ? (int) $value : $default;
    }

    /** @return array<string, mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $file : null;
    }

    public function header(string $name): ?string
    {
        $key = strtoupper(str_replace('-', '_', $name));
        $value = $this->server['HTTP_' . $key] ?? ($key === 'CONTENT_TYPE' ? ($this->server['CONTENT_TYPE'] ?? null) : null);
        return $value === null ? null : (string) $value;
    }

    public function isXhr(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    public function wantsJson(): bool
    {
        return $this->isXhr() || str_contains((string) $this->header('Accept'), 'application/json');
    }

    /** Adresse du client. Derrière un proxy, c'est le serveur (mod_remoteip) qui doit réécrire REMOTE_ADDR. */
    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function isSecure(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        return ($https !== '' && $https !== 'off') || (string) ($this->server['SERVER_PORT'] ?? '') === '443';
    }

    public function fullPath(): string
    {
        return $this->query === [] ? $this->path : $this->path . '?' . http_build_query($this->query);
    }
}
