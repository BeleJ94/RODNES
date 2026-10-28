<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\I18n;
use App\Core\Session;
use App\Core\View;
use App\Domain\Shared\Fx;
use App\Domain\Shared\Milli;
use App\Domain\Shared\Money;
use App\Domain\Shared\Tone;
use App\Security\Auth;
use App\Services\Settings;

function app(?string $id = null): mixed
{
    $container = Container::instance();
    return $id === null ? $container : $container->get($id);
}

function config(string $key, mixed $default = null): mixed
{
    return app(Config::class)->get($key, $default);
}

function setting(string $name): string
{
    return app(Settings::class)->get($name);
}

/** Échappement HTML : toute valeur dynamique affichée dans une vue passe par ici. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** @param array<string, scalar|null> $params */
function t(string $key, array $params = []): string
{
    return app(I18n::class)->get($key, $params);
}

/** @param array<string, scalar> $query */
function url(string $path = '/', array $query = []): string
{
    $url = rtrim((string) config('app.base_path', ''), '/') . '/' . ltrim($path, '/');
    return $query === [] ? $url : $url . '?' . http_build_query($query);
}

/** URL d'une ressource statique, versionnée par sa date de modification (cache navigateur long). */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = BASE_PATH . '/public/assets/' . $path;
    return url('assets/' . $path) . '?v=' . (is_file($file) ? filemtime($file) : '0');
}

function icon(string $name, string $class = ''): string
{
    return '<i data-lucide="' . e($name) . '"' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ' aria-hidden="true"></i>';
}

/** Pastille d'état : la couleur suit la tonalité, le libellé porte toujours l'information. */
function badge(Tone $tone, string $label): string
{
    return '<span class="badge badge--' . e($tone->value) . '">' . e($label) . '</span>';
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(app(Csrf::class)->token()) . '">';
}

/** Valeur ressaisie après une erreur de validation, sinon la valeur par défaut (celle de l'entité en modification). */
function old(string $field, mixed $default = ''): string
{
    return app(Session::class)->old($field, (string) ($default ?? ''));
}

function old_checked(string $field, bool $default): bool
{
    $session = app(Session::class);
    return $session->hasOld() ? $session->old($field) !== '' : $default;
}

function flash(string $key): mixed
{
    return app(Session::class)->getFlash($key);
}

/** Message d'erreur de validation d'un champ (vide s'il n'y en a pas). */
function field_error(string $field): string
{
    $errors = app(Session::class)->getFlash('_errors', []);
    $message = is_array($errors) ? ($errors[$field] ?? null) : null;
    return is_string($message) ? '<p class="error" id="err-' . e($field) . '">' . e($message) . '</p>' : '';
}

/** Attributs ARIA d'un champ en erreur. */
function invalid(string $field): string
{
    $errors = app(Session::class)->getFlash('_errors', []);
    return is_array($errors) && isset($errors[$field]) ? ' aria-invalid="true" aria-describedby="err-' . e($field) . '"' : '';
}

function can(string $permission): bool
{
    return app(Auth::class)->user()?->can($permission) ?? false;
}

/** @param array<string, mixed> $data */
function partial(string $template, array $data = []): string
{
    return app(View::class)->partial($template, $data);
}

function is_en(): bool
{
    return app(I18n::class)->locale() === 'en';
}

/** « 2026-10-26 08:30:00 » → « 26/10/2026 08:30 ». */
function format_datetime(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    return $date === false ? $value : $date->format('d/m/Y H:i');
}

/** « 2026-10-26 » → « 26/10/2026 ». */
function format_date(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
    return $date === false ? $value : $date->format('d/m/Y');
}

/** Quantité en millièmes → « 12 », « 2,5 » (zéros inutiles retirés). */
function format_qty(?int $milli): string
{
    if ($milli === null) {
        return '';
    }
    $text = is_en() ? Milli::format($milli, 3, '.', ',') : Milli::format($milli);
    return rtrim(rtrim($text, '0'), is_en() ? '.' : ',');
}

/** Saisie d'une quantité (sans séparateur de milliers, pour un champ de formulaire). */
function input_qty(?int $milli): string
{
    if ($milli === null) {
        return '';
    }
    return rtrim(rtrim(Milli::format($milli, 3, ',', ''), '0'), ',');
}

/** Centimes → « 1 250,50 USD » (ou sans devise). */
function format_money(?int $minor, ?string $currency = null): string
{
    if ($minor === null) {
        return '';
    }
    $text = is_en() ? Money::format($minor, '.', ',') : Money::format($minor);
    return $currency !== null ? $text . "\u{00A0}" . $currency : $text;
}

/** Saisie d'un montant (sans séparateur de milliers, pour un champ de formulaire). */
function input_money(?int $minor): string
{
    return $minor === null ? '' : Money::format($minor, ',', '');
}

/** Points de base → « 15,5 % ». */
function format_percent(?int $bp): string
{
    if ($bp === null) {
        return '—';
    }
    $text = rtrim(rtrim(Money::format($bp, is_en() ? '.' : ',', ''), '0'), is_en() ? '.' : ',');
    return $text . "\u{00A0}%";
}

function input_percent(?int $bp): string
{
    return $bp === null ? '' : rtrim(rtrim(Money::format($bp, ',', ''), '0'), ',');
}

function format_rate(int $micro): string
{
    return Fx::formatRate($micro, is_en() ? '.' : ',');
}

