<?php

declare(strict_types=1);

// Champs de formulaire : libellé, valeur ressaisie après erreur (old), message d'erreur et attributs ARIA.
// Options communes : required, hint, span (pleine largeur), attrs (attributs HTML supplémentaires déjà sûrs).

/** @param array{type?: string, required?: bool, hint?: string, span?: bool, attrs?: string, maxlength?: int, placeholder?: string} $opts */
function form_input(string $name, string $label, mixed $value = '', array $opts = []): string
{
    $id = 'f-' . str_replace(['[', ']'], ['-', ''], $name);
    $attrs = ' type="' . e($opts['type'] ?? 'text') . '"'
        . (!empty($opts['required']) ? ' required' : '')
        . (isset($opts['maxlength']) ? ' maxlength="' . (int) $opts['maxlength'] . '"' : '')
        . (isset($opts['placeholder']) ? ' placeholder="' . e($opts['placeholder']) . '"' : '')
        . ($opts['attrs'] ?? '');
    return form_field($id, $name, $label, $opts, '<input id="' . e($id) . '" name="' . e($name) . '" value="' . e(old($name, $value)) . '"' . $attrs . invalid($name) . '>');
}

/**
 * @param array<string|int, string> $options valeur → libellé
 * @param array{required?: bool, hint?: string, span?: bool, attrs?: string, placeholder?: string} $opts
 */
function form_select(string $name, string $label, array $options, mixed $selected = '', array $opts = []): string
{
    $id = 'f-' . $name;
    $current = old($name, (string) ($selected ?? ''));
    $html = '<select id="' . e($id) . '" name="' . e($name) . '"' . (!empty($opts['required']) ? ' required' : '') . ($opts['attrs'] ?? '') . invalid($name) . '>';
    if (isset($opts['placeholder'])) {
        $html .= '<option value="">' . e($opts['placeholder']) . '</option>';
    }
    foreach ($options as $value => $text) {
        $html .= '<option value="' . e($value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . e($text) . '</option>';
    }
    return form_field($id, $name, $label, $opts, $html . '</select>');
}

/** @param array{required?: bool, hint?: string, span?: bool, rows?: int, maxlength?: int} $opts */
function form_textarea(string $name, string $label, mixed $value = '', array $opts = []): string
{
    $id = 'f-' . $name;
    $html = '<textarea id="' . e($id) . '" name="' . e($name) . '" rows="' . (int) ($opts['rows'] ?? 3) . '"'
        . (isset($opts['maxlength']) ? ' maxlength="' . (int) $opts['maxlength'] . '"' : '')
        . (!empty($opts['required']) ? ' required' : '') . invalid($name) . '>' . e(old($name, $value)) . '</textarea>';
    return form_field($id, $name, $label, $opts, $html);
}

/** Case à cocher : la valeur envoyée est « 1 », l'absence vaut « non » (règle de validation « bool »). */
function form_check(string $name, string $label, bool $checked, string $hint = ''): string
{
    $id = 'f-' . $name;
    return '<div class="field field--check"><label class="check" for="' . e($id) . '">'
        . '<input type="checkbox" id="' . e($id) . '" name="' . e($name) . '" value="1"' . (old_checked($name, $checked) ? ' checked' : '') . '>'
        . '<span>' . e($label) . '</span></label>'
        . ($hint !== '' ? '<p class="hint">' . e($hint) . '</p>' : '') . field_error($name) . '</div>';
}

/** @param array{required?: bool, hint?: string, span?: bool} $opts */
function form_field(string $id, string $name, string $label, array $opts, string $control): string
{
    return '<div class="field' . (!empty($opts['span']) ? ' span-2' : '') . '">'
        . '<label for="' . e($id) . '"' . (!empty($opts['required']) ? ' class="required"' : '') . '>' . e($label) . '</label>'
        . $control
        . (isset($opts['hint']) ? '<p class="hint">' . e($opts['hint']) . '</p>' : '')
        . field_error($name)
        . '</div>';
}

/** Lignes ressaisies après une erreur de validation (null s'il n'y en a pas). */
function old_rows(string $key): ?array
{
    $old = flash('_old');
    return is_array($old) && is_array($old[$key] ?? null) ? $old[$key] : null;
}

/** Boutons d'action en pied de formulaire. */
function form_actions(string $cancelUrl, string $submitLabel = ''): string
{
    return '<div class="form-actions"><a class="btn btn--ghost" href="' . e($cancelUrl) . '">' . e(t('common.cancel')) . '</a>'
        . '<button type="submit" class="btn btn--primary">' . icon('save') . '<span>' . e($submitLabel !== '' ? $submitLabel : t('common.save')) . '</span></button></div>';
}
