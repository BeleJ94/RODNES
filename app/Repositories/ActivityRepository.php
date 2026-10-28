<?php

declare(strict_types=1);

namespace App\Repositories;

/** Consultation du journal d'activité (lecture seule : le journal n'est jamais modifié). */
final class ActivityRepository extends Repository
{
    public const PAGE = 100;

    /** Fiche consultable de chaque type d'entité journalisé (null : pas de fiche propre). */
    public const ROUTES = [
        'rfq' => '/rfqs/',
        'quotation' => '/quotations/',
        'client_order' => '/client-orders/',
        'supplier_order' => '/supplier-orders/',
        'shipment' => '/shipments/',
        'invoice' => '/invoices/',
        'credit_note' => '/credit-notes/',
        'payment' => '/payments/',
        'supplier_invoice' => '/supplier-invoices/',
        'supplier_payment' => '/supplier-payments/',
        'clients' => '/clients/',
        'user' => '/settings/users/',
        'auth' => '/settings/users/',
    ];

    /**
     * @param array{user?: int|null, entity?: string, action?: string, from?: string, to?: string, q?: string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $page = 1, ?int $limit = self::PAGE): array
    {
        [$where, $params] = self::where($filters);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM activity_log a' . $where, $params);
        $sql = 'SELECT a.*, u.name AS user_name, u.email AS user_email FROM activity_log a LEFT JOIN users u ON u.id = a.user_id'
            . $where . ' ORDER BY a.id DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . (max(1, $page) - 1) * $limit;
        }
        return ['rows' => $this->db->fetchAll($sql, $params), 'total' => $total];
    }

    /** @return array<string, list<string>> actions présentes au journal, par type d'entité */
    public function catalog(): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT DISTINCT entity_type, action FROM activity_log ORDER BY entity_type, action') as $row) {
            $out[(string) $row['entity_type']][] = (string) $row['action'];
        }
        return $out;
    }

    /** @return array{today: int, week: int, users_today: int, failed_today: int} */
    public function kpis(): array
    {
        $row = $this->db->fetchOne(
            'SELECT COALESCE(SUM(created_at >= CURDATE()), 0) AS today,
                    COALESCE(SUM(created_at >= CURDATE() - INTERVAL 6 DAY), 0) AS week,
                    COUNT(DISTINCT CASE WHEN created_at >= CURDATE() THEN user_id END) AS users_today
               FROM activity_log WHERE created_at >= CURDATE() - INTERVAL 6 DAY'
        ) ?? [];
        $failed = (int) $this->db->fetchValue('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at >= CURDATE()');
        return ['today' => (int) ($row['today'] ?? 0), 'week' => (int) ($row['week'] ?? 0), 'users_today' => (int) ($row['users_today'] ?? 0), 'failed_today' => $failed];
    }

    /**
     * Connexions réussies ou refusées (table login_attempts), les plus récentes d'abord.
     *
     * @param array{from?: string, to?: string, q?: string, failed?: bool} $filters
     * @return list<array<string, mixed>>
     */
    public function logins(array $filters, int $limit = 300): array
    {
        $where = [];
        $params = [];
        if (($filters['from'] ?? '') !== '') {
            $where[] = 'l.created_at >= :from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if (($filters['to'] ?? '') !== '') {
            $where[] = 'l.created_at < :to + INTERVAL 1 DAY';
            $params['to'] = $filters['to'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = 'l.email LIKE :q';
            $params['q'] = self::likePattern((string) $filters['q']);
        }
        if (!empty($filters['failed'])) {
            $where[] = 'l.success = 0';
        }
        return $this->db->fetchAll(
            'SELECT l.*, u.id AS user_id, u.name AS user_name FROM login_attempts l LEFT JOIN users u ON u.email = l.email'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY l.id DESC LIMIT ' . max(1, $limit),
            $params,
        );
    }

    /** Adresse IP lisible (stockée en binaire). */
    public static function ip(?string $packed): string
    {
        if ($packed === null || $packed === '') {
            return '';
        }
        $ip = @inet_ntop($packed);
        return $ip === false ? '' : $ip;
    }

    /** Lien vers la fiche concernée, s'il en existe une. */
    public static function link(string $entityType, ?int $entityId): ?string
    {
        return $entityId !== null && isset(self::ROUTES[$entityType]) ? url(self::ROUTES[$entityType] . $entityId) : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function where(array $filters): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['user'])) {
            $where[] = 'a.user_id = :user';
            $params['user'] = (int) $filters['user'];
        }
        if (($filters['entity'] ?? '') !== '') {
            $where[] = 'a.entity_type = :entity';
            $params['entity'] = (string) $filters['entity'];
        }
        if (($filters['action'] ?? '') !== '') {
            $where[] = 'a.action = :action';
            $params['action'] = (string) $filters['action'];
        }
        if (($filters['from'] ?? '') !== '') {
            $where[] = 'a.created_at >= :from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if (($filters['to'] ?? '') !== '') {
            $where[] = 'a.created_at < :to + INTERVAL 1 DAY';
            $params['to'] = (string) $filters['to'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = 'a.changes LIKE :q';
            $params['q'] = self::likePattern((string) $filters['q']);
        }
        if (!empty($filters['entity_id'])) {
            $where[] = 'a.entity_id = :eid';
            $params['eid'] = (int) $filters['entity_id'];
        }
        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }
}
