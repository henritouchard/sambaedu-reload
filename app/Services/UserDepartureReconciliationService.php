<?php

declare(strict_types=1);

namespace App\Services;

use App\Constants\Ldap\MainGroups;
use App\Facades\SEConfig;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

/**
 * Story 49.3 — réconciliation des DÉPARTS : un utilisateur absent d'un balayage
 * AD complet, réussi ET non tronqué, est désactivé en base et déchu de ses
 * appartenances.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * « Rétrogradation » n'est PAS un geste distinct (relecture du cadrage epic)
 * ─────────────────────────────────────────────────────────────────────────────
 * L'AC-skeleton de l'epic parlait de `removeRole(<cible mappée>)`. Cette
 * formulation est ANTÉRIEURE à la Story 49.1 : depuis, le profil de droits est
 * PORTÉ par le groupe, et l'appartenance EST l'attribution. Retirer les
 * appartenances suffit donc — les events pivot déclenchent
 * `UserGroupUserPivotObserver` → `GroupRightsProfileService::reconcile()`, qui
 * retire les profils devenus injustifiés.
 *
 * Conséquences, toutes volontaires :
 *
 *  - `syncRoles()` et `removeRole()` DIRECTS sont INTERDITS sur ce chemin. Le
 *    volet rôles est intégralement délégué à 49.1 : une seconde logique
 *    d'écriture Spatie divergerait tôt ou tard de la première.
 *  - Les DÉLÉGATIONS MANUELLES survivent au départ (NFR-R2), par construction :
 *    `GroupRightsProfileService` ne révoque que les rôles portés par ≥ 1 groupe
 *    (`carriedRoleIds()`). Un professeur également `user-admin` qui quitte
 *    l'établissement garde `user-admin` en base, sur un compte INACTIF — c'est
 *    le guard runtime (Story 49.2) qui lui refusera la session, pas une
 *    déchéance de droits qu'on ne saurait pas remonter.
 *  - Le `detach()` passe par Eloquent (pivot custom `UserGroupUserPivot`), et
 *    JAMAIS par un `DB::table('user_group_user')->delete()` : sans events, ni
 *    les profils portés ni la synchro FS des classes ne seraient traités —
 *    sinistre silencieux.
 *  - Les events pivot restent ACTIFS pendant la passe (D5) : le détachement
 *    nocturne doit produire EXACTEMENT les mêmes effets que celui du read-back
 *    5 min, qui serait de toute façon arrivé (l'utilisateur a disparu des
 *    member lists AD).
 *
 * ### Ce que la passe ne fait PAS
 *
 *  - Aucune ligne `users` n'est supprimée (soft-disable seul — la piste d'audit
 *    est la raison d'être de la table).
 *  - Le HOME N'EST PAS ARCHIVÉ, contrairement à `UserService::disableUser()`
 *    (geste admin manuel). Le devenir des homes orphelins est un sujet distinct
 *    (corbeille / purge) : une passe nocturne qui déplacerait des téraoctets de
 *    données sur un signal d'annuaire serait la pire façon de le trancher.
 *  - Aucune écriture AD : le compte n'y existe plus.
 *  - Rien n'est fait des utilisateurs encore présents dans l'annuaire mais
 *    sortis d'un groupe : ce cas est DÉJÀ couvert, en continu, par le read-back
 *    groupes des 5 minutes (`UserGroupService::syncFromAd`) + 49.1. La passe
 *    nocturne ne traite QUE les ABSENTS du balayage.
 *
 * ### Course avec le tick delta (bénigne)
 *
 * La sync delta (queue `sync`, toutes les 5 min) et cette passe peuvent se
 * chevaucher. Le point fixe est le même : un utilisateur encore dans l'AD n'est
 * absent d'AUCUN des deux balayages (il n'est donc jamais candidat) ; un
 * utilisateur parti est désactivé par l'un ou par l'autre, et le re-run est un
 * no-op. Aucune synchronisation inter-processus n'est requise.
 *
 * ### La garde (NFR-R1) prime sur tout le reste
 *
 * Voir {@see self::guard()} : la passe abandonne en no-op TOTAL, avec log
 * critique et code de sortie dédié, dès que la santé du balayage est douteuse.
 * Une panne d'annuaire ne doit jamais pouvoir désactiver un établissement.
 */
