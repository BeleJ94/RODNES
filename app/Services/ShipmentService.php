<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Domain\Delivery\EventChain;
use App\Domain\Logistics\ShipmentStatus;
use App\Domain\Orders\Coverage;
use App\Domain\Shared\RuleViolation;
use App\Repositories\ShipmentRepository;
use App\Security\Auth;
use RuntimeException;
use Throwable;

/**
 * Expéditions : création (lignes de plusieurs PO fournisseurs d'un même client), jalons, POD, annulation.
 * Chaque jalon et la POD sont scellés dans un journal chaîné (EventChain) : la preuve de livraison est opposable.
 */
final class ShipmentService
{
    private const HEADER = ['carrier_type', 'carrier_name', 'tracking_reference', 'origin', 'destination', 'planned_pickup_on', 'eta', 'notes'];

    public function __construct(
        private Database $db,
        private ShipmentRepository $shipments,
        private ClientOrderService $clientOrders,
        private FileStorage $files,
        private Sequences $sequences,
        private ActivityLogger $logger,
        private Config $config,
        private Auth $auth,
    ) {
    }

    /**
     * @param array<string, mixed> $header
     * @param array<int, int> $lines ligne de PO fournisseur → quantité expédiée (millièmes)
     */
    public function create(int $clientId, array $header, array $lines, array $uploads = [], ?array $requiredTypes = null, array $references = []): int
    {
        return $this->db->transaction(function () use ($clientId, $header, $lines, $uploads, $requiredTypes, $references): int {
            if ($lines === []) {
                throw new RuleViolation('shipments.error_no_lines');
            }
            $shippable = array_column($this->shipments->shippable($clientId), null, 'id');
            foreach ($lines as $solId => $qty) {
                $line = $shippable[$solId] ?? throw new RuleViolation('shipments.error_line_not_shippable');
                if (!Coverage::fits((int) $line['ordered_milli'], (int) $line['shipped_milli'], $qty)) {
                    throw new RuleViolation('shipments.error_over_shipment', ['ref' => $line['supplier_order_reference']]);
                }
            }
            $header = array_intersect_key($header, array_flip(self::HEADER));
            $reference = $this->sequences->next('shipment', 'EXP');
            $id = $this->db->insert(
                'INSERT INTO shipments (reference, client_id, carrier_type, carrier_name, tracking_reference, origin, destination, planned_pickup_on, eta, notes, created_by)
                 VALUES (:reference, :client, :carrier_type, :carrier_name, :tracking_reference, :origin, :destination, :planned_pickup_on, :eta, :notes, :user)',
                $header + ['reference' => $reference, 'client' => $clientId, 'user' => $this->auth->user()?->id],
            );
            foreach ($lines as $solId => $qty) {
                $this->db->run(
                    'INSERT INTO shipment_lines (shipment_id, supplier_order_line_id, quantity_milli) VALUES (:s, :l, :q)',
                    ['s' => $id, 'l' => $solId, 'q' => $qty],
                );
            }
            $this->append($id, 'created', ShipmentStatus::Planned, date('Y-m-d H:i:s'), $header['origin'] ?? null, null, ['lines' => $lines]);
            $this->logger->log('shipment', $id, 'created', [], $header + ['reference' => $reference, 'lines' => count($lines)]);
            $this->attach($id, $uploads);
            // Documents exigés : choix de la saisie, sinon les obligatoires du référentiel.
            $this->setRequired($id, $requiredTypes ?? array_map('intval', array_column($this->db->fetchAll('SELECT id FROM shipment_document_types WHERE is_active = 1 AND is_required = 1'), 'id')), false);
            foreach ($references as $typeId => $reference) {
                $this->addDocument($id, (int) $typeId, $reference, null, null, false);
            }
            return $id;
        });
    }

