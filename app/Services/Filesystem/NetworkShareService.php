<?php

declare(strict_types=1);

namespace App\Services\Filesystem;

use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\QuotaAuditLog;
use App\Services\Filesystem\Acl\AclFormat;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Story 34.1 — provisioning FS/ACL GÉNÉRIQUE des répertoires réseau gérés.
 *
 * Service distinct de {@see ShareService} (spécifique aux classes) : il crée le
 * répertoire d'un {@see NetworkShare} sous une racine DÉDIÉE
 * `/var/sambaedu/Partages/<directory_name>` et applique les ACLs POSIX dérivées
 * des assignations `User`/`UserGroup` (`access` ro→`rx`, rw→`rwx`). Idempotent,
 * fail-soft, audité (`quota_audit_logs`, `target_type='share'`).
 *
 * **Pourquoi self-contained (zéro réutilisation de `AclService::setAcls`).**
 * {@see AclService::validatePath()} (et donc `setAcls`/`getFacl`) est VERROUILLÉ
 * sur `classesRoot()` (`/var/sambaedu/Classes`) : il REFUSE tout chemin sous
 * `Partages`. Plutôt que de détendre cette garde partagée (risque sur la
 * baseline 5.2 et garde-fou « ZÉRO touche AclService »), ce service porte sa
 * PROPRE garde de path calquée 1:1 ({@see validateSharePath()}) — triple garde
 * préservée (regex anti-traversal + `escapeshellarg` + whitelist sudo) — et ses
 * propres shell-outs `setfacl`/`mkdir`/`chown`/`chgrp` (encapsulés `Process`,
 * `Process::fake()` en tests). Les helpers de NOMMAGE de groupe Unix
 * (`aclGroupLocalPart`/`establishmentSuffix`) sont des méthodes PUBLIQUES de
 * `ShareService`, réutilisées en LECTURE (aucune modification de ShareService).
 *
 * **Modèle d'accès à deux axes (décision Henri 2026-06-29).** L'ACL POSIX dérive
 * des seules assignations `User`/`UserGroup`. Une assignation `WorkstationGroup`
 * est MONTAGE-SEUL — elle ne contribue AUCUNE ligne d'ACL (invariant : POSIX ne
 * sait pas exprimer « les utilisateurs de la machine X » ; la visibilité de la
 * lettre par parc est gérée par {@see \App\Services\Agent\Providers\DrivesStateProvider}
 * via la maille WG, pas par le FS).
 */
class NetworkShareService
{
    /**
     * Racine canonique des répertoires réseau. Overridable en tests (iso
     * `AclService::$classesRoot`). `config('filesystem.shares_root')` la
     * surcharge si défini.
     */
    public static string $sharesRoot = '/var/sambaedu/Partages';

    /**
     * Profondeur maximale autorisée sous {@see sharesRoot()} : `<directory_name>`
     * = 1 niveau ; on prévoit 2 pour de futurs sous-dossiers de template
     * (Story 34.3). Au-delà : refus.
     */
    private const MAX_DEPTH = 2;

    public function __construct(private readonly ShareService $shareService)
    {
    }

    public function sharesRoot(): string
    {
        return rtrim((string) config('filesystem.shares_root', static::$sharesRoot), '/');
    }

    // =========================================================================
    // Path / nommage
    // =========================================================================

    /**
     * Motif d'un `directory_name` valide (segment FS sûr) : alphanum + `._-`,
     * 1er char ≠ `.`. Source de vérité UNIQUE du format — consommée à la fois par
     * {@see isValidDirectoryName()} (garde de provisioning) ET par la règle
     * `regex:` du formulaire de création/édition 34.2 (finding 34.1 M4 : le format
     * n'était validé qu'au provisioning, un nom malformé pouvait être persisté).
     */
    public const DIRECTORY_NAME_PATTERN = '/^[A-Za-z0-9_-][A-Za-z0-9_.-]*$/';

    /**
     * Valide un `directory_name` (segment FS sûr, unique) : alphanum + `._-`,
     * 1er char ≠ `.` (calqué `ShareService::bareClassName`). Aucun `/`, espace,
     * métacaractère.
     */
    public function isValidDirectoryName(?string $name): bool
    {
        return $name !== null
            && $name !== ''
            && preg_match(self::DIRECTORY_NAME_PATTERN, $name) === 1;
    }

