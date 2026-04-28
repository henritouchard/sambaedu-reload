<?php

declare(strict_types=1);

namespace App\Services\Permissions;

use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Facades\SEConfig;
use App\Jobs\SyncGpoJob;
use App\LdapModels\LdapRightGroup;
use App\Models\Delegation;
use App\Models\DelegationHistory;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Services\DelegationHistoryService;
use App\Services\PermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LdapRecord\Models\ActiveDirectory\Group as LdapGroup;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Service d'orchestration de la migration one-shot des droits bitmask legacy
 * vers les rôles et délégations Spatie (Story 7.3).
 *
 * Pipeline :
 *  1. `migrateRightsGroupAssignments()` — scan de la branche `rights_rdn`
 *     (groupes legacy `<profile>`). Pour chaque membre du groupe, on résout
 *     le User Eloquent et on pose `$user->assignRole($role)` selon matrice
 *     §5.3 (5 profils seedés) ou via `SambaRole::fromBitmask($info)` sur les
 *     profils custom créés en 7.2 par `importCustomProfilesFromAd`.
 *
 *     Bug `Annu_is_admin` (matrice §8 #6) : si le groupe existe sans `info`,
 *     on NE reproduit PAS le fallback buggé `annu/profiles.php:58` (qui
 *     remappait à tort vers `SE_COMPUTER_ADMIN`). On loggue un warning et
 *     on force `SambaRole::UserAdmin` (seed d'origine `SE_USER_ADMIN = 0xFF`,
 *     cf. `sambaedu/includes/ldap.inc.php:742`).
 *
 *  2. `migrateScopedDelegations()` — scan de la branche `delegations_rdn`
 *     (format `sambaedu/includes/ldap.inc.php:4396-4426` : groupes nommés
 *     `<level>_<parc>` ou `no_<level>_<parc>` avec `level` ∈ {manage, view, rdp},
 *     membres = `[user DN, parc DN]`). Le mapping `level → SambaPermission` est
 *     codé en dur (cf. `LEGACY_DELEGATION_LEVELS`) : `manage→computer.elevate`,
 *     `view→computer.view`, `rdp→computer.remote.rdp` (perm Spatie créée en
 *     7.3, décision Henri 2026-04-25 option C). Persistance via
 *     `Delegation::firstOrCreate` (review #11) + entrée d'historique avec
 *     `context.source = 'migration-7.3'` (review #8). Filtrage du parc DN via
 *     `WorkstationGroup::findByAdDn` plutôt qu'heuristiques (review #4).
 *
 * Idempotence :
 *  - `assignRole` Spatie est idempotent (pas de doublon dans `model_has_roles`).
 *  - Délégations : clé unique `(user_id, workstation_group_id, permission_id,
 *    is_negative)` via `firstOrCreate`. Au re-run, `granted_by` n'est PAS
 *    écrasé (review #11) — les délégations posées manuellement entre deux
 *    runs conservent leur acteur initial.
 *
 * Mode dry-run : toutes les écritures sont gardées sous `if (!$dryRun)`. Le
 * rapport est identique en sortie.
 *
 * Décision kickoff (1) : 7.3 pose les assignations user→rôle en complément
 * de 7.2 qui a créé les rôles en DB. Les 5 seedés suivent matrice §5.3.
 * Décision kickoff (2) : si le format `delegations_rdn` ne correspond pas
 * à la VM cible (aucun groupe), no-op documenté avec warning.
 */
class RightsMigrationService
{
    /**
     * Mapping des profils seedés vers leur rôle Spatie (matrice §5.3).
     * Source : `sambaedu/includes/ldap.inc.php:739-743`.
     *
     * NOTE Story 7.3 (correction post-review #1, décision Henri 2026-04-25) :
     *  `password_is_admin` (0x01) est volontairement absent. Ce profil legacy
     *  ne doit PAS être mappé sur `SambaRole::UserAdmin` (0xFF), ce qui
     *  constituerait une **escalade de privilèges** (8 droits au lieu d'un).
     *  Conformément à la matrice §5.3, on lui pose la permission directe
     *  `user.password.init` via `givePermissionTo` (pas de rôle dédié).
     *  Cf. `migrateRightsGroupAssignments()` cas spécial.
     */
    private const SEEDED_PROFILE_TO_ROLE = [
        'se3_is_admin'       => SambaRole::SuperAdmin,     // 0xFFFF
        'computer_is_admin'  => SambaRole::ComputerAdmin,  // 0xEF00
        'Annu_is_admin'      => SambaRole::UserAdmin,      // 0xFF
        'RefNum'             => SambaRole::ReferentNumerique, // 0x90B
    ];

    /**
     * Profils legacy migrés vers une **permission directe Spatie** au lieu d'un
     * rôle (matrice §5.3 — délégations ciblées). Ajouté Story 7.3 post-review.
     */
    private const SEEDED_PROFILE_TO_DIRECT_PERMISSION = [
        'password_is_admin' => 'user.password.init',
    ];

    /**
     * Mapping des `level` legacy stockés dans `OU=delegations` vers la
     * permission Spatie cible. Format CN confirmé dans
     * `sambaedu/includes/ldap.inc.php:4396-4426` :
     *  - `<level>_<parc>` (positif) ou `no_<level>_<parc>` (négatif)
     *  - `level` ∈ { `manage`, `view`, `rdp` }
     *
     * Story 7.3 (correction post-review #10 — décision Henri 2026-04-25) :
     *  - `manage` → `computer.elevate` (admin de poste, équivalent
     *    `SE_COMPUTER_ELEVATE` legacy 0x400).
     *  - `view`   → `computer.view` (consultation parc, `SE_COMPUTER_VIEW` 0x100).
     *  - `rdp`    → `computer.remote.rdp` (NOUVELLE permission Spatie créée
     *    pour cette migration, option C — sécurité fine RDP).
     */
    private const LEGACY_DELEGATION_LEVELS = [
        'manage' => SambaPermission::ComputerElevate,
        'view'   => SambaPermission::ComputerView,
        'rdp'    => SambaPermission::ComputerRemoteRdp,
    ];

    /**
     * Tag « source » à attacher au champ `context` JSONB des entrées
     * `delegation_history` créées par la migration one-shot. Permet de
     * distinguer en audit les délégations posées par la commande des
     * délégations posées par un acteur humain via l'UI (review #8).
     */
    private const MIGRATION_CONTEXT_SOURCE = 'migration-7.3';

    public function __construct(
        // Conservé pour compat des appelants existants (commande artisan +
        // mocks de test). La migration des délégations bypass désormais
        // `PermissionService::grantDelegation` au profit d'un `firstOrCreate`
        // direct sur `Delegation` (review #11) — `permissionService` reste
        // disponible pour les futures évolutions (ex. invocation de la
        // logique métier complète si un acteur humain est défini).
        private readonly PermissionService $permissionService,
        private readonly ?DelegationHistoryService $historyService = null,
    ) {
    }

    private function history(): DelegationHistoryService
    {
        return $this->historyService ?? app(DelegationHistoryService::class);
    }

    /**
     * Exécute la migration complète (rôles + délégations scopées).
     *
     * @param  bool  $dryRun  Si true, aucune écriture effective.
     * @param  callable|null  $rightsFetcher  Optionnel : fetcher de groupes legacy (cn => info bitmask). Par défaut = `LdapRightGroup::getAllRightsValues()`.
     * @param  callable|null  $rightsMembersFetcher  Optionnel : fetcher des membres d'un groupe legacy (cn → [user DN, ...]). Par défaut = lecture LDAP.
     * @param  callable|null  $delegationsFetcher  Optionnel : fetcher des groupes de délégations scopées (cn → [members]). Par défaut = lecture LDAP `delegations_rdn`.
     * @return array{
     *   users_scanned: int,
     *   roles_assigned: int,
     *   delegations_created: int,
     *   negatives_created: int,
     *   fallbacks_ignored: int,
     *   unmappable: list<array{kind: string, reason: string, context: array<string,mixed>}>,
     *   warnings: list<string>,
     * }
     */
    public function migrate(
        bool $dryRun = false,
        ?callable $rightsFetcher = null,
        ?callable $rightsMembersFetcher = null,
        ?callable $delegationsFetcher = null,
    ): array {
        $report = [
            'users_scanned'        => 0,
            'roles_assigned'       => 0,
            'delegations_created'  => 0,
            'negatives_created'    => 0,
            'fallbacks_ignored'    => 0,
            'unmappable'           => [],
            'warnings'             => [],
        ];

        $this->migrateRightsGroupAssignments(
            dryRun: $dryRun,
            rightsFetcher: $rightsFetcher,
            rightsMembersFetcher: $rightsMembersFetcher,
            report: $report,
        );

        $this->migrateScopedDelegations(
            dryRun: $dryRun,
            delegationsFetcher: $delegationsFetcher,
            report: $report,
        );

        return $report;
    }

    /**
     * Volet 1 — assignations user → rôle Spatie depuis la branche `rights_rdn`.
     *
     * Pour chaque groupe legacy (cn, info) :
     *  1. Résoudre le `SambaRole` cible :
     *     - Profil seedé (cn dans `SEEDED_PROFILE_TO_ROLE`) → mapping direct.
     *       Bug `Annu_is_admin` sans `info` → warning + fallback `UserAdmin`
     *       (PAS `ComputerAdmin` comme le faisait `annu/profiles.php:58`).
     *     - Profil custom → `Role::findByName($cn)` si existant en DB
     *       (créé par `importCustomProfilesFromAd` en 7.2). Sinon `unmappable`.
     *  2. Pour chaque member DN du groupe LDAP → résoudre User Eloquent
     *     (via `dn` exact, fallback sur `login` extrait du DN).
     *  3. `$user->assignRole($role)` si `!$dryRun`.
     */
    private function migrateRightsGroupAssignments(
        bool $dryRun,
        ?callable $rightsFetcher,
        ?callable $rightsMembersFetcher,
        array &$report,
    ): void {
        $fetcher = $rightsFetcher ?? fn () => LdapRightGroup::getAllRightsValues();

        try {
            $rightsValues = $fetcher();
        } catch (Throwable $e) {
            Log::warning('[RightsMigrationService] Impossible de scanner la branche Rights', [
                'error' => $e->getMessage(),
            ]);
            $report['warnings'][] = "Scan branche Rights impossible : {$e->getMessage()}";

            return;
        }

        foreach ($rightsValues as $cn => $info) {
            $infoInt = (int) $info;
            $cnString = (string) $cn;

            // --- Cas spécial Story 7.3 — password_is_admin → permission directe ---
            // Décision Henri 2026-04-25 (correction review #1) : ce profil
            // legacy ne doit PAS être mappé sur un rôle (UserAdmin = escalade).
            // On pose la permission `user.password.init` directement via
            // `givePermissionTo`, conformément à la matrice §5.3.
            if (isset(self::SEEDED_PROFILE_TO_DIRECT_PERMISSION[$cnString])) {
                $this->migrateProfileAsDirectPermission(
                    cn: $cnString,
                    permissionName: self::SEEDED_PROFILE_TO_DIRECT_PERMISSION[$cnString],
                    membersFetcher: $rightsMembersFetcher,
                    dryRun: $dryRun,
                    report: $report,
                );
                continue;
            }

            // --- 1. Résolution du nom de rôle cible ---
            $roleName = $this->resolveRoleNameForProfile($cnString, $infoInt, $report);

            if ($roleName === null) {
                $report['unmappable'][] = [
                    'kind'    => 'profile_no_role',
                    'reason'  => "Profil '{$cn}' (info=0x" . dechex($infoInt) . ") non mappable sur un rôle Spatie.",
                    'context' => ['cn' => $cnString, 'info' => $infoInt],
                ];
                continue;
            }

            // --- 2. Lecture des membres du groupe LDAP ---
            $members = $this->fetchMembersForGroup($cnString, $rightsMembersFetcher);

            foreach ($members as $memberDn) {
                $report['users_scanned']++;

                $user = $this->resolveUserFromDn((string) $memberDn);

                if ($user === null) {
                    $report['unmappable'][] = [
                        'kind'    => 'user_not_found',
                        'reason'  => "Member DN '{$memberDn}' du groupe '{$cn}' sans User SER associé.",
                        'context' => ['dn' => (string) $memberDn, 'group' => $cnString],
                    ];
                    continue;
                }

                // --- 3. Assignation idempotente ---
                if (! $dryRun) {
                    try {
                        // `assignRole` Spatie est idempotent : n'ajoute pas de doublon
                        // dans `model_has_roles` si la ligne existe déjà.
                        if (! $user->hasRole($roleName)) {
                            $user->assignRole($roleName);
                        }
                    } catch (Throwable $e) {
                        $report['unmappable'][] = [
                            'kind'    => 'assign_role_failed',
                            'reason'  => "Erreur assignation rôle '{$roleName}' à user '{$user->login}' : {$e->getMessage()}",
                            'context' => ['login' => $user->login, 'role' => $roleName],
                        ];
                        continue;
                    }
                }

                $report['roles_assigned']++;
            }
        }
    }

    /**
     * Migre un profil legacy en posant une **permission directe Spatie** sur
     * chaque user membre, sans passer par un rôle.
     *
     * Cas d'usage (Story 7.3) : `password_is_admin` (0x01) doit donner
     * `user.password.init` aux 32 occurrences de `SE_USER_PASSWORD_INIT`
     * legacy, sans introduire les 7 droits supplémentaires de `UserAdmin`
     * (matrice §5.3, décision Henri 2026-04-25 post-review #1).
     *
     * Idempotent : `givePermissionTo` Spatie n'ajoute pas de doublon dans
     * `model_has_permissions` si l'user a déjà la permission directe.
     */
    private function migrateProfileAsDirectPermission(
        string $cn,
        string $permissionName,
        ?callable $membersFetcher,
        bool $dryRun,
        array &$report,
    ): void {
        $members = $this->fetchMembersForGroup($cn, $membersFetcher);

        foreach ($members as $memberDn) {
            $report['users_scanned']++;

            $user = $this->resolveUserFromDn((string) $memberDn);
            if ($user === null) {
                $report['unmappable'][] = [
                    'kind'    => 'user_not_found',
                    'reason'  => "Member DN '{$memberDn}' du groupe '{$cn}' sans User SER associé.",
                    'context' => ['dn' => (string) $memberDn, 'group' => $cn],
                ];
                continue;
            }

            if (! $dryRun) {
                try {
                    if (! $user->hasDirectPermission($permissionName)) {
                        $user->givePermissionTo($permissionName);
                    }
                } catch (Throwable $e) {
                    $report['unmappable'][] = [
                        'kind'    => 'give_permission_failed',
                        'reason'  => "Erreur attribution permission '{$permissionName}' à user '{$user->login}' : {$e->getMessage()}",
                        'context' => ['login' => $user->login, 'permission' => $permissionName],
                    ];
                    continue;
                }
            }

            // On compte la permission directe dans `roles_assigned` pour
            // garder le même total fonctionnel dans le rapport (un mapping
            // = un user impacté).
            $report['roles_assigned']++;
        }
    }

    /**
     * Résout le NOM de rôle Spatie cible pour un profil legacy.
     *
     * Retourne le nom du rôle (string) à utiliser avec `$user->assignRole($name)`,
     * qu'il s'agisse d'un enum seedé (`SambaRole::*->value`) ou d'un rôle
     * custom créé en 7.2 avec son nom brut (ex. `Animateur_CDI`).
     *
     * Ordre de résolution :
     *  1. Bug `Annu_is_admin` sans `info` → warning + `user-admin` (matrice §8 #6).
     *  2. Profil seedé listé dans `SEEDED_PROFILE_TO_ROLE` → mapping direct.
     *  3. Profil custom rapatrié en 7.2 : on privilégie un `Role` de même nom
     *     créé par `importCustomProfilesFromAd`. On retourne son nom brut.
     *  4. Fallback matrice : `SambaRole::fromBitmask($info)->value` pour
     *     reconstituer un rôle seedé depuis le bitmask (profils custom non
     *     rapatriés en 7.2).
     */
    private function resolveRoleNameForProfile(string $cn, int $info, array &$report): ?string
    {
        // Cas 1 — bug Annu_is_admin sans info.
        if ($cn === 'Annu_is_admin' && $info === 0) {
            Log::warning('[MigrateRightsToSpatie] Annu_is_admin sans info — fallback buggé ignoré, assignation alignée sur le seed d\'origine SE_USER_ADMIN');
            $report['fallbacks_ignored']++;
            $report['warnings'][] = "Annu_is_admin sans info : fallback buggé ignoré, assignation alignée sur le seed d'origine SE_USER_ADMIN";

            return SambaRole::UserAdmin->value;
        }

        // Cas 2 — profil seedé (matrice §5.3).
        if (isset(self::SEEDED_PROFILE_TO_ROLE[$cn])) {
            return self::SEEDED_PROFILE_TO_ROLE[$cn]->value;
        }

        // Cas 3 — profil custom rapatrié en 7.2 : on vérifie que le Role
        // existe déjà en DB (créé par `importCustomProfilesFromAd`). On pose
        // l'assignation au nom brut du role custom.
        try {
            $customRole = Role::where('name', $cn)->where('guard_name', 'web')->first();
            if ($customRole !== null) {
                return $customRole->name;
            }
        } catch (Throwable $e) {
            Log::debug('[RightsMigrationService] Erreur résolution custom role', [
                'cn'    => $cn,
                'error' => $e->getMessage(),
            ]);
        }

        // Cas 4 — fallback matrice via bitmask (profils custom non rapatriés).
        return SambaRole::fromBitmask($info)?->value;
    }

    /**
     * Lit les membres (user DN) d'un groupe legacy `rights_rdn`.
     *
     * @return list<string>
     */
    private function fetchMembersForGroup(string $cn, ?callable $rightsMembersFetcher): array
    {
        if ($rightsMembersFetcher !== null) {
            $result = $rightsMembersFetcher($cn);

            return is_array($result) ? array_values(array_map('strval', $result)) : [];
        }

        try {
            $group = LdapRightGroup::query()->where('cn', '=', $cn)->first();
            if ($group === null) {
                return [];
            }

            $members = $group->getAttribute('member') ?? [];

            return is_array($members) ? array_values(array_map('strval', $members)) : [];
        } catch (Throwable $e) {
            Log::warning('[RightsMigrationService] Erreur lecture membres du groupe', [
                'cn'    => $cn,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Volet 2 — migration des délégations scopées de la branche `delegations_rdn`.
     *
     * Format CN legacy confirmé dans `sambaedu/includes/ldap.inc.php:4396-4426`
     * (fonction `create_delegation`) :
     *  - `<level>_<parc>` (positif) — `level` ∈ { manage, view, rdp }
     *  - `no_<level>_<parc>` (négatif)
     *  - `member` = `[user DN, parc DN]`
     *
     * Story 7.3 — corrections post-review #2 / #4 / #10 / #11 (décision Henri
     * 2026-04-25) :
     *  - Parsing strict via regex `/^(no_)?(manage|view|rdp)_(.+)$/` qui élimine
     *    l'ambiguïté underscore (les parcs `salle_info_bat_A` sont gérés).
     *  - Mapping `level → SambaPermission` via `LEGACY_DELEGATION_LEVELS` (et
     *    non plus la lecture `OU=rights/<level>/info` — décision option C pour
     *    RDP : permission Spatie dédiée `computer.remote.rdp`).
     *  - Résolution parc : `WorkstationGroup::findByAdDn($memberDn)` pour
     *    filtrer le DN de parc des `member` (au lieu d'heuristiques fragiles).
     *  - Persistance : `Delegation::firstOrCreate` au lieu de `updateOrCreate`
     *    pour ne PAS écraser `granted_by` au re-run sur une délégation
     *    posée manuellement entre deux runs (review #11).
     *  - Audit : entrées `delegation_history` taguées `source = 'migration-7.3'`
     *    avec `actor = null` explicite (review #8).
     */
    private function migrateScopedDelegations(
        bool $dryRun,
        ?callable $delegationsFetcher,
        array &$report,
    ): void {
        $fetcher = $delegationsFetcher ?? fn () => $this->fetchDelegationGroupsFromLdap();

        try {
            $delegationGroups = $fetcher();
        } catch (Throwable $e) {
            Log::warning('[RightsMigrationService] Impossible de scanner la branche Delegations', [
                'error' => $e->getMessage(),
            ]);
            $report['warnings'][] = "Scan branche Delegations impossible : {$e->getMessage()}";

            return;
        }

        if (empty($delegationGroups)) {
            // Décision kickoff (2) : aucun groupe de délégation = no-op documenté.
            Log::info('[RightsMigrationService] Branche delegations_rdn vide ou inexistante, migration délégations scopées no-op.');
            $report['warnings'][] = 'Branche delegations_rdn vide ou inexistante, migration délégations scopées no-op.';

            return;
        }

        foreach ($delegationGroups as $group) {
            $cn = (string) ($group['cn'] ?? '');
            $members = $group['members'] ?? [];
            if ($cn === '' || ! is_array($members)) {
                continue;
            }

            $parsed = $this->parseLegacyDelegationCn($cn);
            if ($parsed === null) {
                $report['unmappable'][] = [
                    'kind'    => 'delegation_parse_error',
                    'reason'  => "CN '{$cn}' : format inconnu — attendu `<level>_<parc>` ou `no_<level>_<parc>` avec level ∈ {manage,view,rdp}.",
                    'context' => ['cn' => $cn],
                ];
                Log::warning('[RightsMigrationService] CN délégation legacy non parsable', ['cn' => $cn]);
                continue;
            }

            ['negate' => $isNegative, 'level' => $level, 'parc' => $parcName] = $parsed;

            // --- Résolution de la permission Spatie via mapping level → perm ---
            $sambaPermission = self::LEGACY_DELEGATION_LEVELS[$level] ?? null;
            if ($sambaPermission === null) {
                // Sécurité : ne devrait pas arriver, le regex contraint déjà
                // les valeurs possibles. Sentinel pour audit défensif.
                $report['unmappable'][] = [
                    'kind'    => 'delegation_level_unmapped',
                    'reason'  => "Level legacy '{$level}' (CN '{$cn}') sans mapping vers une permission Spatie.",
                    'context' => ['cn' => $cn, 'level' => $level],
                ];
                continue;
            }

            $permission = $this->resolveSpatiePermission($sambaPermission->value);
            if ($permission === null) {
                $report['unmappable'][] = [
                    'kind'    => 'permission_not_found',
                    'reason'  => "Permission Spatie '{$sambaPermission->value}' (mappée depuis level '{$level}') introuvable en DB.",
                    'context' => ['cn' => $cn, 'level' => $level, 'permission' => $sambaPermission->value],
                ];
                continue;
            }

            // --- Résolution du WorkstationGroup par nom canonique ---
            $workstationGroup = WorkstationGroup::findByName($parcName);
            if ($workstationGroup === null) {
                $report['unmappable'][] = [
                    'kind'    => 'workstation_group_not_found',
                    'reason'  => "WorkstationGroup '{$parcName}' (parsé depuis '{$cn}') introuvable en DB.",
                    'context' => ['cn' => $cn, 'parc' => $parcName],
                ];
                continue;
            }

            // --- Itération des membres : user DN à migrer, parc DN à ignorer ---
            foreach ($members as $memberDn) {
                $memberStr = (string) $memberDn;

                $user = $this->resolveUserFromDn($memberStr);
                if ($user === null) {
                    // Filtrage explicite du DN de parc (review #4) — on consulte
                    // la table `workstation_groups` plutôt qu'une heuristique
                    // sur l'OU. Source de vérité = DB SER.
                    if ($this->isKnownWorkstationGroupDn($memberStr, $workstationGroup)) {
                        // Le DN référencé pointe sur un parc connu : c'est le
                        // parc DN normalement présent dans `member` (cf.
                        // `groupaddmemberbydn($config, $parc[0]['dn'], $res[0]['dn'])`
                        // dans `ldap.inc.php:4426`). On l'ignore silencieusement.
                        continue;
                    }

                    $report['unmappable'][] = [
                        'kind'    => 'user_not_found',
                        'reason'  => "Member DN '{$memberStr}' du groupe de délégation '{$cn}' sans User SER associé.",
                        'context' => ['dn' => $memberStr, 'group' => $cn],
                    ];
                    continue;
                }

                if (! $dryRun) {
                    try {
                        $this->persistMigratedDelegation(
                            user: $user,
                            permission: $permission,
                            group: $workstationGroup,
                            isNegative: $isNegative,
                            cn: $cn,
                        );
                    } catch (Throwable $e) {
                        $report['unmappable'][] = [
                            'kind'    => 'delegation_create_failed',
                            'reason'  => "Erreur création délégation '{$cn}' pour user '{$user->login}' : {$e->getMessage()}",
                            'context' => ['cn' => $cn, 'login' => $user->login],
                        ];
                        continue;
                    }
                }

                if ($isNegative) {
                    $report['negatives_created']++;
                } else {
                    $report['delegations_created']++;
                }
            }
        }
    }

    /**
     * Parse un CN de délégation legacy.
     *
     * Format strict : `(no_)?(manage|view|rdp)_<parc>` où `<parc>` peut
     * contenir des underscores (le regex consomme tout ce qui suit le `_`
     * post-level, ce qui élimine simultanément les ambiguïtés review #2
     * et #10).
     *
     * @return array{negate: bool, level: string, parc: string}|null
     */
    private function parseLegacyDelegationCn(string $cn): ?array
    {
        if (preg_match('/^(no_)?(manage|view|rdp)_(.+)$/', $cn, $m) !== 1) {
            return null;
        }

        return [
            'negate' => $m[1] === 'no_',
            'level'  => $m[2],
            'parc'   => $m[3],
        ];
    }

    /**
     * Lookup d'une permission Spatie par nom (guard `web`). Encapsule le
     * `try/catch` pour les environnements de test où la table peut ne pas
     * être prête.
     */
    private function resolveSpatiePermission(string $permissionName): ?Permission
    {
        try {
            return Permission::where('name', $permissionName)->where('guard_name', 'web')->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Indique si le DN passé en paramètre désigne un `WorkstationGroup` connu
     * (review #4). Utilisé pour filtrer le « parc DN » présent dans les
     * `member` d'un groupe de délégation legacy.
     *
     * Stratégies (ordre de coût croissant) :
     *  1. Heuristique cheap : si le DN contient le `name` du parc actuellement
     *     traité (en CN=), c'est très probablement le DN du parc lui-même.
     *  2. Lookup DB par `ad_dn` exact via `WorkstationGroup::findByAdDn()`.
     *     Encapsulé dans `try/catch` car le schéma test peut ne pas avoir
     *     la colonne `ad_dn` (cf. `tests/Traits/CreatesPermissionSchema.php`).
     */
    private function isKnownWorkstationGroupDn(string $dn, WorkstationGroup $currentGroup): bool
    {
        // 1. Match cheap par CN=<parc-name>
        if (preg_match('/^cn=([^,]+)/i', $dn, $m) === 1) {
            if (strcasecmp($m[1], $currentGroup->name) === 0) {
                return true;
            }
        }

        // 2. Lookup DB sur `ad_dn` (peut ne pas exister en test).
        try {
            return WorkstationGroup::findByAdDn($dn) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Persiste une délégation migrée via `firstOrCreate` (preserve `granted_by`
     * sur re-run — review #11), et trace l'historique avec un contexte
     * explicite « migration-7.3 » (review #8).
     */
    private function persistMigratedDelegation(
        User $user,
        Permission $permission,
        WorkstationGroup $group,
        bool $isNegative,
        string $cn,
    ): void {
        $delegation = Delegation::firstOrCreate(
            [
                'user_id'              => $user->id,
                'workstation_group_id' => $group->id,
                'permission_id'        => $permission->id,
                'is_negative'          => $isNegative,
            ],
            [
                // Migration legacy : aucun acteur humain. Les délégations
                // posées manuellement entre deux runs ne sont PAS écrasées
                // (decision Henri 2026-04-25 — review #11) car `firstOrCreate`
                // n'applique ces valeurs qu'à la création.
                'granted_by' => null,
                'expires_at' => null,
            ]
        );

        // Audit : on trace UNIQUEMENT la création initiale. Un re-run sur
        // une ligne préexistante (idempotence) ne génère pas de nouvelle
        // entrée d'historique.
        if ($delegation->wasRecentlyCreated) {
            $action = $isNegative
                ? DelegationHistory::ACTION_NEGATE
                : DelegationHistory::ACTION_GRANT;

            $this->history()->log(
                action: $action,
                actor: null, // acteur humain inconnu — c'est la commande de migration.
                target: $user,
                group: $group,
                permissionName: $permission->name,
                isNegative: $isNegative,
                context: [
                    'source'      => self::MIGRATION_CONTEXT_SOURCE,
                    'message'     => 'Migration legacy 7.3 - aucun acteur humain',
                    'legacy_cn'   => $cn,
                ],
            );

            // Dispatch GPO sync sur les permissions qui l'exigent (idempotent
            // côté job — il vérifiera l'état AD courant). Ne se déclenche que
            // si la délégation est bien nouvelle, pas sur un re-run.
            $sambaPermission = SambaPermission::tryFrom($permission->name);
            if ($sambaPermission?->requiresGpoSync()) {
                SyncGpoJob::dispatch(
                    $user->id,
                    $group->id,
                    $isNegative ? 'negate' : 'grant'
                );
            }
        }
    }

    /**
     * Lit les groupes de délégations scopées depuis la branche `delegations_rdn`
     * du LDAP.
     *
     * @return list<array{cn: string, members: list<string>}>
     */
    private function fetchDelegationGroupsFromLdap(): array
    {
        try {
            $ldapConfig = SEConfig::ldap();
            $baseDn = $ldapConfig->baseDn;
            $delegationsRdn = $ldapConfig->delegationsRdn ?? 'ou=delegations';
            $branchDn = "{$delegationsRdn},{$baseDn}";

            $groups = LdapGroup::query()
                ->in($branchDn)
                ->select(['cn', 'member'])
                ->get();

            $result = [];
            foreach ($groups as $group) {
                $cn = $group->getFirstAttribute('cn');
                if (! is_string($cn) || $cn === '') {
                    continue;
                }
                $members = $group->getAttribute('member') ?? [];
                $result[] = [
                    'cn'      => $cn,
                    'members' => is_array($members) ? array_values(array_map('strval', $members)) : [],
                ];
            }

            return $result;
        } catch (Throwable $e) {
            Log::warning('[RightsMigrationService] Erreur lecture branche delegations_rdn', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Résout un User Eloquent depuis un DN LDAP.
     *
     * Stratégie :
     *  1. Recherche par DN exact (colonne `dn` du User Eloquent).
     *  2. Fallback : extraction du CN depuis le DN → recherche par login.
     */
    private function resolveUserFromDn(string $dn): ?User
    {
        if ($dn === '') {
            return null;
        }

        // 1. Recherche par DN exact.
        try {
            $user = User::where('dn', $dn)->first();
            if ($user !== null) {
                return $user;
            }
        } catch (Throwable $e) {
            // SQLite in-memory sans colonne `dn` → fallback silencieux.
        }

        // 2. Fallback via CN extrait du DN.
        if (preg_match('/^(?:CN|cn|uid)=([^,]+)/i', $dn, $matches) === 1) {
            $cn = $matches[1];

            return User::where('login', $cn)->first();
        }

        return null;
    }
}
