<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Database;
use App\Core\Request;
use App\Security\Auth;
use App\Security\LoginThrottle;

/** Journal d'activité métier : à appeler DANS la transaction de la modification. */
final class ActivityLogger
{
    private const IGNORED = ['updated_at', 'created_at', 'password_hash', 'totp_secret'];

    public function __construct(private Database $db, private Auth $auth, private Container $container)
    {
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function log(string $entityType, ?int $entityId, string $action, array $before = [], array $after = []): void
    {
        $changes = self::diff($before, $after);
        $request = $this->container->has(Request::class) ? $this->container->get(Request::class) : null;
        $this->db->run(
            'INSERT INTO activity_log (user_id, entity_type, entity_id, action, changes, ip)
             VALUES (:user, :type, :entity, :action, :changes, :ip)',
            [
                'user' => $this->auth->user()?->id,
                'type' => $entityType,
                'entity' => $entityId,
                'action' => $action,
                'changes' => $changes === [] ? null : json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'ip' => $request === null ? null : LoginThrottle::packIp($request->ip()),
            ],
        );
    }

    /** @return list<array<string, mixed>> historique d'une entité, le plus récent d'abord */
    public function history(string $entityType, int $entityId): array
    {
        return $this->db->fetchAll(
            'SELECT a.*, u.name AS user_name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id
              WHERE a.entity_type = :t AND a.entity_id = :id ORDER BY a.id DESC LIMIT 50',
            ['t' => $entityType, 'id' => $entityId],
        );
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            if (in_array($field, self::IGNORED, true)) {
                continue;
            }
            $from = $before[$field] ?? null;
            $to = $after[$field] ?? null;
            if (is_bool($from)) {
                $from = (int) $from;
            }
            if (is_bool($to)) {
                $to = (int) $to;
            }
            if ((string) $from !== (string) $to || ($from === null) !== ($to === null)) {
                $changes[$field] = ['from' => $from, 'to' => $to];
            }
        }
        return $changes;
    }
}
