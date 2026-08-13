<?php

namespace App\Models;

use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Exceptions\ProtectedAdminRightsException;
use App\Models\Concerns\HasAppCustomizations;
use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;
use Livewire\Wireable;

/**
 * Modèle Eloquent User pour le système de droits SambaEdu 4.6
 *
 * Unique représentation de l'utilisateur authentifié (l'ancien wrapper LDAP
 * `AuthUser` a été supprimé par la Story 49.2).
 * Utilise la table PostgreSQL 'users' comme miroir SQL des utilisateurs AD.
 * Porte le trait HasRoles de Spatie pour la gestion des permissions et rôles.
 *
 * Transition :
 * - Phase A : AD = source de vérité, cette table = cache
 * - Phase B : Double-écriture, SQL prend le relais
 * - Phase C : SQL = source de vérité, AD = proxy Windows
 *
 * Depuis l'Epic 49, le RUNTIME est en phase C pour les rôles et la session :
 * plus aucune décision d'autorisation ni de session n'interroge l'annuaire
 * (les écritures de compte, elles, restent AD-first).
 *
 * @property int $id
 * @property string $login
 * @property string|null $password
 * @property string|null $fullname
 * @property string|null $firstname
 * @property string|null $lastname
 * @property string|null $email
 * @property string|null $dn
 * @property string|null $ad_guid
 * @property string|null $nextcloud_user_id  CACHE de résolution de l'identité Nextcloud (story 61.1) —
 *                                           PAS une autorité : la vérité est chez Nextcloud, la colonne
 *                                           est nullable et reconstructible par `nextcloud:provision`.
 *                                           Volontairement HORS `$fillable` : elle s'écrit nominativement
 *                                           depuis le provisionnement, jamais par assignation de masse.
 * @property string|null $opencloud_user_id  CACHE de résolution de l'identité OpenCloud — mêmes propriétés,
 *                                           annuaire DIFFÉRENT : le même utilisateur y porte un identifiant
 *                                           qui n'a aucune raison de coïncider avec celui de l'autre produit.
 *                                           Nullable, reconstructible, index unique, hors `$fillable`.
 * @property string $role
 * @property string|null $school_code
 * @property string|null $school_name
 * @property bool $is_active
 * @property array|null $ad_right_profiles
 * @property int $ad_rights_bitmask
 * @property \DateTime|null $ad_synced_at
 * @property \DateTime|null $created_at
 * @property \DateTime|null $updated_at
 */
class User extends Authenticatable implements Wireable
{
    use HasFactory;
    /**
     * Les quatre écritures RETRANCHANTES de Spatie sont aliasées puis
     * re-déclarées plus bas (une méthode de classe l'emporte sur celle du trait)
     * afin d'y poser le verrou du compte d'administration protégé.
     * Cf. {@see self::PROTECTED_ADMIN_LOGIN} et {@see self::isProtectedAdmin()}.
     */
    use HasRoles {
        removeRole as private spatieRemoveRole;
        syncRoles as private spatieSyncRoles;
        revokePermissionTo as private spatieRevokePermissionTo;
        syncPermissions as private spatieSyncPermissions;
    }
    use HasAppCustomizations;

    protected $guard_name = 'web';

    protected $table = 'users';

    /**
     * Login du compte d'administration protégé.
     *
     * Ce compte porte l'INTÉGRALITÉ des droits existants, et aucun ne peut lui
     * être retiré : c'est le seul compte dont la déchéance serait irréversible
     * (plus personne ne détiendrait `user.assign.right` pour le restaurer).
     */
    public const PROTECTED_ADMIN_LOGIN = 'admin';

