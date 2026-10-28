<?php

declare(strict_types=1);

// Menu latéral, dans l'ordre du processus opérationnel :
// vendre (RFQ → devis → PO client) → acheter et livrer → facturer et encaisser → payer les fournisseurs → analyser.
// Section : clé de libellé, icône, « step » (numéro d'étape affiché), « pinned » (toujours dépliée, sans titre),
// « collapsed » (repliée par défaut). Chaque entrée : chemin, icône Lucide, clé de libellé, permission requise.
// Les pastilles viennent d'AlertService (clé « nav » de chaque alerte = chemin de l'entrée) ; une section repliée
// affiche la somme des pastilles de ses entrées.

return [
    [
        'section' => 'nav.section.today',
        'pinned' => true,
        'items' => [
            ['path' => '/', 'icon' => 'layout-dashboard', 'label' => 'nav.dashboard', 'perm' => 'dashboard.view'],
            ['path' => '/followups', 'icon' => 'phone-call', 'label' => 'nav.followups', 'perm' => 'dashboard.view'],
        ],
    ],
    [
        'section' => 'nav.section.sell',
        'icon' => 'handshake',
        'step' => 1,
        'items' => [
            ['path' => '/tracking', 'icon' => 'route', 'label' => 'nav.tracking', 'perm' => 'rfqs.view'],
            ['path' => '/rfqs', 'icon' => 'inbox', 'label' => 'nav.rfqs', 'perm' => 'rfqs.view'],
            ['path' => '/quotations', 'icon' => 'file-text', 'label' => 'nav.quotations', 'perm' => 'quotations.view'],
            ['path' => '/client-orders', 'icon' => 'file-check', 'label' => 'nav.client_orders', 'perm' => 'client_orders.view'],
        ],
    ],
    [
        'section' => 'nav.section.supply',
        'icon' => 'truck',
        'step' => 2,
        'items' => [
            ['path' => '/supplier-orders', 'icon' => 'shopping-cart', 'label' => 'nav.supplier_orders', 'perm' => 'supplier_orders.view'],
            ['path' => '/shipments', 'icon' => 'package-check', 'label' => 'nav.shipments', 'perm' => 'shipments.view'],
        ],
    ],
    [
        'section' => 'nav.section.invoice',
        'icon' => 'receipt',
        'step' => 3,
        'items' => [
            ['path' => '/invoices', 'icon' => 'receipt', 'label' => 'nav.invoices', 'perm' => 'invoices.view'],
            ['path' => '/credit-notes', 'icon' => 'receipt-text', 'label' => 'nav.credit_notes', 'perm' => 'invoices.view'],
            ['path' => '/payments', 'icon' => 'wallet', 'label' => 'nav.payments', 'perm' => 'payments.view'],
            ['path' => '/statements', 'icon' => 'scroll-text', 'label' => 'nav.statements', 'perm' => 'invoices.view'],
        ],
    ],
    [
        'section' => 'nav.section.pay',
        'icon' => 'banknote',
        'step' => 4,
        'items' => [
            ['path' => '/supplier-invoices', 'icon' => 'file-input', 'label' => 'nav.supplier_invoices', 'perm' => 'supplier_invoices.view'],
            ['path' => '/supplier-payments', 'icon' => 'send-to-back', 'label' => 'nav.supplier_payments', 'perm' => 'supplier_payments.view'],
            ['path' => '/supplier-statements', 'icon' => 'book-open', 'label' => 'nav.supplier_statements', 'perm' => 'supplier_payments.view'],
        ],
    ],
    [
        'section' => 'nav.section.analyze',
        'icon' => 'chart-column',
        'items' => [
            ['path' => '/finance', 'icon' => 'landmark', 'label' => 'nav.finance', 'perm' => 'reports.view'],
            ['path' => '/reports', 'icon' => 'trending-up', 'label' => 'nav.reports', 'perm' => 'reports.view'],
            ['path' => '/exports', 'icon' => 'file-spreadsheet', 'label' => 'nav.exports', 'perm' => 'reports.view'],
        ],
    ],
    [
        'section' => 'nav.section.reference',
        'icon' => 'database',
        'collapsed' => true,
        'items' => [
            ['path' => '/clients', 'icon' => 'building-2', 'label' => 'nav.clients', 'perm' => 'clients.view'],
            ['path' => '/suppliers', 'icon' => 'factory', 'label' => 'nav.suppliers', 'perm' => 'suppliers.view'],
            ['path' => '/items', 'icon' => 'package', 'label' => 'nav.items', 'perm' => 'items.view'],
            ['path' => '/units', 'icon' => 'ruler', 'label' => 'nav.units', 'perm' => 'units.view'],
            ['path' => '/doctypes', 'icon' => 'folder-check', 'label' => 'nav.doctypes', 'perm' => 'doctypes.view'],
        ],
    ],
    [
        'section' => 'nav.section.settings',
        'icon' => 'settings',
        'collapsed' => true,
        'items' => [
            ['path' => '/settings/company', 'icon' => 'building', 'label' => 'nav.company', 'perm' => 'settings.company'],
            ['path' => '/settings/currencies', 'icon' => 'coins', 'label' => 'nav.currencies', 'perm' => 'settings.currencies'],
            ['path' => '/settings/users', 'icon' => 'users', 'label' => 'nav.users', 'perm' => 'settings.users'],
            ['path' => '/settings/activity', 'icon' => 'history', 'label' => 'nav.activity', 'perm' => 'activity.view'],
        ],
    ],
];