class UserDepartureReconciliationService
{
    /** Le fetch/import a levé une exception (panne AD, bind impossible). */
    public const ABORT_FETCH_FAILED = 'fetch_failed';

    /** Au moins un groupe principal n'a pas pu être lu : ses membres paraîtraient tous absents. */
    public const ABORT_GROUP_FETCH_FAILED = 'group_fetch_failed';

    /** `getAllMainGroupsDn()` vide : le balayage n'a interrogé aucun groupe. */
    public const ABORT_NO_MAIN_GROUPS = 'no_main_groups';

    /** Balayage vide alors que la base compte des actifs : anormal par construction. */
    public const ABORT_EMPTY_RESULT = 'empty_result';

    /** Trop de candidats au départ : seuil anti-masse dépassé (SEULE condition levable par --force). */
    public const ABORT_THRESHOLD = 'threshold_exceeded';

    /**
     * Réconcilie les départs.
     *
     * @param array{present_guids?: string[], present_logins?: string[]} $presence
     *        Identifiants PRÉSENTS au balayage (les deux familles, D6).
     * @param array{fetch_failed?: bool, fetch_groups_failed?: int, main_groups_found?: int} $health
     *        Santé du balayage — c'est ce qui rend la garde clairvoyante.
     * @param null|callable(string, string):void $logger (niveau, message)
     * @return array{
     *   present_ad:int, fetch_groups_failed:int, main_groups_found:int,
     *   active_base:int, candidates:int, disabled:int, skipped:int, errors:int,
     *   threshold:int, guard_aborted:bool, guard_reason:?string,
     *   dry_run:bool, forced:bool
     * }
     */
    public function run(
        array $presence,
        array $health,
        bool $dryRun = false,
        bool $force = false,
        ?callable $logger = null
    ): array {
        $log = $logger ?? function (string $level, string $message): void {
            Log::log($level, "[UserDepartureReconciliation] {$message}");
        };

        $presentGuids = array_values(array_map('strval', $presence['present_guids'] ?? []));
        $presentLogins = array_values(array_map('strval', $presence['present_logins'] ?? []));

        $stats = [
            'present_ad' => count($presentLogins),
            'fetch_groups_failed' => (int) ($health['fetch_groups_failed'] ?? 0),
            'main_groups_found' => (int) ($health['main_groups_found'] ?? 0),
            'active_base' => 0,
            'candidates' => 0,
            'disabled' => 0,
            'skipped' => 0,
            'errors' => 0,
            'threshold' => 0,
            'guard_aborted' => false,
            'guard_reason' => null,
            'dry_run' => $dryRun,
            'forced' => $force,
        ];

        $stats['active_base'] = $this->activeBaseCount();
        // Hors périmètre : comptes ACTIFS que la passe ne regardera jamais
        // (fédérés, compte protégé, comptes système). Affiché au compte-rendu
        // pour que « présents AD » et « actifs base » ne paraissent pas
        // incohérents (AC9).
        $stats['skipped'] = max(User::query()->where('is_active', true)->count() - $stats['active_base'], 0);
        $stats['threshold'] = $this->disableThreshold($stats['active_base']);

        // PREMIÈRE passe de garde, AVANT tout balayage SQL de candidats : avec
        // `candidates = 0` seules les conditions de SANTÉ (1-4) peuvent se
        // déclencher. Ça évite de matérialiser « tout le parc » en mémoire
        // quand le fetch est en échec — l'aveu d'incident se lit sans ça.
        $reason = $this->guard($presence, $health, $stats['active_base'], 0, $force);

        if ($reason === null) {
            $candidates = $this->planDepartures($presentGuids, $presentLogins);
            $stats['candidates'] = $candidates->count();

            // SECONDE passe : mêmes conditions, plus le seuil, désormais
            // calculable. `guard()` reste une fonction PURE des compteurs.
            $reason = $this->guard($presence, $health, $stats['active_base'], $stats['candidates'], $force);
        } else {
            $candidates = new Collection();
        }

        if ($reason !== null) {
            $stats['guard_aborted'] = true;
            $stats['guard_reason'] = $reason;

            $message = sprintf(
                'Réconciliation des départs ABANDONNÉE (%s) : %d présents AD, %d groupe(s) principal(aux) en échec, '
                . '%d groupe(s) principal(aux) trouvé(s), %d actifs en base, %d candidat(s), seuil %d%s.',
                $this->reasonLabel($reason),
                $stats['present_ad'],
                $stats['fetch_groups_failed'],
                $stats['main_groups_found'],
                $stats['active_base'],
                $stats['candidates'],
                $stats['threshold'],
                $force ? ' (--force actif : seul le seuil est levable)' : ''
            );

            $log('error', $message);
            Log::critical('[UserDepartureReconciliation] ' . $message, $stats);

            return $stats;
        }

        if ($dryRun) {
            $log('info', sprintf(
                'Simulation : %d utilisateur(s) seraient désactivés (%d actifs en base, seuil %d).',
                $stats['candidates'],
                $stats['active_base'],
                $stats['threshold']
            ));

            foreach ($candidates as $candidate) {
                $log('info', "  · départ simulé : {$candidate->login}");
            }

            return $stats;
        }

        foreach ($candidates as $candidate) {
            $result = $this->applyDeparture($candidate);
            $stats['disabled'] += $result['disabled'];
            $stats['errors'] += $result['errors'];

            if ($result['errors'] > 0) {
                $log('error', "Échec de la désactivation de {$candidate->login} — voir les logs.");
            }
        }

        if ($stats['disabled'] > 0) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        Log::info('[UserDepartureReconciliation] passe terminée', $stats);

        $log('info', sprintf(
            '%d utilisateur(s) désactivé(s), %d hors périmètre, %d erreur(s).',
            $stats['disabled'],
            $stats['skipped'],
            $stats['errors']
        ));

        return $stats;
    }

