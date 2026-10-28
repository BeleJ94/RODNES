<?php

declare(strict_types=1);

// Données de démonstration fictives : devises et taux, clients miniers, fournisseurs, articles,
// RFQ à tous les stades (sourcing, offres, quotations). Passe par les services : mêmes règles qu'en usage réel.
// Refusé si APP_ENV=production ou si des RFQ existent déjà.
// php bin/seed-demo.php

use App\Core\Config;
use App\Core\Database;
use App\Domain\Shared\Fx;
use App\Domain\Shared\Milli;
use App\Domain\Shared\Money;
use App\Services\QuotationService;
use App\Services\RfqService;
use App\Services\SourcingService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$container = require dirname(__DIR__) . '/app/bootstrap.php';
if ($container->get(Config::class)->get('app.env') === 'production') {
    fwrite(STDERR, "Refusé : données de démonstration interdites en production.\n");
    exit(1);
}
$db = $container->get(Database::class);
if ((int) $db->fetchValue('SELECT COUNT(*) FROM rfqs') > 0) {
    fwrite(STDERR, "Des RFQ existent déjà : démonstration non chargée.\n");
    exit(1);
}
$rfqService = $container->get(RfqService::class);
$sourcing = $container->get(SourcingService::class);
$quotations = $container->get(QuotationService::class);

const DEMO_PASSWORD = 'Ethan-Demo-2026!';

$ins = static fn (string $table, array $row): int => $db->insert(
    "INSERT INTO {$table} (" . implode(', ', array_keys($row)) . ') VALUES (:' . implode(', :', array_keys($row)) . ')',
    $row,
);
$day = static fn (string $offset): string => date('Y-m-d', strtotime($offset));

// Devises et taux (valeurs indicatives de démonstration).
foreach (['USD' => 'Dollar américain', 'ZAR' => 'Rand sud-africain', 'ZMW' => 'Kwacha zambien', 'EUR' => 'Euro', 'CNY' => 'Yuan chinois', 'CDF' => 'Franc congolais'] as $code => $name) {
    $db->run('INSERT IGNORE INTO currencies (code, name) VALUES (:c, :n)', ['c' => $code, 'n' => $name]);
}
foreach (['ZAR' => ['18,40', '18,25'], 'ZMW' => ['26,90', '26,50'], 'EUR' => ['0,93', '0,92'], 'CNY' => ['7,18', '7,15'], 'CDF' => ['2840', '2850']] as $code => [$old, $current]) {
    $db->run('INSERT IGNORE INTO exchange_rates (currency_code, rate_date, units_per_usd_micro) VALUES (:c, :d, :r)', ['c' => $code, 'd' => $day('-30 days'), 'r' => Fx::parseRate($old)]);
    if ($code !== 'CDF') {
        $db->run('INSERT IGNORE INTO exchange_rates (currency_code, rate_date, units_per_usd_micro) VALUES (:c, :d, :r)', ['c' => $code, 'd' => $day('-1 day'), 'r' => Fx::parseRate($current)]);
    }
}

// Utilisateurs de démonstration (un par rôle métier).
$hash = password_hash(DEMO_PASSWORD, PASSWORD_ARGON2ID);
$users = [];
foreach ([
    ['direction@demo.local', 'Patrick Ilunga', 'manager'],
    ['commercial@demo.local', 'Grace Kabeya', 'sales'],
    ['achats@demo.local', 'Serge Mbuyi', 'purchasing'],
    ['logistique@demo.local', 'Joseph Mwamba', 'logistics'],
    ['finance@demo.local', 'Aline Mutombo', 'finance'],
] as [$email, $name, $role]) {
    $users[$role] = $ins('users', ['email' => $email, 'name' => $name, 'role' => $role, 'password_hash' => $hash]);
}

