<?php

declare(strict_types=1);

namespace App\Security;

enum Role: string
{
    /** Administrateur : tout, y compris utilisateurs et paramètres. */
    case Admin = 'admin';
    /** Direction : vue complète, approuve les quotations à marge faible. */
    case Manager = 'manager';
    /** Commercial : RFQ, quotations, relation client. */
    case Sales = 'sales';
    /** Achats : sourcing, offres et PO fournisseurs. */
    case Purchasing = 'purchasing';
    /** Logistique : expéditions, transitaires, POD. */
    case Logistics = 'logistics';
    /** Finance : factures, paiements, relevés. */
    case Finance = 'finance';
    /** Lecture seule. */
    case Viewer = 'viewer';

    public function labelKey(): string
    {
        return 'role.' . $this->value;
    }
}