    /**
     * Garde anti-désactivation en masse (AC3 / NFR-R1) — LA pièce maîtresse.
     *
     * Fonction PURE : elle ne lit rien, elle décide sur des compteurs. Retourne
     * la raison d'abandon, ou `null` si la passe peut s'exécuter.
     *
     * Ordre délibéré : les quatre conditions de SANTÉ d'abord, le seuil ensuite.
     * `--force` ne lève QUE la cinquième — le geste de la rentrée scolaire
     * (purge AAF massive, assumée) ne doit jamais devenir le geste qui
     * désactive un établissement pendant une panne d'annuaire.
     *
     * @param array{present_guids?: string[], present_logins?: string[]} $presence
     * @param array{fetch_failed?: bool, fetch_groups_failed?: int, main_groups_found?: int} $health
     */
    public function guard(array $presence, array $health, int $activeBase, int $candidates, bool $force): ?string
    {
        // 1. Le balayage lui-même a échoué.
        if (($health['fetch_failed'] ?? false) === true) {
            return self::ABORT_FETCH_FAILED;
        }

        // 2. Un seul groupe principal en échec suffit : ses membres
        //    apparaîtraient TOUS absents (le compteur n'existait pas avant
        //    49.3 — l'échec était un warning avalé, la garde aurait été aveugle).
        if ((int) ($health['fetch_groups_failed'] ?? 0) > 0) {
            return self::ABORT_GROUP_FETCH_FAILED;
        }

        // 3. Aucun groupe principal trouvé : le balayage n'a rien interrogé.
        if ((int) ($health['main_groups_found'] ?? 0) === 0) {
            return self::ABORT_NO_MAIN_GROUPS;
        }

        // 4. Résultat vide alors que la base compte des actifs AD.
        if (count($presence['present_logins'] ?? []) === 0 && $activeBase >= 1) {
            return self::ABORT_EMPTY_RESULT;
        }

        // 5. Seuil anti-masse — SEULE condition levable par `--force`.
        if (! $force && $candidates > $this->disableThreshold($activeBase)) {
            return self::ABORT_THRESHOLD;
        }

        return null;
    }