$clients = [
    'ALPHA' => $ins('clients', ['code' => 'ALPHA', 'name' => 'Minière Démo Alpha SA', 'city' => 'Kolwezi', 'address' => 'Route de Likasi, site Alpha', 'contact_name' => 'Service Achats Alpha', 'contact_email' => 'procurement@alpha.example', 'payment_terms_days' => 60]),
    'BETA' => $ins('clients', ['code' => 'BETA', 'name' => 'Minière Démo Bêta SARL', 'city' => 'Likasi', 'contact_name' => 'Supply Chain Bêta', 'contact_email' => 'supply@beta.example', 'payment_terms_days' => 45]),
    'GAMMA' => $ins('clients', ['code' => 'GAMMA', 'name' => 'Gamma Copper Processing', 'city' => 'Lubumbashi', 'contact_email' => 'buyers@gamma.example', 'payment_terms_days' => 30, 'vat_applicable' => 0, 'notes' => 'Exonéré de TVA (régime minier) : attestation à joindre aux factures.']),
];
$suppliers = [
    'BSA' => $ins('suppliers', ['code' => 'BSA', 'name' => 'Bearing Supply SA (Pty) Ltd', 'country' => 'ZA', 'currency_code' => 'ZAR', 'contact_email' => 'quotes@bsa.example', 'payment_terms_days' => 30]),
    'CBI' => $ins('suppliers', ['code' => 'CBI', 'name' => 'Copperbelt Industrial Ltd', 'country' => 'ZM', 'currency_code' => 'USD', 'contact_email' => 'sales@cbi.example', 'payment_terms_days' => 30]),
    'SMP' => $ins('suppliers', ['code' => 'SMP', 'name' => 'Shenzhen Mining Parts Co.', 'country' => 'CN', 'currency_code' => 'USD', 'contact_email' => 'export@smp.example', 'payment_terms_days' => 0]),
    'KHY' => $ins('suppliers', ['code' => 'KHY', 'name' => 'Katanga Hydraulique SARL', 'country' => 'CD', 'currency_code' => 'USD', 'contact_email' => 'devis@khy.example', 'payment_terms_days' => 15]),
    'EUP' => $ins('suppliers', ['code' => 'EUP', 'name' => 'Euro Pumps GmbH', 'country' => 'DE', 'currency_code' => 'EUR', 'contact_email' => 'export@europumps.example', 'payment_terms_days' => 30]),
];
$items = [];
foreach ([
    ['BRG-22220', 'Roulement à rotule sur rouleaux 22220 E', 'u', 'SKF', '22220 E'],
    ['BRG-6312', 'Roulement à billes 6312-2RS1', 'u', 'SKF', '6312-2RS1'],
    ['HOS-R13-1', 'Flexible hydraulique R13 1" serti 2 m', 'u', 'Parker', '781-16'],
    ['FIL-1R0749', 'Filtre à carburant CAT 1R-0749', 'u', 'Caterpillar', '1R-0749'],
    ['FIL-1R1808', 'Filtre à huile CAT 1R-1808', 'u', 'Caterpillar', '1R-1808'],
    ['PPE-HLM', 'Casque de sécurité classe E avec jugulaire', 'u', 'MSA', 'V-Gard'],
    ['PPE-GLV', 'Gants de manutention anti-coupure niveau 5', 'pair', 'Ansell', 'HyFlex 11-435'],
    ['PMP-SLR', 'Pompe à boue 6/4 D-AH, garniture caoutchouc', 'u', 'Warman', '6/4 D-AH'],
    ['CBL-35', 'Câble armé 4 × 35 mm²', 'm', 'Nexans', 'SWA 4C35'],
    ['HDPE-110', 'Tube PEHD PN16 Ø110', 'm', null, 'PE100 SDR11'],
] as [$code, $name, $unit, $maker, $part]) {
    $items[$code] = $ins('items', ['code' => $code, 'name' => $name, 'unit' => $unit, 'manufacturer' => $maker, 'part_number' => $part]);
}

