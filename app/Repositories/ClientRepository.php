<?php

declare(strict_types=1);

namespace App\Repositories;

final class ClientRepository extends MasterRepository
{
    protected const TABLE = 'clients';

    protected const COLUMNS = [
        'code', 'customer_number', 'name', 'rccm', 'nif', 'id_nat', 'address', 'city', 'contact_name', 'contact_email', 'contact_phone',
        'payment_terms_days', 'rfq_response_days', 'quote_validity_days', 'vat_applicable', 'is_active', 'notes',
    ];

    /** @return list<array<string, mixed>> clients avec compteurs d'affaires */
    public function withActivity(): array
    {
        return $this->db->fetchAll(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM rfqs r WHERE r.client_id = c.id AND r.status IN ('received', 'sourcing', 'quoted')) AS open_rfqs,
                    (SELECT COUNT(*) FROM rfqs r WHERE r.client_id = c.id) AS total_rfqs
               FROM clients c ORDER BY c.name"
        );
    }
}