    /**
     * Livraison effectuée : l'expédition passe « livrée » et le bon de livraison (POD) est généré et numéroté,
     * à imprimer et faire signer ; le POD signé est joint ensuite (recordPod).
     *
     * @return string n° du bon de livraison
     */
    public function declareDelivery(int $id, string $deliveredAt, ?string $location, ?string $note): string
    {
        return $this->db->transaction(function () use ($id, $deliveredAt, $location, $note): string {
            $shipment = $this->shipments->get($id);
            $from = ShipmentStatus::from((string) $shipment['status']);
            if (!$from->canReceivePod()) {
                throw new RuleViolation('shipments.error_pod_status');
            }
            $number = \App\Controllers\ShipmentController::deliveryNoteNumber((string) $shipment['reference']);
            $this->db->run(
                "UPDATE shipments SET status = 'delivered', delivered_at = :at, picked_up_at = COALESCE(picked_up_at, :at2),
                        delivery_note_number = :dn, delivery_note_issued_at = NOW(), delivery_note_issued_by = :user WHERE id = :id",
                ['at' => $deliveredAt, 'at2' => $deliveredAt, 'dn' => $number, 'user' => $this->auth->user()?->id, 'id' => $id],
            );
            $this->append($id, 'delivered', ShipmentStatus::Delivered, $deliveredAt, $location ?? $shipment['destination'], $note, ['delivery_note' => $number]);
            $this->logger->log('shipment', $id, 'delivered', ['status' => $from->value], ['status' => 'delivered', 'delivery_note' => $number]);
            return $number;
        });
    }

    public function milestone(int $id, ShipmentStatus $to, string $occurredAt, ?string $location, ?string $note): void
    {
        $this->db->transaction(function () use ($id, $to, $occurredAt, $location, $note): void {
            $shipment = $this->shipments->get($id);
            $from = ShipmentStatus::from((string) $shipment['status']);
            if (!$from->canAdvanceTo($to)) {
                throw new RuleViolation('common.error_transition');
            }
            $this->db->run(
                'UPDATE shipments SET status = :s, picked_up_at = COALESCE(picked_up_at, :at) WHERE id = :id',
                ['s' => $to->value, 'at' => $occurredAt, 'id' => $id],
            );
            $this->append($id, 'milestone', $to, $occurredAt, $location, $note);
            $this->logger->log('shipment', $id, 'status', ['status' => $from->value], ['status' => $to->value, 'location' => $location]);
        });
    }

    /**
     * POD : document signé obligatoire, quantités reçues par ligne (≤ expédiées : manquants, casse).
     * L'expédition passe en « livrée » et les PO clients concernés recalculent leur statut.
     *
     * @param array{received_at: string, receiver_name: string, receiver_reference: ?string, notes: ?string} $pod
     * @param array<int, int> $received ligne d'expédition → quantité reçue (millièmes)
     * @param array<string, mixed>|null $upload fichier ($_FILES)
     */
    public function recordPod(int $id, array $pod, array $received, ?array $upload): void
    {
        if ($upload === null) {
            throw new RuleViolation('shipments.error_pod_file');
        }
        $stored = null;
        try {
            $this->db->transaction(function () use ($id, $pod, $received, $upload, &$stored): void {
                $shipment = $this->shipments->get($id);
                $from = ShipmentStatus::from((string) $shipment['status']);
                // Le POD signé suit la déclaration de livraison (bon généré, signé par les acteurs).
                if ($from !== ShipmentStatus::Delivered || $shipment['pod_id'] !== null) {
                    throw new RuleViolation('shipments.error_pod_status');
                }
                $lines = $this->shipments->lines($id);
                foreach ($lines as $line) {
                    $qty = $received[(int) $line['id']] ?? null;
                    if ($qty === null || $qty < 0 || $qty > (int) $line['quantity_milli']) {
                        throw new RuleViolation('shipments.error_received_qty', ['description' => $line['description']]);
                    }
                }
                $stored = $this->files->store($upload);
                $this->db->run(
                    'INSERT INTO pods (shipment_id, received_at, receiver_name, receiver_reference, file_id, notes, created_by)
                     VALUES (:s, :at, :name, :ref, :file, :notes, :user)',
                    [
                        's' => $id, 'at' => $pod['received_at'], 'name' => $pod['receiver_name'], 'ref' => $pod['receiver_reference'],
                        'file' => $stored['id'], 'notes' => $pod['notes'], 'user' => $this->auth->user()?->id,
                    ],
                );
                $sealedLines = [];
                foreach ($lines as $line) {
                    $qty = $received[(int) $line['id']];
                    $this->db->run('UPDATE shipment_lines SET received_milli = :q WHERE id = :id', ['q' => $qty, 'id' => (int) $line['id']]);
                    $sealedLines[(string) $line['id']] = $qty;
                }
                $this->append($id, 'pod', ShipmentStatus::Delivered, $pod['received_at'], $shipment['destination'], $pod['notes'], [
                    'receiver' => $pod['receiver_name'], 'receiver_reference' => $pod['receiver_reference'],
                    'file_sha256' => $stored['sha256'], 'received' => $sealedLines,
                ]);
                $this->logger->log('shipment', $id, 'pod', [], ['receiver' => $pod['receiver_name'], 'delivery_note' => $shipment['delivery_note_number']]);
                foreach (array_unique(array_map(static fn (array $l): int => (int) $l['client_order_id'], $lines)) as $clientOrderId) {
                    $this->clientOrders->refreshStatus($clientOrderId);
                }
            });
        } catch (Throwable $e) {
            if ($stored !== null && is_file($stored['path'])) {
                unlink($stored['path']);
            }
            throw $e;
        }
    }

