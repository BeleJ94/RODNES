<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\ActivityRepository;
use App\Repositories\UserRepository;

/** Paramètres → Journal d'activité : qui a fait quoi, quand, avec le détail avant → après ; connexions. */
final class ActivityController extends Controller
{
    public function __construct(private ActivityRepository $activity, private UserRepository $users)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $tab = $request->str('tab') === 'logins' ? 'logins' : 'actions';
        if ($request->str('format') === 'xlsx') {
            return $tab === 'logins' ? $this->exportLogins($filters) : $this->exportActions($filters);
        }
        $page = max(1, (int) $request->str('page', '1'));
        $result = $tab === 'actions' ? $this->activity->search($filters, $page) : ['rows' => [], 'total' => 0];
        return $this->view('activity/index', [
            'title' => t('activity.title'),
            'tab' => $tab,
            'filters' => $filters,
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / ActivityRepository::PAGE)),
            'logins' => $tab === 'logins' ? $this->activity->logins($filters + ['failed' => $request->str('failed') === '1']) : [],
            'failedOnly' => $request->str('failed') === '1',
            'catalog' => $this->activity->catalog(),
            'users' => $this->users->allOptions(),
            'kpis' => $this->activity->kpis(),
        ]);
    }

    /** @return array{user: int|null, entity: string, action: string, from: string, to: string, q: string} */
    private function filters(Request $request): array
    {
        $date = static fn (string $v): string => Validator::make(['d' => $v], ['d' => 'date'])->passes() ? $v : '';
        return [
            'user' => $request->int('user') ?: null,
            'entity' => preg_match('/^[a-z_]{1,40}$/', $request->str('entity')) ? $request->str('entity') : '',
            'action' => preg_match('/^[a-z_]{1,40}$/', $request->str('action')) ? $request->str('action') : '',
            'from' => $date($request->str('from')),
            'to' => $date($request->str('to')),
            'q' => mb_substr($request->str('q'), 0, 80),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function exportActions(array $filters): Response
    {
        $rows = $this->activity->search($filters, 1, 20000)['rows'];
        return $this->xlsx(t('activity.title'), [
            ['label' => t('activity.col.date'), 'type' => 'date', 'width' => 18],
            ['label' => t('activity.col.user'), 'width' => 24],
            ['label' => t('activity.col.entity'), 'width' => 22],
            ['label' => t('activity.col.ref'), 'type' => 'int', 'width' => 10],
            ['label' => t('activity.col.action'), 'width' => 24],
            ['label' => t('activity.col.changes'), 'width' => 60],
            ['label' => t('activity.col.ip'), 'width' => 16],
        ], array_map(static fn (array $r): array => [
            $r['created_at'],
            $r['user_name'] ?? t('history.system'),
            activity_entity_label((string) $r['entity_type']),
            $r['entity_id'] !== null ? (int) $r['entity_id'] : null,
            activity_action_label((string) $r['action']),
            implode(' · ', array_map(
                static fn (array $c): string => $c['field'] . ' : ' . $c['from'] . ' → ' . $c['to'],
                activity_changes($r['changes']),
            )),
            ActivityRepository::ip($r['ip']),
        ], $rows));
    }

    /** @param array<string, mixed> $filters */
    private function exportLogins(array $filters): Response
    {
        return $this->xlsx(t('activity.logins_title'), [
            ['label' => t('activity.col.date'), 'type' => 'date', 'width' => 18],
            ['label' => t('users.email'), 'width' => 32],
            ['label' => t('activity.col.user'), 'width' => 24],
            ['label' => t('activity.col.result'), 'width' => 14],
            ['label' => t('activity.col.ip'), 'width' => 16],
        ], array_map(static fn (array $r): array => [
            $r['created_at'], $r['email'], $r['user_name'] ?? '', t($r['success'] ? 'activity.login_ok' : 'activity.login_failed'), ActivityRepository::ip($r['ip']),
        ], $this->activity->logins($filters, 20000)));
    }
}
