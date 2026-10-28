<?php

declare(strict_types=1);

use App\Security\Role as R;

// Permission → rôles autorisés. Vérifiée sur chaque route (403) ; le menu et les boutons masquent ce qui n'est pas permis.

$all = R::cases();

return [
    'dashboard.view' => $all,
    'reports.view' => [R::Admin, R::Manager, R::Finance],

    // Ventes
    'rfqs.view' => $all,
    'rfqs.manage' => [R::Admin, R::Manager, R::Sales],
    'quotations.view' => $all,
    'quotations.manage' => [R::Admin, R::Manager, R::Sales],
    'quotations.approve' => [R::Admin, R::Manager],
    'client_orders.view' => $all,
    'client_orders.manage' => [R::Admin, R::Manager, R::Sales],

    // Achats
    'sourcing.manage' => [R::Admin, R::Manager, R::Sales, R::Purchasing],
    'supplier_orders.view' => $all,
    'supplier_orders.manage' => [R::Admin, R::Manager, R::Purchasing],

    // Logistique
    'shipments.view' => $all,
    'shipments.manage' => [R::Admin, R::Manager, R::Logistics, R::Purchasing],

    // Finance
    'invoices.view' => [R::Admin, R::Manager, R::Finance, R::Viewer],
    'invoices.manage' => [R::Admin, R::Manager, R::Finance],
    'payments.view' => [R::Admin, R::Manager, R::Finance, R::Viewer],
    'payments.manage' => [R::Admin, R::Manager, R::Finance],
    'supplier_invoices.view' => [R::Admin, R::Manager, R::Finance, R::Purchasing, R::Viewer],
    'supplier_invoices.manage' => [R::Admin, R::Manager, R::Finance],
    'supplier_invoices.approve' => [R::Admin, R::Manager],
    'supplier_payments.view' => [R::Admin, R::Manager, R::Finance, R::Viewer],
    'supplier_payments.manage' => [R::Admin, R::Manager, R::Finance],

    // Référentiel
    'clients.view' => $all,
    'clients.manage' => [R::Admin, R::Manager, R::Sales, R::Finance],
    'suppliers.view' => $all,
    'suppliers.manage' => [R::Admin, R::Manager, R::Purchasing, R::Finance],
    'items.view' => $all,
    'items.manage' => [R::Admin, R::Manager, R::Sales, R::Purchasing],
    'units.view' => $all,
    'units.manage' => [R::Admin, R::Manager, R::Purchasing],
    'doctypes.view' => $all,
    'doctypes.manage' => [R::Admin, R::Manager, R::Logistics],

    // Paramètres
    'settings.company' => [R::Admin],
    'settings.currencies' => [R::Admin, R::Manager, R::Finance],
    'settings.users' => [R::Admin],
    'activity.view' => [R::Admin, R::Manager],
];