    public function cancel(int $id, ?string $reason): void
    {
        $this->db->transaction(function () use ($id, $reason): void {
            $shipment = $this->shipments->get($id);
            $from = ShipmentStatus::from((string) $shipment['status']);
            if (!$from->canBeCancelled()) {
                throw new RuleViolation('shipments.error_cancel');
            }
            $this->db->run("UPDATE shipments SET status = 'cancelled' WHERE id = :id", ['id' => $id]);
            $this->append($id, 'cancelled', ShipmentStatus::Cancelled, date('Y-m-d H:i:s'), null, $reason);
            $this->logger->log('shipment', $id, 'cancel', ['status' => $from->value], ['status' => 'cancelled', 'reason' => $reason]);
        });
    }

    /** @return int|null id du premier événement altéré, null si le journal est intact */
    public function verify(int $id): ?int
    {
        $rows = $this->shipments->events($id);
        $chain = array_map(fn (array $r): array => [
            'prev_hash' => (string) $r['prev_hash'],
            'hash' => (string) $r['hash'],
            'data' => self::sealed($id, (string) $r['type'], $r['status_to'], (string) $r['occurred_at'], $r['location'], $r['note'],
                $r['payload'] === null ? null : json_decode((string) $r['payload'], true), $r['user_id'] === null ? null : (int) $r['user_id']),
        ], $rows);
        $broken = EventChain::firstBrokenLink($chain, $this->key());
        return $broken === null ? null : (int) $rows[$broken]['id'];
    }

    /** @param array<string, mixed>|null $payload */
    private function append(int $id, string $type, ShipmentStatus $status, string $occurredAt, ?string $location, ?string $note, ?array $payload = null): void
    {
        $previous = (string) ($this->db->fetchValue('SELECT last_event_hash FROM shipments WHERE id = :id FOR UPDATE', ['id' => $id]) ?? EventChain::genesis());
        $userId = $this->auth->user()?->id;
        $hash = EventChain::hash($previous, self::sealed($id, $type, $status->value, $occurredAt, $location, $note, $payload, $userId), $this->key());
        $this->db->run(
            'INSERT INTO shipment_events (shipment_id, type, status_to, occurred_at, location, note, payload, user_id, prev_hash, hash)
             VALUES (:s, :type, :status, :at, :location, :note, :payload, :user, :prev, :hash)',
            [
                's' => $id, 'type' => $type, 'status' => $status->value, 'at' => $occurredAt, 'location' => $location, 'note' => $note,
                'payload' => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'user' => $userId, 'prev' => $previous, 'hash' => $hash,
            ],
        );
        $this->db->run('UPDATE shipments SET last_event_hash = :h WHERE id = :id', ['h' => $hash, 'id' => $id]);
    }

