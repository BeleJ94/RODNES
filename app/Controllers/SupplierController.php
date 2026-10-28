<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CurrencyRepository;
use App\Repositories\MasterRepository;
use App\Repositories\SupplierRepository;

final class SupplierController extends MasterDataController
{
    public function __construct(private SupplierRepository $suppliers, private CurrencyRepository $currencies)
    {
    }

    protected function repository(): MasterRepository
    {
        return $this->suppliers;
    }

    protected function section(): string
    {
        return 'suppliers';
    }

    protected function rules(): array
    {
        return [
            'code' => 'required|max:32',
            'name' => 'required|max:160',
            'country' => 'nullable|max:2',
            'currency_code' => 'required|currency',
            'contact_name' => 'nullable|max:120',
            'contact_email' => 'nullable|email|max:190',
            'contact_phone' => 'nullable|max:32',
            'payment_terms_days' => 'required|int|between:0,365',
            'is_active' => 'bool',
            'notes' => 'nullable|max:2000',
        ];
    }

    protected function check(array $data): array
    {
        return in_array($data['currency_code'], $this->currencies->activeCodes(), true) ? [] : ['currency_code' => t('validation.in')];
    }

    protected function indexRows(): array
    {
        return $this->suppliers->withActivity();
    }

    protected function formData(): array
    {
        return ['currencies' => $this->currencies->activeCodes()];
    }
}
