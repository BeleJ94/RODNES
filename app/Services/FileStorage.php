<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Domain\Shared\RuleViolation;
use App\Security\Auth;
use finfo;

/**
 * Fichiers joints (POD, documents) : stockés hors racine web sous un nom aléatoire, type déterminé par le contenu
 * (jamais par l'extension ni par le navigateur), taille plafonnée, empreinte SHA-256 conservée.
 */
final class FileStorage
{
    public function __construct(private Database $db, private Config $config, private Auth $auth)
    {
    }

    /**
     * @param array<string, mixed> $upload entrée de $_FILES
     * @param list<string>|null $mimes types admis (par défaut uploads.mimes)
     * @return array{id: int, path: string, sha256: string}
     */
    public function store(array $upload, ?array $mimes = null): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null)) {
            throw new RuleViolation(($upload['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'files.error_size' : 'files.error_upload');
        }
        $tmp = $upload['tmp_name'];
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
            throw new RuleViolation('files.error_upload');
        }
        $size = (int) filesize($tmp);
        if ($size === 0 || $size > (int) $this->config->get('uploads.max_bytes')) {
            throw new RuleViolation('files.error_size');
        }
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, $mimes ?? (array) $this->config->get('uploads.mimes'), true)) {
            throw new RuleViolation('files.error_type');
        }
        $key = bin2hex(random_bytes(32));
        $dir = BASE_PATH . '/storage/uploads/' . substr($key, 0, 2);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $path = $dir . '/' . $key;
        $moved = PHP_SAPI === 'cli' ? copy($tmp, $path) : move_uploaded_file($tmp, $path);
        if (!$moved) {
            throw new RuleViolation('files.error_upload');
        }
        $sha = (string) hash_file('sha256', $path);
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', basename((string) ($upload['name'] ?? 'document'))) ?: 'document';
        $id = $this->db->insert(
            'INSERT INTO files (storage_key, original_name, mime, size_bytes, sha256, uploaded_by) VALUES (:k, :n, :m, :s, :h, :u)',
            ['k' => $key, 'n' => mb_substr($name, 0, 190), 'm' => $mime, 's' => $size, 'h' => $sha, 'u' => $this->auth->user()?->id],
        );
        return ['id' => $id, 'path' => $path, 'sha256' => $sha];
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return $this->db->fetchOne('SELECT * FROM files WHERE id = :id', ['id' => $id]) ?? throw new HttpException(404);
    }

    /** @param array<string, mixed> $file */
    public function path(array $file): string
    {
        $key = (string) $file['storage_key'];
        if (!preg_match('/^[a-f0-9]{64}$/', $key)) {
            throw new HttpException(404);
        }
        return BASE_PATH . '/storage/uploads/' . substr($key, 0, 2) . '/' . $key;
    }
}
