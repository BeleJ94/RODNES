<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\DocTypeRepository;
use App\Repositories\MasterRepository;

/** Référentiel des documents d'expédition : libellés FR / EN, obligatoire par défaut, ordre, désactivation. */
final class DocTypeController extends MasterDataController
{
    public function __construct(private DocTypeRepository $types)
    {
    }

    protected function repository(): MasterRepository
    {
        return $this->types;
    }

    protected function section(): string
    {
        return 'doctypes';
    }

    protected function rules(): array
    {
        return [
            'code' => 'required|max:24',
            'name' => 'required|max:80',
            'name_en' => 'required|max:80',
            'is_required' => 'bool',
            'sort_order' => 'required|int|between:0,9999',
            'is_active' => 'bool',
        ];
    }

    protected function immutable(): array
    {
        return ['code'];
    }

    protected function check(array $data): array
    {
        return preg_match('/^[A-Z0-9_]{1,24}$/', (string) $data['code']) ? [] : ['code' => t('doctypes.code_invalid')];
    }
}
