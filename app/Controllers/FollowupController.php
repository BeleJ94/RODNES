<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Domain\Shared\RuleViolation;
use App\Security\Auth;
use App\Services\FollowupService;

/** Relances à faire : devis sans réponse, PO client attendu, PO fournisseur à confirmer, mise à disposition à vérifier. */
final class FollowupController extends Controller
{
    public function __construct(private FollowupService $followups, private Auth $auth)
    {
    }

    public function index(Request $request): Response
    {
        $kinds = $this->visibleKinds();
        $kind = in_array($request->str('kind'), $kinds, true) ? $request->str('kind') : '';
        $mine = $request->str('scope') === 'mine';
        $userId = $this->auth->user()?->id;
        $items = array_values(array_filter(
            $this->followups->open($kind !== '' ? [$kind] : $kinds),
            static fn (array $f): bool => !$mine || $f['owner_id'] === $userId,
        ));
        $today = date('Y-m-d');
        $horizon = date('Y-m-d', strtotime('+14 days'));
        return $this->view('followups/index', [
            'title' => t('followups.title'),
            'due' => array_values(array_filter($items, static fn (array $f): bool => $f['due_on'] <= $today)),
            'upcoming' => array_values(array_filter($items, static fn (array $f): bool => $f['due_on'] > $today && $f['due_on'] <= $horizon)),
            'kinds' => $kinds,
            'kind' => $kind,
            'mine' => $mine,
        ]);
    }

    public function store(Request $request): Response
    {
        $back = $request->str('back');
        $back = preg_match('#^/[A-Za-z0-9/_-]*$#', $back) ? $back : '/followups';
        $base = rtrim((string) config('app.base_path', ''), '/');
        if ($base !== '' && str_starts_with($back, $base . '/')) {
            $back = substr($back, strlen($base));
        }
        $validator = Validator::make($request->body(), [
            'kind' => 'required|in:' . implode(',', FollowupService::KINDS),
            'subject_id' => 'required|int',
            'channel' => 'required|in:' . implode(',', FollowupService::CHANNELS),
            'contacted_on' => 'required|date',
            'comment' => 'nullable|max:500',
        ]);
        if (!$validator->passes()) {
            return $this->invalid($back, $request, $validator->errors());
        }
        $data = $validator->validated();
        if (!($this->auth->user()?->can(FollowupService::PERMS[$data['kind']][1]) ?? false)) {
            return $this->redirect($back, 'errors.403.text', 'error');
        }
        try {
            $this->followups->record($data['kind'], (int) $data['subject_id'], $data['channel'], $data['contacted_on'], $data['comment'] ?: null);
        } catch (RuleViolation $e) {
            return $this->refused($back, $e);
        }
        return $this->redirect($back, 'followups.flash_recorded');
    }

    /** @return list<string> */
    private function visibleKinds(): array
    {
        $user = $this->auth->user();
        return array_values(array_filter(FollowupService::KINDS, static fn (string $k): bool => $user?->can(FollowupService::PERMS[$k][0]) ?? false));
    }
}
