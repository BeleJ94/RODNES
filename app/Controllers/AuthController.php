<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Security\Auth;
use App\Security\LoginResult;

final class AuthController extends Controller
{
    public function __construct(private Auth $auth, private Session $session)
    {
    }

    public function showLogin(Request $request): Response
    {
        if ($this->auth->user() !== null) {
            return Response::redirect(url('/'));
        }
        return $this->view('auth/login', ['title' => t('auth.title')], 'minimal');
    }

    public function login(Request $request): Response
    {
        $email = $request->str('email');
        $result = $this->auth->attempt($email, $request->secret('password'), $request->ip());
        if ($result !== LoginResult::Ok) {
            // Message identique que le compte existe ou non.
            $this->session->flash('error', $result === LoginResult::Throttled ? 'auth.throttled' : 'auth.invalid');
            $this->session->flash('_old', ['email' => $email]);
            return Response::redirect(url('/login'));
        }
        return Response::redirect(url(safe_path($this->session->pull('_intended'))));
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();
        return Response::redirect(url('/login'));
    }
}
