<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ItemRepository;
use App\Repositories\MasterRepository;
use App\Repositories\UnitRepository;

final class ItemController extends MasterDataController
{
    public function __construct(private ItemRepository $items, private UnitRepository $units)
    {
    }

    protected function repository(): MasterRepository
    {
        return $this->items;
    }

    protected function section(): string
    {
        return 'items';
    }

    protected function rules(): array
    {
        return [
            'code' => 'required|max:32',
            'name' => 'required|max:190',
            'unit' => 'required|in:' . implode(',', $this->units->activeCodes()),
            'manufacturer' => 'nullable|max:80',
            'part_number' => 'nullable|max:80',
            'description' => 'nullable|max:2000',
            'is_active' => 'bool',
        ];
    }

    protected function formData(): array
    {
        return ['units' => $this->units->choices()];
    }
}
