<?php

declare(strict_types=1);

namespace App\Services;

use App\Constants\Ldap\MainGroups;
use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Facades\SEConfig;
use App\LdapModels\LdapUser;
use App\LdapModels\SambaEduGroup;
use App\Models\User as UserModel;
use App\Services\Concerns\ResolvesPwdLastSet;
use App\Types\User as AdUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Service d'import des utilisateurs depuis l'Active Directory vers la base SQL
 *
 * Récupère les utilisateurs des groupes principaux (Eleves, Profs, Administratifs),
 * crée/met à jour les enregistrements Eloquent User.
 *
 * Le compte 'admin' reçoit automatiquement le rôle super-admin et toutes les permissions,
 * même s'il n'est pas présent dans l'AD (il est créé localement).
 */
class UserSyncService
{
    use ResolvesPwdLastSet;

    private const ESTABLISHMENT_SCOPE_ALL = 'all';
    private const ESTABLISHMENT_SCOPE_TREE = 'tree';
    private const ESTABLISHMENT_SCOPE_MEMBER_OF = 'memberOf';
    private const DELTA_CURSOR_KEY = 'users_ad_whenchanged_cursor';

    public function __construct()
    {
    }

    /**
     * Importe tous les utilisateurs depuis l'AD vers la base SQL
     * 
     * @param callable|null $logger Callback pour les logs (fn(string $level, string $message))
     * @param string $establishmentScope Scope de rattachement établissement (all|tree|memberOf)
     * @return array Statistiques d'import
     */
    public function importFromAd(?callable $logger = null, string $establishmentScope = self::ESTABLISHMENT_SCOPE_ALL): array
    {
        return $this->importUsersFromAd($logger, $establishmentScope, false);
    }

    /**
     * Synchronisation delta des utilisateurs AD (whenChanged >= curseur)
     *
     * @param callable|null $logger Callback pour les logs (fn(string $level, string $message))
     * @param string $establishmentScope Scope de rattachement établissement (all|tree|memberOf)
     * @return array Statistiques d'import
     */
    public function importFromAdDelta(?callable $logger = null, string $establishmentScope = self::ESTABLISHMENT_SCOPE_ALL): array
    {
        return $this->importUsersFromAd($logger, $establishmentScope, true);
    }

    public function resetDeltaCursor(): void
    {
        if (!$this->hasSyncCursorStorage()) {
            return;
        }

        DB::table('sync_cursors')->where('name', self::DELTA_CURSOR_KEY)->delete();
    }

    /**
     * @param callable|null $logger
     */
    private function importUsersFromAd(?callable $logger, string $establishmentScope, bool $deltaMode): array
    {
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            // Story 49.3 (AC1/AC6) — comptes réactivés par le miroir `is_active`
            // (compte AD réapparu / réactivé : false → true sur la branche update).
            'reactivated' => 0,
            'admin_granted' => false,
            'total_ad' => 0,
            'etab_tree' => 0,
            'etab_ou_tree' => 0,
            'etab_member_of' => 0,
            'etab_excluded' => 0,
            // Story 49.3 (AC2) — santé du balayage : sans ces deux compteurs la
            // garde anti-désactivation en masse (AC3) est AVEUGLE.
            'fetch_groups_failed' => 0,
            'main_groups_found' => 0,
            // Story 49.3 (AC2) — identifiants PRÉSENTS au balayage (les deux
            // familles, cf. D6 : `ad_guid` d'abord, `login` en repli).
            'present_guids' => [],
            'present_logins' => [],
            'delta_mode' => $deltaMode,
            'delta_cursor_start' => null,
            'delta_cursor_end' => null,
        ];

        $log = $logger ?? fn(string $level, string $message) => Log::log($level, "[UserSyncService] {$message}");
        $deltaCursorStart = null;