    protected $fillable = [
        'login',
        'password',
        'fullname',
        'firstname',
        'lastname',
        'email',
        'phone',
        'description',
        'dn',
        'ad_guid',
        'role',
        'school_code',
        'school_name',
        'is_active',
        'ad_right_profiles',
        'ad_rights_bitmask',
        'ad_synced_at',
        'pwd_reset_at',
        'password_changed_at',
        'quota_snapshot',
        'profile_snapshot',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'ad_right_profiles' => 'array',
        'ad_rights_bitmask' => 'integer',
        'ad_synced_at' => 'datetime',
        'pwd_reset_at' => 'datetime',
        // Timestamp du dernier changement de mdp effectif (depuis pwdLastSet AD).
        // NULL = jamais changé ou pwdLastSet=0 (filtre « mdp par défaut » D3/D7 — story 14.4).
        'password_changed_at' => 'datetime',
        'password' => 'hashed',
        // Snapshot quota quotidien (story 5.1b) — structure documentée dans
        // la migration `add_quota_snapshot_to_users_table` et dans la
        // commande `QuotaSnapshotCommand`.
        'quota_snapshot' => 'array',
        // Snapshot taille du profil itinérant /home/profiles (story 26.3) —
        // alimenté par la commande `profiles:snapshot`. Structure documentée
        // dans la migration `add_profile_snapshot_to_users_table`.
        'profile_snapshot' => 'array',
    ];

    // ========================================================================
    // RELATIONS
    // ========================================================================

