<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\MasterRepository;
use App\Repositories\UnitRepository;

/** Référentiel des unités de mesure : création, libellés FR / EN, ordre d'affichage, désactivation. */
final class UnitController extends MasterDataController
{
    public function __construct(private UnitRepository $units)
    {
    }

    protected function repository(): MasterRepository
    {
        return $this->units;
    }

    protected function section(): string
    {
        return 'units';
    }

    protected function rules(): array
    {
        return [
            'code' => 'required|max:8',
            'name' => 'required|max:40',
            'name_en' => 'required|max:40',
            'sort_order' => 'required|int|between:0,9999',
            'is_active' => 'bool',
        ];
    }

    /** Le code est copié sur les lignes de documents : il ne change plus après la création. */
    protected function immutable(): array
    {
        return ['code'];
    }

    protected function check(array $data): array
    {
        return preg_match('/^[a-z0-9]{1,8}$/', strtolower((string) $data['code'])) ? [] : ['code' => t('units.code_invalid')];
    }
}
