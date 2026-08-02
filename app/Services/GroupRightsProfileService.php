<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Story 49.1 — matérialisation des profils de droits PORTÉS par les groupes.
 *
 * Modèle : un `UserGroup` peut porter au plus un profil (`rights_profile_id`,
 * FK nullable vers `roles`). **L'appartenance au groupe EST l'attribution du
 * profil** : un utilisateur détient l'UNION des profils portés par ses groupes.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * INVARIANT CENTRAL — `syncRoles` est **INTERDIT** dans ce service.
 * ─────────────────────────────────────────────────────────────────────────────
 * `syncRoles` est RETRANCHANT : il détache TOUS les rôles avant de rattacher
 * ceux qu'on lui passe. L'employer ici effacerait, sur tout le parc, les
 * **délégations manuelles** posées au drawer de droits (`user-admin`,
 * `technicien`, profils custom…) — un sinistre silencieux. La réconciliation
 * n'utilise donc QUE des `assignRole` / `removeRole` **ciblés** :
 *
 *  - on ASSIGNE les profils portés manquants ;
 *  - on RETIRE uniquement les rôles appartenant à l'ensemble « portés »
 *    (`carriedRoleIds()`, lu en base) que l'appartenance ne justifie plus ;
 *  - tout rôle porté par AUCUN groupe est **INTACT** — c'est une délégation.
 *
 * Un test-verrou (`GroupRightsProfileServiceTest`) échoue si quelqu'un
 * « simplifie » un jour en `syncRoles`. Les trois usages légitimes de
 * `syncRoles` dans l'application restent hors de ce chemin :
 * `FederatedLoginController` (externe mono-rôle) et `UserSyncService`
 * (compte protégé `admin`).
 *
 * ### Périmètre borné (AC3 / D5)
 *
 * Seuls les comptes `users.source = 'ad'` sont réconciliés. Les comptes
 * `source='federated'` n'appartiennent à aucun groupe et leur login fait
 * `syncRoles([$role])` : sans cette borne, un groupe portant `technicien`
 * ferait boucler retrait-réconciliation / re-pose-login sur chaque technicien
 * externe. Le compte protégé `admin` est sauté AVANT tout appel — son
 * `removeRole()` surchargé lève `ProtectedAdminRightsException`.
 *
 * ### Classification DÉRIVÉE (D3)
 *
 * « Rôle porté » = profil référencé par au moins un groupe, `distinct` LU EN
 * BASE. Aucune constante, aucune colonne d'origine sur `model_has_roles`,
 * aucun cache (la fraîcheur prime, et le coût est borné par l'early-return de
 * l'observer).
 *
 * ### Limite connue — écritures pivot hors Eloquent
 *
 * Les writes bruts `DB::table('user_group_user')` (backfill 42.1,
 * `MergeLegacyUserGroups`) ne passent PAS par l'observer pivot : ce sont des
 * actions de migration, couvertes par le filet `reprojectAll()`
 * (`php artisan users:reproject-group-profiles`).
 *
 * ### Ce que `reprojectAll()` ne rattrape PAS — disparition d'un groupe porteur
 *
 * Attention à la dissymétrie : le filet ci-dessus vaut pour les écritures
 * d'**appartenance** manquées, jamais pour la disparition d'un **porteur**. Dès
 * qu'un groupe porteur est supprimé, son profil sort de `carriedRoleIds()` et
 * devient indistinguable d'une délégation manuelle : la réconciliation
 * générique ne le retire plus — sur AUCUNE passe, y compris répétée. C'est le
 * même piège que le dernier porteur (D4), et il se traite de la même façon :
 * l'appelant capture l'information AVANT la suppression et la passe en rôles
 * révocables additionnels APRÈS. Deux appelants le font :
 * {@see self::setProfile()} (retrait/changement) et
 * {@see self::reconcileOrphanedProfiles()} (cleanup du balayage AD,
 * `UserGroupService::syncFromAd()`).
 */
class GroupRightsProfileService
{
    /**
     * Ensemble des rôles PORTÉS par au moins un groupe (ids `roles.id`).
     *
     * Lu en base à CHAQUE appel — jamais une constante, jamais un cache. C'est
     * l'ensemble des rôles « pilotés par l'appartenance » : leur complément est
     * l'ensemble des délégations manuelles, qu'on ne touche jamais.
     *
     * @return int[]
     */
    public function carriedRoleIds(): array
    {
        return UserGroup::query()
            ->whereNotNull('rights_profile_id')
            ->distinct()
            ->pluck('rights_profile_id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Groupes portant un profil donné — messages d'intégrité (AC6) et raisons
     * affichées dans les deux drawers (AC8).
     *
     * @return Collection<int, UserGroup>
     */
    public function groupsCarrying(int $roleId): Collection
    {
        return UserGroup::query()
            ->where('rights_profile_id', $roleId)
            ->orderBy('name')
            ->get();
    }

    /**
     * Libellés des groupes porteurs d'un profil (pour les messages UI).
     *
     * @return string[]
     */
    public function carrierNames(int $roleId): array
    {
        return $this->groupsCarrying($roleId)
            ->map(fn(UserGroup $g) => $g->display_name_or_name)
            ->values()
            ->all();
    }

    /**
     * Carte `roleId => [noms des groupes porteurs]`, en UNE requête.
     *
     * Consommée par les drawers et l'onglet Profils pour afficher l'état
     * « porté » sans N+1.
     *
     * @return array<int, string[]>
     */
    public function carriersByRoleId(): array
    {
        $map = [];

        UserGroup::query()
            ->whereNotNull('rights_profile_id')
            ->orderBy('name')
            ->get(['id', 'name', 'display_name', 'rights_profile_id'])
            ->each(function (UserGroup $group) use (&$map) {
                $map[(int) $group->rights_profile_id][] = $group->display_name_or_name;
            });

        return $map;
    }

    /**
     * Réconcilie les rôles Spatie d'UN utilisateur avec ses appartenances.
     *
     * @param  User  $user
     * @param  int[] $extraRevocableRoleIds Rôles additionnels rendus révocables
     *         pour CETTE passe. Passé UNIQUEMENT par {@see self::setProfile()}
     *         (piège du dernier porteur, D4) — jamais par l'observer ni par la
     *         commande.
     * @return array{assigned:int, removed:int, skipped:bool}
     */
    public function reconcile(User $user, array $extraRevocableRoleIds = []): array
    {
        // Transactionnel comme les deux autres points d'entrée
        // (`setProfile`, `reprojectAll` via `reconcileSafely`) : les
        // `assignRole` précèdent les `removeRole` dans le plan, une erreur en
        // cours de pose laisserait sinon les assignations déjà commitées et
        // sauterait les retraits de la même passe. Imbriquée dans la
        // transaction appelante (SAVEPOINT sur Postgres) quand il y en a une —
        // c'est le cas sur le chemin observer, appelé pendant une écriture
        // pivot.
        return DB::transaction(fn() => $this->reconcileWithCarriedSet(
            $user,
            $this->carriedRoleIds(),
            $extraRevocableRoleIds
        ));
    }

    /**
     * Réconcilie les anciens membres de groupes porteurs qui viennent d'être
     * SUPPRIMÉS, en rendant leurs profils devenus orphelins explicitement
     * révocables.
     *
     * Appelée par le cleanup du balayage AD (`UserGroupService::syncFromAd()`),
     * qui supprime les groupes disparus de l'annuaire par un `delete()` de
     * masse — donc sans aucun event Eloquent, ni sur le groupe ni sur le pivot.
     * Sans ce rattrapage, le profil du groupe supprimé resterait attribué à vie
     * à ses anciens membres (cf. docblock de classe).
     *
     * L'appelant DOIT capturer `$userIds` et `$orphanRoleIds` **avant** la
     * suppression, et appeler cette méthode **après** : la réconciliation
     * s'appuie sur l'état d'appartenance post-suppression pour recalculer
     * l'union attendue.
     *
     * @param  int[] $userIds        Anciens membres des groupes supprimés.
     * @param  int[] $orphanRoleIds  Profils portés par les groupes supprimés.
     * @return array{users:int, assigned:int, removed:int, errors:int}
     */
    public function reconcileOrphanedProfiles(array $userIds, array $orphanRoleIds): array
    {
        $stats = ['users' => 0, 'assigned' => 0, 'removed' => 0, 'errors' => 0];

        $orphanRoleIds = array_values(array_unique(array_map('intval', $orphanRoleIds)));

        if ($orphanRoleIds === [] || $userIds === []) {
            return $stats;
        }

        $carried = $this->carriedRoleIds();

        User::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $userIds))))
            ->where(function ($q) {
                $q->where('source', 'ad')->orWhereNull('source');
            })
            ->chunkById(200, function (Collection $users) use (&$stats, $carried, $orphanRoleIds) {
                foreach ($users as $user) {
                    $stats['users']++;
                    $result = $this->reconcileSafely($user, $carried, $orphanRoleIds);
                    $stats['assigned'] += $result['assigned'];
                    $stats['removed'] += $result['removed'];
                    $stats['errors'] += $result['errors'];
                }
            });

        if ($stats['removed'] > 0 || $stats['assigned'] > 0) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        Log::info('GroupRightsProfileService: profils orphelins réconciliés après suppression de groupe(s) porteur(s)', [
            'orphan_role_ids' => $orphanRoleIds,
            'users' => $stats['users'],
            'removed' => $stats['removed'],
            'errors' => $stats['errors'],
        ]);

        return $stats;
    }

    /**
     * Pose / change / retire le profil porté par un groupe, et re-projette ses
     * membres DANS LE MÊME GESTE (AC4).
     *
     * **Piège du dernier porteur (D4).** Si ce groupe était le DERNIER porteur
     * de l'ancien profil, celui-ci sort de l'ensemble `carriedRoleIds()` juste
     * après l'écriture : la réconciliation générique ne le retirerait plus et
     * il resterait orphelin sur tous les membres, indistinguable d'une
     * délégation. On passe donc explicitement l'ancien `rights_profile_id` en
     * rôle révocable additionnel sur cette passe — et sur cette passe seule.
     *
     * Sémantique assumée : un membre du groupe AU MOMENT du changement perd
     * l'ancien profil (même s'il le tenait aussi par délégation — indistinguable
     * ici) ; un non-membre le garde.
     *
     * @return array{changed:bool, users:int, assigned:int, removed:int, errors:int}
     */
    public function setProfile(UserGroup $group, ?int $roleId, ?User $actor = null): array
    {
        $previousRoleId = $group->rights_profile_id === null ? null : (int) $group->rights_profile_id;

        if ($previousRoleId === $roleId) {
            // No-op propre : ni écriture, ni re-projection.
            return ['changed' => false, 'users' => 0, 'assigned' => 0, 'removed' => 0, 'errors' => 0];
        }

        if ($roleId !== null) {
            $exists = Role::query()
                ->where('id', $roleId)
                ->where('guard_name', 'web')
                ->exists();

            if (! $exists) {
                throw new \InvalidArgumentException("Profil de droits introuvable (id={$roleId}).");
            }
        }

        return DB::transaction(function () use ($group, $roleId, $previousRoleId, $actor) {
            $group->rights_profile_id = $roleId;
            $group->save();

            // Le carried set est relu APRÈS l'écriture : si ce groupe était le
            // dernier porteur de l'ancien profil, celui-ci n'y est plus — d'où
            // le rôle révocable additionnel ci-dessous.
            $carried = $this->carriedRoleIds();
            $extra = $previousRoleId !== null ? [$previousRoleId] : [];

            $stats = ['changed' => true, 'users' => 0, 'assigned' => 0, 'removed' => 0, 'errors' => 0];

            // Membres du groupe, chunkés sur `users` (et non sur la relation
            // N:N — `chunkById` sur un BelongsToMany impose de qualifier la
            // clé et se prête mal au pivot custom).
            $memberIds = DB::table('user_group_user')
                ->where('user_group_id', $group->id)
                ->pluck('user_id');

            User::query()
                ->whereIn('id', $memberIds)
                ->where(function ($q) {
                    $q->where('source', 'ad')->orWhereNull('source');
                })
                ->chunkById(200, function (Collection $users) use (&$stats, $carried, $extra) {
                    foreach ($users as $member) {
                        $stats['users']++;
                        $result = $this->reconcileSafely($member, $carried, $extra);
                        $stats['assigned'] += $result['assigned'];
                        $stats['removed'] += $result['removed'];
                        $stats['errors'] += $result['errors'];
                    }
                });

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            Log::info('GroupRightsProfileService: profil porté modifié', [
                'group_id' => $group->id,
                'group' => $group->name,
                'previous_role_id' => $previousRoleId,
                'new_role_id' => $roleId,
                'actor' => $actor?->login,
                'members_reprojected' => $stats['users'],
            ]);

            return $stats;
        });
    }

    /**
     * Re-projection idempotente du parc entier (AC4) : backfill au déploiement,
     * filet des chemins qui n'émettent pas d'events pivot, réparation.
     *
     * Une erreur sur un utilisateur n'arrête JAMAIS la boucle : chaque user est
     * traité dans sa propre transaction (imbriquée dans celle de l'appelant
     * quand il y en a une — sur Postgres, un SAVEPOINT ; top-level sinon, cas
     * de la commande artisan). Sans ça, la première erreur avorterait la
     * transaction externe et toutes les suivantes échoueraient en cascade
     * (25P02).
     *
     * @param  null|callable(string, string):void $logger  (niveau, message)
     * @return array{users:int, assigned:int, removed:int, skipped:int, errors:int}
     */
    public function reprojectAll(?callable $logger = null, bool $dryRun = false): array
    {
        $log = $logger ?? function (string $level, string $message): void {
            Log::log($level, $message);
        };

        // Instantané de la passe : la classification est lue une fois au début
        // (ce n'est pas un cache — la passe suivante relit).
        $carried = $this->carriedRoleIds();

        $stats = ['users' => 0, 'assigned' => 0, 'removed' => 0, 'skipped' => 0, 'errors' => 0];

        User::query()
            ->where(function ($q) {
                $q->where('source', 'ad')->orWhereNull('source');
            })
            ->chunkById(500, function (Collection $users) use (&$stats, $carried, $dryRun, $log) {
                foreach ($users as $user) {
                    $stats['users']++;

                    if ($dryRun) {
                        $plan = $this->planFor($user, $carried, []);
                        if ($plan === null) {
                            $stats['skipped']++;
                            continue;
                        }
                        $stats['assigned'] += count($plan['assign']);
                        $stats['removed'] += count($plan['remove']);
                        continue;
                    }

                    $result = $this->reconcileSafely($user, $carried, []);
                    $stats['assigned'] += $result['assigned'];
                    $stats['removed'] += $result['removed'];
                    $stats['errors'] += $result['errors'];
                    if ($result['skipped']) {
                        $stats['skipped']++;
                    }

                    if ($result['errors'] > 0) {
                        $log('error', "Réconciliation en échec pour {$user->login}");
                    }
                }
            });

        if (! $dryRun) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $stats;
    }

    // ========================================================================
    // INTERNES
    // ========================================================================

    /**
     * Réconciliation fail-soft d'un user, dans sa propre transaction imbriquée
     * (SAVEPOINT sur Postgres). Une erreur est loggée et comptée, jamais
     * propagée : elle ne doit ni casser l'écriture pivot qui vient d'aboutir,
     * ni arrêter une boucle de re-projection.
     *
     * @param  int[] $carried
     * @param  int[] $extraRevocableRoleIds
     * @return array{assigned:int, removed:int, skipped:bool, errors:int}
     */
    private function reconcileSafely(User $user, array $carried, array $extraRevocableRoleIds): array
    {
        try {
            $result = DB::transaction(
                fn() => $this->reconcileWithCarriedSet($user, $carried, $extraRevocableRoleIds)
            );

            return $result + ['errors' => 0];
        } catch (\Throwable $e) {
            Log::error('GroupRightsProfileService: échec de réconciliation', [
                'user_id' => $user->id,
                'login' => $user->login,
                'error' => $e->getMessage(),
            ]);

            return ['assigned' => 0, 'removed' => 0, 'skipped' => false, 'errors' => 1];
        }
    }

    /**
     * Cœur de la réconciliation, à ensemble « portés » donné.
     *
     * @param  int[] $carried
     * @param  int[] $extraRevocableRoleIds
     * @return array{assigned:int, removed:int, skipped:bool}
     */
    private function reconcileWithCarriedSet(User $user, array $carried, array $extraRevocableRoleIds): array
    {
        $plan = $this->planFor($user, $carried, $extraRevocableRoleIds);

        if ($plan === null) {
            return ['assigned' => 0, 'removed' => 0, 'skipped' => true];
        }

        // `assignRole` / `removeRole` CIBLÉS. `syncRoles` est interdit ici :
        // il effacerait les délégations manuelles (cf. docblock de classe).
        foreach ($plan['assign'] as $roleId) {
            $user->assignRole($roleId);
        }
        foreach ($plan['remove'] as $roleId) {
            $user->removeRole($roleId);
        }

        return [
            'assigned' => count($plan['assign']),
            'removed' => count($plan['remove']),
            'skipped' => false,
        ];
    }

    /**
     * Calcule (sans écrire) les rôles à poser et à retirer pour un user.
     *
     * @param  int[] $carried
     * @param  int[] $extraRevocableRoleIds
     * @return null|array{assign:int[], remove:int[]} `null` = utilisateur hors périmètre
     */
    private function planFor(User $user, array $carried, array $extraRevocableRoleIds): ?array
    {
        if (! $this->isInScope($user)) {
            return null;
        }

        // Union ATTENDUE : les profils portés par les groupes du user.
        $expected = UserGroup::query()
            ->whereNotNull('rights_profile_id')
            ->whereIn(
                'id',
                DB::table('user_group_user')->select('user_group_id')->where('user_id', $user->id)
            )
            ->distinct()
            ->pluck('rights_profile_id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();

        // Ensemble RÉVOCABLE : rôles pilotés par l'appartenance. Tout ce qui
        // n'y est pas est une délégation manuelle — INTOUCHABLE.
        $revocable = array_values(array_unique(array_merge(
            $carried,
            array_map('intval', $extraRevocableRoleIds)
        )));

        $current = $user->roles()->pluck('roles.id')->map(fn($id) => (int) $id)->all();

        return [
            'assign' => array_values(array_diff($expected, $current)),
            'remove' => array_values(array_intersect(array_diff($revocable, $expected), $current)),
        ];
    }

    /**
     * Périmètre de la réconciliation (AC3 / AC10).
     *
     * `source` null est traité comme `'ad'` : c'est le DÉFAUT de la colonne
     * (migration `2026_06_01_120100`), les lignes antérieures n'ont pas d'autre
     * origine possible.
     */
    private function isInScope(User $user): bool
    {
        if ($user->isProtectedAdmin()) {
            return false; // `removeRole` y lève ProtectedAdminRightsException.
        }

        return ($user->source ?? 'ad') === 'ad';
    }
}
