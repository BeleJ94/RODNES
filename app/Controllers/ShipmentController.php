<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Xlsx;
use App\Core\Validator;
use App\Domain\Logistics\ShipmentStatus;
use App\Domain\Shared\RuleViolation;
use App\Repositories\ClientRepository;
use App\Repositories\ShipmentRepository;
use App\Services\ActivityLogger;
use App\Services\ShipmentService;

final class ShipmentController extends Controller
{
    public function __construct(
        private ShipmentRepository $shipments,
        private ShipmentService $service,
        private ClientRepository $clients,
        private ActivityLogger $logger,
        private \App\Repositories\SupplierInvoiceRepository $supplierInvoices,
        private \App\Repositories\DocTypeRepository $docTypes,
    ) {
    }

    public function index(Request $request): Response
    {
        if ($request->str('format') === 'xlsx') {
            return $this->export($this->shipments->list(['status' => $request->str('status')]));
        }
        $status = $request->str('status');
        return $this->view('shipments/index', [
            'title' => t('shipments.title'),
            'rows' => $this->shipments->list(['status' => $status]),
            'status' => $status,
            'kpis' => $this->shipments->kpis(),
        ]);
    }

    /** Choix du client (rechargement), puis lignes de ses PO fournisseurs restant à expédier. */
    public function create(Request $request): Response
    {
        $clientId = $request->int('client_id');
        $data = $this->formData($clientId, $request->int('supplier_order_id'), self::returnTo($request->str('return_to')));
        if ($request->str('fragment') === '1' && $data['client'] !== null) {
            return $this->view('shipments/_form', $data + ['modal' => true], null)->withHeader('Cache-Control', 'no-store');
        }
        return $this->view('shipments/form', $data + [
            'title' => t('shipments.new'),
            'clients' => $this->shipments->clientsWithShippable(),
        ]);
    }

    /**
     * Données de la saisie, partagées par la page, la fenêtre et les fiches PO (fenêtre rouverte après erreur).
     *
     * @return array{client: ?array<string, mixed>, lines: list<array<string, mixed>>, preselect: ?int, returnTo: string, modal: bool}
     */
    public function formData(?int $clientId, ?int $preselect, string $returnTo = ''): array
    {
        $client = $clientId !== null ? $this->clients->find($clientId) : null;
        return [
            'client' => $client,
            'docTypes' => $this->docTypes->all(true),
            'lines' => $client !== null ? $this->shipments->shippable((int) $client['id']) : [],
            'preselect' => $preselect,
            'returnTo' => $returnTo,
            'modal' => false,
        ];
    }

    /**
     * Bon de livraison (POD) à imprimer : remis avec la marchandise, signé et tamponné par le client,
     * puis joint à l'expédition comme preuve de livraison.
     */
    public function deliveryNote(Request $request, string $id): Response
    {
        $shipment = $this->shipments->get((int) $id);
        return \App\Security\SecurityHeaders::framableBySelf($this->view('shipments/delivery_note', [
            'title' => $shipment['delivery_note_number'] ?? self::deliveryNoteNumber((string) $shipment['reference']),
            'number' => $shipment['delivery_note_number'] ?? self::deliveryNoteNumber((string) $shipment['reference']),
            'shipment' => $shipment,
            'client' => $this->clients->get((int) $shipment['client_id']),
            'lines' => $this->shipments->lines((int) $id),
            's' => app(\App\Services\Settings::class)->all(),
            'back' => url('/shipments/' . $id),
            'embed' => $request->str('embed') === '1',
        ], 'print'));
    }

    /** Livraison effectuée : POD généré pour signature (fenêtre de la fiche). */
    public function declareDelivery(Request $request, string $id): Response
    {
        $back = '/shipments/' . $id;
        $validator = Validator::make($request->body(), [
            'delivered_at' => 'required|datetime',
            'location' => 'nullable|max:160',
            'note' => 'nullable|max:500',
        ]);
        if (!$validator->passes()) {
            return $this->invalid($back . '?modal=deliver', $request, $validator->errors());
        }
        $d = $validator->validated();
        try {
            $number = $this->service->declareDelivery((int) $id, self::datetime($d['delivered_at']), $d['location'], $d['note']);
        } catch (RuleViolation $e) {
            return $this->refused($back, $e);
        }
        // Le POD vient d'être généré : la fiche l'ouvre en aperçu pour impression.
        return $this->redirect($back . '?dn=1', 'shipments.flash_delivered');
    }

    /** N° du bon de livraison, dérivé de l'expédition : EXP-2026-0007 → BL-2026-0007. */
    public static function deliveryNoteNumber(string $shipmentReference): string
    {
        return (string) preg_replace('/^EXP-/', 'BL-', $shipmentReference);
    }

