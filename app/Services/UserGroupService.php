<?php

declare(strict_types=1);

namespace App\Services;

use App\Facades\SEConfig;
use App\Models\Pivot\UserGroupUserPivot;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Constants\Ldap\FunctionGroups;
use App\Constants\Ldap\MainGroups;
use App\Repositories\GroupRepository;
use App\Repositories\RightRepository;
use App\Repositories\UserGroupRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class UserGroupService
{
    public function __construct(
        private UserGroupRepository $repository,
        private GroupRepository $groupRepository,
        private RightRepository $rightRepository,
    ) {
    }

    public function listPaginated(?string $search, int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->paginate($search, $perPage);
    }

    public function getById(int $id): ?UserGroup
    {
        return $this->repository->findById($id);
    }

    public function getAssignableUsers(): Collection
    {
        return User::query()
            ->select(['id', 'login', 'fullname', 'lastname', 'firstname'])
            ->orderByRaw("COALESCE(lastname, '')")
            ->orderByRaw("COALESCE(firstname, '')")
            ->orderBy('login')
            ->get();
    }

    public function createGroup(array $data): UserGroup
    {
        $payload = $this->validateData($data);

        $this->guardReservedPrefixOnCreate($payload['name'], $payload['type']);

        // Sélecteur SQL post-syncFromAd : depuis 4.13, les variantes de classe/
        // équipe foldent en UNE ligne au NOM NU. Le payload `name` est déjà nu
        // (garanti sans préfixe réservé par guardReservedPrefixOnCreate) ; pour
        // les autres types, c'est le CN brut résolu (Cours_X, Matiere_X@Y, …).
        $lookupName = $this->resolveSqlLookupName($payload['name'], $payload['type']);

        // Garde doublon : sans elle, recréer un groupe déjà existant (typiquement
        // une classe déjà présente) « réussit » silencieusement — l'écriture AD
        // est idempotente et le read-back ci-dessous retombe sur la ligne SQL
        // existante — et l'appelant est redirigé vers ce groupe au lieu d'être
        // averti. On refuse EN AMONT de toute écriture AD.
        if (UserGroup::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($lookupName)])->exists()) {
            throw new \InvalidArgumentException("Le groupe « {$lookupName} » existe déjà.");
        }

        $selectedUserIds = !empty($data['user_ids']) && is_array($data['user_ids'])
            ? array_values(array_unique(array_map('intval', $data['user_ids'])))
            : [];

        // Story 4.15 — IDs des professeurs principaux (canal `head_teacher_ids`,
        // projetés arête `role='owner'` depuis 42.2).
        $headTeacherUserIds = !empty($data['head_teacher_ids']) && is_array($data['head_teacher_ids'])
            ? array_values(array_unique(array_map('intval', $data['head_teacher_ids'])))
            : [];

        $created = $this->groupRepository->createGroup(
            name: $payload['name'],
            description: $payload['display_name'] ?? $payload['name'],
            type: $this->mapTypeToLdap($payload['type']),
        );

        if (!$created) {
            throw new RuntimeException("Création AD impossible pour le groupe '{$payload['name']}'.");
        }

        // Story 4.15 (D2) — l'écriture AD (incluant la 3ᵉ cible `PP_<base>`)
        // précède toujours `syncFromAd()` : le read-back re-pose alors l'arête
        // `role='owner'` depuis le `PP_<base>` qu'on vient d'écrire, donc le
        // pivot SQL converge sans clignotement. On force l'écriture dès qu'il
        // y a des membres OU des PP à projeter. Story 42.2 (T2.3) — 5ᵉ param
        // `[]` : le groupe n'existe pas encore en SQL, aucune arête à fournir
        // (l'override owner + le défaut dérivé couvrent tous les membres).
        if (count($selectedUserIds) > 0 || count($headTeacherUserIds) > 0) {
            $this->syncRoleAwareAdGroupMembers(
                $payload['name'],
                $payload['type'],
                $selectedUserIds,
                $headTeacherUserIds,
                [],
            );
        }

        $this->syncFromAd();

        $primaryGroup = UserGroup::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($lookupName)])
            ->first();

        if ($primaryGroup === null) {
            throw new RuntimeException('Le groupe AD a été créé mais introuvable après synchronisation SQL.');
        }

        return $primaryGroup;
    }

    public function updateGroup(int $id, array $data): UserGroup
    {
        $group = $this->repository->findById($id);

        if ($group === null) {
            throw new \InvalidArgumentException('Groupe introuvable.');
        }

        $payload = $this->validateData($data, $group);
        $oldName = $group->name;
        $newName = $payload['name'];

        if ($oldName !== $newName) {
            $renamed = $this->groupRepository->renameGroup($oldName, $newName);

            if (!$renamed) {
                throw new RuntimeException("Renommage AD impossible pour le groupe '{$oldName}' -> '{$newName}'.");
            }
        } else {
            // Story 4.15 (Q1/M1) — n'écrire la description AD que si elle a
            // RÉELLEMENT changé. Sans cette garde, un simple toggle PP (qui
            // renvoie display_name inchangé) déclenchait systématiquement un
            // write LDAP (et une RuntimeException possible) inutile.
            $desiredDescription = $payload['display_name'] ?? $newName;
            $currentDescription = $group->display_name ?? $oldName;

            if ($desiredDescription !== $currentDescription) {
                $updated = $this->groupRepository->updateGroupDescription(
                    cn: $newName,
                    description: $desiredDescription,
                );

                if (!$updated) {
                    throw new RuntimeException("Mise à jour AD impossible pour le groupe '{$newName}'.");
                }
            }
        }

        // Story 4.15 — `head_teacher_ids` peut accompagner le payload (UI
        // « Professeur principal »). Quand il est présent sans `user_ids`
        // explicite, on dérive les membres courants depuis le pivot SQL pour
        // que le routage Equipe_/Classe_ + la 3ᵉ cible PP_ restent cohérents.
        $hasUserIds = array_key_exists('user_ids', $data) && is_array($data['user_ids']);
        $hasHeadTeacherIds = array_key_exists('head_teacher_ids', $data) && is_array($data['head_teacher_ids']);

        if ($hasUserIds || $hasHeadTeacherIds) {
            // Story 42.2 (T2.1) — arêtes pivot lues en UNE requête (`role`,
            // withPivot 42.1) : map `user_id => rôle d'arête` fournie à la
            // résolution D2 de la projection. État volontairement PRÉ-update
            // (le pivot n'est réaligné que par le read-back APRÈS l'écriture
            // AD — flux 4.15/D2 AD-first) : les nouveaux membres du payload
            // n'ont pas d'arête → défaut dérivé ; les membres retirés sont
            // absents de `$selectedUserIds` → sortis des 3 buckets par le diff.
            $edgeRolesByUserId = [];
            foreach ($group->users()->pluck('user_group_user.role', 'users.id') as $userId => $role) {
                $edgeRolesByUserId[(int) $userId] = (string) $role;
            }

            $selectedUserIds = $hasUserIds
                ? array_values(array_unique(array_map('intval', $data['user_ids'])))
                : array_keys($edgeRolesByUserId);

            // Story 4.15 — distinction CLÉ ABSENTE vs `[]` EXPLICITE (INCHANGÉE
            // sur le fond). La 3ᵉ cible `PP_<base>` est TOUJOURS resynchronisée
            // par `syncRoleAwareAdGroupMembers` ; sans précaution, tout appel
            // d'`updateGroup` qui omet `head_teacher_ids` (edit-form : retrait
            // d'un membre, sauvegarde de la liste) écraserait `$headTeacherUserIds`
            // à `[]` → `PP_<base>` vidé en AD, puis le read-back `syncFromAd`
            // rétrograderait les arêtes `owner` : perte SILENCIEUSE des PP sur
            // une édition sans rapport. On préserve donc les PP existants en les
            // dérivant du pivot quand la clé est ABSENTE ; un `[]` EXPLICITE
            // (section « Professeur principal » qui retire tous les PP) reste un
            // effacement volontaire. Story 42.2 (T2.2) — la dérivation lit le
            // RÔLE d'arête (`role='owner'`), plus l'ancien flag d'arête 4.14.
            $headTeacherUserIds = $hasHeadTeacherIds
                ? array_values(array_unique(array_map('intval', $data['head_teacher_ids'])))
                : $group->users()
                    ->wherePivot('role', UserGroupUserPivot::ROLE_OWNER)
                    ->pluck('users.id')
                    ->map(static fn(mixed $id): int => (int) $id)
                    ->all();

            // Story 4.15 (D2) — écrire l'AD (PP_ compris) AVANT `syncFromAd()`.
            $this->syncRoleAwareAdGroupMembers(
                $newName,
                $payload['type'],
                $selectedUserIds,
                $headTeacherUserIds,
                $edgeRolesByUserId,
            );
        }

        // 4.13 — lookup post-sync au NOM NU pour les classes/équipes foldées :
        // l'edit-form peut renvoyer le CN stocké (`Classe_3A`) alors que la ligne
        // foldée est persistée au nom nu (`3A`). Les autres types restent au CN.
        // 4.16 — hissé AVANT syncFromAd pour servir à la fois de scope du read-back
        // (anti-divergence D5) ET de clé de lookup post-sync.
        $lookupName = $this->resolveSqlLookupName($newName, $payload['type']);

        // 4.16 — scoper le read-back au seul groupe édité (parité syncGroupsWithAd).
        // La base nue (ex. `3A`) fait remonter les 3 variantes Classe_/Equipe_/PP_
        // via le filtre onlyGroupNames (l.335-368 : matche CN brut ET base nue),
        // le fold 4.13 reste donc cohérent (1 ligne nue, union des membres, flag PP).
        // En mode scopé, le cleanup whereNotIn (l.433) ne tourne PAS :
        // aucune ligne hors scope n'est purgée (comportement voulu).
        // D2 : on cible $newName (après rename, l'AD porte déjà le NOUVEAU CN).
        $this->syncFromAd(onlyGroupNames: [$lookupName]);

        $updatedGroup = UserGroup::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($lookupName)])
            ->with('users')
            ->first();

        if ($updatedGroup === null) {
            throw new RuntimeException('Le groupe AD a été mis à jour mais introuvable après synchronisation SQL.');
        }

        return $updatedGroup;
    }

    public function deleteGroup(int $id): void
    {
        $group = $this->repository->findById($id);

        if ($group === null) {
            throw new \InvalidArgumentException('Groupe introuvable.');
        }

        $deleted = $this->groupRepository->deleteGroup($group->name);

        if (!$deleted) {
            throw new RuntimeException("Suppression AD impossible pour le groupe '{$group->name}'.");
        }

        // 4.16 — read-back global VOULU ici (NE PAS scoper).
        // La suppression AD retire le CN du lot ; en mode global, le cleanup
        // whereNotIn (l.433) puge la ligne SQL du groupe supprimé. Un scope sur le
        // groupe supprimé ne verrait aucun CN et ne purgerait RIEN → ligne fantôme.
        // D3 (story 4.16) : deleteGroup reste délibérément en read-back global.
        $this->syncFromAd();
    }

    public function bulkDelete(array $groupIds): int
    {
        $ids = collect($groupIds)
            ->map(fn(mixed $id): int => (int) $id)
            ->filter(fn(int $id): bool => $id > 0)
            ->unique()
            ->values();

        $deletedCount = 0;

        DB::transaction(function () use ($ids, &$deletedCount): void {
            foreach ($ids as $id) {
                $this->deleteGroup($id);
                $deletedCount++;
            }
        });

        return $deletedCount;
    }

    public function syncGroupsWithAd(array $groupIds): int
    {
        $ids = collect($groupIds)
            ->map(fn(mixed $id): int => (int) $id)
            ->filter(fn(int $id): bool => $id > 0)
            ->unique()
            ->values();

        $groupNames = UserGroup::query()
            ->whereIn('id', $ids)
            ->pluck('name')
            ->map(fn(mixed $name): string => (string) $name)
            ->filter(fn(string $name): bool => $name !== '')
            ->values()
            ->all();

        if (count($groupNames) === 0) {
            return 0;
        }

        $this->syncFromAd(onlyGroupNames: $groupNames);

        return count($groupNames);
    }

    /**
     * Alias de compatibilité legacy.
     *
     * @param callable|null $logger fn(string $level, string $message)
     * @return array{
     *   created:int,
     *   updated:int,
     *   skipped:int,
     *   linked_users:int,
     *   detached_users:int,
     *   deleted:int,
     *   errors:int,
     *   total_groups_detected:int,
     *   total_cn_detected:int,
     *   total_groups_folded:int
     * }
     */
    public function importFromUsersAdGroups(?callable $logger = null): array
    {
        return $this->syncFromAd($logger);
    }

    /**
     * Synchronise les groupes utilisateurs AD -> SQL (SQL = cache)
     *
     * @param callable|null $logger fn(string $level, string $message)
     * @param array<int,string> $onlyGroupNames
     * @return array{
     *   created:int,
     *   updated:int,
     *   skipped:int,
     *   linked_users:int,
     *   detached_users:int,
     *   deleted:int,
     *   errors:int,
     *   total_groups_detected:int,
     *   total_cn_detected:int,
     *   total_groups_folded:int
     * }
     */
    public function syncFromAd(?callable $logger = null, array $onlyGroupNames = []): array
    {
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'linked_users' => 0,
            'detached_users' => 0,
            // 4.14/42.2 — arêtes dont SEUL l'attribut de pivot a changé (depuis
            // 42.2 : bascule du rôle d'arête `role`, ex. owner↔manager sur
            // retrait/ajout PP), sans attach/detach. Renvoyé par
            // sync()['updated']. La CLÉ est un contrat de stats public
            // (retours, logs, UI) — ne pas la renommer.
            'head_teacher_updated' => 0,
            'deleted' => 0,
            'errors' => 0,
            // total_groups_detected : conservé pour compat (= nb de CN bruts AD).
            // total_cn_detected : alias explicite des CN bruts.
            // total_groups_folded : nb de LIGNES SQL réellement projetées (après
            // fold) — la vraie « unité métier » présentée dans l'UI.
            'total_groups_detected' => 0,
            'total_cn_detected' => 0,
            'total_groups_folded' => 0,
        ];

        $log = $logger ?? fn(string $level, string $message) => Log::log($level, "[UserGroupService] {$message}");

        $eligibleGroups = $this->fetchEligibleAdGroups();

        if (count($onlyGroupNames) > 0) {
            $allowed = array_flip(array_map(
                static fn(string $name): string => mb_strtolower(trim($name)),
                $onlyGroupNames
            ));

            // 4.13 — Les `name` SQL des classes/équipes sont désormais NUS
            // (`3A`), mais les CN AD restent préfixés (`Classe_3A`/`Equipe_3A`/
            // `PP_3A`). `syncGroupsWithAd` passe les noms NUS persistés ; il faut
            // donc faire matcher chaque CN AD sur sa base nue AUTANT que sur le
            // CN brut, sinon le filtre vide tout et la sync ciblée est un no-op
            // (le bouton « Synchroniser avec AD » ne ferait plus rien).
            $eligibleGroups = array_values(array_filter(
                $eligibleGroups,
                function (array $group) use ($allowed): bool {
                    $cn = trim((string) ($group['cn'] ?? ''));
                    if ($cn === '') {
                        return false;
                    }

                    if (isset($allowed[mb_strtolower($cn)])) {
                        return true;
                    }

                    // CN de classe/équipe (Classe_/Equipe_/PP_) → matcher aussi
                    // sa base nue contre les noms NUS demandés.
                    if ($this->foldPrefixOf($cn) !== null) {
                        return isset($allowed[mb_strtolower($this->stripClasseLikePrefix($cn))]);
                    }

                    return false;
                }
            ));
        }

        // 4.13 — Fold import : on replie les variantes AD d'une même base
        // (Classe_X / Equipe_X / PP_X) en UNE seule projection SQL au nom nu
        // (X). On regroupe AVANT toute écriture pour faire un seul
        // users()->sync() par ligne avec l'UNION des membres des CN — sinon un
        // sync() par CN écraserait les membres déjà posés.
        $foldedGroups = $this->buildFoldedGroups($eligibleGroups);

        // Correction review #6 — distinguer les deux compteurs. Avant 4.13 une
        // classe = 3 CN = 3 lignes ; après fold, 3 CN → 1 ligne. Compter les CN
        // bruts comme « groupes détectés » est trompeur dans l'UI. On expose
        // donc explicitement les CN bruts (`total_cn_detected`) ET les lignes
        // réellement projetées (`total_groups_folded`). `total_groups_detected`
        // (compat) reste l'alias des CN bruts.
        $stats['total_cn_detected'] = count($eligibleGroups);
        $stats['total_groups_detected'] = count($eligibleGroups);
        $stats['total_groups_folded'] = count($foldedGroups);
        $log(
            'info',
            sprintf(
                '%d CN AD détecté(s) → %d groupe(s) projeté(s) après fold',
                $stats['total_cn_detected'],
                $stats['total_groups_folded']
            )
        );

        UserGroupObserver::disableSync();
        // Story 42.2 (D4/T3.3) — suspendre le resync AD de l'observer pivot
        // pendant le read-back : `projectFoldedGroup` fait un `sync()` associatif
        // qui UPDATE des rôles en masse (read-back du trio AD — 42.4) — sans
        // cette suspension, chaque flip déclencherait une reprojection LDAP par
        // arête (tempête d'I/O sur un import ~600 groupes), et écrire l'AD PENDANT
        // qu'on le lit est conceptuellement faux. Flag DÉDIÉ : la synchro FS
        // ShareService (`$syncEnabled`, events created/deleted) DOIT continuer
        // à tourner au read-back (création des dossiers élèves — Story 5.2).
        UserGroupUserPivotObserver::disableAdResync();

        try {
            DB::transaction(function () use (&$stats, $foldedGroups, $onlyGroupNames): void {
                $detectedNames = [];

                foreach ($foldedGroups as $folded) {
                    $groupName = $folded['name'];
                    if ($groupName === '') {
                        continue;
                    }

                    // Détecté même si la projection échoue : préserve la ligne du
                    // cleanup `whereNotIn` (pas de suppression accidentelle).
                    $detectedNames[] = mb_strtolower($groupName);

                    try {
                        // 25P02 — chaque projection tourne dans SON savepoint
                        // (transaction imbriquée Laravel = SAVEPOINT Postgres).
                        // Sans cela, la 1re violation de contrainte avorte TOUTE
                        // la transaction : le catch ci-dessous masque l'erreur
                        // d'origine mais ne « dé-avorte » pas Postgres, et chaque
                        // requête suivante — jusqu'au DELETE de cleanup hors
                        // boucle — échoue en « current transaction is aborted »
                        // (25P02). Le savepoint isole l'échec à la seule
                        // projection fautive et laisse vivre la transaction.
                        DB::transaction(function () use ($folded, $groupName, &$stats): void {
                            $this->projectFoldedGroup($folded, $groupName, $stats);
                        });
                    } catch (\Throwable $e) {
                        Log::warning('[UserGroupService] Erreur sync group AD -> SQL', [
                            'group' => $groupName,
                            'error' => $e->getMessage(),
                        ]);
                        $stats['errors']++;
                    }
                }

                if (count($onlyGroupNames) === 0) {
                    // Comparer aux noms NUS effectivement persistés (pas les CN
                    // bruts d'origine) : sinon la ligne foldée `3A` tomberait dans
                    // le whereNotIn (les CN `classe_3a`/`equipe_3a` ne sont plus des
                    // `name` SQL) et serait supprimée à chaque sync.
                    $deleted = UserGroup::query()
                        ->whereNotIn(DB::raw('LOWER(name)'), $detectedNames)
                        ->delete();

                    $stats['deleted'] += $deleted;
                }
            });
        } finally {
            UserGroupObserver::enableSync();
            UserGroupUserPivotObserver::enableAdResync();
        }

        $log(
            'info',
            "Import groupes utilisateurs terminé: {$stats['created']} créés, {$stats['updated']} mis à jour, " .
                "{$stats['skipped']} inchangés, {$stats['linked_users']} liaison(s) ajoutée(s), " .
                "{$stats['detached_users']} liaison(s) retirée(s), {$stats['head_teacher_updated']} rôle(s) d'arête mis à jour, " .
                "{$stats['deleted']} supprimé(s), {$stats['errors']} erreur(s)"
        );

        return $stats;
    }

    /**
     * Projette UNE entrée foldée (nom nu) en ligne SQL `user_groups` + arêtes de
     * membres. Appelée dans un savepoint dédié par {@see syncFromAd()} : toute
     * exception (violation de contrainte, conflit ad_guid…) n'avorte que cette
     * projection, jamais la transaction d'ensemble.
     *
     * Story 42.4 — le RÔLE de chaque arête est reconstruit par READ-BACK du
     * TRIO AD (D1 : `PP_` owner > `Equipe_` manager > `Classe_` member, tier MAX
     * par user), avec préservation par SIGNAL MANQUANT (D3 : un rôle existant
     * n'est rétrogradé que si le CN qui l'exprimerait est présent dans le fold)
     * et dérivation heuristique conservée HORS trio (D5). Fail-soft intégral
     * dans le savepoint (D6 : aucune exception nouvelle sur données sales).
     * Ceci REMPLACE l'heuristique `users.role` de 42.1-AC7/42.2 pour les membres
     * du trio et LÈVE la limite transitoire « l'import écrase un rôle édité ».
     *
     * @param array{name:string, cns:array<int,string>, ad_guid:?string, ad_dn:string, type:string, display_name:string} $folded
     * @param array<string,int> $stats
     */
    private function projectFoldedGroup(array $folded, string $groupName, array &$stats): void
    {
        $adGuid = $folded['ad_guid'];
        $adDn = $folded['ad_dn'];

        $group = null;

        if ($adGuid !== null) {
            $group = UserGroup::query()->where('ad_guid', $adGuid)->first();
        }

        if ($group === null) {
            $group = UserGroup::query()->where('name', $groupName)->first();
        }

        if ($group === null && $adDn !== '') {
            $group = UserGroup::query()->where('ad_dn', $adDn)->first();
        }

        if ($adGuid !== null && $group !== null) {
            $conflict = UserGroup::query()
                ->where('ad_guid', $adGuid)
                ->where('id', '!=', $group->id)
                ->first();

            if ($conflict !== null) {
                throw new RuntimeException(sprintf(
                    'Conflit ad_guid %s entre groupes SQL "%s" et "%s"',
                    $adGuid,
                    $group->name,
                    $conflict->name
                ));
            }
        }

        $detectedType = $folded['type'];
        $displayName = $folded['display_name'];

        if ($group === null) {
            $group = UserGroup::query()->create([
                'name' => $groupName,
                'display_name' => $displayName,
                'type' => $detectedType,
                'ad_dn' => $adDn !== '' ? $adDn : null,
                'ad_guid' => $adGuid,
            ]);
            $stats['created']++;
        } else {
            $updated = false;

            if (($group->name ?? '') !== $groupName) {
                $group->name = $groupName;
                $updated = true;
            }

            if (($group->display_name ?? '') !== $displayName) {
                $group->display_name = $displayName;
                $updated = true;
            }

            // Le type n'existe pas dans l'AD, il est inféré depuis le nom du groupe.
            // On le recalcule systématiquement pour corriger tout écart SQL.
            $group->type = $detectedType;
            if ($group->isDirty('type')) {
                $updated = true;
            }

            if (($group->ad_dn ?? '') !== ($adDn !== '' ? $adDn : null)) {
                $group->ad_dn = $adDn !== '' ? $adDn : null;
                $updated = true;
            }

            if (($group->ad_guid ?? null) !== $adGuid) {
                $group->ad_guid = $adGuid;
                $updated = true;
            }

            if ($updated) {
                $group->save();
                $stats['updated']++;
            } else {
                $stats['skipped']++;
            }
        }

        // =====================================================================
        // Story 42.4 — READ-BACK des rôles d'arête depuis le TRIO AD réel.
        // =====================================================================
        // D1 — Pour chaque membre de l'UNION des CN du groupe foldé (un seul
        // sync()), le rôle d'arête est dérivé du TIER MAX de ses CN
        // d'appartenance dans le trio legacy :
        //   `PP_` → owner (3)  >  `Equipe_` → manager (2)  >  `Classe_` → member (1).
        // La précédence par `max()` garantit UNE SEULE arête par user×groupe
        // (un user dans `Classe_3A` ET `Equipe_3A` → manager ; dans `Equipe_3A`
        // ET `PP_3A` → owner). Un membre présent dans un CN du trio ne consulte
        // PLUS `users.role` — l'AD est AUTORITAIRE. Ceci REMPLACE l'heuristique
        // 42.1-AC7 (conservée « en l'état » par 42.2) et LÈVE la limite
        // transitoire « l'import écrase un rôle édité » : l'import lit désormais
        // ce que la projection 42.2 a réellement écrit dans l'AD (aller-retour
        // projection→read-back→projection = no-op). `foldPrefixOf()` étant
        // insensible à la casse, `classe_3a`/`Classe_3A` dérivent le même tier ;
        // les espaces des bases (`Equipe_301 g1`) transitent sans traitement.
        // D5 — un CN HORS trio (`foldPrefixOf() === null` : Cours_, Matiere_@,
        // custom) garde la dérivation par `users.role` (`defaultRoleForGlobalRole`) :
        // rien dans l'AD n'y porte de signal de rôle et sa projection n'a qu'une
        // cible. Aucune requête LDAP nouvelle : mêmes appels
        // `resolveMemberUserIdsFromAdGroup` par CN qu'en 4.13/42.2.
        $tierOfPrefix = static fn (?string $prefix): int => match ($prefix) {
            'PP_' => 3,
            'Equipe_' => 2,
            'Classe_' => 1,
            default => 0,
        };

        $memberIds = [];
        $trioTierByUser = [];      // user_id => tier (1|2|3) — MAX sur les CN du trio
        // D3 — présence des CN du trio dans le fold : un rôle SQL existant ne
        // peut être RÉTROGRADÉ que si le CN AD qui l'exprimerait est PRÉSENT.
        $hasEquipeCn = false;
        $hasPpCn = false;
        // Review 42.4 #1 — la préservation D3 n'a de sens QUE pour un fold
        // porteur d'au moins un CN du trio : sur un fold standalone hors-trio
        // (Cours_, Matiere_@, custom — aucun CN Classe_/Equipe_/PP_ possible),
        // « CN absent » n'est pas un signal manquant, c'est la structure même
        // du fold — l'heuristique doit y RECALCULER un rôle frais à chaque
        // import (AC4), jamais préserver un rôle stale.
        $isTrioFold = false;
        foreach ($folded['cns'] as $cn) {
            $prefix = $this->foldPrefixOf($cn);
            if ($prefix !== null) {
                $isTrioFold = true;
            }
            if ($prefix === 'Equipe_') {
                $hasEquipeCn = true;
            } elseif ($prefix === 'PP_') {
                $hasPpCn = true;
            }
            $tier = $tierOfPrefix($prefix);
            foreach ($this->resolveMemberUserIdsFromAdGroup($cn) as $memberId) {
                $memberId = (int) $memberId;
                $memberIds[] = $memberId;
                if ($tier > 0) {
                    $trioTierByUser[$memberId] = max($trioTierByUser[$memberId] ?? 0, $tier);
                }
            }
        }

        $uniqueMemberIds = array_values(array_unique($memberIds));

        // D5 — `users.role` n'est résolu QUE pour les membres HORS trio (une
        // seule requête pour l'union restante — pour un fold de classe elle
        // disparaît entièrement). Jamais `isProf()` par membre (round-trip LDAP,
        // `project_isprof_iseleve_ldap_first_cost`).
        $heuristicIds = array_values(array_filter(
            $uniqueMemberIds,
            static fn (int $id): bool => !isset($trioTierByUser[$id])
        ));
        $globalRoles = $heuristicIds === []
            ? collect()
            : User::query()
                ->whereIn('id', $heuristicIds)
                ->pluck('role', 'id');

        // D3 — arêtes EXISTANTES lues en UNE SEULE requête AVANT le sync()
        // (jamais par membre — piège n°3). Groupe fraîchement créé : aucune
        // arête → collection vide → aucune préservation (chemin naturel).
        // Story 42.2 (T4.1) — le payload n'écrit QUE `role` : le miroir booléen
        // 4.14 `is_head_teacher` reste retiré du chemin vivant (STALE jusqu'à la
        // migration destructive post-42.4).
        $existingRoles = DB::table('user_group_user')
            ->where('user_group_id', $group->id)
            ->pluck('role', 'user_id');

        $syncPayload = [];
        foreach ($uniqueMemberIds as $memberId) {
            // Rôle DÉRIVÉ : D1 (tier du trio) sinon D5 (heuristique `users.role`).
            // Toujours une constante de vocabulaire — jamais d'`assertValidRole`
            // en levée dans le chemin d'import (fail-soft intégral, D6).
            if (isset($trioTierByUser[$memberId])) {
                $role = match ($trioTierByUser[$memberId]) {
                    3 => UserGroupUserPivot::ROLE_OWNER,
                    2 => UserGroupUserPivot::ROLE_MANAGER,
                    default => UserGroupUserPivot::ROLE_MEMBER,
                };
            } else {
                $role = UserGroupUserPivot::defaultRoleForGlobalRole(
                    $globalRoles[$memberId] ?? null
                );
            }

            // D3 — préservation par SIGNAL MANQUANT. Un rôle SQL existant VALIDE
            // ne peut être rétrogradé que si le CN AD qui l'exprimerait est
            // présent dans le fold. Composition :
            //  (a) dérivé `member` + PAS de CN `Equipe_` + existant ∈
            //      {manager,owner} → remonte à `manager` ;
            //  (b) puis `manager` + PAS de CN `PP_` + existant `owner` →
            //      remonte à `owner`.
            // Si le CN EST présent, l'AD est autoritaire : la rétrogradation est
            // un vrai changement (PP décoché, retrait d'équipe). Comparaisons
            // STRICTES aux constantes `ROLE_*` : une valeur existante hors
            // vocabulaire (SQLite ne borne pas les varchar — NFR-S4) n'est
            // JAMAIS préservée, le dérivé s'applique (aucune exception — D6).
            $existing = isset($existingRoles[$memberId]) ? (string) $existingRoles[$memberId] : null;
            if ($isTrioFold && $existing !== null && in_array($existing, UserGroupUserPivot::ROLES, true)) {
                if ($role === UserGroupUserPivot::ROLE_MEMBER
                    && !$hasEquipeCn
                    && ($existing === UserGroupUserPivot::ROLE_MANAGER || $existing === UserGroupUserPivot::ROLE_OWNER)) {
                    $role = UserGroupUserPivot::ROLE_MANAGER;
                }
                if ($role === UserGroupUserPivot::ROLE_MANAGER
                    && !$hasPpCn
                    && $existing === UserGroupUserPivot::ROLE_OWNER) {
                    $role = UserGroupUserPivot::ROLE_OWNER;
                }
            }

            $syncPayload[$memberId] = ['role' => $role];
        }

        $syncChanges = $group->users()->sync($syncPayload);
        $stats['linked_users'] += count($syncChanges['attached'] ?? []);
        $stats['detached_users'] += count($syncChanges['detached'] ?? []);
        $stats['head_teacher_updated'] += count($syncChanges['updated'] ?? []);
    }

    /**
     * @return array<int,array{cn:string,dn:string,description:string,objectguid:mixed}>
     */
    private function fetchEligibleAdGroups(): array
    {
        try {
            $knownRightsGroupNames = array_map(
                static fn(string $name): string => mb_strtolower(trim($name)),
                array_keys($this->rightRepository->getAllRightsValues())
            );

            $excludedRdns = array_values(array_filter([
                mb_strtolower(trim((string) SEConfig::get('rights_rdn', ''))),
                mb_strtolower(trim((string) SEConfig::get('delegations_rdn', ''))),
            ], static fn(string $rdn): bool => $rdn !== ''));
            $groupsRdn = mb_strtolower(trim((string) SEConfig::get('groups_rdn', '')));

            return $this->groupRepository
                ->getGroupsWithMemberCount()
                ->filter(function (array $group) use ($knownRightsGroupNames, $excludedRdns, $groupsRdn): bool {
                    $cn = trim((string) ($group['cn'] ?? ''));
                    $dn = mb_strtolower(trim((string) ($group['dn'] ?? '')));

                    if ($cn === '') {
                        return false;
                    }

                    if ($dn === '') {
                        return false;
                    }

                    if ($groupsRdn !== '' && preg_match('/(^|,)' . preg_quote($groupsRdn, '/') . '(,|$)/i', $dn) !== 1) {
                        return false;
                    }

                    foreach ($excludedRdns as $rdn) {
                        if (preg_match('/(^|,)' . preg_quote($rdn, '/') . '(,|$)/i', $dn) === 1) {
                            return false;
                        }
                    }

                    $cnLower = mb_strtolower($cn);

                    if (in_array($cnLower, $knownRightsGroupNames, true)) {
                        return false;
                    }

                    return true;
                })
                ->map(function (array $group): array {
                    return [
                        'cn' => trim((string) ($group['cn'] ?? '')),
                        'dn' => trim((string) ($group['dn'] ?? '')),
                        'description' => trim((string) ($group['description'] ?? '')),
                        'objectguid' => $group['objectguid'] ?? null,
                    ];
                })
                ->filter(fn(array $group): bool => ($group['cn'] ?? '') !== '')
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable $exception) {
            Log::warning('[UserGroupService] Impossible de charger les groupes AD pour l\'import SQL', [
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Préfixes des variantes AD d'une même classe (foldables ensemble).
     * Le CN canonique est toujours le premier disponible dans cet ordre
     * (D2 : `Classe_` > `Equipe_` > `PP_`).
     *
     * @var array<int,string>
     */
    private const FOLD_PREFIXES = ['Classe_', 'Equipe_', 'PP_'];

    /**
     * 4.13 — Replie les CN AD éligibles en projections SQL « nom nu ».
     *
     * Pour les variantes de classe/équipe (`Classe_X`/`Equipe_X`/`PP_X`) d'une
     * même base `X`, produit UNE seule entrée au nom nu `X` avec :
     * - `cns` : la liste des CN AD à unir pour les membres (T1.4) ;
     * - `ad_guid`/`ad_dn` du CN canonique (D2 : `Classe_` > `Equipe_` > `PP_`) ;
     * - `type = 'classe'` (D3) ;
     * - `display_name` = description du CN canonique (fallback nom nu).
     *
     * Règle D1 (`Equipe_` orphelin) : un `Equipe_Y` ne fold avec sa base que si
     * un `Classe_Y`/`PP_Y` est présent dans le lot OU si une ligne nue `Y` de
     * type classe/équipe préexiste en SQL. Sinon il reste sa propre projection
     * (nom nu `Y`, type `equipe`) — il ne fold jamais avec un `Cours_Y`.
     *
     * Les autres CN (`Cours_`, `Projet_`, `Matiere_`, `Matiere_@`, rôle/
     * fonction/custom) restent 1 CN = 1 projection (comportement inchangé) ;
     * leur `name` reste le CN brut.
     *
     * @param array<int,array{cn:string,dn:string,description:string,objectguid:mixed}> $eligibleGroups
     * @return array<int,array{name:string, cns:array<int,string>, ad_guid:?string, ad_dn:string, type:string, display_name:string}>
     */
    private function buildFoldedGroups(array $eligibleGroups): array
    {
        // 1) Recenser les bases qui possèdent au moins un CN « ancre » de classe
        //    (Classe_ ou PP_) dans le lot — elles autorisent le fold d'un Equipe_.
        $foldAnchorBases = [];
        foreach ($eligibleGroups as $groupData) {
            $cn = trim((string) ($groupData['cn'] ?? ''));
            if ($cn === '') {
                continue;
            }
            $prefix = $this->foldPrefixOf($cn);
            if ($prefix === 'Classe_' || $prefix === 'PP_') {
                $foldAnchorBases[mb_strtolower($this->stripClasseLikePrefix($cn))] = true;
            }
        }

        /** @var array<string,array{name:string, cns:array<int,string>, byPrefix:array<string,array{ad_guid:?string,ad_dn:string,description:string}>}> $folds */
        $folds = [];
        /** @var array<int,array{name:string, cns:array<int,string>, ad_guid:?string, ad_dn:string, type:string, display_name:string}> $standalone */
        $standalone = [];

        foreach ($eligibleGroups as $groupData) {
            $cn = trim((string) ($groupData['cn'] ?? ''));
            if ($cn === '') {
                continue;
            }

            $adGuid = $this->convertAdGuidToString($groupData['objectguid'] ?? null);
            $adDn = trim((string) ($groupData['dn'] ?? ''));
            $description = trim((string) ($groupData['description'] ?? ''));

            $prefix = $this->foldPrefixOf($cn);
            $base = $prefix !== null ? $this->stripClasseLikePrefix($cn) : $cn;
            $baseKey = mb_strtolower($base);

            $foldable = $prefix !== null && $this->shouldFold($prefix, $baseKey, $foldAnchorBases);

            if (!$foldable) {
                // Cas 1 — `Equipe_` orphelin (D1) : pas d'ancre Classe_/PP_ ni de
                // ligne nue classe/équipe préexistante. Il ne fold pas avec un
                // éventuel `Cours_Y`, mais devient quand même SA PROPRE ligne au
                // NOM NU `Y` de type `equipe` (AC6) — pas le CN brut.
                if ($prefix === 'Equipe_') {
                    $standalone[] = [
                        'name' => $base,
                        'cns' => [$cn],
                        'ad_guid' => $adGuid,
                        'ad_dn' => $adDn,
                        'type' => 'equipe',
                        'display_name' => $description !== '' ? $description : $base,
                    ];
                    continue;
                }

                // Cas 2 — CN non foldable (Cours_, Projet_, Matiere_, Matiere_@,
                // rôle/fonction/custom) : 1 CN = 1 projection (nom = CN brut,
                // type détecté à l'identique).
                $standalone[] = [
                    'name' => $cn,
                    'cns' => [$cn],
                    'ad_guid' => $adGuid,
                    'ad_dn' => $adDn,
                    'type' => $this->detectTypeFromAdGroupName($cn),
                    'display_name' => $description !== '' ? $description : $cn,
                ];
                continue;
            }

            $folds[$baseKey] ??= [
                'name' => $base,
                'cns' => [],
                'byPrefix' => [],
            ];
            $folds[$baseKey]['cns'][] = $cn;
            $folds[$baseKey]['byPrefix'][$prefix] = [
                'ad_guid' => $adGuid,
                'ad_dn' => $adDn,
                'description' => $description,
            ];
        }

        $result = [];

        foreach ($folds as $fold) {
            // CN canonique = premier prefix disponible dans l'ordre D2.
            $canonical = null;
            foreach (self::FOLD_PREFIXES as $prefix) {
                if (isset($fold['byPrefix'][$prefix])) {
                    $canonical = $fold['byPrefix'][$prefix];
                    break;
                }
            }

            // Garde-fou défensif (jamais atteint : un fold a toujours ≥ 1 prefix).
            if ($canonical === null) {
                $canonical = reset($fold['byPrefix']);
            }

            $result[] = [
                'name' => $fold['name'],
                'cns' => array_values(array_unique($fold['cns'])),
                'ad_guid' => $canonical['ad_guid'],
                'ad_dn' => $canonical['ad_dn'],
                'type' => 'classe',
                'display_name' => $canonical['description'] !== '' ? $canonical['description'] : $fold['name'],
            ];
        }

        return array_merge($result, $standalone);
    }

    /**
     * Retourne le préfixe CANONIQUE de fold ({@see FOLD_PREFIXES}) du CN, ou null
     * si le CN n'est pas une variante de classe/équipe.
     *
     * Comparaison INSENSIBLE À LA CASSE : l'AD réel mélange des CN legacy en
     * minuscules (`classe_3a`/`equipe_3a`/`pp_3a`, majoritaires sur le parc) et
     * des CN SE5 canoniques (`Classe_3A`). On reconnaît le préfixe quelle que
     * soit la casse du CN, et on renvoie TOUJOURS la forme canonique (valeur de
     * FOLD_PREFIXES, p. ex. `'PP_'`) pour que l'aval (canonique D2, détection de
     * type, flag PP) raisonne sur une forme stable — jamais sur un extrait du CN.
     */
    private function foldPrefixOf(string $cn): ?string
    {
        foreach (self::FOLD_PREFIXES as $prefix) {
            if (strncasecmp($cn, $prefix, strlen($prefix)) === 0) {
                return $prefix;
            }
        }

        return null;
    }

    /**
     * D1 — Décide si une variante doit folder vers le nom nu de sa base.
     *
     * `Classe_`/`PP_` foldent toujours. `Equipe_Y` ne fold que si la base `Y`
     * possède un `Classe_`/`PP_` dans le LOT AD COURANT (`$foldAnchorBases`) —
     * sinon il reste autonome (cas `Cours_Y` + `Equipe_Y` : pas d'ancre →
     * l'équipe du cours ne fold pas, elle devient sa propre ligne `equipe`).
     *
     * Story 4.13 (correction review #4/#5) — la décision repose UNIQUEMENT sur
     * le lot AD courant. L'ancienne dépendance à l'état SQL (`EXISTS` sur la
     * ligne nue déjà persistée) était (a) NON IDEMPOTENTE — au 1er run un
     * `Equipe_Y` orphelin se persistait en `type='equipe'`, puis au 2e run ce
     * `EXISTS` matchait sa propre ligne et le faisait basculer en `type='classe'`
     * (viole AC6) — et (b) une requête SQL par variante `Equipe_` (N requêtes).
     * Décider sur le seul lot AD corrige idempotence ET perf.
     *
     * @param array<string,bool> $foldAnchorBases bases (lower) avec un Classe_/PP_ dans le lot AD
     */
    private function shouldFold(string $prefix, string $baseKey, array $foldAnchorBases): bool
    {
        if ($prefix === 'Classe_' || $prefix === 'PP_') {
            return true;
        }

        // $prefix === 'Equipe_' : fold seulement si une ancre Classe_/PP_ de la
        // même base est présente dans le LOT AD courant (jamais l'état SQL).
        return isset($foldAnchorBases[$baseKey]);
    }

    /**
     * @return array<int,int>
     */
    private function resolveMemberUserIdsFromAdGroup(string $groupName): array
    {
        $members = $this->groupRepository->getGroupMembers($groupName);

        if (!$members instanceof Collection) {
            return [];
        }

        $logins = $members
            ->map(static fn(array $member): string => trim((string) ($member['cn'] ?? '')))
            ->filter(static fn(string $login): bool => $login !== '')
            ->values()
            ->all();

        if (count($logins) === 0) {
            return [];
        }

        return User::query()
            ->whereIn('login', $logins)
            ->pluck('id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Projette les membres SQL sélectionnés vers le(s) groupe(s) AD cible(s),
     * routés par le RÔLE D'ARÊTE `user_group_user.role` pour les groupes de
     * classe/équipe (Story 42.2 — remplace la partition `isProf()` de 4.12).
     *
     * - `type ∈ {classe, equipe}` — buckets D1 :
     *   `Equipe_<base>` = arêtes `manager` ∪ `owner` (un owner est un cas
     *   particulier de prof : il ne sort JAMAIS du bucket équipe — parité rwx
     *   prof 4.12, orthogonalité 4.15 conservée) ; `Classe_<base>` = arêtes
     *   `member` ; `PP_<base>` = arêtes `owner`. Les 3 cibles sont TOUJOURS
     *   synchronisées (bucket vide → vidage par le diff idempotent fail-soft
     *   de {@see syncAdGroupMembersByUserIds()}, pas de rémanence).
     * - Résolution du rôle effectif (D2, dans cet ordre de précédence) :
     *   1. `owner` si l'id ∈ `$headTeacherUserIds ∩ $selectedUserIds` — le
     *      paramètre PP reste AUTORITAIRE (canal de désignation 4.15) ;
     *   2. sinon rôle de l'ARÊTE (`$edgeRolesByUserId`) si elle existe — avec
     *      rétrogradation de projection `owner`→`manager` pour un ex-PP décoché
     *      (son arête dit encore `owner` jusqu'au read-back : il doit sortir de
     *      `PP_` mais rester dans `Equipe_`) ;
     *   3. sinon (membre du payload SANS arête — nouvel ajout, l'arête sera
     *      créée par le read-back) : défaut dérivé de `users.role` résolu EN
     *      UNE SEULE requête pour tous les manquants (JAMAIS `isProf()` —
     *      round-trip LDAP interdit).
     *   Valeur d'arête HORS vocabulaire : fallback rôle dérivé + Log::warning —
     *   la projection est fail-soft, pas d'exception.
     * - autres types (`cours`, `matiere`, `projet`, `custom`…) : une seule
     *   cible résolue via {@see resolvePrimaryGroupName()} — comportement inchangé
     *   (le rôle d'arête n'y route rien).
     *
     * Le nom reçu peut être NU (`3A`, depuis `createGroup`) ou déjà le CN
     * primaire stocké en SQL (`Classe_3A`, depuis `updateGroup` dont le form
     * renvoie `group->name`). Pour les classes/équipes on dérive donc la base
     * nue (suppression d'un éventuel préfixe `Classe_`/`Equipe_`/`PP_`) avant
     * de router — c'est exactement la dé-duplication de la résolution
     * exigée par la story 4.12 (createGroup résolu vs updateGroup brut).
     *
     * Bypass CN legacy préfixé d'un AUTRE type : un nom déjà préfixé par une
     * autre catégorie (`Matiere_*@*`, `Cours_*`, `Projet_*`, `Matiere_*`) n'est
     * jamais ré-expansé — on synchronise exactement ce groupe (1 SQL = 1 AD).
     *
     * @param array<int,int> $selectedUserIds
     * @param array<int,int> $headTeacherUserIds Story 4.15 — canal de désignation
     *        PP (`head_teacher_ids`), projeté vers `PP_<base>`. Intersecté
     *        défensivement avec `$selectedUserIds` (un PP doit être membre —
     *        un id forgé hors membres est ignoré, sans exception).
     * @param array<int,string> $edgeRolesByUserId Story 42.2 — map
     *        `user_id => role` des arêtes pivot PRÉ-update (fournie par
     *        l'appelant ; `[]` depuis `createGroup` — le groupe n'existe pas
     *        encore en SQL, tous les membres passent par le défaut dérivé).
     */
    private function syncRoleAwareAdGroupMembers(
        string $rawName,
        string $type,
        array $selectedUserIds,
        array $headTeacherUserIds = [],
        array $edgeRolesByUserId = [],
    ): void {
        $normalizedType = mb_strtolower(trim($type));
        $isClasseLike = in_array($normalizedType, ['class', 'classe', 'equipe'], true);

        // Type non classe/équipe : un nom déjà préfixé (CN legacy d'une autre
        // catégorie) ne doit jamais être ré-expansé — cible unique résolue.
        if (!$isClasseLike) {
            $this->syncAdGroupMembersByUserIds(
                $this->resolvePrimaryGroupName($rawName, $type),
                $selectedUserIds
            );

            return;
        }

        // Classe/équipe : dériver la base nue en retirant un éventuel préfixe
        // de classe/équipe déjà présent (CN primaire stocké en SQL).
        $baseName = $this->stripClasseLikePrefix($rawName);

        // Garde-fou D1 (4.15, conservé) : un PP doit être membre du groupe —
        // intersection avec `$selectedUserIds`, ordre/dédup préservés pour des
        // assertions stables. `$ppIds` est LE bucket `PP_<base>` (D2.1 : le set
        // PP est autoritaire ; une arête `owner` HORS de ce set est rétrogradée
        // `manager` à la projection, jamais promue).
        $selectedSet = array_flip($selectedUserIds);
        $ppIds = array_values(array_unique(array_filter(
            $headTeacherUserIds,
            static fn(int $id): bool => isset($selectedSet[$id])
        )));
        $ppSet = array_flip($ppIds);

        // D2.3 — défaut dérivé pour les ids sans arête (ou à l'arête invalide) :
        // `users.role` résolu en UNE SEULE requête (jamais `isProf()` en boucle —
        // `project_isprof_iseleve_ldap_first_cost`).
        $idsNeedingDerivedRole = [];
        foreach ($selectedUserIds as $userId) {
            if (isset($ppSet[$userId])) {
                continue; // D2.1 — owner autoritaire, pas de dérivation.
            }
            $edgeRole = $edgeRolesByUserId[$userId] ?? null;
            if ($edgeRole === null || !in_array($edgeRole, UserGroupUserPivot::ROLES, true)) {
                $idsNeedingDerivedRole[] = $userId;
            }
        }

        $globalRolesById = $idsNeedingDerivedRole === []
            ? collect()
            : User::query()
                ->whereIn('id', $idsNeedingDerivedRole)
                ->pluck('role', 'id');

        // Buckets D1 — `Equipe_` = manager ∪ owner ; `Classe_` = member.
        $equipeIds = [];
        $classeIds = [];

        foreach ($selectedUserIds as $userId) {
            // D2.1 — override PP autoritaire : owner → Equipe_ (ET PP_ via $ppIds).
            if (isset($ppSet[$userId])) {
                $equipeIds[] = $userId;
                continue;
            }

            // D2.2 — rôle de l'arête, fail-soft sur valeur hors vocabulaire.
            $edgeRole = $edgeRolesByUserId[$userId] ?? null;
            if ($edgeRole === '') {
                // Review 42.2 #2 — un `role` NULL SQL (attach brut hors
                // écrivains dérivés) est casté '' par les appelants : c'est une
                // arête ABSENTE (→ défaut dérivé), pas une valeur « hors
                // vocabulaire » — pas de warning parasite à chaque projection.
                $edgeRole = null;
            }
            if ($edgeRole !== null && !in_array($edgeRole, UserGroupUserPivot::ROLES, true)) {
                Log::warning('[UserGroupService] Rôle d\'arête hors vocabulaire — fallback rôle dérivé', [
                    'group' => $rawName,
                    'user_id' => $userId,
                    'edge_role' => $edgeRole,
                ]);
                $edgeRole = null;
            }
            if ($edgeRole === UserGroupUserPivot::ROLE_OWNER) {
                // Rétrogradation de projection : ex-PP décoché (arête encore
                // `owner` jusqu'au read-back) — il sort de `PP_` (hors $ppIds)
                // mais RESTE dans `Equipe_` (pas de perte rwx).
                $edgeRole = UserGroupUserPivot::ROLE_MANAGER;
            }

            // D2.3 — défaut dérivé du rôle global pour les membres sans arête.
            $effectiveRole = $edgeRole ?? UserGroupUserPivot::defaultRoleForGlobalRole(
                $globalRolesById[$userId] ?? null
            );

            if ($effectiveRole === UserGroupUserPivot::ROLE_MANAGER) {
                $equipeIds[] = $userId;
            } else {
                $classeIds[] = $userId;
            }
        }

        // Toujours synchroniser les TROIS cibles (même bucket vide) pour que le
        // retrait/bascule de rôle retire bien du groupe d'origine (pas de
        // rémanence — parité 4.12/4.15 AC2).
        $this->syncAdGroupMembersByUserIds("Equipe_{$baseName}", $equipeIds);
        $this->syncAdGroupMembersByUserIds("Classe_{$baseName}", $classeIds);
        $this->syncAdGroupMembersByUserIds("PP_{$baseName}", $ppIds);
    }

    /**
     * Story 42.2 (T3.2) — point d'entrée PUBLIC de reprojection AD d'un groupe
     * depuis l'état COURANT de ses arêtes pivot (membres, rôles, owners lus en
     * une requête). Délègue au chokepoint {@see syncRoleAwareAdGroupMembers()}
     * — aucun canal de projection parallèle. Consommé par l'observer pivot
     * (`UserGroupUserPivotObserver::updated`, resync sur changement de rôle) et
     * réutilisable tel quel par 42.3 (édition du rôle en UI).
     *
     * Les PP projetés (`PP_<base>`) sont dérivés des arêtes `role='owner'` : ici
     * l'arête EST la source (pas de payload `head_teacher_ids` en jeu). La
     * couche LDAP sous-jacente reste fail-soft (groupe AD absent → no-op).
     */
    public function resyncGroupAdProjection(UserGroup $group): void
    {
        $edgeRolesByUserId = [];
        foreach ($group->users()->pluck('user_group_user.role', 'users.id') as $userId => $role) {
            $edgeRolesByUserId[(int) $userId] = (string) $role;
        }

        $headTeacherUserIds = array_keys(array_filter(
            $edgeRolesByUserId,
            static fn(string $role): bool => $role === UserGroupUserPivot::ROLE_OWNER
        ));

        $this->syncRoleAwareAdGroupMembers(
            (string) $group->name,
            (string) $group->type,
            array_keys($edgeRolesByUserId),
            $headTeacherUserIds,
            $edgeRolesByUserId,
        );
    }

    /**
     * Empêche la création d'un groupe classe/équipe dont le nom NU porte déjà un
     * préfixe réservé géré par le serveur (`Classe_`/`Equipe_`/`PP_`). Sans ce
     * garde-fou, l'expansion (`Equipe_<name>`/`Classe_<name>`) viserait des CN
     * fantômes : les membres seraient écrits sur des groupes AD inexistants
     * (add LDAP fail-soft → échec SILENCIEUX) et le sélecteur SQL post-sync
     * lèverait « introuvable après synchronisation ». On bloque dès la saisie.
     *
     * Ne concerne QUE les types classe/équipe : les types à CN préfixé légitime
     * (`matiere_classe` → `Matiere_…`) ne passent jamais par cette expansion.
     *
     * 4.16 (Q2) — détection INSENSIBLE À LA CASSE, par cohérence avec le fold
     * casse-insensible (`foldPrefixOf`/`stripClasseLikePrefix`). Sans ça, une
     * saisie minuscule `classe_x` échappait au garde-fou et partait en expansion
     * fantôme `Classe_classe_x` — exactement le CN cassé que ce garde-fou existe
     * pour empêcher.
     */
    private function guardReservedPrefixOnCreate(string $rawName, string $type): void
    {
        $normalizedType = mb_strtolower(trim($type));

        if (!in_array($normalizedType, ['class', 'classe', 'equipe', 'équipe'], true)) {
            return;
        }

        foreach (['Classe_', 'Equipe_', 'PP_'] as $prefix) {
            if (strncasecmp($rawName, $prefix, strlen($prefix)) === 0) {
                throw new \InvalidArgumentException(
                    "Le nom « {$rawName} » ne peut pas commencer par le préfixe réservé « {$prefix} » pour un groupe de type classe/équipe."
                );
            }
        }
    }

    /**
     * Retire un éventuel préfixe de classe/équipe (`Classe_`, `Equipe_`, `PP_`)
     * pour retrouver la base nue. Idempotent sur un nom déjà nu.
     *
     * Strip INSENSIBLE À LA CASSE du préfixe (AD legacy minuscule : `classe_3a`,
     * `pp_3a`…), mais le SUFFIXE conserve sa casse d'origine : `classe_3a` → `3a`,
     * `Classe_3A` → `3A`. Le regroupement par nom nu en aval (`buildFoldedGroups`)
     * normalise déjà la CLÉ via `mb_strtolower`, donc un lot mixte casse
     * (`Classe_3A`+`equipe_3a`) fold bien en une seule ligne.
     */
    private function stripClasseLikePrefix(string $name): string
    {
        foreach (['Classe_', 'Equipe_', 'PP_'] as $prefix) {
            if (strncasecmp($name, $prefix, strlen($prefix)) === 0) {
                return substr($name, strlen($prefix));
            }
        }

        return $name;
    }

    /**
     * @param array<int,int> $selectedUserIds
     */
    private function syncAdGroupMembersByUserIds(string $groupName, array $selectedUserIds): void
    {
        $desiredDns = User::query()
            ->whereIn('id', $selectedUserIds)
            ->whereNotNull('dn')
            ->pluck('dn')
            ->map(static fn(mixed $dn): string => trim((string) $dn))
            ->filter(static fn(string $dn): bool => $dn !== '')
            ->unique()
            ->values();

        $members = $this->groupRepository->getGroupMembers($groupName);

        if (!$members instanceof Collection) {
            return;
        }

        $currentDns = $members
            ->map(static fn(array $member): string => trim((string) ($member['dn'] ?? '')))
            ->filter(static fn(string $dn): bool => $dn !== '')
            ->unique()
            ->values();

        foreach ($desiredDns->diff($currentDns) as $memberDn) {
            $this->groupRepository->addMember($groupName, (string) $memberDn);
        }

        $sqlKnownDns = User::query()
            ->whereNotNull('dn')
            ->pluck('dn')
            ->map(static fn(mixed $dn): string => trim((string) $dn))
            ->filter(static fn(string $dn): bool => $dn !== '')
            ->unique()
            ->values();

        foreach ($currentDns->intersect($sqlKnownDns)->diff($desiredDns) as $memberDn) {
            $this->groupRepository->removeMember($groupName, (string) $memberDn);
        }
    }

    private function mapTypeToLdap(string $type): string
    {
        return match (mb_strtolower(trim($type))) {
            'class', 'classe' => 'classe',
            'cours' => 'cours',
            'matiere', 'matière' => 'matiere',
            'projet' => 'projet',
            'equipe', 'équipe' => 'equipe',
            'matiere_classe', 'matiere-classe' => 'matiere',
            default => 'other_group',
        };
    }

    /**
     * 4.13 — Nom SQL attendu après `syncFromAd` pour le sélecteur post-sync.
     *
     * Pour les classes/équipes (foldées au nom nu depuis 4.13), c'est la base
     * nue (`Classe_3A`/`Equipe_3A`/`3A` → `3A`). Pour les autres types, c'est le
     * CN brut tel que stocké en SQL (`Cours_X`, `Matiere_X@Y`, …) — résolu via
     * {@see resolvePrimaryGroupName()} pour conserver le comportement existant.
     */
    private function resolveSqlLookupName(string $rawName, string $type): string
    {
        $normalizedType = mb_strtolower(trim($type));

        if (in_array($normalizedType, ['class', 'classe', 'equipe', 'équipe'], true)) {
            return $this->stripClasseLikePrefix($rawName);
        }

        return $this->resolvePrimaryGroupName($rawName, $type);
    }

    private function resolvePrimaryGroupName(string $rawName, string $type): string
    {
        $normalizedType = mb_strtolower(trim($type));

        // Garde d'idempotence (4.16 Q2, généralisée post-42) : les types hors
        // classe/équipe sont stockés en SQL avec leur CN PRÉFIXÉ (`Projet_X`,
        // `Cours_X`, …) — `updateGroup` repasse donc ici un nom déjà préfixé.
        // Sans la détection casse-insensible, on double-préfixait
        // (`Projet_Projet_X`) : écriture AD fail-soft sur un CN inexistant,
        // read-back scopé à vide, re-lookup SQL null → 500 sur tout save avec
        // membres d'un groupe projet/cours/matière. Le bras classe/équipe reste
        // sans garde : ses appelants strippent (`stripClasseLikePrefix`).
        return match ($normalizedType) {
            'class', 'classe', 'equipe' => "Classe_{$rawName}",
            'cours' => strncasecmp($rawName, 'Cours_', 6) === 0 ? $rawName : "Cours_{$rawName}",
            'projet' => strncasecmp($rawName, 'Projet_', 7) === 0 ? $rawName : "Projet_{$rawName}",
            'matiere', 'matière' => strncasecmp($rawName, 'Matiere_', 8) === 0 ? $rawName : "Matiere_{$rawName}",
            'matiere_classe', 'matiere-classe' => strncasecmp($rawName, 'Matiere_', 8) === 0 ? $rawName : "Matiere_{$rawName}",
            default => $rawName,
        };
    }

    /**
     * @return array{name:string, display_name:?string, type:string, ad_dn:?string}
     */
    private function validateData(array $data, ?UserGroup $existing = null): array
    {
        $name = trim((string) ($data['name'] ?? $existing?->name ?? ''));
        $displayNameRaw = trim((string) ($data['display_name'] ?? $existing?->display_name ?? ''));
        $type = trim((string) ($data['type'] ?? $existing?->type ?? 'custom'));

        if ($name === '') {
            throw new \InvalidArgumentException('Le nom technique du groupe est obligatoire.');
        }

        if (!preg_match('/^[a-zA-Z0-9._@-]+$/', $name)) {
            throw new \InvalidArgumentException('Le nom technique contient des caractères invalides.');
        }

        if ($type === '') {
            throw new \InvalidArgumentException('Le type du groupe est obligatoire.');
        }

        $displayName = $displayNameRaw !== '' ? $displayNameRaw : null;

        return [
            'name' => $name,
            'display_name' => $displayName,
            'type' => $type,
            'ad_dn' => $existing?->ad_dn,
        ];
    }

    /**
     * Détecte le type SE5 d'un CN AD à partir de son préfixe.
     *
     * Préfixes reconnus INSENSIBLEMENT À LA CASSE : l'AD legacy stocke des CN en
     * minuscules (`classe_3a`/`equipe_3a`/`pp_3a`/`cours_…`/`projet_…`/`matiere_…`,
     * majoritaires sur le parc). Sans ce relâchement, ces CN tombaient en
     * `custom` (déclassement) au lieu de classe/équipe/cours/… Les valeurs de
     * retour (chaînes de type) sont strictement inchangées.
     */
    private function detectTypeFromAdGroupName(string $groupName): string
    {
        if (strncasecmp($groupName, 'Matiere_', 8) === 0 && str_contains($groupName, '@')) {
            return 'matiere_classe';
        }

        if (strncasecmp($groupName, 'Classe_', 7) === 0) {
            return 'classe';
        }

        if (strncasecmp($groupName, 'Equipe_', 7) === 0 || strncasecmp($groupName, 'PP_', 3) === 0) {
            return 'equipe';
        }

        if (strncasecmp($groupName, 'Cours_', 6) === 0) {
            return 'cours';
        }

        if (strncasecmp($groupName, 'Projet_', 7) === 0) {
            return 'projet';
        }

        if (strncasecmp($groupName, 'Matiere_', 8) === 0) {
            return 'matiere';
        }

        if (MainGroups::isMainGroup($groupName)) {
            return 'role';
        }

        if (FunctionGroups::isFunctionGroup($groupName)) {
            return 'function';
        }

        return 'custom';
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
     * Résout les membres directs (non récursif) d'une liste de groupes SQL
     * pour une réinitialisation bulk. Reproduit la sémantique legacy
     * `search_people_group($config, $groupsam, $recurse=false)` — si un groupe
     * contient un sous-groupe, les utilisateurs du sous-groupe ne sont PAS
     * ramenés (idempotence stricte vs `sambaedu/includes/ldap.inc.php:5872`).
     *
     * La source de vérité ici est l'AD (cf. mémoire projet
     * `feedback_gpo_real_ad_not_eloquent.md`). Pour chaque groupe on
     * interroge l'AD via {@see GroupRepository::getGroupMembers()} puis on
     * valide l'existence SQL (sync si besoin) — sans synchro on perdrait
     * les users AD jamais connectés via SER.
     *
     * @param array<int|string> $groupIds identifiants SQL des UserGroup
     * @return array{
     *     users: \Illuminate\Support\Collection<\App\Models\User>,
     *     login_to_source_group: array<string, array{id: int, name: string}>
     * }
     */
    public function getDirectMembersForBulkReset(array $groupIds): array
    {
        /** @var array<string, User> $byLogin */
        $byLogin = [];
        /** @var array<string, array{id:int, name:string}> $sourceGroup */
        $sourceGroup = [];

        $ids = collect($groupIds)
            ->map(fn(mixed $id): int => (int) $id)
            ->filter(fn(int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (count($ids) === 0) {
            return [
                'users' => collect([]),
                'login_to_source_group' => [],
            ];
        }

        $groups = UserGroup::query()->whereIn('id', $ids)->get();

        foreach ($groups as $group) {
            try {
                $members = $this->groupRepository->getGroupMembers($group->name);
            } catch (\Throwable $e) {
                Log::warning('[UserGroupService] Lecture membres AD impossible pour bulk reset', [
                    'group' => $group->name,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (!$members instanceof Collection || $members->isEmpty()) {
                Log::info('[UserGroupService] Groupe sans membres directs', [
                    'group' => $group->name,
                ]);
                continue;
            }

            foreach ($members as $member) {
                // En AD, CN peut différer du sAMAccountName si le user a été renommé —
                // on préfère samaccountname (login AD canonique) avec fallback sur cn.
                $login = trim((string) ($member['samaccountname'] ?? $member['cn'] ?? ''));
                if ($login === '') {
                    Log::warning('[UserGroupService] Membre AD sans login résolvable', [
                        'group' => $group->name,
                        'member_keys' => array_keys((array) $member),
                    ]);
                    continue;
                }

                if (array_key_exists($login, $byLogin)) {
                    // Dédup : on conserve le premier groupe qui a ramené ce user
                    // (ordre d'itération sur $ids = ordre de la requête bulk).
                    continue;
                }

                $sqlUser = User::query()->where('login', $login)->first();

                if ($sqlUser === null) {
                    // L'user AD n'est pas encore dans la table SQL : on tente
                    // une synchro minimale. Si cela échoue on ignore ce user
                    // (il sera remonté en erreur par la phase de validation
                    // de UserService::bulkResetPasswords).
                    Log::info('[UserGroupService] User AD absent en SQL, skip sync inline', [
                        'login' => $login,
                        'group' => $group->name,
                    ]);
                    continue;
                }

                $byLogin[$login] = $sqlUser;
                $sourceGroup[$login] = [
                    'id' => (int) $group->id,
                    'name' => (string) ($group->display_name ?? $group->name),
                ];
            }
        }

        return [
            'users' => collect(array_values($byLogin)),
            'login_to_source_group' => $sourceGroup,
        ];
    }
}
