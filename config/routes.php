<?php

declare(strict_types=1);

use App\Controllers\AccountingExportController;
use App\Controllers\AuthController;
use App\Controllers\ClientController;
use App\Controllers\ClientOrderController;
use App\Controllers\CreditNoteController;
use App\Controllers\SupplierOrderController;
use App\Controllers\DashboardController;
use App\Controllers\FileController;
use App\Controllers\FinanceDashboardController;
use App\Controllers\FollowupController;
use App\Controllers\InvoiceController;
use App\Controllers\PaymentController;
use App\Controllers\StatementController;
use App\Controllers\SupplierInvoiceController;
use App\Controllers\SupplierPaymentController;
use App\Controllers\SupplierStatementController;
use App\Controllers\UnitController;
use App\Controllers\TrackingController;
use App\Controllers\ReportController;
use App\Controllers\ShipmentController;
use App\Controllers\HealthController;
use App\Controllers\ItemController;
use App\Controllers\LocaleController;
use App\Controllers\PlaceholderController;
use App\Controllers\QuotationController;
use App\Controllers\RfqController;
use App\Controllers\SettingsController;
use App\Controllers\SourcingController;
use App\Controllers\SupplierController;
use App\Core\Router;

// Toute route exige une session, sauf public: true. La permission est vérifiée par le routeur (403).

