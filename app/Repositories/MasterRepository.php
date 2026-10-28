<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Référentiel simple (clients, fournisseurs, articles) : table et colonnes modifiables déclarées en constantes.
 * Seules les colonnes de COLUMNS peuvent être écrites (pas d'affectation de masse).
 */
abstract class MasterRepository extends Repository
{
    protected const TABLE = '';

    /** @var list<string> */
    protected const COLUMNS = [];

    public function entityType(): string
    {
        return static::TABLE;
    }

    /** @return list<array<string, mixed>> */
    public function all(bool $activeOnly = false): array
    {
        return $this->db->fetchAll('SELECT * FROM ' . static::TABLE . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY name');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM ' . static::TABLE . ' WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        return self::orFail($this->find($id));
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        $data = $this->writable($data);
        $columns = array_keys($data);
        return $this->db->insert(
            'INSERT INTO ' . static::TABLE . ' (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')',
            $data,
        );
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $data = $this->writable($data);
        $set = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($data)));
        $this->db->run('UPDATE ' . static::TABLE . " SET {$set} WHERE id = :id", $data + ['id' => $id]);
    }

    /** @return list<array{id: int, label: string}> entrées actives pour les listes déroulantes */
    public function options(): array
    {
        return $this->db->fetchAll(
            "SELECT id, CONCAT(code, ' — ', name) AS label FROM " . static::TABLE . ' WHERE is_active = 1 ORDER BY name'
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function writable(array $data): array
    {
        return array_intersect_key($data, array_flip(static::COLUMNS));
    }
}