$line = static fn (string $item, string $qty, ?string $requiredBy = null) => [
    'item_id' => $items[$item], 'description' => (string) $db->fetchValue('SELECT name FROM items WHERE id = :id', ['id' => $items[$item]]),
    'quantity_milli' => Milli::parse($qty), 'unit' => (string) $db->fetchValue('SELECT unit FROM items WHERE id = :id', ['id' => $items[$item]]),
    'required_by' => $requiredBy,
];
$rfq = static fn (string $client, string $clientRef, string $received, ?string $due, string $site, array $lines, string $owner = 'sales') => $rfqService->create([
    'client_id' => $clients[$client], 'client_reference' => $clientRef, 'received_on' => $received, 'due_at' => $due,
    'buyer_name' => 'Acheteur ' . ucfirst(strtolower($client)), 'buyer_email' => null, 'delivery_site' => $site, 'owner_id' => $users[$owner], 'notes' => null,
], $lines);
/** Offre : prix unitaires dans l'ordre des lignes de la RFQ (null = article non proposé). */
$offer = static function (int $rfqId, string $supplier, string $currency, array $prices, int $lead, string $incoterm) use ($db, $sourcing, $suppliers, $day): void {
    $sourcing->request($rfqId, [$suppliers[$supplier]]);
    $sqId = (int) $db->fetchValue('SELECT id FROM supplier_quotes WHERE rfq_id = :r AND supplier_id = :s', ['r' => $rfqId, 's' => $suppliers[$supplier]]);
    $lineIds = array_map('intval', array_column($db->fetchAll('SELECT id FROM rfq_lines WHERE rfq_id = :r ORDER BY line_no', ['r' => $rfqId]), 'id'));
    $lines = [];
    foreach ($lineIds as $i => $lineId) {
        $lines[$lineId] = isset($prices[$i]) ? ['unit_price_minor' => Money::parse($prices[$i]), 'lead_time_days' => null, 'note' => null] : null;
    }
    $sourcing->recordOffer($sqId, [
        'supplier_reference' => $supplier . '-Q' . (1000 + $rfqId), 'received_on' => $day('-1 day'), 'currency_code' => $currency,
        'incoterm' => $incoterm, 'lead_time_days' => $lead, 'valid_until' => $day('+30 days'), 'notes' => null,
    ], $lines);
};
$backdate = static fn (string $table, int $id, array $set) => $db->run(
    "UPDATE {$table} SET " . implode(', ', array_map(static fn ($c) => "{$c} = :{$c}", array_keys($set))) . ' WHERE id = :id',
    $set + ['id' => $id],
);

// 1. Reçue, réponse attendue après-demain : à traiter.
$rfq('ALPHA', 'ALP-RFQ-55821', $day('-1 day'), $day('+2 days') . ' 12:00:00', 'Site Alpha — magasin central', [
    $line('PPE-HLM', '200'), $line('PPE-GLV', '500'),
]);

// 2. En retard : réponse attendue hier, pas encore chiffrée.
$late = $rfq('BETA', 'BET/2026/0912', $day('-6 days'), $day('-1 day') . ' 16:00:00', 'Usine Bêta — Likasi', [
    $line('CBL-35', '850', $day('+40 days')), $line('HDPE-110', '1200', $day('+40 days')),
]);

// 3. En sourcing : 2 offres reçues (ZAR et USD), 1 fournisseur relancé sans réponse depuis 5 jours.
$sourcingRfq = $rfq('ALPHA', 'ALP-RFQ-55790', $day('-7 days'), $day('+3 days') . ' 10:00:00', 'Concentrateur Alpha', [
    $line('BRG-22220', '12', $day('+30 days')), $line('BRG-6312', '40', $day('+30 days')), $line('HOS-R13-1', '25'),
]);
$offer($sourcingRfq, 'BSA', 'ZAR', ['8 450,00', '1 120,00', null], 21, 'DAP');
$offer($sourcingRfq, 'CBI', 'USD', ['489,00', '58,50', '96,00'], 14, 'DAP');
$sourcing->request($sourcingRfq, [$suppliers['SMP']]);
$db->run("UPDATE supplier_quotes SET requested_on = :d WHERE rfq_id = :r AND status = 'requested'", ['d' => $day('-5 days'), 'r' => $sourcingRfq]);