return static function (Router $r): void {
    $r->get('/health', [HealthController::class, 'show'], public: true);
    $r->get('/login', [AuthController::class, 'showLogin'], public: true);
    $r->post('/login', [AuthController::class, 'login'], public: true);
    $r->post('/logout', [AuthController::class, 'logout']);
    $r->post('/locale', [LocaleController::class, 'switch'], public: true);

    $r->get('/', [DashboardController::class, 'index'], 'dashboard.view');
    $r->get('/dashboard/panels/{key:[a-z_]+\.[a-z_]+}', [DashboardController::class, 'panel'], 'dashboard.view');

    // Suivi commercial et relances (types de relance filtrés selon les droits de chacun)
    $r->get('/tracking', [TrackingController::class, 'index'], 'rfqs.view');
    $r->get('/followups', [FollowupController::class, 'index'], 'dashboard.view');
    $r->post('/followups', [FollowupController::class, 'store'], 'dashboard.view');

    // Ventes : RFQ → sourcing → quotation
    $r->get('/rfqs', [RfqController::class, 'index'], 'rfqs.view');
    $r->get('/rfqs/new', [RfqController::class, 'create'], 'rfqs.manage');
    $r->post('/rfqs', [RfqController::class, 'store'], 'rfqs.manage');
    $r->get('/rfqs/{id:\d+}', [RfqController::class, 'show'], 'rfqs.view');
    $r->get('/rfqs/{id:\d+}/edit', [RfqController::class, 'edit'], 'rfqs.manage');
    $r->post('/rfqs/{id:\d+}', [RfqController::class, 'update'], 'rfqs.manage');
    $r->post('/rfqs/{id:\d+}/close', [RfqController::class, 'close'], 'rfqs.manage');

    $r->post('/rfqs/{id:\d+}/sourcing', [SourcingController::class, 'request'], 'sourcing.manage');
    $r->get('/sourcing/{id:\d+}', [SourcingController::class, 'offerForm'], 'sourcing.manage');
    $r->post('/sourcing/{id:\d+}', [SourcingController::class, 'saveOffer'], 'sourcing.manage');
    $r->post('/sourcing/{id:\d+}/decline', [SourcingController::class, 'decline'], 'sourcing.manage');
    $r->get('/sourcing/{id:\d+}/print', [SourcingController::class, 'print'], 'rfqs.view');
    $r->get('/sourcing/{id:\d+}/view', [SourcingController::class, 'offerView'], 'rfqs.view');

    $r->post('/rfqs/{id:\d+}/quotations', [QuotationController::class, 'store'], 'quotations.manage');
    $r->get('/quotations', [QuotationController::class, 'index'], 'quotations.view');
    $r->get('/quotations/{id:\d+}', [QuotationController::class, 'show'], 'quotations.view');
    $r->get('/quotations/{id:\d+}/edit', [QuotationController::class, 'edit'], 'quotations.manage');
    $r->post('/quotations/{id:\d+}', [QuotationController::class, 'update'], 'quotations.manage');
    $r->post('/quotations/{id:\d+}/status', [QuotationController::class, 'transition'], 'quotations.manage');
    $r->post('/quotations/{id:\d+}/revise', [QuotationController::class, 'revise'], 'quotations.manage');
    $r->get('/quotations/{id:\d+}/print', [QuotationController::class, 'print'], 'quotations.view');

    // Commandes : PO client (depuis la quotation) → PO fournisseurs
    $r->get('/quotations/{id:\d+}/client-order/new', [ClientOrderController::class, 'create'], 'client_orders.manage');
    $r->post('/quotations/{id:\d+}/client-order', [ClientOrderController::class, 'store'], 'client_orders.manage');
    $r->get('/client-orders', [ClientOrderController::class, 'index'], 'client_orders.view');
    $r->get('/client-orders/{id:\d+}', [ClientOrderController::class, 'show'], 'client_orders.view');
    $r->get('/client-orders/{id:\d+}/edit', [ClientOrderController::class, 'edit'], 'client_orders.manage');
    $r->post('/client-orders/{id:\d+}', [ClientOrderController::class, 'update'], 'client_orders.manage');
    $r->post('/client-orders/{id:\d+}/finish', [ClientOrderController::class, 'finish'], 'client_orders.manage');

    $r->post('/client-orders/{id:\d+}/supplier-orders', [SupplierOrderController::class, 'prepare'], 'supplier_orders.manage');
    $r->get('/supplier-orders', [SupplierOrderController::class, 'index'], 'supplier_orders.view');
    $r->get('/supplier-orders/new', [SupplierOrderController::class, 'create'], 'supplier_orders.manage');
    $r->post('/supplier-orders', [SupplierOrderController::class, 'store'], 'supplier_orders.manage');
    $r->get('/supplier-orders/{id:\d+}', [SupplierOrderController::class, 'show'], 'supplier_orders.view');
    $r->get('/supplier-orders/{id:\d+}/edit', [SupplierOrderController::class, 'edit'], 'supplier_orders.manage');
    $r->post('/supplier-orders/{id:\d+}', [SupplierOrderController::class, 'update'], 'supplier_orders.manage');
    $r->post('/supplier-orders/{id:\d+}/status', [SupplierOrderController::class, 'transition'], 'supplier_orders.manage');
    $r->get('/supplier-orders/{id:\d+}/print', [SupplierOrderController::class, 'print'], 'supplier_orders.view');

    // Logistique : expéditions, jalons, POD
    $r->get('/shipments', [ShipmentController::class, 'index'], 'shipments.view');
    $r->get('/shipments/new', [ShipmentController::class, 'create'], 'shipments.manage');
    $r->post('/shipments', [ShipmentController::class, 'store'], 'shipments.manage');
    $r->get('/shipments/{id:\d+}', [ShipmentController::class, 'show'], 'shipments.view');
    $r->post('/shipments/{id:\d+}/milestones', [ShipmentController::class, 'milestone'], 'shipments.manage');
    $r->post('/shipments/{id:\d+}/pod', [ShipmentController::class, 'pod'], 'shipments.manage');
    $r->get('/shipments/{id:\d+}/delivery-note', [ShipmentController::class, 'deliveryNote'], 'shipments.view');
    $r->post('/shipments/{id:\d+}/deliver', [ShipmentController::class, 'declareDelivery'], 'shipments.manage');
    $r->post('/shipments/{id:\d+}/cancel', [ShipmentController::class, 'cancel'], 'shipments.manage');
    $r->post('/shipments/{id:\d+}/documents', [ShipmentController::class, 'addDocument'], 'shipments.manage');
    $r->post('/shipments/{id:\d+}/documents/required', [ShipmentController::class, 'requiredDocuments'], 'shipments.manage');
    $r->post('/shipments/{id:\d+}/documents/{doc:\d+}/delete', [ShipmentController::class, 'removeDocument'], 'shipments.manage');
    $r->get('/files/{id:\d+}', [FileController::class, 'show'], 'shipments.view');

    // Finance (clients) : factures sur livré, encaissements, relevés
    $r->get('/client-orders/{id:\d+}/invoices/new', [InvoiceController::class, 'create'], 'invoices.manage');
    $r->post('/client-orders/{id:\d+}/invoices', [InvoiceController::class, 'store'], 'invoices.manage');
    $r->get('/invoices', [InvoiceController::class, 'index'], 'invoices.view');
    $r->get('/invoices/{id:\d+}', [InvoiceController::class, 'show'], 'invoices.view');
    $r->get('/invoices/{id:\d+}/edit', [InvoiceController::class, 'edit'], 'invoices.manage');
    $r->post('/invoices/{id:\d+}', [InvoiceController::class, 'update'], 'invoices.manage');
    $r->post('/invoices/{id:\d+}/issue', [InvoiceController::class, 'issue'], 'invoices.manage');
    $r->post('/invoices/{id:\d+}/delete', [InvoiceController::class, 'delete'], 'invoices.manage');
    $r->post('/invoices/{id:\d+}/cancel', [InvoiceController::class, 'cancel'], 'invoices.manage');
    $r->get('/invoices/{id:\d+}/print', [InvoiceController::class, 'print'], 'invoices.view');
    $r->get('/invoices/{id:\d+}/credit-notes/new', [CreditNoteController::class, 'create'], 'invoices.manage');
    $r->post('/invoices/{id:\d+}/credit-notes', [CreditNoteController::class, 'store'], 'invoices.manage');
    $r->get('/credit-notes', [CreditNoteController::class, 'index'], 'invoices.view');
    $r->get('/credit-notes/{id:\d+}', [CreditNoteController::class, 'show'], 'invoices.view');
    $r->post('/credit-notes/{id:\d+}/allocate', [CreditNoteController::class, 'allocate'], 'invoices.manage');
    $r->get('/credit-notes/{id:\d+}/print', [CreditNoteController::class, 'print'], 'invoices.view');
    $r->get('/payments', [PaymentController::class, 'index'], 'payments.view');
    $r->get('/payments/new', [PaymentController::class, 'create'], 'payments.manage');
    $r->post('/payments', [PaymentController::class, 'store'], 'payments.manage');
    $r->get('/payments/{id:\d+}', [PaymentController::class, 'show'], 'payments.view');
    $r->post('/payments/{id:\d+}/allocate', [PaymentController::class, 'allocate'], 'payments.manage');
    $r->get('/statements', [StatementController::class, 'index'], 'invoices.view');
    $r->get('/statements/{id:\d+}', [StatementController::class, 'show'], 'invoices.view');
    $r->get('/statements/{id:\d+}/print', [StatementController::class, 'print'], 'invoices.view');
    $r->get('/supplier-orders/{id:\d+}/invoices/new', [SupplierInvoiceController::class, 'create'], 'supplier_invoices.manage');
    $r->post('/supplier-orders/{id:\d+}/invoices', [SupplierInvoiceController::class, 'store'], 'supplier_invoices.manage');
    $r->get('/supplier-invoices', [SupplierInvoiceController::class, 'index'], 'supplier_invoices.view');
    $r->get('/supplier-invoices/logistics/new', [SupplierInvoiceController::class, 'createLogistics'], 'supplier_invoices.manage');
    $r->post('/supplier-invoices/logistics', [SupplierInvoiceController::class, 'storeLogistics'], 'supplier_invoices.manage');
    $r->get('/supplier-invoices/{id:\d+}', [SupplierInvoiceController::class, 'show'], 'supplier_invoices.view');
    $r->post('/supplier-invoices/{id:\d+}/recheck', [SupplierInvoiceController::class, 'recheck'], 'supplier_invoices.manage');
    $r->post('/supplier-invoices/{id:\d+}/approve', [SupplierInvoiceController::class, 'approve'], 'supplier_invoices.approve');
    $r->post('/supplier-invoices/{id:\d+}/cancel', [SupplierInvoiceController::class, 'cancel'], 'supplier_invoices.manage');
    $r->get('/supplier-payments', [SupplierPaymentController::class, 'index'], 'supplier_payments.view');
    $r->get('/supplier-payments/new', [SupplierPaymentController::class, 'create'], 'supplier_payments.manage');
    $r->post('/supplier-payments', [SupplierPaymentController::class, 'store'], 'supplier_payments.manage');
    $r->get('/supplier-payments/{id:\d+}', [SupplierPaymentController::class, 'show'], 'supplier_payments.view');
    $r->post('/supplier-payments/{id:\d+}/allocate', [SupplierPaymentController::class, 'allocate'], 'supplier_payments.manage');
    $r->get('/supplier-statements', [SupplierStatementController::class, 'index'], 'supplier_payments.view');
    $r->get('/supplier-statements/{id:\d+}', [SupplierStatementController::class, 'show'], 'supplier_payments.view');
    $r->get('/reports', [ReportController::class, 'index'], 'reports.view');
    $r->get('/finance', [FinanceDashboardController::class, 'index'], 'reports.view');
    $r->get('/exports', [AccountingExportController::class, 'index'], 'reports.view');

    // Référentiel
    foreach (['clients' => ClientController::class, 'suppliers' => SupplierController::class, 'items' => ItemController::class, 'units' => UnitController::class, 'doctypes' => \App\Controllers\DocTypeController::class] as $section => $controller) {
        $r->get("/{$section}", [$controller, 'index'], "{$section}.view");
        $r->get("/{$section}/new", [$controller, 'create'], "{$section}.manage");
        $r->post("/{$section}", [$controller, 'store'], "{$section}.manage");
        $r->get("/{$section}/{id:\\d+}/edit", [$controller, 'edit'], "{$section}.manage");
        $r->post("/{$section}/{id:\\d+}", [$controller, 'update'], "{$section}.manage");
    }
    $r->get('/clients/{id:\d+}', [ClientController::class, 'show'], 'clients.view');

    // Paramètres
    $r->get('/settings/company', [SettingsController::class, 'company'], 'settings.company');
    $r->post('/settings/company', [SettingsController::class, 'saveCompany'], 'settings.company');
    $r->get('/settings/currencies', [SettingsController::class, 'currencies'], 'settings.currencies');
    $r->post('/settings/currencies/rates', [SettingsController::class, 'saveRate'], 'settings.currencies');

    // Modules des phases suivantes (docs/ARCHITECTURE.md §9) : page d'attente explicite, mêmes permissions.
    foreach (require BASE_PATH . '/config/menu.php' as $section) {
        foreach ($section['items'] as $item) {
            if (!$r->has('GET', $item['path'])) {
                $r->get($item['path'], [PlaceholderController::class, 'show'], $item['perm']);
            }
        }
    }
};
