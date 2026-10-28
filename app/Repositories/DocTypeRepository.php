<?php

declare(strict_types=1);

namespace App\Repositories;

/** Types de document d'expédition : libellé bilingue, obligatoire ou non par défaut, ordre d'affichage. */
final class DocTypeRepository extends MasterRepository
{
    protected const TABLE = 'shipment_document_types';
    protected const COLUMNS = ['code', 'name', 'name_en', 'is_required', 'sort_order', 'is_active'];

    /** @return list<array<string, mixed>> types avec le nombre de documents fournis, dans l'ordre d'affichage */
    public function all(bool $activeOnly = false): array
    {
        return $this->db->fetchAll(
            'SELECT t.*, (SELECT COUNT(*) FROM shipment_files f WHERE f.doc_type_id = t.id) AS doc_count FROM shipment_document_types t'
            . ($activeOnly ? ' WHERE t.is_active = 1' : '') . ' ORDER BY t.sort_order, t.name'
        );
    }

    /** Libellé dans la langue courante. */
    public static function label(array $type): string
    {
        return is_en() && ($type['name_en'] ?? '') !== '' ? (string) $type['name_en'] : (string) $type['name'];
    }
}