    /**
     * Seuil de désactivations toléré : `max(ceil(ratio × base), plancher)`.
     *
     * Le ratio protège les gros parcs, le plancher les petits (cf. le
     * commentaire de `config/sambaedu.php`).
     */
    public function disableThreshold(int $activeBase): int
    {
        $ratio = (float) config('sambaedu.user_sync.reconcile.max_disable_ratio', 0.10);
        $floor = (int) config('sambaedu.user_sync.reconcile.max_disable_floor', 5);

        // Clampé à 0 : une config aberrante (ratio ou plancher négatif) doit
        // rendre la garde plus stricte, jamais la désarmer.
        return max((int) ceil($ratio * max($activeBase, 0)), $floor, 0);
    }

    /**
     * Utilisateurs candidats au départ : actifs, dans le périmètre, et absents
     * du balayage sur les DEUX identifiants (D6).
     *
     * @param string[] $presentGuids  GUID normalisés présents à l'AD
     * @param string[] $presentLogins Logins (lowercase) présents à l'AD
     * @return Collection<int, User>
     */
    public function planDepartures(array $presentGuids, array $presentLogins): Collection
    {
        // Sets de hachage : le prédicat d'absence est en O(1) par utilisateur,
        // et surtout il n'envoie JAMAIS des milliers de valeurs au moteur SQL.
        $guidSet = array_fill_keys(array_map('strtolower', $presentGuids), true);
        $loginSet = array_fill_keys(array_map('strtolower', $presentLogins), true);

        $candidates = new Collection();

        $this->inScopeQuery()
            ->where('is_active', true)
            ->chunkById(500, function (Collection $users) use (&$candidates, $guidSet, $loginSet) {
                foreach ($users as $user) {
                    // Deuxième filet, en PHP, sur la source de vérité du
                    // prédicat (`isSystemAccount`) : l'exclusion SQL est dérivée
                    // des mêmes constantes, mais c'est ici qu'elle fait foi.
                    if ($user->isProtectedAdmin() || MainGroups::isSystemAccount((string) $user->login)) {
                        continue;
                    }

                    $guid = $user->ad_guid === null ? null : strtolower((string) $user->ad_guid);
                    if ($guid !== null && $guid !== '' && isset($guidSet[$guid])) {
                        continue; // présent par GUID
                    }

                    if (isset($loginSet[strtolower((string) $user->login)])) {
                        continue; // présent par login (ligne SE5 sans ad_guid)
                    }

                    $candidates->push($user);
                }
            });

        return $candidates;
    }

    /**
     * Applique un départ, dans sa PROPRE transaction.
     *
     * En production elle est TOP-LEVEL : par construction (D9), la passe court
     * après le commit de l'import, sans transaction englobante — ne pas croire,
     * en lisant « imbriquée », qu'un SAVEPOINT explicite existerait ici. Elle
     * ne devient imbriquée (donc un SAVEPOINT sur Postgres) que sous une
     * transaction appelante, ce qui est le cas dans les tests hôte enveloppés
     * par `RefreshDatabase`. Dans les deux cas le résultat est le même et c'est
     * le seul qui compte : l'échec d'une ligne est isolé et n'avorte pas la
     * passe (piège 25P02 — une transaction englobante avortée ferait échouer en
     * cascade TOUTES les désactivations suivantes). Le pattern est celui de
     * `GroupRightsProfileService::reconcileSafely()`.
     *
     * Fail-soft : l'erreur est loggée et comptée, jamais propagée — une ligne
     * en défaut n'arrête pas la passe.
     *
     * @return array{disabled:int, errors:int}
     */
    public function applyDeparture(User $user): array
    {
        try {
            DB::transaction(function () use ($user): void {
                $user->is_active = false;
                // Hygiène de miroir : `users.role` ne garde AUCUN accès (les
                // droits sont Spatie), on ne câble donc rien dessus — mais
                // laisser 'prof' sur un compte parti est un mensonge de plus
                // dans les écrans.
                $user->role = 'autre';
                $user->save();

                // Events pivot ACTIFS (D5) → observer 49.1 → retrait des
                // profils PORTÉS. Jamais un delete brut sur le pivot.
                $user->groups()->detach();

                // Filet : couvre le cas d'un rôle porté résiduel dont le pivot
                // aurait disparu par un chemin sans events. Idempotent, sans
                // `extraRevocableRoleIds` — les délégations manuelles restent
                // hors d'atteinte.
                app(GroupRightsProfileService::class)->reconcile($user);
            });

            return ['disabled' => 1, 'errors' => 0];
        } catch (\Throwable $e) {
            Log::error('[UserDepartureReconciliation] échec de désactivation', [
                'user_id' => $user->id,
                'login' => $user->login,
                'error' => $e->getMessage(),
            ]);

            return ['disabled' => 0, 'errors' => 1];
        }
    }