    /**
     * Relation N:N avec les groupes d'utilisateurs
     */
    public function userGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            UserGroup::class,
            'user_group_user',
            'user_id',
            'user_group_id'
        )
            ->using(\App\Models\Pivot\UserGroupUserPivot::class)
            // Story 42.1 — `withPivot('role')` : l'arête est désormais écrite ET
            // lue des deux côtés (42.1 écrit via `User::groups()` dans UserService ;
            // 42.2/42.3 liront de part et d'autre). Le D4 « minimal » de 4.14 est levé.
            ->withPivot('role');
    }

    /**
     * Story 42.1 (review #1) — payload de sync pivot appliquant le rôle d'arête
     * PAR DÉFAUT (dérivé du rôle GLOBAL `users.role`, jamais de round-trip
     * LDAP) aux arêtes NOUVELLES uniquement. Les ids déjà attachés sont
     * renvoyés SANS attribut : `sync()`/`syncWithoutDetaching()` ne réécrit
     * alors pas leur pivot, donc un rôle promu (`owner`) n'est jamais
     * rétrogradé par ces chemins.
     *
     * Consommé par les écrivains pivot UI hors import (fiche user
     * `syncGroupsFromAd`, drawer « Gestion des groupes ») ; l'import users
     * (`UserService::persistUserGroupsToSql`) porte sa propre variante avec
     * capture d'état préalable.
     *
     * @param array<int,int|string> $groupIds
     * @return array<int,array<string,string>>
     */
    public function userGroupSyncPayloadWithDerivedRole(array $groupIds): array
    {
        $derivedRole = \App\Models\Pivot\UserGroupUserPivot::defaultRoleForGlobalRole($this->role);

        $alreadyAttachedIds = $this->userGroups()
            ->pluck('user_groups.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $payload = [];
        foreach ($groupIds as $gid) {
            $gid = (int) $gid;
            $payload[$gid] = in_array($gid, $alreadyAttachedIds, true)
                ? []
                : ['role' => $derivedRole];
        }

        return $payload;
    }

    /**
     * Story 4.13 — Helper PARTAGÉ de résolution classe↔partage post-fold.
     *
     * Depuis le fold de l'import (4.13), une classe est UNE seule ligne
     * `user_groups` au NOM NU (`3A`, `type='classe'`) : il n'existe plus de
     * distinction SQL `Equipe_`/`Classe_`. Le professeur ET l'élève sont
     * membres de la MÊME ligne nue ; la partition prof/élève vient de
     * `User.role` (cohérent 4.12), pas du nom du groupe.
     *
     * Retourne donc les NOMS NUS des classes (`type='classe'`) dont CET
     * utilisateur est membre — qu'il soit prof ou élève. Source de vérité
     * unique réutilisée par `UserPolicy::sharesClassWithTarget` ET par le
     * scoping du listing (`resources/views/pages/users/index.blade.php`).
     *
     * @return \Illuminate\Support\Collection<int,string>
     */
    public function classGroupNames(): \Illuminate\Support\Collection
    {
        return $this->userGroups()
            ->where('type', 'classe')
            ->pluck('name')
            ->map(static fn(mixed $n): string => (string) $n)
            ->unique()
            ->values();
    }

    /**
     * Story 4.13 — Deux utilisateurs partagent une classe s'ils sont membres
     * d'une même ligne `user_groups` (`type='classe'`, même nom nu).
     *
     * Utilisé par la Policy pour décider si un prof peut voir/reset un élève :
     * post-fold, prof et élève sont co-membres de la même classe nue. La
     * distinction de rôle (qui PEUT agir) reste portée par `User.role` via les
     * gates de la Policy ; ce helper ne décide QUE du partage de scope.
     */
    public function sharesClassGroupWith(User $other): bool
    {
        $mine = $this->classGroupNames();

        if ($mine->isEmpty()) {
            return false;
        }

        $theirs = $other->classGroupNames();

        if ($theirs->isEmpty()) {
            return false;
        }

        return $mine->intersect($theirs)->isNotEmpty();
    }

    /**
     * Groupes d'utilisateurs (classes, équipes, etc.)
     *
     * Story 5.2 (D5=A) — `->using(UserGroupUserPivot::class)` permet à
     * `App\Observers\UserGroupUserPivotObserver` d'écouter les events
     * `created`/`deleted` sur les rows pivot. Aucune autre conséquence
     * fonctionnelle (le pivot ne porte ni timestamps ni colonnes additionnelles).
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            UserGroup::class,
            'user_group_user',
            'user_id',
            'user_group_id'
        )
            ->using(\App\Models\Pivot\UserGroupUserPivot::class)
            // Story 42.1 — `withPivot('role')` : c'est la relation d'ÉCRITURE de
            // l'arête à l'import users (`persistUserGroupsToSql`). Sans elle, le
            // rôle dérivé passé au sync/attach serait ignoré silencieusement.
            ->withPivot('role');
    }

    /**
     * Story 34.1 — répertoires réseau assignés directement à cet utilisateur.
     * Maille `User` : la lettre s'affiche dans sa session ET l'ACL POSIX réelle
     * est dérivée (`user:<login>` rx/rwx selon `access`). Porte le pivot
     * `access`.
     */
    public function networkShares(): MorphToMany
    {
        return $this->morphToMany(
            NetworkShare::class,
            'assignable',
            'network_share_assignables',
            'assignable_id',
            'network_share_id',
        )->withPivot('access')->withTimestamps();
    }

    /**
     * Délégations accordées à cet utilisateur
     */
    public function delegations(): HasMany
    {
        return $this->hasMany(Delegation::class, 'user_id');
    }

    /**
     * Délégations accordées par cet utilisateur
     */
    public function grantedDelegations(): HasMany
    {
        return $this->hasMany(Delegation::class, 'granted_by');
    }

    public function wallpapers(): MorphMany
    {
        return $this->morphMany(Wallpaper::class, 'owner');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByRole(Builder $query, string $role): Builder
    {
        return $query->where('role', $role);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $q) use ($search) {
            $q->where('login', 'ILIKE', "%{$search}%")
                ->orWhere('fullname', 'ILIKE', "%{$search}%")
                ->orWhere('firstname', 'ILIKE', "%{$search}%")
                ->orWhere('lastname', 'ILIKE', "%{$search}%")
                ->orWhere('email', 'ILIKE', "%{$search}%");
        });
    }

    public function scopeSyncedFromAd(Builder $query): Builder
    {
        return $query->whereNotNull('ad_synced_at');
    }

    public function scopeExternal(Builder $query, string $currentSchoolCode): Builder
    {
        return $query->whereNotNull('school_code')
            ->where('school_code', '!=', '0')
            ->whereRaw('LOWER(school_code) != LOWER(?)', [$currentSchoolCode]);
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Trouve un utilisateur par son login.
     *
     * Story 4.10 (correctif review #14) — comparaison case-insensitive.
     * AD est case-insensitive sur sAMAccountName ; Postgres `=` est
     * case-sensitive. Sans `LOWER()`, un user POSTant `JDOE` ne matche pas
     * l'enregistrement `jdoe` → faux `permission_denied` lors d'un POST iPXE
     * alors que le bind LDAP a réussi.
     */
    public static function findByLogin(string $login): ?self
    {
        return static::whereRaw('LOWER(login) = ?', [strtolower($login)])->first();
    }

    /**
     * Vérifie si l'utilisateur a été synchronisé depuis l'AD
     */
    public function isSyncedFromAd(): bool
    {
        return $this->ad_synced_at !== null;
    }

    // ========================================================================
    // Helpers d'authentification
    //
    // Why: le guard web injecte directement l'Eloquent User (cf.
    // `LdapUserProvider`). Ces accesseurs exposent l'identité du compte aux
    // consommateurs (`auth()->user()->getLogin()`, …) sans résoudre un modèle
    // à la main.
    //
    // Story 49.2 (FR-R3) — les prédicats scolaires LDAP-first
    // (`isAdmin()`/`isProf()`/`isEleve()`/`getGroups()`) et toute la chaîne
    // LDAP-lazy qui les portait (`getLdapUser()`, `ldapBusinessObject()`, le
    // cache statique `$ldapCache`) ont été SUPPRIMÉS, pas réécrits : ils
    // nommaient des catégories scolaires en dur et coûtaient un aller-retour
    // annuaire par utilisateur. Selon ce que l'appelant voulait réellement, il
    // lit désormais un DROIT (`can('…')` / `hasRole(…)`, matérialisés par 49.1),
    // une APPARTENANCE (`userGroups()`), ou `users.role` quand il s'agit d'un
    // comportement type-OU assumé (affichage). Aucun prédicat de remplacement
    // n'a été introduit : ce serait reconduire le problème éteint ici.
    // ========================================================================

    public function getLogin(): string
    {
        return (string) $this->login;
    }

    public function getFullName(): string
    {
        return $this->fullname !== null && $this->fullname !== ''
            ? $this->fullname
            : (string) $this->login;
    }

    public function getFirstName(): ?string
    {
        return $this->firstname;
    }

    public function getLastName(): ?string
    {
        return $this->lastname;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getRole(): string
    {
        return $this->role ?? 'autre';
    }

    /**
     * Story 54.3 — LA résolution canonique du rôle MÉTIER (`admin`/`prof`/
     * `eleve`/`administratif`), 100 % Postgres, pensée pour un rendu
     * omniprésent (la navbar, sur les 156 pages du produit).
     *
     * **Pourquoi cette méthode existe et pas une autre :**
     *
     * 1. **PAS `isProf()`/`isEleve()`/`isAdmin()`** : ces méthodes étaient
     *    **LDAP-first** (un aller-retour annuaire par login, avec un cache
     *    seulement *request-scoped*) — inacceptable dans une navbar rendue à
     *    CHAQUE page vue, par CHAQUE utilisateur. Elles n'existent plus depuis
     *    la Story 49.2 (FR-R3 : suppression, pas réécriture) ; ce docblock garde
     *    la trace du raisonnement pour que le réflexe ne revienne pas.
     * 2. **PAS `hasRole('prof')`/`hasRole('eleve')` (Spatie)** : ces rôles sont
     *    désormais matérialisés par la Story 49.1 (le groupe porte le profil de
     *    droits) et seraient donc fiables pour `prof`/`eleve`. Mais ils ne
     *    couvrent PAS `administratif`, qui n'a aucun rôle Spatie : la résolution
     *    ci-dessous doit rendre les quatre familles métier, `users.role` reste
     *    donc son entrée. Point d'attention si un jour `administratif` devient
     *    un rôle : cette méthode pourra alors basculer entièrement sur Spatie.
     * 3. **PAS `$this->role` brut sans normalisation** : la colonne `role` a
     *    TROIS écrivains au vocabulaire divergent — la sync AD→SQL
     *    (`UserSyncService.php:503-508`, singulier `eleve|prof|administratif`),
     *    l'auto-provisioning au login (`LdapUserProvider`, `AuthController` —
     *    **PLURIEL** `eleves|profs|administratifs`)
     *    et `UserService` (`administratifs → 'admin'`). Un prof connecté avant
     *    le prochain passage de sync porte `role='profs'` : un simple `===`
     *    le raterait.
     *
     * **`'admin'` n'est JAMAIS déduit de `users.role`** : la valeur littérale
     * `'admin'` en base est ambiguë (elle peut désigner un profil
     * administratif via le roleMap `UserService`, et le compte protégé
     * `admin` porte `role='autre'` en régime stable). Le SEUL signal fiable
     * pour le rôle métier `admin` est le rôle Spatie `super-admin`
     * ({@see \App\Enums\SambaRole::SuperAdmin}) — matérialisé de façon fiable
     * par `grantAdminRights()` de la sync et par le drawer de délégation.
     *
     * **Coût** : 0 LDAP, 0 SQL pour `users.role` (déjà hydraté en mémoire par
     * `Auth::login()` — le guard pose l'Eloquent), au plus 1 SELECT Spatie par
     * requête HTTP (relation mise en cache par le registrar Spatie). NFR9 tenu.
     *
     * **Résultat = un ENSEMBLE, jamais un scalaire** : un prof délégué
     * `super-admin` renvoie `['prof', 'admin']` — il voit les deux familles
     * de tuiles du lanceur.
     *
     * **Fraîcheur (mise à jour 49.3)** : la réconciliation nocturne des départs
     * désactive les comptes disparus et retire leurs appartenances ; le sursis
     * d'un `users.role` non rétrogradé se limite donc à l'intervalle entre deux
     * passes, et n'a jamais valeur d'autorisation (FR14 : la tuile est un
     * affichage, l'autorisation réelle reste côté extension).
     *
     * **Point de modification unique** : cette méthode est réutilisée par le
     * claim `role` du SSO (Story 55.2) — un seul endroit à faire évoluer,
     * volontairement pas de classe résolveuse dédiée pour une seule méthode
     * nommée et testée (fiche « pas de sur-conçu »).
     *
     * @return list<string>
     */
    public function businessRoles(): array
    {
        $roles = [];

        // 1. users.role NORMALISÉ (colonne déjà hydratée par Auth::login() :
        //    0 SQL). ⚠️ 'admin' est VOLONTAIREMENT mappé sur 'administratif',
        //    JAMAIS sur 'admin' (valeur ambiguë — voir docblock ci-dessus).
        $profile = match (strtolower(trim((string) $this->role))) {
            'prof', 'profs' => 'prof',
            'eleve', 'eleves' => 'eleve',
            'administratif', 'administratifs', 'admin' => 'administratif',
            default => null,   // 'autre', 'federated', vide…
        };
        if ($profile !== null) {
            $roles[] = $profile;
        }

        // 2. Le rôle métier 'admin' vient EXCLUSIVEMENT de Spatie super-admin.
        if ($this->hasRole(SambaRole::SuperAdmin->value)) {
            $roles[] = 'admin';
        }

        return $roles;
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    // ========================================================================
    // Verrou du compte d'administration protégé
    // ========================================================================

    /**
     * Ce compte est-il le compte d'administration protégé ?
     *
     * Il s'agit d'un login RÉSERVÉ ({@see self::PROTECTED_ADMIN_LOGIN}), à ne
     * pas confondre avec « être administrateur », qui se lit sur le rôle Spatie
     * `super-admin` ({@see \App\Enums\SambaRole::SuperAdmin}) et vaut pour de
     * multiples comptes.
     */
    public function isProtectedAdmin(): bool
    {
        return $this->login === self::PROTECTED_ADMIN_LOGIN;
    }

    /**
     * Refuse le retrait d'un rôle au compte protégé.
     *
     * @throws \App\Exceptions\ProtectedAdminRightsException
     */
    public function removeRole(...$role)
    {
        if ($this->isProtectedAdmin()) {
            throw ProtectedAdminRightsException::cannotRemove($this->login);
        }

        return $this->spatieRemoveRole(...$role);
    }

    /**
     * Refuse le retrait d'une permission directe au compte protégé.
     *
     * Les permissions du compte `admin` sont DIRECTES (attachées à
     * l'utilisateur, pas héritées d'un rôle) : elles se révoquent sans toucher
     * à ses rôles.
     *
     * @throws \App\Exceptions\ProtectedAdminRightsException
     */
    public function revokePermissionTo($permission)
    {
        if ($this->isProtectedAdmin()) {
            throw ProtectedAdminRightsException::cannotRemove($this->login);
        }

        return $this->spatieRevokePermissionTo($permission);
    }

    /**
     * `syncRoles` est retranchant : il détache tous les rôles avant de rattacher
     * ceux demandés. Sur le compte protégé, `super-admin` est réinjecté quel que
     * soit l'argument reçu. `collectRoles()` dédoublonne, un `super-admin` déjà
     * présent dans l'argument est donc sans effet.
     */
    public function syncRoles(...$roles)
    {
        if ($this->isProtectedAdmin()) {
            $roles = array_merge(Arr::flatten($roles), [SambaRole::SuperAdmin->value]);

            return $this->spatieSyncRoles($roles);
        }

        return $this->spatieSyncRoles(...$roles);
    }

    /**
     * Idem pour les permissions : sur le compte protégé, la synchronisation
     * porte TOUJOURS sur l'intégralité des droits déclarés dans le code
     * ({@see SambaPermission}), l'argument reçu étant ignoré. Un droit ajouté
     * à l'enum est ainsi acquis au compte dès la synchronisation suivante —
     * et immédiatement via le `Gate::before` d'`AuthServiceProvider`.
     */
    public function syncPermissions(...$permissions)
    {
        if ($this->isProtectedAdmin()) {
            return $this->spatieSyncPermissions(
                array_column(SambaPermission::cases(), 'value')
            );
        }

        return $this->spatieSyncPermissions(...$permissions);
    }

    /**
     * Vérifie si l'utilisateur est externe (rattaché à un autre établissement)
     */
    public function isExternal(): bool
    {
        if (empty(trim($this->school_code ?? '')) || $this->school_code === '0') {
            return false;
        }

        $currentCode = \App\Facades\SEConfig::getCurrentEstablishmentCode();

        if (empty($currentCode) || $currentCode === '0') {
            return false;
        }

        return strtolower($this->school_code) !== strtolower($currentCode);
    }

    /**
     * Retourne le nom d'affichage (fullname ou login)
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->fullname ?? $this->login;
    }

    /**
     * Retourne le groupe de rôle (Eleves, Profs, Administratifs) de l'utilisateur.
     */
    public function roleGroup(): ?UserGroup
    {
        return $this->groups()->where('type', 'role')->first();
    }

    /**
     * Retourne le groupe de fonction (Direction, Agent, etc.) de l'utilisateur.
     */
    public function functionGroup(): ?UserGroup
    {
        return $this->groups()->where('type', 'function')->first();
    }

    // ========================================================================
    // WIREABLE (Livewire)
    // ========================================================================

    public function toLivewire(): array
    {
        return ['id' => $this->id];
    }

    public static function fromLivewire($value): static
    {
        return static::findOrFail($value['id']);
    }
}
