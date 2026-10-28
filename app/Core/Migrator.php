<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * Applique database/migrations/NNNN_nom.sql dans l'ordre, une seule fois.
 * Une empreinte SHA-256 signale toute migration modifiée après application.
 * Convention : une instruction se termine par « ; » en fin de ligne ; commentaires « -- » sur leur propre ligne.
 * Le DDL MariaDB n'est pas transactionnel : une migration en échec se corrige puis se relance.
 */
final class Migrator
{
    public function __construct(private PDO $pdo, private string $dir)
    {
    }

    /** @param callable(string): void $out */
    public function migrate(callable $out): int
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(190) NOT NULL PRIMARY KEY,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $applied = $this->pdo->query('SELECT version, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
        $files = glob($this->dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $count = 0;
        foreach ($files as $file) {
            $version = basename($file);
            if (!preg_match('/^\d{4}_[a-z0-9_]+\.sql$/', $version)) {
                throw new RuntimeException("Nom de migration invalide : {$version}");
            }
            $checksum = (string) hash_file('sha256', $file);
            if (isset($applied[$version])) {
                if (!hash_equals($applied[$version], $checksum)) {
                    $out("! {$version} a été modifiée après application — créer une nouvelle migration à la place.");
                }
                continue;
            }
            foreach (self::split((string) file_get_contents($file)) as $statement) {
                $this->pdo->exec($statement);
            }
            $this->pdo->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)')->execute([$version, $checksum]);
            $out("✓ {$version}");
            $count++;
        }
        return $count;
    }

    /** @return list<string> */
    public static function split(string $sql): array
    {
        $statements = [];
        $buffer = '';
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $buffer .= $line . "\n";
            if (str_ends_with($trimmed, ';')) {
                $statements[] = rtrim(trim($buffer), ';');
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }
        return $statements;
    }
}
