<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Shared\RuleViolation;
use App\Repositories\MasterRepository;
use PDOException;

/** Création / modification d'une fiche du référentiel, journalisée dans la même transaction. */
final class MasterDataService
{
    public function __construct(private Database $db, private ActivityLogger $logger)
    {
    }

    /** @param array<string, mixed> $data */
    public function save(MasterRepository $repository, ?int $id, array $data): int
    {
        try {
            return $this->db->transaction(function () use ($repository, $id, $data): int {
                $type = $repository->entityType();
                if ($id === null) {
                    $newId = $repository->insert($data);
                    $this->logger->log($type, $newId, 'created', [], $data);
                    return $newId;
                }
                $before = $repository->get($id);
                $repository->update($id, $data);
                $this->logger->log($type, $id, 'updated', array_intersect_key($before, $data), $data);
                return $id;
            });
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'Duplicate')) {
                throw new RuleViolation('validation.code_taken');
            }
            throw $e;
        }
    }
}
