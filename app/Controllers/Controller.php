<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Core\Xlsx;
use App\Domain\Shared\RuleViolation;

/** Raccourcis communs : rendu, redirection après POST, retour au formulaire avec erreurs et saisie conservée. */
abstract class Controller
{
    /** @param array<string, mixed> $data */
    protected function view(string $template, array $data = [], ?string $layout = 'app'): Response
    {
        return Response::html(app(View::class)->render($template, $data, $layout));
    }

    protected function redirect(string $path, ?string $flashKey = null, string $type = 'success'): Response
    {
        if ($flashKey !== null) {
            app(Session::class)->flash($type, $flashKey);
        }
        return Response::redirect(url($path));
    }

    /** @param array<string, string> $errors */
    protected function invalid(string $path, Request $request, array $errors, string $message = 'common.flash_invalid'): Response
    {
        $session = app(Session::class);
        $session->flash('_errors', $errors);
        $session->flash('_old', array_diff_key($request->body(), ['_csrf' => true]));
        $session->flash('error', $message);
        return Response::redirect(url($path));
    }

    /** Règle métier refusée : retour au formulaire avec la saisie conservée et le message (avec ses paramètres). */
    protected function violated(string $path, Request $request, RuleViolation $e): Response
    {
        $session = app(Session::class);
        $session->flash('_old', array_diff_key($request->body(), ['_csrf' => true]));
        $session->flash('error', ['key' => $e->key, 'params' => $e->params]);
        return Response::redirect(url($path));
    }

    /** Règle métier refusée sur une action (bouton) : retour à la fiche avec le message. */
    protected function refused(string $path, RuleViolation $e): Response
    {
        app(Session::class)->flash('error', ['key' => $e->key, 'params' => $e->params]);
        return Response::redirect(url($path));
    }

    /**
     * Export Excel d'une liste : une feuille, colonnes typées (voir Xlsx), nom de fichier daté.
     *
     * @param list<array{label: string, type?: string, width?: int}> $columns
     * @param iterable<list<mixed>> $rows
     */
    protected function xlsx(string $name, array $columns, iterable $rows, ?string $sheet = null): Response
    {
        return self::workbook($name, (new Xlsx())->sheet($sheet ?? $name, $columns, $rows, self::exportMeta($name)));
    }

    /**
     * Bandeau d'un export : société, titre, sous-titre (période, filtres), mention d'édition.
     *
     * @return array{company: string, title: string, subtitle: string, generated: string, total_label: string}
     */
    protected static function exportMeta(string $title, string $subtitle = ''): array
    {
        return [
            'company' => setting('company_name'),
            'title' => $title,
            'subtitle' => $subtitle,
            'generated' => t('grid.generated', ['date' => date('d/m/Y H:i'), 'user' => app(\App\Security\Auth::class)->user()?->name ?? '']),
            'total_label' => t('grid.total'),
        ];
    }

    protected static function workbook(string $name, Xlsx $book): Response
    {
        $logo = company_logo();
        if ($logo !== null) {
            $book->logo($logo['bytes'], $logo['mime']);
        }
        return Response::download($book->build(), $name . ' ' . date('Y-m-d') . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /** Ligne de formulaire entièrement vide (ajoutée puis laissée vierge) : ignorée. */
    protected static function blankRow(array $row): bool
    {
        return implode('', array_map('strval', $row)) === '';
    }

    /**
     * Fichiers joints d'un champ multiple (name="champ[]") remis à plat, sans les champs vides ; 10 au plus.
     *
     * @return list<array<string, mixed>>
     */
    protected static function uploadedFiles(Request $request, string $field): array
    {
        $raw = $request->file($field);
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw['name'] ?? null)) {
            return ($raw['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$raw];
        }
        $out = [];
        foreach (array_keys($raw['name']) as $i) {
            if (($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => $raw['name'][$i], 'type' => $raw['type'][$i] ?? '', 'tmp_name' => $raw['tmp_name'][$i], 'error' => $raw['error'][$i], 'size' => $raw['size'][$i] ?? 0];
        }
        return array_slice($out, 0, 10);
    }
}