// 4. Chiffrée : quotation envoyée, validité qui expire dans 3 jours.
$quoted = $rfq('BETA', 'BET/2026/0877', $day('-20 days'), $day('-14 days') . ' 12:00:00', 'Usine Bêta — Likasi', [
    $line('FIL-1R0749', '60'), $line('FIL-1R1808', '60'),
]);
$offer($quoted, 'CBI', 'USD', ['18,40', '14,90'], 7, 'DAP');
$offer($quoted, 'KHY', 'USD', ['21,00', '16,20'], 3, 'DAP');
$q4 = $quotations->createFromRfq($quoted);
$db->run('UPDATE quotation_lines SET extra_unit_usd_minor = 150 WHERE quotation_id = :q', ['q' => $q4]);
$quotations->transition($q4, 'send');
$backdate('quotations', $q4, ['valid_until' => $day('+3 days'), 'sent_at' => $day('-13 days') . ' 15:20:00']);

// 5. Gagnée : quotation acceptée (client exonéré de TVA, pompe en EUR).
$won = $rfq('GAMMA', 'GCP-PR-3310', $day('-45 days'), $day('-38 days') . ' 12:00:00', 'Gamma — usine SX/EW', [
    $line('PMP-SLR', '2'), $line('HOS-R13-1', '10'),
], 'manager');
$offer($won, 'EUP', 'EUR', ['14 800,00', null], 56, 'CIP');
$offer($won, 'KHY', 'USD', ['17 900,00', '104,00'], 30, 'DAP');
$q5 = $quotations->createFromRfq($won);
$quotations->transition($q5, 'send');
$quotations->transition($q5, 'accept');
$backdate('quotations', $q5, ['sent_at' => $day('-37 days') . ' 09:00:00', 'decided_at' => $day('-20 days') . ' 11:00:00']);

// 6. Perdue : prix jugé trop élevé.
$lost = $rfq('ALPHA', 'ALP-RFQ-55102', $day('-60 days'), $day('-55 days') . ' 12:00:00', 'Site Alpha', [$line('CBL-35', '400')]);
$offer($lost, 'CBI', 'USD', ['31,50'], 10, 'DAP');
$q6 = $quotations->createFromRfq($lost);
$quotations->transition($q6, 'send');
$quotations->transition($q6, 'decline', ['reason' => 'price', 'comment' => 'Concurrent local 8 % moins cher']);
$rfqService->close($lost, \App\Domain\Sales\RfqStatus::Lost, 'Concurrent moins cher de 8 %');

// 7. Brouillon à marge faible : pour tester la validation des marges (Paramètres → Société).
$draft = $rfq('GAMMA', 'GCP-PR-3402', $day('-3 days'), $day('+5 days') . ' 12:00:00', 'Gamma — atelier', [$line('BRG-6312', '100')]);
$offer($draft, 'SMP', 'USD', ['41,00'], 45, 'CIF');
$q7 = $quotations->createFromRfq($draft);
$db->run('UPDATE quotation_lines SET unit_price_minor = 4550 WHERE quotation_id = :q', ['q' => $q7]);

