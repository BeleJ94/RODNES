<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(public string $body = '', public int $status = 200, public array $headers = [])
    {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return new self($body, $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /** Redirection après POST (303 : le navigateur refait un GET). */
    public static function redirect(string $location, int $status = 303): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    /** Fichier à télécharger (nom ASCII + nom UTF-8 selon RFC 6266). */
    public static function download(string $body, string $filename, string $mime): self
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'export';
        return new self($body, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }
}
