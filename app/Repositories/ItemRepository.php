<?php

declare(strict_types=1);

namespace App\Repositories;

final class ItemRepository extends MasterRepository
{
    protected const TABLE = 'items';

    protected const COLUMNS = ['code', 'name', 'unit', 'manufacturer', 'part_number', 'description', 'is_active'];

    /** @return list<array{id: int, label: string, unit: string, name: string}> */
    public function pickerOptions(): array
    {
        return $this->db->fetchAll(
            "SELECT id, code, CONCAT(code, ' — ', name) AS label, name, unit, manufacturer, part_number,
                    TRIM(CONCAT_WS(' ', manufacturer, part_number)) AS reference
               FROM items WHERE is_active = 1 ORDER BY name"
        );
    }
}