    /**
     * Comptes actifs du PÉRIMÈTRE, AVANT la passe — la base du seuil.
     */
    public function activeBaseCount(): int
    {
        return $this->inScopeQuery()->where('is_active', true)->count();
    }

    // ========================================================================
    // INTERNES
    // ========================================================================

    /**
     * Périmètre de la réconciliation (AC5).
     *
     * Exclus, définitivement :
     *  - les comptes `source != 'ad'` (techniciens fédérés) — ils n'existent
     *    dans AUCUN balayage AD par nature : sans cette borne, chaque nuit les
     *    désactiverait. `null` est traité `'ad'` (défaut de la colonne, cohérent
     *    avec `GroupRightsProfileService::isInScope()`) ;
     *  - le compte protégé `admin`, dont `removeRole()` LÈVE ;
     *  - les comptes système : ils sont filtrés du fetch, donc « absents » à
     *    chaque passe — une ligne `users` résiduelle (reprise legacy) serait
     *    sinon désactivée toutes les nuits ;
     *  - **les comptes rattachés à un AUTRE établissement** (correction de
     *    review). C'est la même règle que les deux précédentes, appliquée à un
     *    troisième filtre du balayage : quand un code établissement est
     *    configuré, `fetchUsersFromAd()` écarte des sets de présence tout
     *    utilisateur qui ne matche pas `establishmentDn`. Un compte d'un autre
     *    établissement est donc absent de CHAQUE balayage par construction, et
     *    serait déclaré parti toutes les nuits.
     *
     * L'invariant est le même dans les quatre cas, et c'est celui qui doit
     * gouverner toute évolution de ce périmètre : **ce que le balayage ne peut
     * pas voir ne peut pas être déclaré parti.** Le périmètre de la
     * réconciliation doit rester le miroir exact de celui du fetch — toute
     * asymétrie se paie en désactivations silencieuses, sous le seuil de la
     * garde puisqu'elles portent sur de petits effectifs.
     *
     * Le prédicat des comptes système est DÉRIVÉ des constantes `MainGroups`
     * (jamais recopié) et doublé d'un contrôle PHP dans la boucle ; celui des
     * externes réutilise le scope `User::external()` pour la même raison.
     */
    private function inScopeQuery(): Builder
    {
        $query = User::query()
            ->where(function (Builder $q): void {
                $q->where('source', 'ad')->orWhereNull('source');
            })
            ->whereRaw('LOWER(login) <> ?', [strtolower(User::PROTECTED_ADMIN_LOGIN)]);

        $currentCode = trim((string) (SEConfig::getCurrentEstablishmentCode() ?? ''));

        if ($currentCode !== '' && $currentCode !== '0') {
            $query->whereNot(function (Builder $q) use ($currentCode): void {
                $q->external($currentCode);
            });
        }

        foreach (MainGroups::SYSTEM_ACCOUNTS as $account) {
            $query->whereRaw('LOWER(login) <> ?', [strtolower($account)]);
        }

        foreach (MainGroups::SYSTEM_ACCOUNT_PREFIXES as $prefix) {
            $query->whereRaw('LOWER(login) NOT LIKE ?', [strtolower($prefix) . '%']);
        }

        return $query;
    }

    private function reasonLabel(string $reason): string
    {
        return match ($reason) {
            self::ABORT_FETCH_FAILED => 'balayage AD en échec',
            self::ABORT_GROUP_FETCH_FAILED => 'au moins un groupe principal illisible',
            self::ABORT_NO_MAIN_GROUPS => 'aucun groupe principal trouvé dans l\'AD',
            self::ABORT_EMPTY_RESULT => 'balayage vide alors que la base compte des actifs',
            self::ABORT_THRESHOLD => 'seuil anti-désactivation en masse dépassé',
            default => $reason,
        };
    }
}
