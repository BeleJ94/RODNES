<?php

declare(strict_types=1);

namespace App\Domain\Delivery;

/**
 * Scellement du journal d'une livraison : chaque événement porte HMAC-SHA256(clé, hash précédent + contenu).
 * Modifier, supprimer ou réordonner un événement après coup casse la chaîne : la preuve
 * (départ, pesées, scellés, POD) reste opposable en cas de litige avec le donneur d'ordre.
 * La clé (APP_KEY) n'est pas en base : un accès SQL seul ne suffit pas à recalculer la chaîne.
 */
final class EventChain
{
    public static function genesis(): string
    {
        return str_repeat('0', 64);
    }

    /** @param array<string, mixed> $event */
    public static function hash(string $previousHash, array $event, string $key): string
    {
        return hash_hmac('sha256', $previousHash . "\n" . self::canonical($event), $key);
    }

    /** JSON stable : clés triées récursivement, listes conservées dans leur ordre. */
    public static function canonical(mixed $data): string
    {
        return json_encode(
            self::normalize($data),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * @param list<array{prev_hash: string, hash: string, data: array<string, mixed>}> $events dans l'ordre d'insertion
     * @return int|null index du premier maillon invalide, null si la chaîne est intacte
     */
    public static function firstBrokenLink(array $events, string $key): ?int
    {
        $previous = self::genesis();
        foreach ($events as $i => $event) {
            if (!hash_equals($previous, $event['prev_hash'])
                || !hash_equals(self::hash($previous, $event['data'], $key), $event['hash'])) {
                return $i;
            }
            $previous = $event['hash'];
        }
        return null;
    }

    private static function normalize(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }
        if (!array_is_list($data)) {
            ksort($data, SORT_STRING);
        }
        return array_map(self::normalize(...), $data);
    }
}
