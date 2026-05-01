<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Pivot\UserGroupUserPivot;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Filesystem\ShareService;
use Illuminate\Support\Facades\Log;

/**
 * Story 5.2 (D5=A) — Observer sur le pivot `user_group_user`.
 *
 * Quand un user est attaché ou détaché d'un `UserGroup` de type `'classe'`,
 * délègue à `ShareService::syncUserClassMemberships()` pour synchroniser
 * les ACLs filesystem (création / archivage `<eleve>` perso).
 *
 * Filtres :
 *  - Le groupe doit être de type `'classe'` ; les rattachements `role`,
 *    `function`, `equipe`, etc. sont ignorés (pas de partage FS dédié).
 *  - Désactivable globalement via `UserGroupUserPivotObserver::disableSync()`
 *    pour les tests/imports massifs (cohérent pattern `UserGroupObserver`).
 *
 * Fail-soft : aucune exception n'est propagée — un échec côté ShareService
 * est loggé et l'opération Eloquent (attach/detach) reste valide. Le filet
 * de sécurité est la commande `php artisan shares:resync-class --classe=X`
 * (Story 5.2 D5=D).
 */
class UserGroupUserPivotObserver
{
    public static bool $syncEnabled = true;

    public static function disableSync(): void
    {
        self::$syncEnabled = false;
    }

    public static function enableSync(): void
    {
        self::$syncEnabled = true;
    }

    public function created(UserGroupUserPivot $pivot): void
    {
        if (! self::$syncEnabled) {
            return;
        }
        $this->dispatch($pivot, isAttach: true);
    }

    public function deleted(UserGroupUserPivot $pivot): void
    {
        if (! self::$syncEnabled) {
            return;
        }
        $this->dispatch($pivot, isAttach: false);
    }

    private function dispatch(UserGroupUserPivot $pivot, bool $isAttach): void
    {
        $userId = (int) ($pivot->user_id ?? 0);
        $groupId = (int) ($pivot->user_group_id ?? 0);
        if ($userId <= 0 || $groupId <= 0) {
            return;
        }

        $group = UserGroup::find($groupId);
        if (! $group || $group->type !== 'classe') {
            return; // hors scope
        }

        $user = User::find($userId);
        if (! $user) {
            return;
        }

        try {
            $service = app(ShareService::class);
            if ($isAttach) {
                $service->syncUserClassMemberships(
                    $user,
                    oldClassIds: [],
                    newClassIds: [$groupId]
                );
            } else {
                $service->syncUserClassMemberships(
                    $user,
                    oldClassIds: [$groupId],
                    newClassIds: []
                );
            }
        } catch (\Throwable $e) {
            Log::error('UserGroupUserPivotObserver: échec sync ShareService', [
                'user_id' => $userId,
                'group_id' => $groupId,
                'is_attach' => $isAttach,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