/**
 * Attributs d'ouverture dans la fenêtre d'aperçu (data-preview), à placer sur un lien dont le href reste utilisable sans JS.
 *
 * @param 'image'|'pdf'|'document' $kind document = page imprimable produite par ETHAN
 */
function preview_attrs(string $kind, string $title, string $meta = '', ?string $downloadUrl = null): string
{
    return ' data-preview="' . e($kind) . '" data-preview-title="' . e($title) . '"'
        . ($meta !== '' ? ' data-preview-meta="' . e($meta) . '"' : '')
        . ($downloadUrl !== null ? ' data-preview-download="' . e($downloadUrl) . '"' : '');
}

/** Type d'aperçu d'un fichier joint selon son type MIME (null : non prévisualisable). */
function preview_kind(string $mime): ?string
{
    return match (true) {
        str_starts_with($mime, 'image/') => 'image',
        $mime === 'application/pdf' => 'pdf',
        default => null,
    };
}

/** 3679 → « 3,6 Ko ». */
function format_bytes(int $bytes): string
{
    $units = ['o', 'Ko', 'Mo', 'Go'];
    $i = 0;
    $value = (float) $bytes;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) $bytes : number_format($value, 1, is_en() ? '.' : ',', '')) . "\u{00A0}" . $units[$i];
}

/** Bloc de données pour le JavaScript (jamais de script exécutable inline : CSP). */
function json_script(string $id, mixed $data): string
{
    $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    return '<script type="application/json" id="' . e($id) . '">' . $json . '</script>';
}

/** Chemin interne sûr pour une redirection (bloque les redirections ouvertes « //site » ou « https:// »). */
function safe_path(mixed $path, string $fallback = '/'): string
{
    return is_string($path) && preg_match('#^/(?![/\\\\])[^\s]*$#', $path) ? $path : $fallback;
}

/** Bouton « Exporter (Excel) » : la page courante avec ses filtres, en format=xlsx. */
function export_button(string $label = ''): string
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $query = array_filter(array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $_GET), static fn (string $v): bool => $v !== '');
    $href = $path . '?' . http_build_query(['format' => 'xlsx'] + $query);
    return '<a class="btn btn--ghost" href="' . e($href) . '" title="' . e(t('export.button_hint')) . '" download>' . icon('file-spreadsheet') . '<span>' . e($label !== '' ? $label : t('export.button')) . '</span></a>';
}

/**
 * Détail dépliable d'une ligne de tableau (lu par app.js) : paires libellé → valeur, puis un bloc libre.
 * Les valeurs sont du HTML déjà sûr ; les valeurs vides sont ignorées.
 *
 * @param array<string, string|null> $fields libellé → HTML
 */
function row_detail(array $fields, string $extra = ''): string
{
    $items = '';
    foreach ($fields as $label => $html) {
        if ($html === null || trim(strip_tags($html)) === '' || trim(strip_tags($html)) === '—') {
            continue;
        }
        $items .= '<div><dt>' . e($label) . '</dt><dd>' . $html . '</dd></div>';
    }
    if ($items === '' && $extra === '') {
        return '';
    }
    return '<template data-detail><div class="row-detail">' . ($items !== '' ? '<dl class="row-detail__grid">' . $items . '</dl>' : '') . $extra . '</div></template>';
}

/** Libellé d'une unité de mesure (référentiel des unités), dans la langue courante. */
function unit_label(?string $code): string
{
    return $code === null || $code === '' ? '' : app(\App\Repositories\UnitRepository::class)->label($code);
}

/**
 * Logo de la société (Paramètres) en image intégrée : affiché sur les documents imprimés et dans leur PDF
 * sans dépendre des droits d'accès aux fichiers. Chaîne vide s'il n'y en a pas.
 */
function company_logo_data_uri(): string
{
    $logo = company_logo();
    return $logo === null ? '' : 'data:' . $logo['mime'] . ';base64,' . base64_encode($logo['bytes']);
}

/**
 * Logo de la société (Paramètres) : contenu et type MIME, ou null s'il n'y en a pas.
 *
 * @return array{bytes: string, mime: string}|null
 */
function company_logo(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;
    $id = (int) setting('company_logo');
    if ($id <= 0) {
        return null;
    }
    try {
        $storage = app(\App\Services\FileStorage::class);
        $file = $storage->get($id);
        $path = $storage->path($file);
    } catch (\Throwable) {
        return null;
    }
    $mime = (string) $file['mime'];
    if (!str_starts_with($mime, 'image/') || !is_file($path)) {
        return null;
    }
    return $cache = ['bytes' => (string) file_get_contents($path), 'mime' => $mime];
}
/**
 * Fil des documents de l'affaire (RFQ → … → factures) affiché en tête des pages de document ;
 * $type/$id désignent le document courant, mis en évidence. Chaîne vide si rien à relier.
 */
function doc_chain(string $type, int $id): string
{
    $chain = app(\App\Services\DocumentChain::class)->for($type, $id);
    return $chain === [] ? '' : partial('partials/doc-chain', ['chain' => $chain, 'type' => $type, 'id' => $id]);
}

/** Logo de la société en tête des documents imprimés (chaîne vide sans logo). */
function doc_logo(): string
{
    $logo = company_logo_data_uri();
    return $logo === '' ? '' : '<img src="' . e($logo) . '" alt="' . e(setting('company_name')) . '" class="doc__logo">';
}