    /** Document fourni (fenêtre de la fiche) : type, référence, date, fichier facultatif. */
    public function addDocument(Request $request, string $id): Response
    {
        $this->shipments->get((int) $id);
        $back = '/shipments/' . $id;
        $validator = Validator::make($request->body(), ['doc_type_id' => 'required|int', 'reference' => 'nullable|max:80', 'document_date' => 'nullable|date']);
        if (!$validator->passes()) {
            return $this->invalid($back . '?doc=1', $request, $validator->errors());
        }
        $d = $validator->validated();
        try {
            $this->service->addDocument((int) $id, (int) $d['doc_type_id'], $d['reference'] ?? null, $d['document_date'] ?? null, $request->file('doc_file'));
        } catch (RuleViolation $e) {
            return $this->violated($back . '?doc=1', $request, $e);
        }
        return $this->redirect($back . '#documents', 'shipments.docs.flash_added');
    }

    public function removeDocument(Request $request, string $id, string $doc): Response
    {
        try {
            $this->service->removeDocument((int) $id, (int) $doc);
        } catch (RuleViolation $e) {
            return $this->refused('/shipments/' . $id, $e);
        }
        return $this->redirect('/shipments/' . $id . '#documents', 'shipments.docs.flash_removed');
    }

    /** Documents exigés pour cette expédition (cases cochées). */
    public function requiredDocuments(Request $request, string $id): Response
    {
        $this->shipments->get((int) $id);
        $ids = array_map('intval', array_keys(array_filter((array) ($request->body()['required'] ?? []), static fn (mixed $v): bool => $v === '1')));
        $this->service->setRequired((int) $id, $ids);
        return $this->redirect('/shipments/' . $id . '#documents', 'shipments.docs.flash_required');
    }

    /** Fiche d'origine de la fenêtre (PO client ou fournisseur), seule destination de retour admise. */
    private static function returnTo(string $path): string
    {
        return preg_match('#^/(client|supplier)-orders/\d+$#', $path) === 1 ? $path : '';
    }

    public function store(Request $request): Response
    {
        $clientId = (int) $request->int('client_id', 0);
        // Erreur : retour à la fiche d'origine (fenêtre rouverte) ou à la page de saisie.
        $returnTo = self::returnTo($request->str('return_to'));
        $back = $returnTo !== '' ? $returnTo . '?shipment=1' : '/shipments/new?client_id=' . $clientId;
        $validator = Validator::make($request->body(), [
            'carrier_type' => 'required|in:forwarder,supplier',
            'carrier_name' => 'nullable|max:160',
            'tracking_reference' => 'nullable|max:80',
            'origin' => 'nullable|max:160',
            'destination' => 'nullable|max:255',
            'planned_pickup_on' => 'nullable|date',
            'eta' => 'nullable|date',
            'notes' => 'nullable|max:2000',
        ]);
        $errors = $validator->errors();
        $lines = [];
        foreach ($request->rows('lines') as $solId => $row) {
            if (($row['ship'] ?? '') !== '1') {
                continue;
            }
            $v = Validator::make($row, ['quantity' => 'required|qty']);
            if (!$v->passes()) {
                $errors['lines'] = t('client_orders.line_error', ['error' => implode(' ', $v->errors())]);
                continue;
            }
            $lines[(int) $solId] = $v->validated()['quantity'];
        }
        if ($lines === [] && !isset($errors['lines'])) {
            $errors['lines'] = t('shipments.error_no_lines');
        }
        if ($errors !== []) {
            return $this->invalid($back, $request, $errors);
        }
        try {
            $references = array_filter(array_map(static fn (mixed $v): string => mb_substr(trim((string) $v), 0, 80), (array) ($request->body()['doc_ref'] ?? [])), static fn (string $v): bool => $v !== '');
            $required = array_map('intval', array_keys(array_filter((array) ($request->body()['doc_required'] ?? []), static fn (mixed $v): bool => $v === '1')));
            $id = $this->service->create($clientId, $validator->validated(), $lines, self::uploadedFiles($request, 'shipment_files'), $request->str('doc_required_sent') === '1' ? $required : null, $references);
        } catch (RuleViolation $e) {
            return $this->violated($back, $request, $e);
        }
        return $this->redirect('/shipments/' . $id, 'shipments.flash_created');
    }

    public function show(Request $request, string $id): Response
    {
        $shipment = $this->shipments->get((int) $id);
        return $this->view('shipments/show', [
            'title' => (string) $shipment['reference'],
            'shipment' => $shipment,
            'status' => ShipmentStatus::from((string) $shipment['status']),
            'lines' => $this->shipments->lines((int) $id),
            'events' => $this->shipments->events((int) $id),
            'pod' => $this->shipments->pod((int) $id),
            'files' => $this->shipments->files((int) $id),
            'docTypes' => $this->docTypes->all(),
            'requiredTypes' => $this->shipments->requiredTypeIds((int) $id),
            'reopenDoc' => $request->str('doc') === '1' && can('shipments.manage'),
            'reopenModal' => can('shipments.manage') && in_array($request->str('modal'), ['milestone', 'pod', 'deliver'], true) ? $request->str('modal') : '',
            'openNote' => $request->str('dn') === '1',
            'costs' => can('supplier_invoices.view') ? $this->supplierInvoices->costLinesForShipment((int) $id) : null,
            'brokenEventId' => $this->service->verify((int) $id),
        ]);
    }

