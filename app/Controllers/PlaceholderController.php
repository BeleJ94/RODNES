<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/** Page d'attente des modules de la feuille de route : la navigation et les droits sont déjà en place. */
final class PlaceholderController
{
    public function __construct(private View $view)
    {
    }

    public function show(Request $request, string ...$params): Response
    {
        $item = null;
        foreach (require BASE_PATH . '/config/menu.php' as $section) {
            foreach ($section['items'] as $candidate) {
                if ($candidate['path'] === $request->path) {
                    $item = $candidate;
                }
            }
        }
        return Response::html($this->view->render('placeholder/show', [
            'title' => $item !== null ? t($item['label']) : t('placeholder.title'),
            'icon' => $item['icon'] ?? 'construction',
        ]));
    }
}
