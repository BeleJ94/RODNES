<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Domain\Shared\RuleViolation;
use App\Repositories\MasterRepository;
use App\Services\MasterDataService;

/**
 * Fiches simples du référentiel : liste, création, modification.
 * Vues attendues : {section}/index et {section}/form ; libellés {section}.title / .new / .edit.
 */
abstract class MasterDataController extends Controller
{
    abstract protected function repository(): MasterRepository;

    /** Section : préfixe d'URL, dossier de vues et de traductions (clients, suppliers, items). */
    abstract protected function section(): string;

    /** @return array<string, string> */
    abstract protected function rules(): array;

    /**
     * Données complémentaires du formulaire (listes déroulantes…).
     *
     * @return array<string, mixed>
     */
    protected function formData(): array
    {
        return [];
    }

    /** @return list<array<string, mixed>> */
    protected function indexRows(): array
    {
        return $this->repository()->all();
    }

    /**
     * Contrôles complémentaires après validation ; renvoie les erreurs par champ.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    protected function check(array $data): array
    {
        return [];
    }

    /**
     * Champs figés après la création (ignorés à la modification).
     *
     * @return list<string>
     */
    protected function immutable(): array
    {
        return [];
    }

    protected function afterSave(int $id): string
    {
        return '/' . $this->section();
    }

    public function index(Request $request): Response
    {
        return $this->view($this->section() . '/index', ['title' => t($this->section() . '.title'), 'rows' => $this->indexRows()]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function edit(Request $request, string $id): Response
    {
        return $this->form($this->repository()->get((int) $id));
    }

    public function store(Request $request): Response
    {
        return $this->save($request, null);
    }

    public function update(Request $request, string $id): Response
    {
        $this->repository()->get((int) $id);
        return $this->save($request, (int) $id);
    }

    /** @param array<string, mixed>|null $entity */
    private function form(?array $entity): Response
    {
        $section = $this->section();
        return $this->view($section . '/form', [
            'title' => $entity === null ? t($section . '.new') : t($section . '.edit', ['name' => (string) $entity['name']]),
            'entity' => $entity ?? [],
            'action' => $entity === null ? url('/' . $section) : url('/' . $section . '/' . $entity['id']),
            'cancel' => url('/' . $section),
        ] + $this->formData());
    }

    private function save(Request $request, ?int $id): Response
    {
        $back = $id === null ? '/' . $this->section() . '/new' : '/' . $this->section() . '/' . $id . '/edit';
        $validator = Validator::make($request->body(), $this->rules());
        $data = $validator->validated();
        if (isset($data['code'])) {
            // Codes de référentiel en majuscules, sauf les unités (symboles : kg, m3…).
            $data['code'] = $this->section() === 'units' ? strtolower((string) $data['code']) : strtoupper((string) $data['code']);
        }
        $errors = $validator->errors() + ($validator->passes() ? $this->check($data) : []);
        if ($errors !== []) {
            return $this->invalid($back, $request, $errors);
        }
        if ($id !== null) {
            $data = array_diff_key($data, array_flip($this->immutable()));
        }
        try {
            $savedId = app(MasterDataService::class)->save($this->repository(), $id, $data);
        } catch (RuleViolation $e) {
            return $this->invalid($back, $request, ['code' => t($e->key)]);
        }
        return $this->redirect($this->afterSave($savedId), $id === null ? 'common.flash_created' : 'common.flash_saved');
    }
}
