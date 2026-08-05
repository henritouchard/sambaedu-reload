<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Pivot\UserGroupUserPivot;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Filesystem\ClassTreeShareService;
use App\Services\Filesystem\ShareService;
use App\Services\GroupRightsProfileService;
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
 *  - Réconciliation des PROFILS DE DROITS (Story 49.1) suspendue SEULE via
 *    `disableProfileReconcile()`. Elle est ancrée AVANT le guard
 *    `$syncEnabled` — cf. le docblock de `created()`.
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

    /**
     * Story 49.1 (D9) — flag DÉDIÉ à la réconciliation des profils de droits
     * portés par les groupes. Pattern documenté 42.2 : chaque canal son flag.
     *
     * Ne JAMAIS le confondre avec `$syncEnabled` (synchro FS ShareService) ni
     * avec `$adResyncEnabled` (projection AD). `$syncEnabled` est coupé par des
     * chemins — import users, tests FS — qui DOIVENT malgré tout matérialiser
     * les rôles : c'est précisément pourquoi le hook profils est ancré AVANT
     * ce guard, sous son propre flag.
     */
    public static bool $profileReconcileEnabled = true;

    public static function disableProfileReconcile(): void
    {
        self::$profileReconcileEnabled = false;
    }

    public static function enableProfileReconcile(): void
    {
        self::$profileReconcileEnabled = true;
    }

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

    /**
     * Story 49.1 (D9) — le hook « profils de droits » est appelé AVANT le guard
     * `$syncEnabled`, DÉLIBÉRÉMENT : ce flag est coupé par des chemins qui
     * doivent quand même matérialiser les rôles (import users
     * `persistUserGroupsToSql`, tests FS). Il a son propre flag.
     */
    public function created(UserGroupUserPivot $pivot): void
    {
        $this->reconcileProfiles($pivot);
        $this->queueTreeReconciliation($pivot);

        if (! self::$syncEnabled) {
            return;
        }
        $this->dispatch($pivot, isAttach: true);
    }

    public function deleted(UserGroupUserPivot $pivot): void
    {
        $this->reconcileProfiles($pivot);
        $this->queueTreeReconciliation($pivot);

        if (! self::$syncEnabled) {
            return;
        }
        $this->dispatch($pivot, isAttach: false);
    }

    /**
     * Story 60.5 — DEUX AUTORITÉS, DEUX ZONES, AUCUN CONFLIT.
     *
     * Cette méthode enfile la réconciliation de l'arbre NEUF, gouverné par le plan.
     * {@see dispatch()}, juste en dessous, gouverne l'arbre HISTORIQUE (chemin figé
     * de la story 5.2, archivage implicite compris) et ne bouge pas d'une ligne. Les
     * deux zones sont DISJOINTES par construction — la garde de chemin du backend
     * n'a aucun jeton pour l'arbre historique — et c'est cette disjonction qui fait
     * tenir « une seule autorité d'écriture par zone » sans qu'aucune des deux ne
     * cède. Ne surtout pas « factoriser » les deux voies : elles décrivent des états
     * désirés différents, sur des arbres différents, et leur seule ressemblance est
     * de partir du même événement.
     *
     * **Elle ne CRÉE aucun partage.** Un rattachement n'est pas une demande de
     * partage : elle réconcilie les arbres qui existent déjà. Le nœud personnel du
     * nouvel élève apparaît donc par réconciliation, jamais par un chemin
     * parallèle qui saurait créer un dossier tout seul.
     *
     * **Elle est ancrée AVANT le garde de projection d'annuaire**, sous
     * l'interrupteur dédié du canal fichiers : ce drapeau-là est coupé par des
     * chemins — imports, tests d'annuaire — qui n'ont aucune raison de suspendre
     * les fichiers.
     */
    private function queueTreeReconciliation(UserGroupUserPivot $pivot): void
    {
        $groupId = (int) ($pivot->user_group_id ?? 0);
        if ($groupId <= 0) {
            return;
        }

        $group = UserGroup::find($groupId);
        if (! $group) {
            return;
        }

        app(ClassTreeShareService::class)->reconcileExistingQuietly($group);
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
        // Story 60.5 — un changement de RÔLE D'ARÊTE change les audiences de
        // l'arbre neuf (l'équipe enseignante EST un rôle d'arête depuis l'Epic 42) :
        // il enfile sa réconciliation, sous son propre interrupteur et avant les
        // gardes de l'annuaire.
        if ($pivot->wasChanged('role')) {
            $this->queueTreeReconciliation($pivot);
        }

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

    /**
     * Story 49.1 (AC2 / D9) — matérialise les profils de droits après un
     * changement d'appartenance (attach ou detach, quel que soit le canal).
     *
     * **Early-return borné.** Si le groupe du pivot ne porte AUCUN profil, la
     * réconciliation est un no-op MATHÉMATIQUE : l'union attendue comme
     * l'ensemble révocable ne dépendent que des groupes PORTEURS. Une seule
     * requête légère (le `rights_profile_id` du groupe) suffit à le décider —
     * c'est ce qui borne le coût d'un import massif, où la quasi-totalité des
     * ~600 groupes ne porte rien.
     *
     * Le groupe INTROUVABLE est le seul cas où l'on réconcilie sans savoir :
     * il correspond à un detach accompagnant la suppression du groupe, donc à
     * un porteur potentiellement disparu. (Le cleanup de sync supprime en
     * masse via `whereNotIn(...)->delete()`, qui n'émet aucun event pivot : ce
     * chemin-là relève du filet `users:reproject-group-profiles`.)
     *
     * **Fail-soft intégral** : un échec de réconciliation est loggé et ne casse
     * JAMAIS l'écriture pivot qui vient d'aboutir. Aucune récursion possible :
     * la réconciliation n'écrit que `model_has_roles`, jamais le pivot.
     */
    private function reconcileProfiles(UserGroupUserPivot $pivot): void
    {
        if (! self::$profileReconcileEnabled) {
            return;
        }

        $userId = (int) ($pivot->user_id ?? 0);
        $groupId = (int) ($pivot->user_group_id ?? 0);
        if ($userId <= 0 || $groupId <= 0) {
            return;
        }

        try {
            $group = UserGroup::query()
                ->whereKey($groupId)
                ->first(['id', 'rights_profile_id']);

            if ($group !== null && $group->rights_profile_id === null) {
                return; // groupe non porteur : no-op mathématique.
            }

            $user = User::find($userId);
            if (! $user) {
                return;
            }

            app(GroupRightsProfileService::class)->reconcile($user);
        } catch (\Throwable $e) {
            Log::error('UserGroupUserPivotObserver: échec réconciliation des profils de droits', [
                'user_id' => $userId,
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
