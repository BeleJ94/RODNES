<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Fil des documents d'une affaire : RFQ client → offres fournisseurs → devis → PO fournisseurs → PO client
 * → expéditions → factures fournisseurs → factures clients. Reconstitué à partir de n'importe quel document
 * (la RFQ et/ou les PO clients servent d'ancres), pour se repérer et naviguer d'un document à l'autre.
 */
final class DocumentChain
{
    /** Étapes dans l'ordre d'affichage : clé → [icône, permission de consultation]. */
    public const STAGES = [
        'rfq' => ['inbox', 'rfqs.view'],
        'supplier_quote' => ['file-input', 'rfqs.view'],
        'quotation' => ['file-text', 'quotations.view'],
        'supplier_order' => ['shopping-cart', 'supplier_orders.view'],
        'client_order' => ['file-check', 'client_orders.view'],
        'shipment' => ['truck', 'shipments.view'],
        'supplier_invoice' => ['file-down', 'supplier_invoices.view'],
        'client_invoice' => ['receipt', 'invoices.view'],
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * @return array<string, list<array{id: int, ref: string, sub: string, url: string}>> étape → documents
     */
    public function for(string $type, int $id): array
    {
        [$rfqIds, $orderIds] = $this->anchors($type, $id);
        if ($rfqIds === [] && $orderIds === []) {
            return [];
        }
        // Les PO clients issus de la RFQ complètent les ancres, et inversement.
        if ($rfqIds !== []) {
            $orderIds = array_merge($orderIds, $this->ids('SELECT id FROM client_orders WHERE rfq_id IN (' . $this->in($rfqIds) . ')', $rfqIds));
        }
        if ($orderIds !== []) {
            $rfqIds = array_merge($rfqIds, $this->ids(
                'SELECT COALESCE(co.rfq_id, q.rfq_id) FROM client_orders co LEFT JOIN quotations q ON q.id = co.quotation_id
                  WHERE co.id IN (' . $this->in($orderIds) . ') AND COALESCE(co.rfq_id, q.rfq_id) IS NOT NULL',
                $orderIds,
            ));
        }
        $rfqIds = array_values(array_unique($rfqIds));
        $orderIds = array_values(array_unique($orderIds));

        $chain = array_fill_keys(array_keys(self::STAGES), []);
        if ($rfqIds !== []) {
            $in = $this->in($rfqIds);
            $chain['rfq'] = $this->rows("SELECT r.id, r.reference ref, COALESCE(r.client_reference, '') sub FROM rfqs r WHERE r.id IN ($in) ORDER BY r.id", $rfqIds, '/rfqs/');
            $chain['supplier_quote'] = $this->rows(
                "SELECT sq.id, sq.reference ref, s.name sub FROM supplier_quotes sq JOIN suppliers s ON s.id = sq.supplier_id WHERE sq.rfq_id IN ($in) ORDER BY sq.id",
                $rfqIds,
                '/sourcing/',
                '/view',
            );
            $chain['quotation'] = $this->rows(
                "SELECT q.id, CONCAT(q.reference, ' v', q.version) ref, q.status sub FROM quotations q WHERE q.rfq_id IN ($in) ORDER BY q.reference, q.version",
                $rfqIds,
                '/quotations/',
            );
            foreach ($chain['quotation'] as &$quote) {
                $quote['sub'] = t('quotation_status.' . $quote['sub']);
            }
            unset($quote);
        }
        if ($orderIds !== []) {
            $in = $this->in($orderIds);
            $chain['client_order'] = $this->rows("SELECT id, reference ref, COALESCE(client_po_number, '') sub FROM client_orders WHERE id IN ($in) ORDER BY id", $orderIds, '/client-orders/');
            $chain['supplier_order'] = $this->rows(
                "SELECT so.id, so.reference ref, s.name sub FROM supplier_orders so JOIN suppliers s ON s.id = so.supplier_id WHERE so.client_order_id IN ($in) ORDER BY so.id",
                $orderIds,
                '/supplier-orders/',
            );
            $chain['shipment'] = $this->rows(
                "SELECT DISTINCT sh.id, sh.reference ref, COALESCE(sh.tracking_reference, '') sub FROM shipments sh
                   JOIN shipment_lines sl ON sl.shipment_id = sh.id
                   JOIN supplier_order_lines sol ON sol.id = sl.supplier_order_line_id
                   JOIN supplier_orders so ON so.id = sol.supplier_order_id
                  WHERE so.client_order_id IN ($in) ORDER BY sh.id",
                $orderIds,
                '/shipments/',
            );
            $chain['supplier_invoice'] = $this->rows(
                "SELECT si.id, si.reference ref, COALESCE(si.supplier_invoice_number, '') sub FROM supplier_invoices si
                   JOIN supplier_orders so ON so.id = si.supplier_order_id WHERE so.client_order_id IN ($in) ORDER BY si.id",
                $orderIds,
                '/supplier-invoices/',
            );
            $chain['client_invoice'] = $this->rows("SELECT id, reference ref, '' sub FROM client_invoices WHERE client_order_id IN ($in) ORDER BY id", $orderIds, '/invoices/');
        }
        return $chain;
    }

    /** @return array{0: list<int>, 1: list<int>} RFQ et PO clients d'où part la reconstitution */
    private function anchors(string $type, int $id): array
    {
        $one = fn (string $sql): array => $this->ids($sql, [$id]);
        return match ($type) {
            'rfq' => [[$id], []],
            'supplier_quote' => [$one('SELECT rfq_id FROM supplier_quotes WHERE id = :p0'), []],
            'quotation' => [$one('SELECT rfq_id FROM quotations WHERE id = :p0'), []],
            'client_order' => [[], [$id]],
            'supplier_order' => [[], $one('SELECT client_order_id FROM supplier_orders WHERE id = :p0')],
            'shipment' => [[], $one(
                'SELECT DISTINCT so.client_order_id FROM shipment_lines sl
                   JOIN supplier_order_lines sol ON sol.id = sl.supplier_order_line_id
                   JOIN supplier_orders so ON so.id = sol.supplier_order_id WHERE sl.shipment_id = :p0',
            )],
            'supplier_invoice' => [[], $one('SELECT so.client_order_id FROM supplier_invoices si JOIN supplier_orders so ON so.id = si.supplier_order_id WHERE si.id = :p0')],
            'client_invoice' => [[], $one('SELECT client_order_id FROM client_invoices WHERE id = :p0')],
            default => [[], []],
        };
    }

    /**
     * @param list<int> $params
     * @return list<array{id: int, ref: string, sub: string, url: string}>
     */
    private function rows(string $sql, array $params, string $prefix, string $suffix = ''): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'ref' => (string) $r['ref'],
            'sub' => (string) $r['sub'],
            'url' => url($prefix . $r['id'] . $suffix),
        ], $this->db->fetchAll($sql, self::named($params)));
    }

    /**
     * @param list<int> $params
     * @return list<int>
     */
    private function ids(string $sql, array $params): array
    {
        return array_map(static fn (array $r): int => (int) array_values($r)[0], $this->db->fetchAll($sql, self::named($params)));
    }

    /**
     * @param list<int> $ids
     * @return array<string, int> paramètres nommés :p0, :p1… (cf. in())
     */
    private static function named(array $ids): array
    {
        return array_combine(array_map(static fn (int $i): string => 'p' . $i, array_keys($ids)), $ids);
    }

    /** @param list<int> $ids */
    private function in(array $ids): string
    {
        return implode(',', array_map(static fn (int $i): string => ':p' . $i, array_keys($ids)));
    }
}