    public function milestone(Request $request, string $id): Response
    {
        $back = '/shipments/' . $id;
        // Erreur de saisie : la fenêtre du jalon est rouverte.
        $reopen = $back . '?modal=milestone';
        $validator = Validator::make($request->body(), [
            'status' => 'required|in:picked_up,in_transit,at_border,cleared',
            'occurred_at' => 'required|datetime',
            'location' => 'nullable|max:160',
            'note' => 'nullable|max:500',
        ]);
        if (!$validator->passes()) {
            return $this->invalid($reopen, $request, $validator->errors());
        }
        $d = $validator->validated();
        try {
            $this->service->milestone((int) $id, ShipmentStatus::from($d['status']), self::datetime($d['occurred_at']), $d['location'], $d['note']);
        } catch (RuleViolation $e) {
            return $this->refused($back, $e);
        }
        return $this->redirect($back, 'shipments.flash_milestone');
    }

    public function pod(Request $request, string $id): Response
    {
        $back = '/shipments/' . $id . '?modal=pod';
        $validator = Validator::make($request->body(), [
            'received_at' => 'required|datetime',
            'receiver_name' => 'required|max:120',
            'receiver_reference' => 'nullable|max:80',
            'notes' => 'nullable|max:2000',
        ]);
        $errors = $validator->errors();
        $received = [];
        foreach ($request->rows('received') as $lineId => $row) {
            $value = $row['quantity'] ?? '';
            if ($value === '0') {
                $received[(int) $lineId] = 0;
                continue;
            }
            $v = Validator::make($row, ['quantity' => 'required|qty']);
            if (!$v->passes()) {
                $errors['received'] = t('client_orders.line_error', ['error' => implode(' ', $v->errors())]);
                continue;
            }
            $received[(int) $lineId] = $v->validated()['quantity'];
        }
        if ($request->file('pod_file') === null) {
            $errors['pod_file'] = t('shipments.error_pod_file');
        }
        if ($errors !== []) {
            return $this->invalid($back, $request, $errors);
        }
        $d = $validator->validated();
        try {
            $this->service->recordPod((int) $id, [
                'received_at' => self::datetime($d['received_at']),
                'receiver_name' => $d['receiver_name'],
                'receiver_reference' => $d['receiver_reference'],
                'notes' => $d['notes'],
            ], $received, $request->file('pod_file'));
        } catch (RuleViolation $e) {
            return $this->violated($back, $request, $e);
        }
        return $this->redirect('/shipments/' . $id, 'shipments.flash_pod');
    }

    public function cancel(Request $request, string $id): Response
    {
        try {
            $this->service->cancel((int) $id, mb_substr($request->str('reason'), 0, 255) ?: null);
        } catch (RuleViolation $e) {
            return $this->refused('/shipments/' . $id, $e);
        }
        return $this->redirect('/shipments/' . $id, 'shipments.flash_cancelled');
    }

    /** « 2026-10-26T14:30 » (champ datetime-local) → « 2026-10-26 14:30:00 » (format scellé). */
    private static function datetime(string $value): string
    {
        return str_replace('T', ' ', $value) . ':00';
    }

    /** @param list<array<string, mixed>> $rows */
    private function export(array $rows): Response
    {
        return $this->xlsx(t('nav.shipments'), [
            ['label' => t('export.col.reference')], ['label' => t('export.col.client'), 'width' => 28], ['label' => t('export.col.status')],
            ['label' => t('export.col.carrier'), 'width' => 24], ['label' => t('export.col.tracking')], ['label' => t('export.col.origin')], ['label' => t('export.col.destination'), 'width' => 28],
            ['label' => t('export.col.pickup_planned'), 'type' => Xlsx::DATE], ['label' => t('export.col.eta'), 'type' => Xlsx::DATE],
            ['label' => t('export.col.picked_up'), 'type' => Xlsx::DATE], ['label' => t('export.col.delivered'), 'type' => Xlsx::DATE],
            ['label' => t('export.col.pod'), 'type' => Xlsx::DATE], ['label' => t('export.col.supplier_orders'), 'width' => 28], ['label' => t('export.col.lines'), 'type' => Xlsx::INT],
        ], array_map(static fn (array $s): array => [
            $s['reference'], $s['client_name'], t(ShipmentStatus::from($s['status'])->labelKey()), $s['carrier_name'], $s['tracking_reference'],
            $s['origin'], $s['destination'], $s['planned_pickup_on'], $s['eta'], $s['picked_up_at'], $s['delivered_at'], $s['pod_received_at'],
            $s['po_references'], $s['line_count'],
        ], $rows));
    }
}