        try {
            // S'assurer que les permissions et rôles Spatie existent
            $this->ensurePermissionsExist($log);

            // Story 7.2 (AC4) — Rapatriement non-destructif des profils LDAP
            // custom de la branche `rights_rdn`. Crée les rôles personnalisés
            // (ex. "Animateur CDI") côté SER s'ils n'existent pas. Jamais
            // destructif sur les rôles existants. En cas d'erreur LDAP (ex.
            // branche absente, hors environnement de test), on passe silencieux.
            try {
                $customProfilesStats = app(PermissionService::class)->importCustomProfilesFromAd($log);
                $stats['custom_profiles_rapatries'] = $customProfilesStats;
            } catch (\Throwable $e) {
                $log('warning', 'Rapatriement profils LDAP custom ignoré : ' . $e->getMessage());
                $stats['custom_profiles_rapatries'] = ['errors' => 1];
            }

            if ($deltaMode) {
                $deltaCursorStart = $this->getDeltaCursor();
                $stats['delta_cursor_start'] = $deltaCursorStart;

                if ($deltaCursorStart !== null) {
                    $log('info', "Synchronisation delta active depuis whenChanged >= {$deltaCursorStart}");
                } else {
                    $log('info', 'Synchronisation delta active sans curseur existant (scan initial)');
                }
            }

            // 1. Récupérer les utilisateurs depuis l'AD (groupes principaux)
            $log('info', 'Récupération des utilisateurs depuis l\'AD...');
            $fetchResult = $this->fetchUsersFromAd($log, $establishmentScope, $deltaCursorStart);
            $adUsers = $fetchResult['users'];
            $stats['total_ad'] = count($adUsers);
            $stats['etab_tree'] = $fetchResult['establishment']['tree'];
            $stats['etab_ou_tree'] = $fetchResult['establishment']['ou_tree'];
            $stats['etab_member_of'] = $fetchResult['establishment']['member_of'];
            $stats['etab_excluded'] = $fetchResult['establishment']['excluded'];
            $stats['fetch_groups_failed'] = $fetchResult['fetch_groups_failed'];
            $stats['main_groups_found'] = $fetchResult['main_groups_found'];
            $stats['present_guids'] = $fetchResult['present_guids'];
            $stats['present_logins'] = $fetchResult['present_logins'];
            $log('info', count($adUsers) . ' utilisateurs trouvés dans l\'AD');

            // 2. Importer chaque utilisateur
            DB::beginTransaction();

            try {
                foreach ($adUsers as $adUser) {
                    try {
                        $reactivated = false;
                        $result = $this->upsertUser($adUser, $reactivated);
                        $stats[$result]++;
                        if ($reactivated) {
                            $stats['reactivated']++;
                        }
                    } catch (\Exception $e) {
                        $stats['errors']++;
                        $log('warning', "Erreur pour {$adUser->login}: " . $e->getMessage());
                    }
                }

                // 3. Garantir le compte admin avec tous les droits
                $this->ensureAdminUser($log);
                $stats['admin_granted'] = true;

                if ($deltaMode) {
                    $deltaCursorEnd = $fetchResult['max_whenchanged'];

                    if ($deltaCursorEnd !== null) {
                        $this->saveDeltaCursor($deltaCursorEnd);
                        $stats['delta_cursor_end'] = $deltaCursorEnd;
                    }
                }

                DB::commit();

                $log('info', "Import terminé : {$stats['created']} créés, {$stats['updated']} mis à jour, {$stats['skipped']} inchangés, {$stats['errors']} erreurs");

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            $log('error', 'Erreur lors de l\'import : ' . $e->getMessage());
            Log::error('[UserSyncService] Erreur import', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        return $stats;
    }

    /**
     * S'assure que toutes les permissions et rôles Spatie existent en base
     *
     * Story 7.2 (AC2) : NON-DESTRUCTIF.
     *
     * Cette méthode garantit la simple **existence** des tables :
     *  - Les 19 permissions `SambaPermission` sont créées via `findOrCreate`.
     *  - Les 9 rôles `SambaRole` sont créés via `firstOrCreate`.
     *
     * Elle NE synchronise PLUS les permissions des rôles à chaque sync AD.
     * Ce comportement écrasait les profils personnalisés (rôles custom créés
     * en UI, ou perms de rôles seedés édités par un admin).
     *
     * La synchro initiale des permissions de rôles est déléguée au
     * `PermissionSeeder` (exécuté au premier déploiement). Sur les runs
     * suivants, les rôles existants sont laissés intacts.
     */
    private function ensurePermissionsExist(callable $log): void
    {
        $log('info', 'Vérification des permissions et rôles Spatie...');

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $createdPerms = 0;
        foreach (SambaPermission::cases() as $perm) {
            $existing = Permission::where('name', $perm->value)->where('guard_name', 'web')->first();
            if (!$existing) {
                Permission::create(['name' => $perm->value, 'guard_name' => 'web']);
                $createdPerms++;
            }
        }

        $createdRoles = 0;
        foreach (SambaRole::cases() as $sambaRole) {
            $role = Role::firstOrCreate(
                ['name' => $sambaRole->value, 'guard_name' => 'web']
            );
            // Story 7.2 — AC2 : on attache les permissions SEULEMENT si le rôle
            // vient d'être créé. Sinon, préserver la configuration existante
            // (édition admin via UI ou rapatriement LDAP custom).
            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($sambaRole->permissionNames());
                $createdRoles++;
            }
        }

        if ($createdPerms > 0 || $createdRoles > 0) {
            $log('info', "{$createdPerms} permission(s) créée(s), {$createdRoles} rôle(s) créé(s)");
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Balayage fetch-only, SANS AUCUNE ÉCRITURE (Story 49.3 — AC2).
     *
     * Sert le `--dry-run` de `users:reconcile-departures` : l'admin doit pouvoir
     * lire le plan de désactivation AVANT d'assumer un `--force`, sans qu'un
     * seul upsert ne parte. C'est le MÊME chemin interne que l'import
     * (`fetchUsersFromAd`) — jamais un second code de fetch, sous peine de voir
     * les deux diverger et le dry-run mentir (D2).
     *
     * @return array{
     *   total_ad: int,
     *   fetch_groups_failed: int,
     *   main_groups_found: int,
     *   present_guids: string[],
     *   present_logins: string[]
     * }
     */
    public function fetchPresence(?callable $logger = null, string $establishmentScope = self::ESTABLISHMENT_SCOPE_ALL): array
    {
        $log = $logger ?? fn(string $level, string $message) => Log::log($level, "[UserSyncService] {$message}");

        // `paginationIsCritical: true` — sur CE chemin (et lui seul), un
        // annuaire qui n'honore pas le contrôle paged-results doit faire
        // ÉCHOUER le balayage plutôt que rendre un résultat peut-être amputé :
        // la réconciliation en déduirait des départs qui n'ont pas eu lieu.
        $fetchResult = $this->fetchUsersFromAd($log, $establishmentScope, null, true);

        return [
            'total_ad' => count($fetchResult['users']),
            'fetch_groups_failed' => $fetchResult['fetch_groups_failed'],
            'main_groups_found' => $fetchResult['main_groups_found'],
            'present_guids' => $fetchResult['present_guids'],
            'present_logins' => $fetchResult['present_logins'],
        ];
    }

    /**
     * Récupère les utilisateurs depuis l'AD via les groupes principaux
     *
     * ─────────────────────────────────────────────────────────────────────────
     * Story 49.3 (AC2 / D3) — pourquoi `paginate()` et NON `get()`.
     * ─────────────────────────────────────────────────────────────────────────
     * `MaxPageSize` vaut 1000 par défaut côté AD : au-delà, le serveur renvoie
     * un résultat PARTIEL accompagné d'une erreur « Size limit exceeded » /
     * « Partial search results returned ». Or LdapRecord AVALE cette erreur —
     * `HandlesConnection::shouldBypassError()` la bypasse explicitement via
     * `DetectsErrors::causedBySizeLimit()` — et `get()` rend le partiel SANS
     * exception ni signal post-hoc exploitable. Autrement dit : la troncature
     * est INDÉTECTABLE après coup. La seule parade est de ne jamais la
     * provoquer, d'où le contrôle paged-results (`paginate()`, supporté par
     * Samba AD), qui corrige au passage le balayage delta.
     *
     * La garde anti-désactivation en masse (`UserDepartureReconciliationService`)
     * reste derrière, en défense en profondeur : un balayage amputé pour une
     * raison qu'on n'aurait pas prévue ne doit jamais désactiver un parc.
     *
     * @param string $establishmentScope Scope de rattachement établissement (all|tree|memberOf)
     * @param string|null $changedSince Filtre whenChanged >= valeur AD generalized time
     * @return array{
     *   users: array<AdUser>,
     *   establishment: array{tree: int, ou_tree: int, member_of: int, excluded: int},
     *   max_whenchanged: ?string,
     *   fetch_groups_failed: int,
     *   main_groups_found: int,
     *   present_guids: string[],
     *   present_logins: string[]
     * }
     */
    private function fetchUsersFromAd(
        callable $log,
        string $establishmentScope,
        ?string $changedSince = null,
        bool $paginationIsCritical = false
    ): array {
        $users = [];
        $seenIdentifiers = [];
        // Story 49.3 (AC2 / D6) — les DEUX familles d'identifiants présents,
        // renseignées pour CHAQUE entrée retenue (et pas l'une ou l'autre selon
        // la clé de dédoublonnage) : le prédicat d'absence teste le guid ET le
        // login, une ligne SQL sans `ad_guid` (créée par SE5 avant sa première
        // sync) ne doit pas être vue comme partie.
        $presentGuids = [];
        $presentLogins = [];
        $fetchGroupsFailed = 0;
        $establishmentMatchedByTree = 0;
        $establishmentMatchedByOuTree = 0;
        $establishmentMatchedByMemberOf = 0;
        $establishmentExcluded = 0;
        $maxWhenChanged = $changedSince;
        $establishmentScope = $this->normalizeEstablishmentScope($establishmentScope);

        $establishmentCode = SEConfig::getCurrentEstablishmentCode();
        $establishmentDn = null;

        if (! empty($establishmentCode) && $establishmentCode !== '0') {
            $establishmentDn = SEConfig::ldap()->etablissementDn($establishmentCode);
            $log('info', "Filtre établissement actif: {$establishmentCode}");
        }

        if ($establishmentDn === null && $establishmentScope !== self::ESTABLISHMENT_SCOPE_ALL) {
            $log('info', 'Aucun établissement sélectionné: import users en mode domaine entier');
            $establishmentScope = self::ESTABLISHMENT_SCOPE_ALL;
        }

        // Récupérer les DN des groupes principaux
        $mainGroupsDn = SambaEduGroup::getAllMainGroupsDn();

        if (empty($mainGroupsDn)) {
            $log('warning', 'Aucun groupe principal trouvé dans l\'AD (Eleves, Profs, Administratifs)');
            return [
                'users' => [],
                'establishment' => [
                    'tree' => 0,
                    'ou_tree' => 0,
                    'member_of' => 0,
                    'excluded' => 0,
                ],
                'max_whenchanged' => $maxWhenChanged,
                // Story 49.3 (AC3-3) — `main_groups_found = 0` est une condition
                // d'ABANDON de la réconciliation : sans groupe principal, tout
                // le parc paraîtrait absent.
                'fetch_groups_failed' => 0,
                'main_groups_found' => 0,
                'present_guids' => [],
                'present_logins' => [],
            ];
        }

        $log('info', 'Groupes principaux trouvés : ' . implode(', ', array_keys($mainGroupsDn)));

        foreach ($mainGroupsDn as $groupName => $groupDn) {
            $log('info', "Récupération des membres de {$groupName}...");

            try {
                $ldapQuery = LdapUser::query()
                    ->where('memberof', 'contains', $groupDn);

                if ($changedSince !== null) {
                    $ldapQuery->where('whenchanged', '>=', $changedSince);
                }

                // `paginate()` et non `get()` : cf. le docblock de la méthode —
                // la troncature silencieuse au `MaxPageSize` AD est
                // indétectable après coup, on ne peut que la prévenir.
                //
                // Correction de review — la CRITICITÉ du contrôle paged-results
                // n'est pas un détail. À `false` (défaut LdapRecord), un serveur
                // qui ne reconnaît pas le contrôle a le droit de l'IGNORER
                // (RFC 4511 §4.1.11) : la requête retombe alors exactement dans
                // le mode tronqué-silencieux qu'on cherche à fuir, et le
                // correctif se dégrade sans bruit. À `true`, le même serveur
                // REJETTE la requête — on récolte une exception, donc un fetch
                // en échec, donc l'abandon de la garde. Fail-closed.
                //
                // On ne l'impose QUE sur le chemin de la réconciliation
                // (`fetchPresence`) : là, ne rien faire est toujours préférable
                // à désactiver sur un balayage douteux. L'import et le delta
                // 5 min gardent la criticité par défaut — les casser toutes les
                // 5 minutes sur un annuaire qui n'honore pas le contrôle serait
                // une régression bien pire que la troncature qu'ils subissaient
                // déjà avant 49.3.
                $ldapUsers = $ldapQuery->paginate(1000, $paginationIsCritical);

                $count = 0;
                foreach ($ldapUsers as $ldapUser) {
                    $login = $ldapUser->getLogin();
                    $objectGuid = $this->convertAdGuidToString($ldapUser->getFirstAttribute('objectguid'));

                    $dedupeKey = $objectGuid !== null && $objectGuid !== ''
                        ? 'guid:' . $objectGuid
                        : 'login:' . strtolower($login);

                    if (empty($login) || isset($seenIdentifiers[$dedupeKey])) {
                        continue;
                    }

                    $establishmentMatchType = $this->getEstablishmentMatchType($ldapUser, $establishmentDn);

                    if ($establishmentMatchType === null) {
                        if ($establishmentDn !== null) {
                            $establishmentExcluded++;
                        }
                        continue;
                    }

                    if ($establishmentScope !== self::ESTABLISHMENT_SCOPE_ALL && $establishmentMatchType !== $establishmentScope) {
                        continue;
                    }

                    if ($establishmentMatchType === \App\Services\Ldap\EstablishmentMatcher::MATCH_TREE) {
                        $establishmentMatchedByTree++;
                    } elseif ($establishmentMatchType === \App\Services\Ldap\EstablishmentMatcher::MATCH_OU_TREE) {
                        $establishmentMatchedByOuTree++;
                    } elseif ($establishmentMatchType === \App\Services\Ldap\EstablishmentMatcher::MATCH_MEMBER_OF) {
                        $establishmentMatchedByMemberOf++;
                    }

                    // Ne pas exclure admin ici — on le gère séparément
                    if (MainGroups::isSystemAccount($login) && $login !== 'admin') {
                        continue;
                    }

                    $userWhenChanged = $this->normalizeWhenChanged((string) ($ldapUser->getFirstAttribute('whenchanged') ?? ''));
                    if ($userWhenChanged !== null && ($maxWhenChanged === null || strcmp($userWhenChanged, $maxWhenChanged) > 0)) {
                        $maxWhenChanged = $userWhenChanged;
                    }

                    $seenIdentifiers[$dedupeKey] = true;
                    if ($objectGuid !== null && $objectGuid !== '') {
                        $presentGuids[$objectGuid] = true;
                    }
                    $presentLogins[strtolower($login)] = true;
                    $users[] = $this->ldapUserToAdData($ldapUser, $groupName);
                    $count++;
                }

                $log('info', "  → {$count} utilisateurs dans {$groupName}");

            } catch (\Exception $e) {
                // Story 49.3 (AC2 / AC3-2) — l'échec d'UN groupe principal était
                // jusqu'ici un simple warning avalé : le retour d'`importFromAd`
                // paraissait sain alors qu'un tiers du parc manquait, et la
                // réconciliation des départs aurait désactivé tous les membres
                // du groupe en échec. On le COMPTE, et la garde abandonne dès
                // qu'il est non nul.
                $fetchGroupsFailed++;
                $log('warning', "Erreur récupération {$groupName}: " . $e->getMessage());
            }
        }

        if ($establishmentDn !== null) {
            $log(
                'info',
                sprintf(
                    'Filtre établissement: %d via CN-arbo, %d via OU-arbo, %d via memberOf, %d exclu(s)',
                    $establishmentMatchedByTree,
                    $establishmentMatchedByOuTree,
                    $establishmentMatchedByMemberOf,
                    $establishmentExcluded
                )
            );
        }

        return [
            'users' => $users,
            'establishment' => [
                'tree' => $establishmentMatchedByTree,
                'ou_tree' => $establishmentMatchedByOuTree,
                'member_of' => $establishmentMatchedByMemberOf,
                'excluded' => $establishmentExcluded,
            ],
            'max_whenchanged' => $maxWhenChanged,
            'fetch_groups_failed' => $fetchGroupsFailed,
            'main_groups_found' => count($mainGroupsDn),
            'present_guids' => array_keys($presentGuids),
            'present_logins' => array_keys($presentLogins),
        ];
    }

    private function normalizeWhenChanged(string $rawValue): ?string
    {
        $value = trim($rawValue);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{14})(?:\.\d+)?Z$/', $value, $matches) === 1) {
            return $matches[1] . '.0Z';
        }

        return null;
    }

    private function hasSyncCursorStorage(): bool
    {
        return Schema::hasTable('sync_cursors');
    }

    private function getDeltaCursor(): ?string
    {
        if (!$this->hasSyncCursorStorage()) {
            return null;
        }

        $cursor = DB::table('sync_cursors')
            ->where('name', self::DELTA_CURSOR_KEY)
            ->value('cursor_value');

        if (!is_string($cursor) || trim($cursor) === '') {
            return null;
        }

        return $this->normalizeWhenChanged($cursor);
    }

    private function saveDeltaCursor(string $cursor): void
    {
        if (!$this->hasSyncCursorStorage()) {
            return;
        }

        $normalizedCursor = $this->normalizeWhenChanged($cursor);
        if ($normalizedCursor === null) {
            return;
        }

        DB::table('sync_cursors')->updateOrInsert(
            ['name' => self::DELTA_CURSOR_KEY],
            [
                'cursor_value' => $normalizedCursor,
                'updated_at' => now(),
            ]
        );
    }

    private function normalizeEstablishmentScope(string $scope): string
    {
        return match ($scope) {
            self::ESTABLISHMENT_SCOPE_TREE => self::ESTABLISHMENT_SCOPE_TREE,
            self::ESTABLISHMENT_SCOPE_MEMBER_OF => self::ESTABLISHMENT_SCOPE_MEMBER_OF,
            default => self::ESTABLISHMENT_SCOPE_ALL,
        };
    }

    /**
     * Vérifie qu'un utilisateur LDAP est rattaché à l'établissement courant.
     *
     * Règles de rattachement:
     * - arborescence: le DN utilisateur est sous le DN établissement
     * - appartenance: memberOf contient le groupe établissement
     */
    private function getEstablishmentMatchType(LdapUser $ldapUser, ?string $establishmentDn): ?string
    {
        $memberOf = $ldapUser->getAttribute('memberof');

        return \App\Services\Ldap\EstablishmentMatcher::match(
            $ldapUser->getDn(),
            is_array($memberOf) ? $memberOf : null,
            $establishmentDn
        );
    }

    /**
     * Convertit un LdapUser en DTO typé pour le cache SQL des utilisateurs
     */
    private function ldapUserToAdData(LdapUser $ldapUser, string $mainGroup): AdUser
    {
        $memberOf = $ldapUser->getAttribute('memberof') ?? [];
        if (is_array($memberOf) && isset($memberOf['count'])) {
            unset($memberOf['count']);
            $memberOf = array_values($memberOf);
        }

        $groupNames = array_map(function (string $dn): string {
            if (preg_match('/^CN=([^,]+),/i', $dn, $matches)) {
                return $matches[1];
            }
            return $dn;
        }, $memberOf);

        // Extraire les profils de droits (groupes dans OU=Rights)
        $rightProfiles = [];
        foreach ($memberOf as $dn) {
            if (stripos($dn, 'ou=rights') !== false || stripos($dn, 'ou=droits') !== false) {
                if (preg_match('/^CN=([^,]+),/i', $dn, $matches)) {
                    $rightProfiles[] = $matches[1];
                }
            }
        }

        // Déterminer le rôle depuis le groupe principal
        $role = match ($mainGroup) {
            MainGroups::ELEVES => 'eleve',
            MainGroups::PROFS => 'prof',
            MainGroups::ADMINISTRATIFS => 'administratif',
            default => 'autre',
        };

        $getValue = function (string $attr) use ($ldapUser): ?string {
            $val = $ldapUser->getFirstAttribute($attr);
            return is_string($val) ? $val : null;
        };

        $businessObject = $ldapUser->toBusinessObject();

        // Lecture pwdLastSet via le trait ResolvesPwdLastSet (story 14.4 — AC3 / Tâche 4.2)
        $pwdLastSetRaw = $ldapUser->getFirstAttribute('pwdlastset');
        $pwdLastSetInt = $this->resolvePwdLastSetRaw($pwdLastSetRaw);
        $passwordChangedAt = self::pwdLastSetToCarbon($pwdLastSetInt);

        return new AdUser(
            login: (string) ($ldapUser->getLogin() ?? ''),
            fullname: $getValue('displayname') ?? $getValue('cn') ?? '',
            firstname: $getValue('givenname'),
            lastname: $getValue('sn'),
            email: $getValue('mail'),
            etabCode: $businessObject->etabCode,
            etabName: $businessObject->etabName,
            dn: $ldapUser->getDn(),
            groups: $groupNames,
            rights: $rightProfiles,
            // Story 49.3 (AC1) — MIROIR de `useraccountcontrol`. Le business
            // object le calcule déjà (`LdapUser::toBusinessObject()` :
            // `uac === 512`) ; il n'était simplement pas transmis au DTO, si
            // bien que `upsertUser` posait un `true` en dur à la création et ne
            // touchait rien à l'update. Le transmettre est ce qui permet à la
            // sync de refléter les désactivations ET les retours.
            isActive: $businessObject->isActiveAccount(),
            role: $role,
            objectGuid: $this->convertAdGuidToString($ldapUser->getFirstAttribute('objectguid')),
            passwordChangedAt: $passwordChangedAt,
        );
    }

    private function convertAdGuidToString(mixed $rawGuid): ?string
    {
        if (!is_string($rawGuid) || $rawGuid === '') {
            return null;
        }

        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $rawGuid) === 1) {
            return strtolower($rawGuid);
        }

