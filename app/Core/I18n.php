<?php

declare(strict_types=1);

namespace App\Core;

/** Traductions par clés « zone.element » (lang/fr.php, lang/en.php). Le français sert de repli. */
final class I18n
{
    /** @var array<string, array<string, string>> */
    private array $catalogs = [];

    private string $locale;

    public function __construct(private Config $config)
    {
        $this->locale = (string) $config->get('app.locale', 'fr');
    }

    public function setLocale(string $locale): void
    {
        if (in_array($locale, (array) $this->config->get('app.locales', ['fr']), true)) {
            $this->locale = $locale;
        }
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @param array<string, scalar|null> $params */
    public function get(string $key, array $params = []): string
    {
        $text = $this->catalog($this->locale)[$key] ?? $this->catalog('fr')[$key] ?? $key;
        if ($params === []) {
            return $text;
        }
        $replace = [];
        foreach ($params as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }
        return strtr($text, $replace);
    }

    /** @return array<string, string> */
    public function catalog(string $locale): array
    {
        if (!isset($this->catalogs[$locale])) {
            $file = BASE_PATH . '/lang/' . basename($locale) . '.php';
            $this->catalogs[$locale] = is_file($file) ? require $file : [];
        }
        return $this->catalogs[$locale];
    }

    /**
     * Sous-catalogue envoyé au JavaScript : seules les clés aux préfixes autorisés sortent du serveur.
     * @param list<string> $prefixes
     * @return array<string, string>
     */
    public function forJs(array $prefixes): array
    {
        $out = [];
        foreach (array_keys($this->catalog('fr')) as $key) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $out[$key] = $this->get($key);
                    break;
                }
            }
        }
        return $out;
    }
}