// 8. Affaire gagnée commandée : PO client reçu, PO fournisseurs préparés (EUR et USD), l'un envoyé.
$q5Lines = [];
foreach ($db->fetchAll('SELECT id, description, quantity_milli, unit_price_minor FROM quotation_lines WHERE quotation_id = :q', ['q' => $q5]) as $ql) {
    $q5Lines[(int) $ql['id']] = ['description' => $ql['description'], 'quantity_milli' => (int) $ql['quantity_milli'], 'unit_price_minor' => (int) $ql['unit_price_minor']];
}
$clientOrder = $container->get(\App\Services\ClientOrderService::class)->createFromQuotation($q5, [
    'client_po_number' => 'GCP-PO-8842', 'received_on' => $day('-18 days'), 'required_by' => $day('+40 days'),
    'delivery_site' => 'Gamma — usine SX/EW', 'incoterm' => 'DAP', 'payment_terms_days' => 30, 'vat_applicable' => false, 'notes' => null,
], $q5Lines);
$prepared = $container->get(\App\Services\SupplierOrderService::class)->prepareFromClientOrder($clientOrder);
if ($prepared['created'] !== []) {
    $container->get(\App\Services\SupplierOrderService::class)->transition($prepared['created'][0], 'send');
    // 9. La pompe (PO envoyé) est en route : bloquée à la frontière.
    $shipping = $container->get(\App\Services\ShipmentService::class);
    $solIds = array_map('intval', array_column($db->fetchAll('SELECT id FROM supplier_order_lines WHERE supplier_order_id = :id', ['id' => $prepared['created'][0]]), 'id'));
    $shipmentId = $shipping->create((int) $clients['GAMMA'], [
        'carrier_type' => 'forwarder', 'carrier_name' => 'Trans-Kat Logistics', 'tracking_reference' => 'CMR-55017', 'origin' => 'Johannesburg (ZA)',
        'destination' => 'Gamma Copper Processing — usine SX/EW', 'planned_pickup_on' => $day('-6 days'), 'eta' => $day('+2 days'), 'notes' => null,
    ], array_fill_keys($solIds, 2000));
    $shipping->milestone($shipmentId, \App\Domain\Logistics\ShipmentStatus::PickedUp, $day('-5 days') . ' 08:30:00', 'Johannesburg', null);
    $shipping->milestone($shipmentId, \App\Domain\Logistics\ShipmentStatus::AtBorder, $day('-1 day') . ' 17:10:00', 'Kasumbalesa', 'File d\'attente côté zambien');
}
if (isset($prepared['created'][1])) {
    // 10. Le second PO, livré par le fournisseur avec POD : facture client émise et réglée en partie,
    //     facture fournisseur rapprochée (bon à payer).
    $poId = (int) $prepared['created'][1];
    $container->get(\App\Services\SupplierOrderService::class)->transition($poId, 'send');
    $poLines = $db->fetchAll('SELECT id, quantity_milli, unit_price_minor FROM supplier_order_lines WHERE supplier_order_id = :id', ['id' => $poId]);
    $delivery = $shipping->create((int) $clients['GAMMA'], [
        'carrier_type' => 'supplier', 'carrier_name' => null, 'tracking_reference' => 'BL-20931', 'origin' => 'Lubumbashi',
        'destination' => 'Gamma Copper Processing — usine SX/EW', 'planned_pickup_on' => $day('-9 days'), 'eta' => $day('-7 days'), 'notes' => null,
    ], array_column($poLines, 'quantity_milli', 'id'));
    $shipping->milestone($delivery, \App\Domain\Logistics\ShipmentStatus::PickedUp, $day('-9 days') . ' 07:45:00', 'Lubumbashi', null);
    // Livraison déclarée (POD généré), puis POD signé joint.
    $shipping->declareDelivery($delivery, $day('-7 days') . ' 11:20:00', 'Usine SX/EW', null);
    $podFile = (string) tempnam(sys_get_temp_dir(), 'pod');
    file_put_contents($podFile, "%PDF-1.4\n% POD de démonstration ETHAN\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    $shipping->recordPod($delivery, [
        'received_at' => $day('-7 days') . ' 11:20:00', 'receiver_name' => 'P. Mwamba (magasin Gamma)', 'receiver_reference' => 'GRN-40217', 'notes' => null,
    ], array_column($db->fetchAll('SELECT id, quantity_milli FROM shipment_lines WHERE shipment_id = :id', ['id' => $delivery]), 'quantity_milli', 'id'),
        ['name' => 'POD-BL-20931.pdf', 'tmp_name' => $podFile, 'error' => UPLOAD_ERR_OK]);
    @unlink($podFile);

    $invoicing = $container->get(\App\Services\InvoiceService::class);
    $toInvoice = array_filter(array_column($invoicing->invoiceableLines($clientOrder), 'invoiceable_milli', 'id'));
    $invoiceId = $invoicing->createDraft($clientOrder, $toInvoice, null);
    $invoicing->issue($invoiceId, $day('-6 days'));
    $issued = $container->get(\App\Repositories\InvoiceRepository::class)->get($invoiceId);
    $advance = intdiv((int) $issued['total'], 2);
    $container->get(\App\Services\PaymentService::class)->record((int) $clients['GAMMA'], [
        'received_on' => $day('-2 days'), 'amount_minor' => $advance, 'method' => 'bank_transfer', 'bank_reference' => 'RAWBANK-TRF-77310', 'notes' => null,
    ], [$invoiceId => $advance]);

    $order = $container->get(\App\Repositories\SupplierOrderRepository::class)->get($poId);
    $container->get(\App\Services\SupplierInvoiceService::class)->record($poId, [
        'supplier_invoice_number' => 'INV-' . $order['supplier_code'] . '-0457', 'invoice_date' => $day('-6 days'),
        'fx_units_per_usd_micro' => (int) $order['fx_units_per_usd_micro'], 'vat_minor' => 0, 'notes' => null,
    ], array_map(static fn (array $l): array => ['quantity' => (int) $l['quantity_milli'], 'price' => (int) $l['unit_price_minor']], array_column($poLines, null, 'id')), null);
    $supplierInvoice = $container->get(\App\Repositories\SupplierInvoiceRepository::class)->forSupplierOrder($poId)[0];
    $container->get(\App\Services\SupplierPaymentService::class)->record((int) $order['supplier_id'], [
        'paid_on' => $day('-1 day'), 'currency_code' => (string) $order['currency_code'], 'amount_minor' => (int) $supplierInvoice['total_minor'],
        'fx_units_per_usd_micro' => (int) $order['fx_units_per_usd_micro'], 'method' => 'bank_transfer', 'bank_reference' => 'RAWBANK-OUT-11842', 'notes' => null,
    ], [(int) $supplierInvoice['id'] => (int) $supplierInvoice['total_minor']]);

    // 11. Frais logistiques du transitaire sur les deux expéditions (facture en ZAR, non encore payée) : entrent dans la marge réelle.
    $forwarder = $ins('suppliers', ['code' => 'TKL', 'name' => 'Trans-Kat Logistics SARL', 'country' => 'CD', 'currency_code' => 'ZAR', 'contact_email' => 'billing@transkat.example', 'payment_terms_days' => 15]);
    $container->get(\App\Services\SupplierInvoiceService::class)->recordLogistics($forwarder, [
        'supplier_invoice_number' => 'TKL-2026-0311', 'invoice_date' => $day('-3 days'), 'currency_code' => 'ZAR',
        'fx_units_per_usd_micro' => \App\Domain\Shared\Fx::parseRate('18,25'), 'vat_minor' => 0, 'notes' => null,
    ], [
        ['shipment_id' => $delivery, 'cost_type' => 'transport', 'description' => 'Lubumbashi → Kolwezi', 'amount' => 380_000],
        ['shipment_id' => $shipmentId, 'cost_type' => 'transport', 'description' => 'Johannesburg → Kasumbalesa', 'amount' => 1_250_000],
        ['shipment_id' => $shipmentId, 'cost_type' => 'border_fees', 'description' => 'Kasumbalesa', 'amount' => 210_000],
    ], null);
}

echo 'Démonstration chargée : 7 RFQ, 1 PO client, ' . count($prepared['created']) . ' PO fournisseurs, 2 expéditions dont 1 livrée et facturée, ' . count($suppliers) . ' fournisseurs, ' . count($items) . ' articles.' . PHP_EOL;
echo 'Comptes (mot de passe : ' . DEMO_PASSWORD . ') : direction@, commercial@, achats@, logistique@, finance@ — @demo.local' . PHP_EOL;
