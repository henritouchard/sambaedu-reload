<?php

declare(strict_types=1);

namespace App\Services\Filesystem;

use App\Models\QuotaAuditLog;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Story 5.2 — Service métier d'orchestration des partages de classe.
 *
 * Décalque fidèle des fonctions du legacy `sambaedu/includes/partages.inc.php` :
 *  - `update_classes()` (l. 452-580) → {@see createClassShare()} +
 *    {@see toggleEchange()} (le legacy combinait les deux ; on les sépare en
 *    Story 5.2 pour une UX claire).
 *  - `cree_rep()`       (l. 326-393) → {@see syncUserClassMemberships()}.
 *  - Renommage `Classe_X` → `.Classe_X` (l. 575-578) → {@see archiveClassShare()}
 *    (D4=A : méthode publique, mais aucun appel automatique).
 *
 * Convention naming (cf. legacy l. 344) :
 *   - Path FS : `/var/sambaedu/Classes/Classe_<UserGroup::name>` (casse préservée).
 *   - ACL group : `equipe_<lower(name)>` et `Classe_<lower(name)>` avec `\\040`
 *     pour les espaces (cf. {@see escapeAclClassName()}).
 *
 * Décisions kickoff (cf. story §Kickoff Décisions) :
 *  - **D1=A** : pas de table dédiée `shares`, FS = source de vérité.
 *  - **D3=A** : archive élève via `Classe_<new>/<eleve>/Archives/`.
 *  - **D4=A** : `archiveClassShare()` exposée mais pas auto-déclenchée.
 *  - **D6=A** : `_echange` activé par défaut à la création.
 *  - **D9=A** : pas de rollback mkdir+setfacl, idempotence garantie via
 *    `setAcls -b` qui wipe avant le batch.
 *  - **D10=A** : audit via `quota_audit_logs` avec `target_type='share'`.
 *
 * Sécurité : toutes les opérations FS sous `/var/sambaedu/Classes` sont
 * protégées par {@see AclService::validatePath()} (regex anti-traversal +
 * profondeur max). En complément, `mkdir`/`mv`/`chgrp`/`chown` sont préfixés
 * `escapeshellarg`.
 *
 * Cache UI (review 5.2 #13) : la SFC `class-share-section.blade.php` cache
 * `getStatus($group)` 60s sous la clé `share-status:<group_id>`. Toutes les
 * mutations publiques (`createClassShare`, `toggleEchange`, `archiveClassShare`,
 * `syncUserClassMemberships`) appellent `Cache::forget('share-status:<id>')`
 * en fin de méthode (succès ou échec) pour que l'UI ne voie pas un état
 * périmé après une opération Artisan ou un changement par un autre admin.
 */
class ShareService
{
    /**
     * Racine canonique des partages classes. Overridable en tests (D13).
     * Cohérent avec `AclService::$classesRoot`.
     */
    public static string $classesRoot = '/var/sambaedu/Classes';

    public function __construct(private readonly AclService $aclService)
    {
    }

    public function classesRoot(): string
    {
        return rtrim((string) config('filesystem.classes_root', static::$classesRoot), '/');
    }

    // =========================================================================
    // Helpers nommage / paths
    // =========================================================================

    /**
     * Dépouille le préfixe `Classe_` (case-insensitive) si présent et applique
     * la regex de durcissement (alphanum + `._-`, 1er char != `.`).
     *
     * Cohérent avec le legacy : `ldap.inc.php::list_classes` (l. 5187) extrait
     * la partie après le premier `_` du CN AD ; `partages.inc.php::update_classes`
     * (l. 459) re-préfixe ensuite `"Classe_" . $Classe` pour construire le path.
     * Le SER a une copie SQL `user_groups.name` qui peut être stockée préfixée
     * (CN brut) OU non (legacy ancien). On normalise ici une fois pour toutes,
     * pour éviter le double préfixe (`Classe_Classe_3eme3`) et la mauvaise clé
     * ACL (`classe_classe_3eme3` au lieu de `classe_3eme3`).
     *
     * Casse préservée : `bareClassName('Classe_3emeA')` → `'3emeA'`.
     *
     * @return string|null Nom court (casse préservée) ou null si invalide.
     */
    public function bareClassName(string $rawName): ?string
    {
        $bare = preg_match('/^Classe_(.+)$/i', $rawName, $m) ? $m[1] : $rawName;

        // Review 5.2 #12 (durcissement préventif) : refuser les noms
        // commençant par `.` (les classes admin n'ont pas besoin de noms
        // cachés, et `archiveClassShare` réserve le préfixe `.` à l'archive).
        // Review 5.2 #15 (Q décision) : refuser également les espaces (la
        // racine FS `Classe_<name>` est ensuite passée à `validatePath` qui
        // les rejette de toute façon — cohérence et simplicité).
        // Caractères autorisés : alphanum + . _ - (1er char != `.`).
        if (! preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]*$/', $bare)) {
            return null;
        }
        return $bare;
    }

    /**
     * Convertit un nom de classe brut en sa forme canonique pour les ACLs :
     * nom court (sans préfixe `Classe_`) lowercase. Cohérent avec les groupes
     * AD `classe_<bare>` / `equipe_<bare>` posés par `buildXxxAcls()`.
     *
     * Note : retourne `null` si le nom contient des caractères non autorisés.
     * L'escape `\040` n'est plus nécessaire ici (la regex de
     * {@see bareClassName()} rejette les espaces) ; il est conservé dans
     * `buildXxxAcls()` pour `domain admins` (groupe AD avec espace, hors
     * scope nom de classe).
     */
    public function escapeAclClassName(string $rawName): ?string
    {
        $bare = $this->bareClassName($rawName);
        return $bare !== null ? strtolower($bare) : null;
    }

    /**
     * Construit le path absolu du partage d'un groupe classe.
     *
     * Refuse si :
     *  - `$group->type !== 'classe'` ;
     *  - `$group->name` contient des caractères suspects (cf. {@see bareClassName()}).
     *
     * Le `Classe_` préfixe stocké en DB (cas SER) est dé-préfixé puis re-préfixé
     * une seule fois — pas de doublon `Classe_Classe_X`.
     *
     * @return string|null Path absolu ou `null` si refusé.
     */
    public function resolveClassPath(UserGroup $group): ?string
    {
        if ($group->type !== 'classe') {
            return null;
        }
        $bare = $this->bareClassName($group->name);
        if ($bare === null) {
            return null;
        }
        $path = $this->classesRoot() . '/Classe_' . $bare;
        if (! $this->aclService->validatePath($path)) {
            return null;
        }
        return $path;
    }

    // =========================================================================
    // ACL builders — décalqués 1:1 sur partages.inc.php l. 452-580 + 372
    // =========================================================================

    /**
     * Set d'ACLs canonique pour la racine `/Classe_<nom>/`. Décalque l. 498.
     */
    public function buildCanonicalAcls(string $classNameLower): array
    {
        return [
            'user::rwx',
            'group::---',
            "group:equipe_{$classNameLower}:rwx",
            'group:domain\\040admins:rwx',
            'mask::rwx',
            'other::---',
            'default:user::rwx',
            'default:group::---',
            "default:group:equipe_{$classNameLower}:rwx",
            'default:group:domain\\040admins:rwx',
            'default:mask::rwx',
            'default:other::---',
        ];
    }

    /**
     * Set d'ACLs ajoutées sur la racine après le set canonique. Décalque l. 567 :
     * `setfacl -m "group:equipe_X:rx,group:Classe_X:rx"`.
     *
     * Note legacy : la racine reçoit d'abord `equipe_X:rwx` (canonique l. 498)
     * puis le retrait du `w` est fait via cet -m additif. On reproduit cela
     * via un appel addAcl chainé après le setAcls canonique.
     */
    public function buildRootRwToRxAdjustment(string $classNameLower): array
    {
        return [
            "group:equipe_{$classNameLower}:rx",
            "group:classe_{$classNameLower}:rx",
        ];
    }

    /**
     * Set d'ACLs sur `Classe_<nom>/_travail/`. Décalque l. 506.
     */
    public function buildTravailAcls(string $classNameLower): array
    {
        return [
            'user::rwx',
            'group::---',
            "group:equipe_{$classNameLower}:rwx",
            'group:domain\\040admins:rwx',
            "group:classe_{$classNameLower}:rx",
            'mask::rwx',
            'other::---',
            'default:user::rwx',
            'default:group::---',
            "default:group:equipe_{$classNameLower}:rwx",
            'default:group:domain\\040admins:rwx',
            "default:group:classe_{$classNameLower}:rx",
            'default:mask::rwx',
            'default:other::---',
        ];
    }

    /**
     * Set d'ACLs sur `Classe_<nom>/_profs/` (privé enseignants).
     * Le legacy ne pose pas explicitement de set sur _profs (l. 513-518 :
     * mkdir + chown/chgrp seulement) — l'héritage default depuis la racine
     * suffit. On expose néanmoins un set explicite pour idempotence.
     */
    public function buildProfsAcls(string $classNameLower): array
    {
        return [
            'user::rwx',
            'group::---',
            "group:equipe_{$classNameLower}:rwx",
            'group:domain\\040admins:rwx',
            'mask::rwx',
            'other::---',
            'default:user::rwx',
            'default:group::---',
            "default:group:equipe_{$classNameLower}:rwx",
            'default:group:domain\\040admins:rwx',
            'default:mask::rwx',
            'default:other::---',
        ];
    }

    /**
     * Set d'ACLs sur `Classe_<nom>/_echange/`. Décalque l. 492.
     *
     * @param bool $active Si `true`, group:Classe_X:rwx ; sinon ---.
     */
    public function buildEchangeAcls(string $classNameLower, bool $active): array
    {
        $mode = $active ? 'rwx' : '---';
        return [
            'user::rwx',
            'group::---',
            "group:equipe_{$classNameLower}:rwx",
            'group:domain\\040admins:rwx',
            "group:classe_{$classNameLower}:{$mode}",
            'mask::rwx',
            'other::---',
            'default:user::rwx',
            'default:group::---',
            "default:group:equipe_{$classNameLower}:rwx",
            'default:group:domain\\040admins:rwx',
            "default:group:classe_{$classNameLower}:{$mode}",
            'default:mask::rwx',
            'default:other::---',
        ];
    }

    /**
     * Set d'ACLs sur `Classe_<nom>/<eleve>/`. Décalque l. 372.
     */
    public function buildEleveAcls(string $login, string $classNameLower): array
    {
        return [
            'user::rwx',
            'group::---',
            "user:{$login}:rwx",
            "group:equipe_{$classNameLower}:rwx",
            'group:domain\\040admins:rwx',
            'mask::rwx',
            'other::---',
            'default:user::rwx',
            'default:group::---',
            "default:group:equipe_{$classNameLower}:rwx",
            'default:group:domain\\040admins:rwx',
            'default:mask::rwx',
            'default:other::---',
            "default:user:{$login}:rwx",
        ];
    }

    // =========================================================================
    // Operations publiques
    // =========================================================================

    /**
     * Crée le partage d'une classe (racine + sous-dirs + dossiers élèves) +
     * applique les ACLs canoniques. Décalque `partages.inc.php::update_classes`
     * l. 452-580.
     *
     * Idempotent (AC 3) : un second appel re-applique les ACLs sans toucher
     * aux dossiers existants ni aux data dedans.
     *
     * D6=A : `_echange` activé par défaut à la création.
     */
    public function createClassShare(UserGroup $group, ?string $performedBy = null): bool
    {
        $performedBy = $performedBy ?? (string) (auth()->user()?->getAuthIdentifier() ?? 'system');

        $classPath = $this->resolveClassPath($group);
        if ($classPath === null) {
            Log::error('ShareService: createClassShare refusé (type ou nom invalide)', [
                'group_id' => $group->id,
                'group_type' => $group->type,
                'group_name' => $group->name,
            ]);
            return false;
        }

        $classNameLower = $this->escapeAclClassName($group->name);
        if ($classNameLower === null) {
            return false;
        }

        $lock = Cache::lock('shares:resync:' . $group->id, 60);
        if (! $lock->get()) {
            Log::warning('ShareService: createClassShare verrouillé (autre opération en cours)', [
                'group_id' => $group->id,
                'group_name' => $group->name,
            ]);
            return false;
        }

        try {
            $allOk = true;

            // 1. mkdir racine si absent.
            if (! $this->ensureDirectory($classPath)) {
                $allOk = false;
            }

            // 2. ACLs canoniques racine.
            if (! $this->aclService->setAcls($classPath, $this->buildCanonicalAcls($classNameLower), recurse: true)) {
                $allOk = false;
            }

            // 3. Ajustement -m sur racine : retrait du w, ajout Classe_X:rx
            //    (cf. legacy l. 567).
            foreach ($this->buildRootRwToRxAdjustment($classNameLower) as $aclLine) {
                if (! $this->aclService->addAcl($classPath, $aclLine, recurse: false)) {
                    $allOk = false;
                }
            }

            // 4. ownership racine.
            $allOk = $this->chownAndChgrp($classPath) && $allOk;

            // 5. sous-dirs canoniques + ACLs.
            $subdirs = [
                '_travail' => $this->buildTravailAcls($classNameLower),
                '_profs' => $this->buildProfsAcls($classNameLower),
                '_echange' => $this->buildEchangeAcls($classNameLower, active: true), // D6=A
            ];
            foreach ($subdirs as $sub => $acls) {
                $subPath = $classPath . '/' . $sub;
                if (! $this->ensureDirectory($subPath)) {
                    $allOk = false;
                    continue;
                }
                if (! $this->aclService->setAcls($subPath, $acls, recurse: true)) {
                    $allOk = false;
                }
                $allOk = $this->chownAndChgrp($subPath) && $allOk;
            }

            // 6. Dossiers élèves (membres du groupe classe).
            // On charge proprement la relation pour éviter les surprises.
            $members = $group->users()->get(['users.id', 'users.login']);
            foreach ($members as $member) {
                if (! $this->createEleveDir($classPath, $classNameLower, (string) $member->login)) {
                    $allOk = false;
                }
            }

            // 7. audit log.
            $this->writeAudit('create_share', $performedBy, $group, [
                'class_name' => $group->name,
                'path' => $classPath,
                'success' => $allOk,
                'members_count' => $members->count(),
            ]);

            Log::info('ShareService: createClassShare terminé', [
                'group_id' => $group->id,
                'class_name' => $group->name,
                'path' => $classPath,
                'success' => $allOk,
            ]);

            return $allOk;
        } finally {
            $lock->release();
            // Review 5.2 #13 — cache invalidation post-mutation.
            Cache::forget('share-status:' . $group->id);
        }
    }

    /**
     * Synchronise les dossiers élève d'un user lors d'un changement de classe.
     * Décalque `cree_rep()` l. 326-393.
     *
     * @param User  $user
     * @param int[] $oldClassIds Classes précédentes (UserGroup::id, type='classe').
     * @param int[] $newClassIds Nouvelles classes (UserGroup::id, type='classe').
     */
    public function syncUserClassMemberships(User $user, array $oldClassIds = [], array $newClassIds = []): bool
    {
        $login = (string) $user->login;
        if ($login === '' || ! preg_match('/^[a-zA-Z0-9._-]+$/', $login)) {
            Log::error('ShareService: syncUserClassMemberships login invalide', ['login' => $login]);
            return false;
        }

        $performedBy = (string) (auth()->user()?->getAuthIdentifier() ?? 'system');

        $oldClassIds = array_unique(array_map('intval', $oldClassIds));
        $newClassIds = array_unique(array_map('intval', $newClassIds));

        $added = array_diff($newClassIds, $oldClassIds);
        $removed = array_diff($oldClassIds, $newClassIds);

        $allOk = true;

        // 1. Pour chaque nouvelle classe : créer dossier élève si absent + ACLs.
        foreach ($added as $newId) {
            $newGroup = UserGroup::find($newId);
            if (! $newGroup || $newGroup->type !== 'classe') {
                continue;
            }
            $newPath = $this->resolveClassPath($newGroup);
            if ($newPath === null) {
                $allOk = false;
                continue;
            }
            $classLower = $this->escapeAclClassName($newGroup->name);
            if ($classLower === null) {
                $allOk = false;
                continue;
            }

            // S'il existe une (ou plusieurs) anciennes classe(s) à archiver
            // dans le dossier élève de la nouvelle classe.
            foreach ($removed as $oldId) {
                $oldGroup = UserGroup::find($oldId);
                if (! $oldGroup || $oldGroup->type !== 'classe') {
                    continue;
                }
                $oldPath = $this->resolveClassPath($oldGroup);
                if ($oldPath === null) {
                    continue;
                }
                $oldEleveDir = $oldPath . '/' . $login;
                if (! is_dir($oldEleveDir)) {
                    continue;
                }

                // D3=A : déplacer vers Classe_<new>/<eleve>/Archives.
                $newEleveDir = $newPath . '/' . $login;
                $this->ensureDirectory($newEleveDir);

                $archiveTarget = $newEleveDir . '/Archives';
                if (is_dir($archiveTarget)) {
                    // Archive déjà présente — on ne dégage pas l'historique,
                    // on log et on retire simplement les anciennes données
                    // (cohérent legacy l. 354).
                    Log::warning(
                        'ShareService: syncUserClassMemberships archive déjà présente, suppression de l\'ancien dossier',
                        ['login' => $login, 'old_path' => $oldEleveDir]
                    );
                    if (! $this->removeDirectory($oldEleveDir)) {
                        $allOk = false;
                    }
                } else {
                    if (! $this->moveDirectory($oldEleveDir, $archiveTarget)) {
                        $allOk = false;
                    }
                }
            }

            // Crée (ou s'assure de) le dossier élève + applique les ACLs.
            if (! $this->createEleveDir($newPath, $classLower, $login)) {
                $allOk = false;
            }
        }

        // 2. Pour les classes retirées (sans nouvelle classe correspondante) :
        //    on retire l'ACL `user:<login>:rwx` du dossier classe pour ne pas
        //    laisser un accès résiduel. Le dossier élève est laissé tel quel
        //    (data préservée — cohérent D9 fail-soft).
        // Review 5.2 #8 — `$added` et `$removed` sont disjoints par
        // construction (`array_diff` symétrique), donc inutile de re-filtrer :
        // toutes les classes retirées sont également candidates au nettoyage
        // d'ACL (que la première boucle ait archivé un dossier ou non).
        $remainingRemoved = $removed;
        foreach ($remainingRemoved as $oldId) {
            $oldGroup = UserGroup::find($oldId);
            if (! $oldGroup || $oldGroup->type !== 'classe') {
                continue;
            }
            $oldPath = $this->resolveClassPath($oldGroup);
            if ($oldPath === null) {
                continue;
            }
            // Retrait soft de l'ACL utilisateur (ne touche pas aux fichiers).
            $this->aclService->removeAcl($oldPath, "user:{$login}", recurse: true);
        }

        $this->writeAudit('sync_user', $performedBy, null, [
            'login' => $login,
            'old_class_ids' => $oldClassIds,
            'new_class_ids' => $newClassIds,
            'success' => $allOk,
        ], targetName: $login);

        Log::info('ShareService: syncUserClassMemberships terminé', [
            'login' => $login,
            'added' => array_values($added),
            'removed' => array_values($removed),
            'success' => $allOk,
        ]);

        // Review 5.2 #13 — cache invalidation post-mutation : on bust toutes
        // les classes concernées (old ∪ new) pour que l'UI ne montre pas un
        // état périmé sur l'une ou l'autre.
        foreach (array_unique(array_merge($oldClassIds, $newClassIds)) as $cid) {
            Cache::forget('share-status:' . (int) $cid);
        }

        return $allOk;
    }

    /**
     * Toggle le dossier `_echange` d'une classe (ACLs uniquement, pas de
     * suppression de data). Décalque l. 475-493.
     */
    public function toggleEchange(UserGroup $group, bool $active): bool
    {
        $performedBy = (string) (auth()->user()?->getAuthIdentifier() ?? 'system');

        $classPath = $this->resolveClassPath($group);
        if ($classPath === null) {
            return false;
        }
        $classLower = $this->escapeAclClassName($group->name);
        if ($classLower === null) {
            return false;
        }

        $echangePath = $classPath . '/_echange';
        if (! $this->ensureDirectory($echangePath)) {
            return false;
        }

        $ok = $this->aclService->setAcls(
            $echangePath,
            $this->buildEchangeAcls($classLower, $active),
            recurse: true
        );

        if ($ok) {
            $ok = $this->chownAndChgrp($echangePath) && $ok;
        }

        $this->writeAudit('toggle_echange', $performedBy, $group, [
            'class_name' => $group->name,
            'active' => $active,
            'success' => $ok,
        ]);

        // Review 5.2 #13 — cache invalidation post-mutation.
        Cache::forget('share-status:' . $group->id);

        return $ok;
    }

    /**
     * Archive le partage d'une classe (rename `Classe_X` → `.Classe_X`). D4=A :
     * exposé mais pas auto. Décalque l. 575-578.
     */
    public function archiveClassShare(UserGroup $group): bool
    {
        $performedBy = (string) (auth()->user()?->getAuthIdentifier() ?? 'system');

        $classPath = $this->resolveClassPath($group);
        if ($classPath === null) {
            return false;
        }
        if (! is_dir($classPath)) {
            Log::info('ShareService: archiveClassShare path inexistant', ['path' => $classPath]);
            return true; // idempotent : déjà archivé.
        }

        $parent = dirname($classPath);
        $base = basename($classPath);
        $target = $parent . '/.' . $base;

        // Sécurité : refuser si target sort de la racine (paranoia).
        if (! str_starts_with($target, $this->classesRoot() . '/.')) {
            Log::error('ShareService: archiveClassShare cible hors racine', ['target' => $target]);
            return false;
        }

        // Review 5.2 #11 (Q2 décalque legacy strict + log) : si le `.Classe_X`
        // cible existe déjà (cycle restauration → re-archivage, ou doublon de
        // groupe avec le même nom), on REFUSE le mv. Le legacy ligne 577 a le
        // même bug silencieux ; on ajoute un log warning explicit pour que
        // l'admin puisse détecter le cas anormal en prod sans pour autant
        // écraser/timestamper l'archive existante (préservation data).
        if (is_dir($target)) {
            Log::warning('ShareService: archiveClassShare cible déjà existante, mv refusé', [
                'classe' => $group->name,
                'from' => $classPath,
                'target' => $target,
            ]);
            return false;
        }

        $cmd = sprintf('sudo mv %s %s', escapeshellarg($classPath), escapeshellarg($target));
        $r = Process::run($cmd);
        if (! $r->successful()) {
            Log::error('ShareService: archiveClassShare échec mv', [
                'path' => $classPath,
                'target' => $target,
                'output' => trim($r->errorOutput() ?: $r->output()),
            ]);
            return false;
        }

        $this->writeAudit('archive_share', $performedBy, $group, [
            'class_name' => $group->name,
            'from' => $classPath,
            'to' => $target,
        ]);

        Log::info('ShareService: archiveClassShare', ['from' => $classPath, 'to' => $target]);

        // Review 5.2 #13 — cache invalidation post-mutation.
        Cache::forget('share-status:' . $group->id);

        return true;
    }

    /**
     * Lecture publique de l'état d'un partage (existence + sous-dirs + ACLs
     * résumées) — utilisé par l'UI Livewire et les commandes Artisan en
     * `--dry-run`. Pas de side-effect.
     *
     * @return array{
     *   exists: bool,
     *   path: string|null,
     *   subdirs: array<string, bool>,
     *   echange_active: ?bool,
     *   members_count: int,
     * }
     */
    public function getStatus(UserGroup $group): array
    {
        $classPath = $this->resolveClassPath($group);
        $status = [
            'exists' => false,
            'path' => $classPath,
            'subdirs' => ['_travail' => false, '_profs' => false, '_echange' => false],
            'echange_active' => null,
            'members_count' => $group->users()->count(),
        ];
        if ($classPath === null) {
            return $status;
        }
        $status['exists'] = is_dir($classPath);
        if (! $status['exists']) {
            return $status;
        }
        foreach (array_keys($status['subdirs']) as $sub) {
            $status['subdirs'][$sub] = is_dir($classPath . '/' . $sub);
        }
        // Lecture facl _echange pour deviner l'état actif/inactif.
        if ($status['subdirs']['_echange']) {
            $facl = $this->aclService->getFacl($classPath . '/_echange');
            if (is_array($facl)) {
                $classLower = $this->escapeAclClassName($group->name);
                $key = $classLower !== null ? 'classe_' . $classLower : null;
                if ($key !== null && isset($facl[$key]['mode'])) {
                    $status['echange_active'] = ($facl[$key]['mode'] === 'rwx');
                }
            }
        }
        return $status;
    }

    // =========================================================================
    // Helpers privés FS — encapsulent les sudo mkdir/mv/rm/chown/chgrp.
    // =========================================================================

    /**
     * Crée un dossier (sudo mkdir -p) si absent. Idempotent.
     */
    private function ensureDirectory(string $path): bool
    {
        if (! $this->aclService->validatePath($path)) {
            Log::error('ShareService: ensureDirectory path invalide', ['path' => $path]);
            return false;
        }
        if (is_dir($path)) {
            return true;
        }
        $cmd = sprintf('sudo mkdir -p %s', escapeshellarg($path));
        $r = Process::run($cmd);
        if (! $r->successful()) {
            Log::error('ShareService: ensureDirectory échec mkdir', [
                'path' => $path,
                'output' => trim($r->errorOutput() ?: $r->output()),
            ]);
            return false;
        }
        return true;
    }

    /**
     * Déplace un dossier (sudo mv).
     */
    private function moveDirectory(string $from, string $to): bool
    {
        if (! $this->aclService->validatePath($from) || ! $this->aclService->validatePath($to)) {
            Log::error('ShareService: moveDirectory path invalide', ['from' => $from, 'to' => $to]);
            return false;
        }
        $cmd = sprintf('sudo mv -f %s %s', escapeshellarg($from), escapeshellarg($to));
        $r = Process::run($cmd);
        if (! $r->successful()) {
            Log::error('ShareService: moveDirectory échec mv', [
                'from' => $from,
                'to' => $to,
                'output' => trim($r->errorOutput() ?: $r->output()),
            ]);
            return false;
        }
        return true;
    }

    /**
     * Supprime un dossier (sudo rm -rf) — UNIQUEMENT sous classesRoot avec
     * triple garde validatePath + escapeshellarg + sudo whitelist.
     */
    private function removeDirectory(string $path): bool
    {
        if (! $this->aclService->validatePath($path)) {
            Log::error('ShareService: removeDirectory path invalide', ['path' => $path]);
            return false;
        }
        // Garde supplémentaire : refuser de supprimer la racine ou un Classe_X
        // entier (uniquement les sous-dossiers élèves/Archives).
        $rel = trim(substr($path, strlen($this->classesRoot())), '/');
        $segments = explode('/', $rel);
        if (count($segments) < 2) {
            Log::error('ShareService: removeDirectory profondeur insuffisante (refus)', ['path' => $path]);
            return false;
        }
        $cmd = sprintf('sudo rm -rf %s', escapeshellarg($path));
        $r = Process::run($cmd);
        if (! $r->successful()) {
            Log::error('ShareService: removeDirectory échec rm', [
                'path' => $path,
                'output' => trim($r->errorOutput() ?: $r->output()),
            ]);
            return false;
        }
        return true;
    }

    /**
     * Applique chown www-admin + chgrp domain admins (cohérent legacy).
     *
     * Review 5.2 #7 : retourne `bool` et log warning si l'une des commandes
     * échoue (ex: groupe `domain admins` inexistant en environnement non-AD
     * ou dev VM). Cohérent AC 10 fail-soft non-silencieux.
     */
    private function chownAndChgrp(string $path): bool
    {
        if (! $this->aclService->validatePath($path)) {
            return false;
        }
        $escaped = escapeshellarg($path);
        $r1 = Process::run(sprintf('sudo chown www-admin %s', $escaped));
        $r2 = Process::run(sprintf("sudo chgrp 'domain admins' %s", $escaped));
        if (! $r1->successful()) {
            Log::warning('ShareService: chown échec', [
                'path' => $path,
                'err' => trim($r1->errorOutput() ?: $r1->output()),
            ]);
        }
        if (! $r2->successful()) {
            Log::warning('ShareService: chgrp échec', [
                'path' => $path,
                'err' => trim($r2->errorOutput() ?: $r2->output()),
            ]);
        }
        return $r1->successful() && $r2->successful();
    }

    /**
     * Crée le dossier d'un élève dans une classe + applique les ACLs élève.
     */
    private function createEleveDir(string $classPath, string $classNameLower, string $login): bool
    {
        if (! preg_match('/^[a-zA-Z0-9._-]+$/', $login)) {
            Log::error('ShareService: createEleveDir login invalide', ['login' => $login]);
            return false;
        }
        $eleveDir = $classPath . '/' . $login;
        if (! $this->ensureDirectory($eleveDir)) {
            return false;
        }
        $ok = $this->aclService->setAcls(
            $eleveDir,
            $this->buildEleveAcls($login, $classNameLower),
            recurse: true
        );
        $ok = $this->chownAndChgrp($eleveDir) && $ok;
        return $ok;
    }

    /**
     * Écrit un row dans `quota_audit_logs` (D10=A). Best-effort : si la table
     * n'est pas disponible (env tests sans schéma), on log error mais on ne
     * fait pas remonter l'erreur (le filesystem a déjà été modifié).
     *
     * @param string                $action     create_share|sync_user|toggle_echange|archive_share|resync_class
     * @param string                $performedBy
     * @param UserGroup|null        $group
     * @param array<string, mixed>  $newValues
     */
    public function writeAudit(
        string $action,
        string $performedBy,
        ?UserGroup $group,
        array $newValues,
        ?string $targetName = null
    ): void {
        try {
            QuotaAuditLog::log(
                action: $action,
                performedBy: $performedBy,
                targetType: 'share',
                targetName: $targetName ?? $group?->name,
                partition: '/var/sambaedu',
                oldValues: null,
                newValues: $newValues,
                quotaRuleId: null,
                fsApplied: true,
            );
        } catch (\Throwable $e) {
            Log::error('ShareService: audit log écriture échouée', [
                'action' => $action,
                'performed_by' => $performedBy,
                'group_id' => $group?->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
