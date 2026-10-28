<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Domain\Shared\RuleViolation;
use App\Repositories\ActivityRepository;
use App\Repositories\UserRepository;
use App\Security\Role;
use App\Services\UserService;

/** Paramètres → Utilisateurs : comptes, rôles, activation, mots de passe provisoires, sessions. */
final class UserController extends Controller
{
    public function __construct(
        private UserRepository $users,
        private UserService $service,
        private ActivityRepository $activity,
        private Session $session,
    ) {
    }

    public function index(Request $request): Response
    {
        $rows = $this->users->list();
        if ($request->str('format') === 'xlsx') {
            return $this->xlsx(t('users.title'), [
                ['label' => t('users.name'), 'width' => 26], ['label' => t('users.email'), 'width' => 32], ['label' => t('users.role'), 'width' => 16],
                ['label' => t('common.status'), 'width' => 12], ['label' => t('users.last_login'), 'type' => 'date', 'width' => 18],
                ['label' => t('users.col.actions_30d'), 'type' => 'int', 'width' => 12],
            ], array_map(static fn (array $u): array => [
                $u['name'], $u['email'], t('role.' . $u['role']), t($u['is_active'] ? 'common.active' : 'common.inactive'), $u['last_login_at'], (int) $u['actions_30d'],
            ], $rows));
        }
        return $this->view('users/index', ['title' => t('users.title'), 'rows' => $rows, 'kpis' => $this->users->kpis()]);
    }

    public function show(Request $request, string $id): Response
    {
        $user = $this->users->get((int) $id);
        return $this->view('users/show', [
            'title' => $user['name'],
            'user' => $user,
            'activity' => $this->activity->search(['user' => (int) $id], 1, 25)['rows'],
            'history' => $this->activity->search(['entity' => 'user', 'entity_id' => (int) $id], 1, 20)['rows'],
            'logins' => $this->users->logins((string) $user['email']),
            'tempPassword' => flash('temp_password'),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function edit(Request $request, string $id): Response
    {
        return $this->form($this->users->get((int) $id));
    }

    public function store(Request $request): Response
    {
        [$data, $errors] = $this->validated($request);
        if ($errors !== []) {
            return $this->invalid('/settings/users/new', $request, $errors);
        }
        try {
            [$id, $password] = $this->service->create($data);
        } catch (RuleViolation $e) {
            return $this->invalid('/settings/users/new', $request, ['email' => t($e->key)]);
        }
        $this->session->flash('temp_password', $password);
        return $this->redirect('/settings/users/' . $id, 'users.flash_created');
    }

    public function update(Request $request, string $id): Response
    {
        $back = '/settings/users/' . (int) $id . '/edit';
        [$data, $errors] = $this->validated($request);
        if ($errors !== []) {
            return $this->invalid($back, $request, $errors);
        }
        try {
            $this->service->update((int) $id, $data);
        } catch (RuleViolation $e) {
            return $e->key === 'users.error_email_taken' ? $this->invalid($back, $request, ['email' => t($e->key)]) : $this->violated($back, $request, $e);
        }
        return $this->redirect('/settings/users/' . (int) $id, 'common.flash_saved');
    }

    public function activate(Request $request, string $id): Response
    {
        return $this->toggle((int) $id, true);
    }

    public function deactivate(Request $request, string $id): Response
    {
        return $this->toggle((int) $id, false);
    }

    public function resetPassword(Request $request, string $id): Response
    {
        $password = $this->service->resetPassword((int) $id);
        $this->session->flash('temp_password', $password);
        return $this->redirect('/settings/users/' . (int) $id, 'users.flash_reset');
    }

    public function revokeSessions(Request $request, string $id): Response
    {
        $this->service->revokeSessions((int) $id);
        return $this->redirect('/settings/users/' . (int) $id, 'users.flash_revoked');
    }

    private function toggle(int $id, bool $active): Response
    {
        try {
            $this->service->setActive($id, $active);
        } catch (RuleViolation $e) {
            return $this->refused('/settings/users/' . $id, $e);
        }
        return $this->redirect('/settings/users/' . $id, $active ? 'users.flash_activated' : 'users.flash_deactivated');
    }

    /** @param array<string, mixed>|null $user */
    private function form(?array $user): Response
    {
        return $this->view('users/form', [
            'title' => $user === null ? t('users.new') : t('users.edit', ['name' => (string) $user['name']]),
            'user' => $user ?? [],
            'action' => url($user === null ? '/settings/users' : '/settings/users/' . $user['id']),
            'cancel' => url($user === null ? '/settings/users' : '/settings/users/' . $user['id']),
            'isSelf' => $user !== null && (int) $user['id'] === app(\App\Security\Auth::class)->user()?->id,
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function validated(Request $request): array
    {
        $validator = Validator::make($request->body(), [
            'name' => 'required|max:120',
            'email' => 'required|email|max:190',
            'phone' => 'nullable|max:32',
            'role' => 'required|in:' . implode(',', array_map(static fn (Role $r): string => $r->value, Role::cases())),
            'locale' => 'required|in:fr,en',
        ]);
        return [$validator->validated(), $validator->errors()];
    }
}
