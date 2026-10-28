<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Security\Auth;

final class LocaleController
{
    public function __construct(private Session $session, private Auth $auth, private Database $db)
    {
    }

    public function switch(Request $request): Response
    {
        $locale = $request->str('locale');
        if (in_array($locale, (array) config('app.locales'), true)) {
            $this->session->set('locale', $locale);
            $user = $this->auth->user();
            if ($user !== null) {
                $this->db->run('UPDATE users SET locale = :l WHERE id = :id', ['l' => $locale, 'id' => $user->id]);
            }
        }
        return Response::redirect(url(safe_path($request->str('back'))));
    }
}
