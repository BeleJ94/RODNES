<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\ClientRepository;
use App\Repositories\MasterRepository;
use App\Services\DeadlinePolicy;
use App\Services\SalesTrackingService;

final class ClientController extends MasterDataController
{
    public function __construct(private ClientRepository $clients, private SalesTrackingService $tracking, private DeadlinePolicy $policy)
    {
    }

    protected function repository(): MasterRepository
    {
        return $this->clients;
    }

    protected function section(): string
    {
        return 'clients';
    }

    protected function rules(): array
    {
        return [
            'code' => 'required|max:32',
            'customer_number' => 'nullable|max:40',
            'name' => 'required|max:160',
            'rccm' => 'nullable|max:64',
            'nif' => 'nullable|max:64',
            'id_nat' => 'nullable|max:64',
            'address' => 'nullable|max:255',
            'city' => 'nullable|max:80',
            'contact_name' => 'nullable|max:120',
            'contact_email' => 'nullable|email|max:190',
            'contact_phone' => 'nullable|max:32',
            'payment_terms_days' => 'required|int|between:0,365',
            'rfq_response_days' => 'nullable|int|between:1,60',
            'quote_validity_days' => 'nullable|int|between:1,365',
            'vat_applicable' => 'bool',
            'is_active' => 'bool',
            'notes' => 'nullable|max:2000',
        ];
    }

    protected function indexRows(): array
    {
        return $this->clients->withActivity();
    }

    protected function afterSave(int $id): string
    {
        return '/clients/' . $id;
    }

    public function show(Request $request, string $id): Response
    {
        $client = $this->clients->get((int) $id);
        $rows = can('rfqs.view') ? $this->tracking->rows(['client_id' => (int) $id]) : [];
        return $this->view('clients/show', [
            'title' => (string) $client['name'],
            'client' => $client,
            'rows' => $rows,
            'summary' => $this->tracking->summary($rows),
            'mode' => $this->policy->mode(),
            'delays' => ['rfq' => $this->policy->rfqResponseDays((int) $id), 'validity' => $this->policy->quoteValidityDays((int) $id)],
        ]);
    }
}