        $hex = bin2hex($rawGuid);
        if (strlen($hex) !== 32) {
            return null;
        }

        return strtolower(sprintf(
            '%s%s%s%s-%s%s-%s%s-%s-%s',
            substr($hex, 6, 2),
            substr($hex, 4, 2),
            substr($hex, 2, 2),
            substr($hex, 0, 2),
            substr($hex, 10, 2),
            substr($hex, 8, 2),
            substr($hex, 14, 2),
            substr($hex, 12, 2),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        ));
    }

    /**
     * Crée ou met à jour un utilisateur Eloquent depuis les données AD
     *
     * Story 49.3 (AC1) — `is_active` est un MIROIR de l'AD sur les DEUX
     * branches. Ni un `true` en dur (il ressusciterait toutes les 5 minutes un
     * compte désactivé à la main : `UserService::disableUser()` pose
     * `uac=514` + `is_active=false`, mais le compte AD désactivé RESTE membre
     * de ses groupes, donc présent au balayage), ni une absence (le retour d'un
     * utilisateur ne serait jamais reflété).
     *
     * @param bool|null $reactivated Renseigné par référence : `true` quand
     *        l'update fait passer `is_active` de false à true (compteur AC6).
     * @return string 'created'|'updated'|'skipped'
     */
    private function upsertUser(AdUser $adUser, ?bool &$reactivated = null): string
    {
        $reactivated = false;
        $login = $adUser->login;
        $adGuid = $this->convertAdGuidToString($adUser->objectGuid);
        $user = null;

        if ($adGuid !== null) {
            $user = UserModel::query()->where('ad_guid', $adGuid)->first();
        }

        if ($user === null && $login !== '') {
            $user = UserModel::query()->where('login', $login)->first();
        }

        if ($user === null && $adUser->dn !== null && $adUser->dn !== '') {
            $user = UserModel::query()->where('dn', $adUser->dn)->first();
        }

        $isNew = $user === null;

        if ($login === '') {
            throw new RuntimeException('Login AD vide, synchronisation impossible');
        }

        if ($adGuid !== null && $user !== null) {
            $conflict = UserModel::query()
                ->where('ad_guid', $adGuid)
                ->where('id', '!=', $user->id)
                ->first();

            if ($conflict !== null) {
                throw new RuntimeException(sprintf(
                    'Conflit ad_guid %s entre login SQL "%s" et "%s"',
                    $adGuid,
                    $user->login,
                    $conflict->login
                ));
            }
        }

        if ($isNew) {
            $user = UserModel::query()->create([
                'login' => $login,
                'fullname' => $adUser->fullname,
                'firstname' => $adUser->firstname,
                'lastname' => $adUser->lastname,
                'email' => $adUser->email,
                'dn' => $adUser->dn,
                'ad_guid' => $adGuid,
                'role' => $adUser->role,
                'school_code' => $adUser->etabCode,
                'school_name' => $adUser->etabName,
                // Story 49.3 (AC1) — miroir `useraccountcontrol` (512 = actif).
                'is_active' => $adUser->isActive,
                'ad_synced_at' => now(),
                // Story 14.4 — AC3 / Tâche 4.3 : backfill password_changed_at depuis pwdLastSet AD
                'password_changed_at' => $adUser->passwordChangedAt,
            ]);
        } else {
            // Story 49.3 (AC1/AC6) — lu AVANT l'update : c'est la transition
            // false → true qui compte comme « réactivation » dans le rapport.
            $wasActive = (bool) $user->is_active;
            $reactivated = ! $wasActive && $adUser->isActive;

            $user->update([
                'login' => $login,
                'fullname' => $adUser->fullname !== '' ? $adUser->fullname : $user->fullname,
                'firstname' => $adUser->firstname ?? $user->firstname,
                'lastname' => $adUser->lastname ?? $user->lastname,
                'email' => $adUser->email ?? $user->email,
                'dn' => $adUser->dn ?? $user->dn,
                'ad_guid' => $adGuid ?? $user->ad_guid,
                'role' => $adUser->role !== '' ? $adUser->role : $user->role,
                'school_code' => ($adUser->etabCode !== null && $adUser->etabCode !== '') ? $adUser->etabCode : $user->school_code,
                'school_name' => ($adUser->etabName !== null && $adUser->etabName !== '') ? $adUser->etabName : $user->school_name,
                // Story 49.3 (AC1) — miroir `useraccountcontrol` sur l'update
                // aussi : sans lui, `users.is_active` ne reflétait NI les
                // désactivations AD, NI les retours (prérequis FR-R4).
                'is_active' => $adUser->isActive,
                'ad_synced_at' => now(),
                // Story 14.4 — AC3 / Tâche 4.3 : mise à jour password_changed_at.
                // Préserve la date SQL existante si l'AD répond null
                // (ex: pwdLastSet absent/filtré côté replica LDAP, ACL, etc.).
                // D6 + R3 affinée post-review Opus 14.4 #2 : on évite d'écraser
                // une vraie date persistée au login par un null transitoire de sync AD.
                'password_changed_at' => $adUser->passwordChangedAt ?? $user->password_changed_at,
            ]);
        }

        if ($isNew) {
            return 'created';
        }

        return 'updated';
    }

    /**
     * Garantit l'existence du compte admin avec tous les droits
     * 
     * Le compte 'admin' est l'administrateur du serveur SambaEdu.
     * Il n'est pas nécessairement dans l'AD (ou peut être filtré comme compte système).
     * On le crée/met à jour localement avec le rôle super-admin et toutes les permissions.
     */
    private function ensureAdminUser(callable $log): void
    {
        $log('info', 'Configuration du compte administrateur (admin)...');

        $user = UserModel::query()->firstOrCreate(
            ['login' => UserModel::PROTECTED_ADMIN_LOGIN],
            [
                'fullname' => 'Administrateur',
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        $this->grantAdminRights($user);

        $log('info', 'Compte admin configuré : rôle super-admin, ' . count(SambaPermission::cases()) . ' permissions');
    }

    /**
     * Accorde tous les droits super-admin à un utilisateur Eloquent
     * 
     * Appelé par ensureAdminUser() lors du sync, et par le middleware
     * SambaEduAuth lors de l'auto-provisioning du compte admin.
     */
    public function grantAdminRights(UserModel $user): void
    {
        $this->ensurePermissionsExist(
            fn(string $level, string $message) => Log::log($level, "[UserSyncService] {$message}")
        );

        $user->update([
            'is_active' => true,
        ]);

        $allPermissions = array_map(fn(SambaPermission $p) => $p->value, SambaPermission::cases());
        $user->syncPermissions($allPermissions);
        $user->syncRoles([SambaRole::SuperAdmin->value]);
    }
}
