<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\Orders\ClientOrderStatus;
use App\Domain\Orders\Coverage;
use App\Domain\Orders\SupplierOrderStatus;
use App\Domain\Shared\RuleViolation;
use App\Repositories\ClientOrderRepository;
use App\Repositories\CurrencyRepository;
use App\Repositories\SupplierOrderRepository;
use App\Repositories\SupplierRepository;
use App\Security\Auth;

/** PO fournisseurs : préparation depuis le PO client, saisie, envoi (taux figé), confirmation, annulation. */
final class SupplierOrderService
{
    public const ACTIONS = ['send', 'confirm', 'cancel'];

    public function __construct(
        private Database $db,
        private SupplierOrderRepository $orders,
        private ClientOrderRepository $clientOrders,
        private SupplierRepository $suppliers,
        private CurrencyRepository $currencies,
        private ClientOrderService $clientOrderService,
        private Sequences $sequences,
        private ActivityLogger $logger,
        private Auth $auth,
    ) {
    }

    /**
     * Brouillons de PO pour tout le reste à commander, un par fournisseur retenu en sourcing,
     * au prix et dans la devise de son offre. Les lignes sans fournisseur retenu restent à commander à la main.
     *
     * @return array{created: list<int>, skipped: int}
     */
    public function prepareFromClientOrder(int $clientOrderId): array
    {
        return $this->db->transaction(function () use ($clientOrderId): array {
            $order = $this->clientOrders->get($clientOrderId);
            $this->assertOpen($order);
            $groups = [];
            $skipped = 0;
            foreach ($this->clientOrders->lines($clientOrderId) as $line) {
                $remaining = (int) $line['quantity_milli'] - (int) $line['allocated_milli'];
                if ($remaining <= 0) {
                    continue;
                }
                $offer = $line['supplier_quote_line_id'] === null ? null : $this->db->fetchOne(
                    'SELECT l.unit_price_minor, q.supplier_id, q.currency_code, q.incoterm, q.lead_time_days
                       FROM supplier_quote_lines l JOIN supplier_quotes q ON q.id = l.supplier_quote_id WHERE l.id = :id',
                    ['id' => (int) $line['supplier_quote_line_id']],
                );
                if ($offer === null) {
                    $skipped++;
                    continue;
                }
                $key = $offer['supplier_id'] . '|' . $offer['currency_code'];
                $groups[$key]['offer'] ??= $offer;
                $groups[$key]['lines'][(int) $line['id']] = [
                    'quantity_milli' => $remaining, 'unit_price_minor' => (int) $offer['unit_price_minor'],
                    'description' => (string) $line['description'], 'unit' => (string) $line['unit'],
                ];
            }
            $created = [];
            foreach ($groups as $group) {
                $offer = $group['offer'];
                $created[] = $this->insertOrder($order, (int) $offer['supplier_id'], (string) $offer['currency_code'], [
                    'incoterm' => $offer['incoterm'],
                    'expected_ready_on' => $offer['lead_time_days'] !== null ? date('Y-m-d', strtotime('+' . (int) $offer['lead_time_days'] . ' days')) : null,
                ], $group['lines']);
            }
            $this->clientOrderService->refreshStatus($clientOrderId);
            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    /**
     * PO saisi à la main : fournisseur, devise et lignes (quantité ≤ reste à commander).
     *
     * @param array<string, mixed> $header currency_code, incoterm, expected_ready_on, notes
     * @param array<int, array{quantity_milli: int, unit_price_minor: int}> $lines ligne client → valeurs
     */
    public function create(int $clientOrderId, int $supplierId, array $header, array $lines): int
    {
        return $this->db->transaction(function () use ($clientOrderId, $supplierId, $header, $lines): int {
            $order = $this->clientOrders->get($clientOrderId);
            $this->assertOpen($order);
            $this->suppliers->get($supplierId);
            $clientLines = array_column($this->clientOrders->lines($clientOrderId), null, 'id');
            $prepared = [];
            foreach ($lines as $clientLineId => $input) {
                $line = $clientLines[$clientLineId] ?? throw new RuleViolation('common.error_transition');
                if (!Coverage::fits((int) $line['quantity_milli'], (int) $line['allocated_milli'], $input['quantity_milli'])) {
                    throw new RuleViolation('supplier_orders.error_over_allocation', ['n' => (int) $line['line_no']]);
                }
                $prepared[$clientLineId] = $input + ['description' => (string) $line['description'], 'unit' => (string) $line['unit']];
            }
            if ($prepared === []) {
                throw new RuleViolation('supplier_orders.error_no_lines');
            }
            $id = $this->insertOrder($order, $supplierId, (string) $header['currency_code'], $header, $prepared);
            $this->clientOrderService->refreshStatus($clientOrderId);
            return $id;
        });
    }

    /**
     * Brouillon uniquement. Quantité 0 = ligne retirée (au moins une ligne doit rester).
     *
     * @param array<string, mixed> $header
     * @param array<int, array{quantity_milli: int, unit_price_minor: int}> $lines id de ligne fournisseur → valeurs
     */
    public function update(int $id, array $header, array $lines): void
    {
        $this->db->transaction(function () use ($id, $header, $lines): void {
            $order = $this->orders->get($id);
            if (!SupplierOrderStatus::from((string) $order['status'])->isEditable()) {
                throw new RuleViolation('supplier_orders.error_not_draft');
            }
            if ($header['currency_code'] !== $order['currency_code'] && $this->currencies->rateFor($header['currency_code']) === null) {
                throw new RuleViolation('sourcing.error_no_rate');
            }
            $this->db->run(
                'UPDATE supplier_orders SET currency_code = :currency, incoterm = :incoterm, expected_ready_on = :ready, delivery_address = :address,
                        payment_terms_days = :terms, notes = :notes WHERE id = :id',
                [
                    'currency' => $header['currency_code'], 'incoterm' => $header['incoterm'], 'ready' => $header['expected_ready_on'],
                    'address' => $header['delivery_address'], 'terms' => $header['payment_terms_days'], 'notes' => $header['notes'], 'id' => $id,
                ],
            );
            $kept = 0;
            foreach ($this->orders->lines($id) as $line) {
                $input = $lines[(int) $line['id']] ?? ['quantity_milli' => (int) $line['quantity_milli'], 'unit_price_minor' => (int) $line['unit_price_minor']];
                if ($input['quantity_milli'] === 0) {
                    $this->db->run('DELETE FROM supplier_order_lines WHERE id = :id', ['id' => (int) $line['id']]);
                    continue;
                }
                $already = $this->orders->allocatedFor((int) $line['client_order_line_id'], $id);
                if (!Coverage::fits((int) $line['client_qty_milli'], $already, $input['quantity_milli'])) {
                    throw new RuleViolation('supplier_orders.error_over_allocation', ['n' => (int) $line['client_line_no']]);
                }
                $this->db->run('UPDATE supplier_order_lines SET quantity_milli = :q, unit_price_minor = :p WHERE id = :id',
                    ['q' => $input['quantity_milli'], 'p' => $input['unit_price_minor'], 'id' => (int) $line['id']]);
                $kept++;
            }
            if ($kept === 0) {
                throw new RuleViolation('supplier_orders.error_no_lines');
            }
            $this->logger->log('supplier_order', $id, 'updated', array_intersect_key($order, $header), $header);
            $this->clientOrderService->refreshStatus((int) $order['client_order_id']);
        });
    }

    /** @return string clé du message de confirmation */
    public function transition(int $id, string $action, ?string $note = null): string
    {
        return $this->db->transaction(function () use ($id, $action, $note): string {
            $order = $this->orders->get($id);
            $from = SupplierOrderStatus::from((string) $order['status']);
            $to = match ($action) {
                'send' => SupplierOrderStatus::Sent,
                'confirm' => SupplierOrderStatus::Confirmed,
                'cancel' => SupplierOrderStatus::Cancelled,
                default => throw new RuleViolation('common.error_transition'),
            };
            if (!$from->canTransitionTo($to)) {
                throw new RuleViolation('common.error_transition');
            }
            $fields = ['status' => $to->value];
            if ($to === SupplierOrderStatus::Sent) {
                // Taux figé à l'émission : le coût réel en USD reste celui du jour de la commande.
                $rate = $this->currencies->rateFor((string) $order['currency_code']) ?? throw new RuleViolation('sourcing.error_no_rate');
                $fields += ['fx_units_per_usd_micro' => $rate, 'issued_on' => date('Y-m-d'), 'sent_at' => date('Y-m-d H:i:s')];
            } elseif ($to === SupplierOrderStatus::Confirmed) {
                $fields += ['confirmed_at' => date('Y-m-d H:i:s'), 'supplier_reference' => $note ?: $order['supplier_reference']];
            } else {
                $fields += ['cancelled_reason' => $note];
            }
            $set = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($fields)));
            $this->db->run("UPDATE supplier_orders SET {$set} WHERE id = :id", $fields + ['id' => $id]);
            $this->logger->log('supplier_order', $id, $action, ['status' => $from->value], $fields);
            $this->clientOrderService->refreshStatus((int) $order['client_order_id']);
            return 'supplier_orders.flash_' . $action;
        });
    }

    /** @param array<string, mixed> $clientOrder */
    private function assertOpen(array $clientOrder): void
    {
        if (ClientOrderStatus::from((string) $clientOrder['status'])->isFinal()) {
            throw new RuleViolation('client_orders.error_final');
        }
    }

    /**
     * @param array<string, mixed> $clientOrder
     * @param array<string, mixed> $header
     * @param array<int, array{quantity_milli: int, unit_price_minor: int, description: string, unit: string}> $lines ligne client → valeurs
     */
    private function insertOrder(array $clientOrder, int $supplierId, string $currency, array $header, array $lines): int
    {
        $supplier = $this->suppliers->get($supplierId);
        $rate = $this->currencies->rateFor($currency) ?? throw new RuleViolation('sourcing.error_no_rate');
        $reference = $this->sequences->next('supplier_order', 'PO');
        $address = $header['delivery_address'] ?? trim($clientOrder['client_name'] . ($clientOrder['delivery_site'] ? ' — ' . $clientOrder['delivery_site'] : ''));
        $id = $this->db->insert(
            'INSERT INTO supplier_orders (reference, supplier_id, client_order_id, currency_code, fx_units_per_usd_micro, expected_ready_on, incoterm,
                                          delivery_address, payment_terms_days, notes, created_by)
             VALUES (:reference, :supplier, :client_order, :currency, :rate, :ready, :incoterm, :address, :terms, :notes, :user)',
            [
                'reference' => $reference, 'supplier' => $supplierId, 'client_order' => (int) $clientOrder['id'], 'currency' => $currency,
                'rate' => $rate, 'ready' => $header['expected_ready_on'] ?? null, 'incoterm' => $header['incoterm'] ?? null,
                'address' => mb_substr($address, 0, 255), 'terms' => (int) ($header['payment_terms_days'] ?? $supplier['payment_terms_days']), 'notes' => $header['notes'] ?? null,
                'user' => $this->auth->user()?->id,
            ],
        );
        $no = 0;
        foreach ($lines as $clientLineId => $line) {
            $this->db->run(
                'INSERT INTO supplier_order_lines (supplier_order_id, line_no, client_order_line_id, description, quantity_milli, unit, unit_price_minor)
                 VALUES (:order, :no, :line, :description, :qty, :unit, :price)',
                [
                    'order' => $id, 'no' => ++$no, 'line' => $clientLineId, 'description' => $line['description'],
                    'qty' => $line['quantity_milli'], 'unit' => $line['unit'], 'price' => $line['unit_price_minor'],
                ],
            );
        }
        $this->logger->log('supplier_order', $id, 'created', [], ['reference' => $reference, 'supplier_id' => $supplierId, 'client_order_id' => (int) $clientOrder['id'], 'lines' => $no]);
        return $id;
    }
}
