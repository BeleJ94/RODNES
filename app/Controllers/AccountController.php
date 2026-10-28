<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Domain\Shared\RuleViolation;
use App\Repositories\ActivityRepository;
use App\Repositories\UserRepository;
use App\Security\Auth;
use App\Services\UserService;

/** Mon compte : profil, langue, mot de passe (obligatoire après un mot de passe provisoire), mon activité. */
final class AccountController extends Controller
{
    public function __construct(
        private Auth $auth,
        private Session $session,
        private UserRepository $users,
        private UserService $service,
        private ActivityRepository $activity,
    ) {
    }

    public function show(Request $request): Response
    {
        $me = $this->auth->user();
        return $this->view('account/show', [
            'title' => t('account.title'),
            'user' => $this->users->get($me->id),
            'activity' => $this->activity->search(['user' => $me->id], 1, 15)['rows'],
            'logins' => $this->users->logins($me->email, 8),
        ]);
    }

    public function update(Request $request): Response
    {
        $validator = Validator::make($request->body(), ['name' => 'required|max:120', 'phone' => 'nullable|max:32', 'locale' => 'required|in:fr,en']);
        if (!$validator->passes()) {
            return $this->invalid('/account', $request, $validator->errors());
        }
        $data = $validator->validated();
        $this->service->updateProfile($this->auth->user()->id, $data);
        $this->session->set('locale', $data['locale']);
        app(I18n::class)->setLocale($data['locale']);
        return $this->redirect('/account', 'common.flash_saved');
    }

    public function password(Request $request): Response
    {
        $forced = $this->auth->user()->mustChangePassword;
        return $this->view('account/password', ['title' => t('account.password_title'), 'forced' => $forced], $forced ? 'minimal' : 'app');
    }

    public function changePassword(Request $request): Response
    {
        $me = $this->auth->user();
        $new = $request->secret('new_password');
        if ($new !== $request->secret('new_password_confirm')) {
            return $this->passwordError(['new_password_confirm' => t('account.error_confirm')]);
        }
        try {
            $version = $this->service->changeOwnPassword($me->id, $request->secret('current_password'), $new);
        } catch (RuleViolation $e) {
            $field = $e->key === 'account.error_current' ? 'current_password' : 'new_password';
            return $this->passwordError([$field => t($e->key, $e->params)]);
        }
        // Les autres sessions sont fermées ; celle-ci reste ouverte, avec un nouvel identifiant.
        $this->session->regenerate();
        $this->session->set('auth.session_version', $version);
        return $this->redirect($me->mustChangePassword ? '/' : '/account', 'account.flash_password');
    }

    /**
     * Erreur du formulaire de mot de passe : jamais de ressaisie conservée (aucun mot de passe en session).
     *
     * @param array<string, string> $errors
     */
    private function passwordError(array $errors): Response
    {
        $this->session->flash('_errors', $errors);
        $this->session->flash('error', 'common.flash_invalid');
        return Response::redirect(url('/account/password'));
    }
}
