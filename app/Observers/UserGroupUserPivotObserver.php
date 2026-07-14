<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Pivot\UserGroupUserPivot;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Filesystem\ShareService;
use App\Services\UserGroupService;
use Illuminate\Support\Facades\Log;

/**
 * Story 5.2 (D5=A) — Observer sur le pivot `user_group_user`.
 *
 * Quand un user est attaché ou détaché d'un `UserGroup` de type `'classe'`,
 * délègue à `ShareService::syncUserClassMemberships()` pour synchroniser
 * les ACLs filesystem (création / archivage `<eleve>` perso).
 *
 * Story 42.2 (D4) — s'y ajoute l'ancrage `updated()` : un CHANGEMENT DE RÔLE
 * d'arête (`wasChanged('role')`) reprojette le groupe concerné vers l'AD
 * (`UserGroupService::resyncGroupAdProjection()`). Les events created/deleted
 * n'y participent PAS : chaque canal d'attach/detach existant a déjà sa
 * projection AD (createGroup/updateGroup = appel explicite ; drawer groupes =
 * écrit l'AD lui-même ; imports = AD-first). Le changement de rôle est la
 * dimension nouvelle, invisible des handlers existants.
 *
 * Filtres :
 *  - Le groupe doit être de type `'classe'` (FS) / `'classe'|'equipe'` (resync
 *    AD) ; les rattachements `role`, `function`, etc. sont ignorés.
 *  - Désactivable globalement via `UserGroupUserPivotObserver::disableSync()`
 *    pour les tests/imports massifs (cohérent pattern `UserGroupObserver`) —
 *    ce flag suspend AUSSI le resync AD (guard commun).
 *  - Resync AD suspendu SEUL via `disableAdResync()` pendant `syncFromAd`
 *    (read-back) : le `sync()` associatif y flippe des rôles en masse — sans
 *    suspension, chaque flip déclencherait une reprojection LDAP (tempête
 *    d'I/O), et écrire l'AD pendant qu'on le lit est conceptuellement faux.
 *    Flag DÉDIÉ : ne JAMAIS réutiliser `$syncEnabled`, qui gouverne la synchro
 *    FS ShareService (laquelle DOIT continuer à tourner au read-back).
 *
 * Fail-soft : aucune exception n'est propagée — un échec côté ShareService ou
 * côté projection AD est loggé et l'opération Eloquent (attach/detach/update)
 * reste valide. Le filet de sécurité est la commande
 * `php artisan shares:resync-class --classe=X` (Story 5.2 D5=D) et le bouton
 * « Synchroniser avec AD » côté groupes.
 */
class UserGroupUserPivotObserver
{
    public static bool $syncEnabled = true;

    /**
     * Story 42.2 (D4/T3.1) — flag DÉDIÉ au resync AD sur changement de rôle.
     * Distinct de `$syncEnabled` (synchro FS ShareService) : pendant le
     * read-back `syncFromAd`, seul le resync AD est suspendu.
     */
    public static bool $adResyncEnabled = true;

    public static function disableSync(): void
    {
        self::$syncEnabled = false;
    }

    public static function enableSync(): void
    {
        self::$syncEnabled = true;
    }

    public static function disableAdResync(): void
    {
        self::$adResyncEnabled = false;
    }

    public static function enableAdResync(): void
    {
        self::$adResyncEnabled = true;
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

    /**
     * Story 42.2 (D4/T3.2) — resync AD du groupe sur CHANGEMENT DE RÔLE d'arête.
     *
     * Déclenché par les writes Eloquent du pivot custom (`sync()` associatif /
     * `updateExistingPivot` → `fill()->save()` → event `updated`, uniquement si
     * dirty — un sync sans changement ne déclenche rien, bon pour
     * l'idempotence). Les writes `DB::table('user_group_user')` bruts
     * (backfill 42.1, MergeLegacyUserGroups) ne passent PAS par ici — voulu
     * (actions de migration).
     */
    public function updated(UserGroupUserPivot $pivot): void
    {
        // Guard commun : `$syncEnabled=false` (imports users, tests) suspend
        // AUSSI le resync AD ; `$adResyncEnabled=false` = read-back en cours.
        if (! self::$syncEnabled || ! self::$adResyncEnabled) {
            return;
        }

        if (! $pivot->wasChanged('role')) {
            return;
        }

        $groupId = (int) ($pivot->user_group_id ?? 0);
        if ($groupId <= 0) {
            return;
        }

        $group = UserGroup::find($groupId);
        // Review 42.2 #1 — prédicat aligné sur le chokepoint (`class` toléré :
        // fixtures historiques `type='class'` traitées classe-like).
        if (! $group || ! in_array($group->type, ['class', 'classe', 'equipe'], true)) {
            return; // hors scope — le rôle d'arête ne route rien ailleurs.
        }

        // Fail-soft (pattern dispatch() ci-dessous) : un échec de projection
        // AD ne casse JAMAIS l'écriture pivot qui vient d'aboutir.
        try {
            app(UserGroupService::class)->resyncGroupAdProjection($group);
        } catch (\Throwable $e) {
            Log::error('UserGroupUserPivotObserver: échec resync projection AD (changement de rôle d\'arête)', [
                'user_id' => (int) ($pivot->user_id ?? 0),
                'group_id' => $groupId,
                'error' => $e->getMessage(),
            ]);
        }
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
