<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Invoicing\Receivables;

/** Graphiques du tableau de bord : requêtes agrégées, une par graphique. */
final class DashboardService
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Activité commerciale des 6 derniers mois : RFQ reçues, devis envoyés (premier envoi), PO clients reçus.
     *
     * @return list<array{month: string, rfqs: int, quotes: int, orders: int}>
     */
    public function activity(int $months = 6): array
    {
        $start = date('Y-m-01', strtotime('-' . ($months - 1) . ' months', strtotime(date('Y-m-01'))));
        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $series[date('Y-m', strtotime("+{$i} months", strtotime($start)))] = ['rfqs' => 0, 'quotes' => 0, 'orders' => 0];
        }
        $count = function (string $sql, string $key) use (&$series, $start): void {
            foreach ($this->db->fetchAll($sql, ['start' => $start]) as $r) {
                if (isset($series[$r['m']])) {
                    $series[$r['m']][$key] = (int) $r['n'];
                }
            }
        };
        $count("SELECT DATE_FORMAT(received_on, '%Y-%m') AS m, COUNT(*) AS n FROM rfqs WHERE received_on >= :start GROUP BY m", 'rfqs');
        $count("SELECT DATE_FORMAT(f.first_sent, '%Y-%m') AS m, COUNT(*) AS n
                  FROM (SELECT MIN(sent_at) AS first_sent FROM quotations WHERE sent_at IS NOT NULL GROUP BY rfq_id) f
                 WHERE f.first_sent >= :start GROUP BY m", 'quotes');
        $count("SELECT DATE_FORMAT(received_on, '%Y-%m') AS m, COUNT(*) AS n FROM client_orders WHERE status <> 'cancelled' AND received_on >= :start GROUP BY m", 'orders');
        $out = [];
        foreach ($series as $month => $v) {
            $out[] = ['month' => $month] + $v;
        }
        return $out;
    }

    /** @return array<string, int> expéditions actives par étape, plus livrées sur 30 jours */
    public function shipmentStages(): array
    {
        $stages = ['planned' => 0, 'picked_up' => 0, 'in_transit' => 0, 'at_border' => 0, 'cleared' => 0, 'delivered' => 0];
        foreach ($this->db->fetchAll(
            "SELECT status, COUNT(*) AS n FROM shipments
              WHERE status NOT IN ('delivered', 'cancelled') OR (status = 'delivered' AND delivered_at >= NOW() - INTERVAL 30 DAY)
              GROUP BY status"
        ) as $r) {
            $stages[$r['status']] = (int) $r['n'];
        }
        return $stages;
    }

    /**
     * Créances clients par ancienneté (USD).
     *
     * @param list<array<string, mixed>> $openInvoices factures ouvertes (InvoiceRepository::list(['status' => 'open']))
     * @return array<string, int>
     */
    public function aging(array $openInvoices): array
    {
        return Receivables::aging(array_map(static fn (array $i): array => ['due_date' => (string) $i['due_date'], 'balance' => (int) $i['balance']], $openInvoices), date('Y-m-d'));
    }
}
