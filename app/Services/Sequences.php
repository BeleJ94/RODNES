<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Shared\Reference;
use LogicException;

/** Numérotation sans trou ni doublon : à appeler dans la transaction qui crée le document. */
final class Sequences
{
    public function __construct(private Database $db)
    {
    }

    public function next(string $name, string $prefix): string
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new LogicException('Sequences::next doit être appelé dans une transaction.');
        }
        $year = (int) date('Y');
        $this->db->run('INSERT IGNORE INTO sequences (name, year, value) VALUES (:n, :y, 0)', ['n' => $name, 'y' => $year]);
        $value = (int) $this->db->fetchValue('SELECT value FROM sequences WHERE name = :n AND year = :y FOR UPDATE', ['n' => $name, 'y' => $year]) + 1;
        $this->db->run('UPDATE sequences SET value = :v WHERE name = :n AND year = :y', ['v' => $value, 'n' => $name, 'y' => $year]);
        return Reference::format($prefix, $year, $value);
    }
}
