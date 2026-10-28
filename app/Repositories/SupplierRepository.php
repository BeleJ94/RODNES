<?php

declare(strict_types=1);

namespace App\Repositories;

final class SupplierRepository extends MasterRepository
{
    protected const TABLE = 'suppliers';

    protected const COLUMNS = [
        'code', 'name', 'country', 'currency_code', 'contact_name', 'contact_email', 'contact_phone',
        'payment_terms_days', 'is_active', 'notes',
    ];

    /** @return list<array<string, mixed>> fournisseurs avec taux de réponse aux demandes de prix */
    public function withActivity(): array
    {
        return $this->db->fetchAll(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM supplier_quotes q WHERE q.supplier_id = s.id) AS requests,
                    (SELECT COUNT(*) FROM supplier_quotes q WHERE q.supplier_id = s.id AND q.status = 'received') AS answered
               FROM suppliers s ORDER BY s.name"
        );
    }
}
