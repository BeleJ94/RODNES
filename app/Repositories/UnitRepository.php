<?php

declare(strict_types=1);

namespace App\Repositories;

/** Unités de mesure des articles : le code est stable (copié sur les lignes de documents), le libellé est bilingue. */
final class UnitRepository extends MasterRepository
{
    protected const TABLE = 'units';
    protected const COLUMNS = ['code', 'name', 'name_en', 'sort_order', 'is_active'];

    /** @var array<string, array{name: string, name_en: string}>|null */
    private ?array $labels = null;

    /** @return list<array<string, mixed>> unités avec le nombre d'articles qui les utilisent */
    public function all(bool $activeOnly = false): array
    {
        return $this->db->fetchAll(
            'SELECT u.*, (SELECT COUNT(*) FROM items i WHERE i.unit = u.code) AS item_count FROM units u'
            . ($activeOnly ? ' WHERE u.is_active = 1' : '') . ' ORDER BY u.sort_order, u.code'
        );
    }

    /** @return list<string> codes des unités actives, dans l'ordre d'affichage */
    public function activeCodes(): array
    {
        return array_column($this->all(true), 'code');
    }

    /** @return array<string, string> code → libellé dans la langue courante (unités actives, pour les listes) */
    public function choices(): array
    {
        $out = [];
        foreach ($this->activeCodes() as $code) {
            $out[$code] = $this->label($code);
        }
        return $out;
    }

    /** Libellé d'un code, même désactivé (historique) ; le code lui-même s'il est inconnu. */
    public function label(string $code): string
    {
        if ($this->labels === null) {
            $this->labels = array_column($this->db->fetchAll('SELECT code, name, name_en FROM units'), null, 'code');
        }
        $row = $this->labels[$code] ?? null;
        if ($row === null) {
            return $code;
        }
        return is_en() && $row['name_en'] !== '' ? $row['name_en'] : $row['name'];
    }
}