    /**
     * Garde de path durcie, calquée 1:1 sur {@see AclService::validatePath()}
     * mais PARAMÉTRÉE sur {@see sharesRoot()} et {@see MAX_DEPTH}. Rejette :
     * path non absolu / hors racine / caractères hors `[A-Za-z0-9_./-]` /
     * segment `..`|`.` / profondeur > MAX_DEPTH. Pas de `realpath()` (le path
     * peut ne pas exister à la création).
     */
    public function validateSharePath(string $path): bool
    {
        $root = $this->sharesRoot();

        if ($path === '' || $path[0] !== '/') {
            return false;
        }
        if (! str_starts_with($path, $root . '/') && $path !== $root) {
            return false;
        }
        if (! preg_match('#^/[A-Za-z0-9_./-]+$#', $path)) {
            return false;
        }

        $segments = $path === $root
            ? []
            : explode('/', trim(substr($path, strlen($root) + 1), '/'));
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '..' || $seg === '.') {
                return false;
            }
        }
        if (count($segments) > self::MAX_DEPTH) {
            return false;
        }

        return true;
    }

    /**
     * Path absolu du répertoire d'un share, ou `null` si `directory_name`
     * invalide ou path refusé par la garde.
     */
    public function resolveSharePath(NetworkShare $share): ?string
    {
        if (! $this->isValidDirectoryName($share->directory_name)) {
            return null;
        }
        $path = $this->sharesRoot() . '/' . $share->directory_name;

        return $this->validateSharePath($path) ? $path : null;
    }

    // =========================================================================
    // ACL builder
    // =========================================================================

    /**
     * Set d'ACLs POSIX d'un répertoire réseau : set canonique de base + une
     * ligne par assignation `User`/`UserGroup` (rx si `access=ro`, rwx si
     * `access=rw`), avec défauts miroir pour l'héritage (calqué
     * `ShareService::buildEchangeAcls`). Les assignations `WorkstationGroup`
     * sont IGNORÉES (montage-seul — aucune ACL).
     *
     * @return list<string>
     */
    public function buildAcls(NetworkShare $share): array
    {
        // Set canonique de base (pas de groupe `equipe_` générique — propre aux
        // classes). `domain admins` garde la main, `other` n'a rien.
        $acls = [
            'user::rwx',
            'group::---',
            'group:domain\\040admins:rwx',
            'mask::rwx',
            'other::---',
            'default:user::rwx',
            'default:group::---',
            'default:group:domain\\040admins:rwx',
            'default:mask::rwx',
            'default:other::---',
        ];

        foreach ($share->assignments as $assignment) {
            $mode = $assignment->isWritable() ? 'rwx' : 'rx';

            if ($assignment->assignable_type === User::class) {
                $login = $this->loginFor($assignment);
                if ($login === null) {
                    Log::warning('NetworkShareService: assignation user ignorée (login invalide)', [
                        'share_id' => $share->id,
                        'assignable_id' => $assignment->assignable_id,
                    ]);
                    continue;
                }
                $acls[] = "user:{$login}:{$mode}";
                $acls[] = "default:user:{$login}:{$mode}";
                continue;
            }

            if ($assignment->assignable_type === UserGroup::class) {
                $unix = $this->unixGroupFor($assignment);
                if ($unix === null) {
                    Log::warning('NetworkShareService: assignation groupe ignorée (nom de groupe Unix indérivable)', [
                        'share_id' => $share->id,
                        'assignable_id' => $assignment->assignable_id,
                    ]);
                    continue;
                }
                $acls[] = "group:{$unix}:{$mode}";
                $acls[] = "default:group:{$unix}:{$mode}";
                continue;
            }

            // WorkstationGroup (et tout autre type) : MONTAGE-SEUL — aucune ACL.
        }

        return $acls;
    }

    /**
     * Login POSIX d'une assignation `User` (validé `^[a-zA-Z0-9._-]+$`, iso
     * `ShareService::createEleveDir`). `null` si introuvable/invalide.
     */
    private function loginFor(NetworkShareAssignable $assignment): ?string
    {
        $user = $assignment->relationLoaded('assignable')
            ? $assignment->assignable
            : User::find($assignment->assignable_id);

        $login = $user instanceof User ? (string) $user->login : '';

        return ($login !== '' && preg_match('/^[a-zA-Z0-9._-]+$/', $login) === 1)
            ? $login
            : null;
    }

    /**
     * Nom de groupe Unix d'une assignation `UserGroup` — MAPPING RETENU
     * (documenté) :
     *  - `type === 'classe'` → `classe_<localPart>` (groupe d'appartenance des
     *    ÉLÈVES de la classe ; localPart = nom court + suffixe établissement
     *    fédéré, via `ShareService::aclGroupLocalPart`, mémoire
     *    acl_equipe_group_missing_etab_suffix) ;
     *  - `type === 'equipe'` → `equipe_<localPart>` (équipe pédagogique /
     *    enseignants) ;
     *  - sinon → `<localPart>` : le groupe générique (admin, custom) dont le
     *    samAccountName Unix == son propre nom court (« à défaut le name du
     *    groupe »).
     * Les préfixes `classe_`/`equipe_` déjà présents dans localPart sont
     * dé-préfixés avant re-préfixage (anti double-préfixe). `null` si nom
     * indérivable.
     */
    private function unixGroupFor(NetworkShareAssignable $assignment): ?string
    {
        $group = $assignment->relationLoaded('assignable')
            ? $assignment->assignable
            : UserGroup::find($assignment->assignable_id);

        if (! $group instanceof UserGroup) {
            return null;
        }

        return $this->unixGroupForGroup($group);
    }

    /**
     * Projette un {@see UserGroup} sur son nom de groupe Unix POSIX (sujet
     * d'ACL `group:<unix>`). Source de vérité UNIQUE du mapping forward,
     * réutilisée en LECTURE par {@see AclInspectionService} pour construire
     * l'index INVERSE (nom disque → UserGroup) par forward-projection — approche
     * robuste qui évite tout strip fragile du suffixe établissement. Cf.
     * {@see unixGroupFor()} pour la sémantique du mapping (classe_/equipe_/nu).
     */
    public function unixGroupForGroup(UserGroup $group): ?string
    {
        $local = $this->shareService->aclGroupLocalPart($group);
        if ($local === null) {
            // Nom non conforme à la regex de durcissement : repli sur le name nu
            // lowercased s'il est sûr, sinon abandon (fail-soft). On N'AJOUTE PAS
            // le préfixe `classe_`/`equipe_` ici : ce chemin n'est atteint que
            // pour un nom que `aclGroupLocalPart` a déjà rejeté ; le regex
            // ci-dessous ne laisse passer que des noms déjà sûrs (espaces/accents
            // rejetés → `null`). Si le groupe Unix nu n'existe pas, `setfacl`
            // échouera et sera tracé (fail-closed, jamais de sur-octroi silencieux).
            $fallback = strtolower((string) $group->name);

            return preg_match('/^[a-z0-9._-]+$/', $fallback) === 1 ? $fallback : null;
        }

        $bare = $this->stripAclPrefix($local);

        return match ($group->type) {
            'classe' => 'classe_' . $bare,
            'equipe' => 'equipe_' . $bare,
            default => $local,
        };
    }

    /**
     * Retire un préfixe `classe_`/`equipe_` de tête (le localPart est déjà
     * lowercased par `aclGroupLocalPart`).
     */
    private function stripAclPrefix(string $local): string
    {
        foreach (['classe_', 'equipe_'] as $prefix) {
            if (str_starts_with($local, $prefix)) {
                return substr($local, strlen($prefix));
            }
        }

        return $local;
    }

    // =========================================================================
    // Opérations publiques
    // =========================================================================

    /**
     * Provisionne le répertoire d'un share : mkdir -p idempotent + ACLs POSIX
     * (wipe `setfacl -b` puis batch) + ownership (`chown www-admin`,
     * `chgrp 'domain admins'`) + audit. Fail-soft (retour `bool`, `Log::error`
     * préfixé, aucune exception).
     */
    public function provision(NetworkShare $share, ?string $performedBy = null): bool
    {
        $performedBy = $performedBy ?? (string) (auth()->user()?->getAuthIdentifier() ?? 'system');

        $path = $this->resolveSharePath($share);
        if ($path === null) {
            Log::error('NetworkShareService: provision refusé (directory_name invalide)', [
                'share_id' => $share->id,
                'directory_name' => $share->directory_name,
            ]);

            return false;
        }

        // `Cache::store('file')` : APCu ne supporte pas les locks cross-process
        // (mémoire apcu_cache_no_lock).
        $lock = Cache::store('file')->lock('network-shares:provision:' . $share->id, 60);
        if (! $lock->get()) {
            Log::warning('NetworkShareService: provision verrouillé (autre opération en cours)', [
                'share_id' => $share->id,
                'directory_name' => $share->directory_name,
            ]);

            return false;
        }

        try {
            // Charge les assignations ET leur cible polymorphe une fois (Laravel
            // groupe les MorphTo par type → ~2 requêtes au lieu d'un User::find /
            // UserGroup::find par assignation). `loginFor`/`unixGroupFor`
            // emprunteront alors le chemin `relationLoaded('assignable')`.
            $share->loadMissing(['assignments', 'assignments.assignable']);

            $allOk = true;

            // mkdir -p crée aussi la racine `Partages` (idempotent — [PROD]).
            if (! $this->ensureDirectory($path)) {
                $allOk = false;
            }

            if (! $this->setAcls($path, $this->buildAcls($share))) {
                $allOk = false;
            }

            $allOk = $this->chownAndChgrp($path) && $allOk;

            $this->writeAudit('provision_share', $performedBy, $share, [
                'directory_name' => $share->directory_name,
                'path' => $path,
                'assignments_count' => $share->assignments->count(),
                'success' => $allOk,
            ]);

            Log::info('NetworkShareService: provision terminé', [
                'share_id' => $share->id,
                'directory_name' => $share->directory_name,
                'path' => $path,
                'success' => $allOk,
            ]);

            return $allOk;
        } finally {
            $lock->release();
            Cache::forget('network-share-status:' . $share->id);
        }
    }

    /**
     * DÉPROVISIONNE le répertoire d'un share supprimé : révoque tout accès POSIX
     * puis archive le dossier HORS de l'espace de noms exposé.
     *
     * **Pourquoi (sécurité).** `Partages/` est exporté en entier par le share SMB
     * `[partages]` : un sous-dossier « supprimé » côté SQL mais laissé sur disque
     * AVEC ses ACL reste atteignable en UNC (`\\serveur\partages\<name>`) par tous
     * ceux qui avaient un grant — fuite de contrôle d'accès. La suppression SQL
     * seule ne suffit donc pas.
     *
     * Séquence idempotente et data-safe (on NE détruit PAS les données —
     * cohérent CLAUDE.md « jamais rm -rf ») :
     *  1. `setfacl -R -P -b` : purge des ACL étendues (retire tous les grants) ;
     *  2. `chmod -R 0770` : retire l'accès `other` que le mode de base laissait
     *     traîner après le wipe (sinon dossier world-readable via la perm de base) ;
     *  3. `mv` vers `Partages/.trash/<directory_name>-<id>` (répertoire poubelle
     *     en `0700 www-admin` — non listable par les autres), sortant le dossier
     *     de la vue des partages actifs sans perdre son contenu.
     *
     * Fail-soft (retour `bool`, `Log::error` préfixé). No-op réussi si le dossier
     * n'existe déjà plus.
     */
    public function deprovision(NetworkShare $share, ?string $performedBy = null): bool
    {
        $performedBy = $performedBy ?? (string) (auth()->user()?->getAuthIdentifier() ?? 'system');

        $path = $this->resolveSharePath($share);
        if ($path === null) {
            Log::error('NetworkShareService: deprovision refusé (directory_name invalide)', [
                'share_id' => $share->id,
                'directory_name' => $share->directory_name,
            ]);

            return false;
        }

        $lock = Cache::store('file')->lock('network-shares:provision:' . $share->id, 60);
        if (! $lock->get()) {
            Log::warning('NetworkShareService: deprovision verrouillé (autre opération en cours)', [
                'share_id' => $share->id,
                'directory_name' => $share->directory_name,
            ]);

            return false;
        }

        try {
            // Déjà absent : rien à révoquer (idempotent).
            if (! is_dir($path)) {
                return true;
            }

            $escaped = escapeshellarg($path);
            $allOk = true;

            // 1. Purge des ACL étendues (retire tous les grants).
            $wipe = Process::run(sprintf('sudo setfacl -R -P -b %s', $escaped));
            if (! $wipe->successful()) {
                Log::error('NetworkShareService: deprovision échec wipe ACL', [
                    'path' => $path,
                    'output' => trim($wipe->errorOutput() ?: $wipe->output()),
                ]);
                $allOk = false;
            }

            // 2. Retire l'accès `other` résiduel du mode de base.
            $chmod = Process::run(sprintf('sudo chmod -R 0770 %s', $escaped));
            if (! $chmod->successful()) {
                Log::error('NetworkShareService: deprovision échec chmod', [
                    'path' => $path,
                    'output' => trim($chmod->errorOutput() ?: $chmod->output()),
                ]);
                $allOk = false;
            }

            // 3. Archive hors de l'espace exposé.
            $archived = $this->archiveOutOfBand($path, $share);
            $allOk = $archived && $allOk;

            $this->writeAudit('deprovision_share', $performedBy, $share, [
                'directory_name' => $share->directory_name,
                'path' => $path,
                'archived' => $archived,
                'success' => $allOk,
            ]);

            Log::info('NetworkShareService: deprovision terminé', [
                'share_id' => $share->id,
                'directory_name' => $share->directory_name,
                'success' => $allOk,
            ]);

            return $allOk;
        } finally {
            $lock->release();
            Cache::forget('network-share-status:' . $share->id);
        }
    }

    /**
     * Déplace un répertoire dé-provisionné vers `Partages/.trash/<name>-<id>`
     * (poubelle `0700 www-admin`, non listable). Data-safe (mv, pas rm).
     */
    private function archiveOutOfBand(string $path, NetworkShare $share): bool
    {
        $trashRoot = $this->sharesRoot() . '/.trash';
        $target = $trashRoot . '/' . $share->directory_name . '-' . $share->id;

        if (! $this->validateSharePath($target)) {
            Log::error('NetworkShareService: archiveOutOfBand cible refusée', ['target' => $target]);

            return false;
        }

        // Poubelle : créée en 0700 www-admin (contenu protégé des autres).
        $mk = Process::run(sprintf('sudo mkdir -p -m 0700 %s', escapeshellarg($trashRoot)));
        if ($mk->successful()) {
            Process::run(sprintf('sudo chown www-admin %s', escapeshellarg($trashRoot)));
        } else {
            Log::error('NetworkShareService: archiveOutOfBand échec mkdir poubelle', [
                'trash' => $trashRoot,
                'output' => trim($mk->errorOutput() ?: $mk->output()),
            ]);

            return false;
        }

        $mv = Process::run(sprintf('sudo mv %s %s', escapeshellarg($path), escapeshellarg($target)));
        if (! $mv->successful()) {
            Log::error('NetworkShareService: archiveOutOfBand échec mv', [
                'path' => $path,
                'target' => $target,
                'output' => trim($mv->errorOutput() ?: $mv->output()),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Lecture de l'état d'un répertoire (sans side-effect, pour future UI /
     * commande). Ne shell-oute pas.
     *
     * @return array{exists: bool, path: string|null, assignments_count: int}
     */
    public function getStatus(NetworkShare $share): array
    {
        $path = $this->resolveSharePath($share);

        return [
            'exists' => $path !== null && is_dir($path),
            'path' => $path,
            'assignments_count' => $share->assignments()->count(),
        ];
    }

    /**
     * Audit de dérive : compare le set d'ACL DÉSIRÉ ({@see buildAcls()}, dérivé
     * du SQL autoritaire) au set EFFECTIF lu sur le disque (`getfacl` du
     * répertoire de tête). Rend l'idempotence VISIBLE : c'est l'analogue SE5
     * programmatique du « relire getfacl » manuel du legacy `visuacls.php`, mais
     * avec un diff exploitable et une reconvergence 1-clic (= {@see provision()}).
     *
     * Read-only (aucun `setfacl`). `getfacl` normalisé via {@see AclFormat} pour
     * que la comparaison reflète l'égalité SÉMANTIQUE (raccourci `rx` vs sortie
     * `r-x`). Limite assumée : ne compare que l'ACL du répertoire de tête (pas
     * une descente récursive) — suffisant pour détecter une dérive de contrat.
     *
     * @return array{
     *   status: 'conforme'|'drifted'|'absent'|'error',
     *   path: string|null,
     *   expected: list<string>,
     *   effective: list<string>|null,
     *   missing: list<string>,
     *   unexpected: list<string>,
     * }
     */
    public function computeDrift(NetworkShare $share): array
    {
        $path = $this->resolveSharePath($share);
        $share->loadMissing(['assignments', 'assignments.assignable']);
        $expected = AclFormat::normalizeSet($this->buildAcls($share));

        $base = [
            'path' => $path,
            'expected' => $expected,
            'effective' => null,
            'missing' => [],
            'unexpected' => [],
        ];

        if ($path === null) {
            return ['status' => 'error'] + $base;
        }
        if (! is_dir($path)) {
            return ['status' => 'absent'] + $base;
        }

        $cmd = sprintf('sudo getfacl -c -E -p %s 2>/dev/null', escapeshellarg($path));
        $r = Process::run($cmd);
        if (! $r->successful()) {
            Log::warning('NetworkShareService: computeDrift échec getfacl', [
                'share_id' => $share->id,
                'path' => $path,
                'output' => trim($r->errorOutput() ?: $r->output()),
            ]);

            return ['status' => 'error'] + $base;
        }

        $effective = AclFormat::normalizeSet(preg_split('/\R/', $r->output()) ?: []);
        $missing = array_values(array_diff($expected, $effective));    // désiré absent du disque
        $unexpected = array_values(array_diff($effective, $expected)); // présent sur disque, non désiré

        return [
            'status' => ($missing === [] && $unexpected === []) ? 'conforme' : 'drifted',
            'path' => $path,
            'expected' => $expected,
            'effective' => $effective,
            'missing' => $missing,
            'unexpected' => $unexpected,
        ];
    }

    // =========================================================================
    // Helpers FS privés (sudo mkdir/setfacl/chown/chgrp encapsulés)
    // =========================================================================

    private function ensureDirectory(string $path): bool
    {
        if (! $this->validateSharePath($path)) {
            Log::error('NetworkShareService: ensureDirectory path invalide', ['path' => $path]);

            return false;
        }
        if (is_dir($path)) {
            return true;
        }
        $cmd = sprintf('sudo mkdir -p %s', escapeshellarg($path));
        $r = Process::run($cmd);
        if (! $r->successful()) {
            Log::error('NetworkShareService: ensureDirectory échec mkdir', [
                'path' => $path,
                'output' => trim($r->errorOutput() ?: $r->output()),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Wipe (`setfacl -R -P -b`) puis ré-applique le set canonique. `-P`
     * (physical) anti symlink traversal (iso AclService review 5.2 #3).
     * Idempotent.
     *
     * @param  list<string>  $acls
     */
    private function setAcls(string $path, array $acls): bool
    {
        if (! $this->validateSharePath($path)) {
            Log::error('NetworkShareService: setAcls path invalide', ['path' => $path]);

            return false;
        }

        $escaped = escapeshellarg($path);

        $wipe = sprintf('sudo setfacl -R -P -b %s', $escaped);
        $r = Process::run($wipe);
        if (! $r->successful()) {
            Log::error('NetworkShareService: setAcls échec wipe', [
                'path' => $path,
                'output' => trim($r->errorOutput() ?: $r->output()),
            ]);

            return false;
        }

        $allOk = true;
        foreach ($acls as $acl) {
            $cmd = sprintf('sudo setfacl -R -P -m %s %s', escapeshellarg($acl), $escaped);
            $rr = Process::run($cmd);
            if (! $rr->successful()) {
                Log::error('NetworkShareService: setAcls échec ACL', [
                    'path' => $path,
                    'acl' => $acl,
                    'output' => trim($rr->errorOutput() ?: $rr->output()),
                ]);
                $allOk = false;
            }
        }

        return $allOk;
    }

    /**
     * `chown www-admin` + `chgrp 'domain admins'` (PHP-FPM = www-admin, mémoire
     * php_fpm_user_www_admin). Fail-soft non-silencieux (iso ShareService #7).
     */
    private function chownAndChgrp(string $path): bool
    {
        if (! $this->validateSharePath($path)) {
            return false;
        }
        $escaped = escapeshellarg($path);
        $r1 = Process::run(sprintf('sudo chown www-admin %s', $escaped));
        $r2 = Process::run(sprintf("sudo chgrp 'domain admins' %s", $escaped));
        if (! $r1->successful()) {
            Log::warning('NetworkShareService: chown échec', [
                'path' => $path,
                'err' => trim($r1->errorOutput() ?: $r1->output()),
            ]);
        }
        if (! $r2->successful()) {
            Log::warning('NetworkShareService: chgrp échec', [
                'path' => $path,
                'err' => trim($r2->errorOutput() ?: $r2->output()),
            ]);
        }

        return $r1->successful() && $r2->successful();
    }

    /**
     * Audit `quota_audit_logs` (`target_type='share'`, `partition='/var/sambaedu'`).
     * Best-effort (iso ShareService::writeAudit).
     *
     * @param  array<string, mixed>  $newValues
     */
    public function writeAudit(string $action, string $performedBy, NetworkShare $share, array $newValues): void
    {
        try {
            QuotaAuditLog::log(
                action: $action,
                performedBy: $performedBy,
                targetType: 'share',
                targetName: $share->directory_name,
                partition: '/var/sambaedu',
                oldValues: null,
                newValues: $newValues,
                quotaRuleId: null,
                fsApplied: true,
            );
        } catch (\Throwable $e) {
            Log::error('NetworkShareService: audit log écriture échouée', [
                'action' => $action,
                'share_id' => $share->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