    /**
     * Contenu scellé, sous la forme exacte relue en base (la vérification recalcule le même JSON).
     *
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private static function sealed(int $shipmentId, string $type, ?string $status, string $occurredAt, ?string $location, ?string $note, ?array $payload, ?int $userId): array
    {
        return [
            'shipment_id' => $shipmentId, 'type' => $type, 'status_to' => $status, 'occurred_at' => $occurredAt,
            'location' => $location, 'note' => $note, 'payload' => $payload, 'user_id' => $userId,
        ];
    }

    private function key(): string
    {
        $key = base64_decode((string) $this->config->get('app.key'), true);
        if ($key === false || strlen($key) < 32) {
            throw new RuntimeException('APP_KEY absente ou invalide : php bin/keygen.php');
        }
        return $key;
    }

    /**
     * Documents de transport (liste de colisage, lettre de transport…) : stockés puis rattachés à l'expédition.
     *
     * @param list<array<string, mixed>> $uploads entrées de $_FILES
     */
    private function attach(int $shipmentId, array $uploads): void
    {
        foreach ($uploads as $upload) {
            $file = $this->files->store($upload, (array) $this->config->get('uploads.document_mimes'));
            $this->db->run('INSERT INTO shipment_files (shipment_id, file_id, created_by) VALUES (:s, :f, :u)', ['s' => $shipmentId, 'f' => $file['id'], 'u' => $this->auth->user()?->id]);
        }
        if ($uploads !== []) {
            $this->logger->log('shipment', $shipmentId, 'files_added', [], ['files' => count($uploads)]);
        }
    }

    /**
     * Document fourni : type, référence, date et fichier facultatif (au moins la référence ou le fichier).
     *
     * @param array<string, mixed>|null $upload entrée de $_FILES
     */
    public function addDocument(int $shipmentId, int $typeId, ?string $reference, ?string $date, ?array $upload, bool $log = true): int
    {
        $reference = $reference !== null && trim($reference) !== '' ? trim($reference) : null;
        if ($reference === null && $upload === null) {
            throw new RuleViolation('shipments.docs.error_empty');
        }
        if (!$this->db->fetchValue('SELECT 1 FROM shipment_document_types WHERE id = :id', ['id' => $typeId])) {
            throw new RuleViolation('shipments.docs.error_type');
        }
        $fileId = $upload !== null ? (int) $this->files->store($upload, (array) $this->config->get('uploads.document_mimes'))['id'] : null;
        $id = $this->db->insert(
            'INSERT INTO shipment_files (shipment_id, doc_type_id, reference, document_date, file_id, created_by) VALUES (:s, :t, :r, :d, :f, :u)',
            ['s' => $shipmentId, 't' => $typeId, 'r' => $reference, 'd' => $date, 'f' => $fileId, 'u' => $this->auth->user()?->id],
        );
        if ($log) {
            $this->logger->log('shipment', $shipmentId, 'document_added', [], ['type' => $typeId, 'reference' => $reference, 'file' => $fileId !== null]);
        }
        return $id;
    }

    public function removeDocument(int $shipmentId, int $documentId): void
    {
        $row = $this->db->fetchOne('SELECT * FROM shipment_files WHERE id = :id AND shipment_id = :s', ['id' => $documentId, 's' => $shipmentId]);
        if ($row === null) {
            throw new RuleViolation('shipments.docs.error_type');
        }
        // Le fichier reste stocké (traçabilité) ; seul le rattachement disparaît.
        $this->db->run('DELETE FROM shipment_files WHERE id = :id', ['id' => $documentId]);
        $this->logger->log('shipment', $shipmentId, 'document_removed', ['type' => $row['doc_type_id'], 'reference' => $row['reference']], []);
    }

    /** @param list<int> $typeIds documents exigés pour l'expédition (remplace la liste) */
    public function setRequired(int $shipmentId, array $typeIds, bool $log = true): void
    {
        $this->db->transaction(function () use ($shipmentId, $typeIds, $log): void {
            $this->db->run('DELETE FROM shipment_required_documents WHERE shipment_id = :s', ['s' => $shipmentId]);
            foreach (array_unique($typeIds) as $typeId) {
                $this->db->run(
                    'INSERT INTO shipment_required_documents (shipment_id, doc_type_id) SELECT :s, id FROM shipment_document_types WHERE id = :t',
                    ['s' => $shipmentId, 't' => $typeId],
                );
            }
            if ($log) {
                // Journal : valeurs scalaires uniquement (codes des types exigés).
                $codes = array_column($this->db->fetchAll(
                    'SELECT t.code FROM shipment_required_documents r JOIN shipment_document_types t ON t.id = r.doc_type_id WHERE r.shipment_id = :s ORDER BY t.sort_order',
                    ['s' => $shipmentId],
                ), 'code');
                $this->logger->log('shipment', $shipmentId, 'documents_required', [], ['types' => implode(', ', $codes)]);
            }
        });
    }
}
