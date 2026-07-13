<?php

declare(strict_types=1);

namespace App\Actions\Groups;

use App\Models\Pivot\UserGroupUserPivot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Story 4.14 — Fusion des lignes `user_groups` HÉRITÉES (bases importées AVANT
 * le fold de 4.13) vers la forme « 1 ligne SQL = 1 classe au nom nu ».
 *
 * Une base déjà importée avant 4.13 peut contenir jusqu'à 3 lignes physiques
 * `Classe_<X>` / `Equipe_<X>` / `PP_<X>` (chacune avec ses pivots, son `ad_guid`,
 * son `ad_dn`). Le fold de 4.13 ne réécrit que le flux d'import ; il ne touche
 * pas l'existant déjà persisté. Cette action **converge** cet existant SQL vers
 * la forme produite par `UserGroupService::buildFoldedGroups`.
 *
 * Pourquoi une action invocable (et pas la logique inline dans la migration) :
 * la migration de données est ainsi **testable** sur un état monté à la main
 * (cf. `tests/Feature/Migrations/MergeLegacyUserGroupsMigrationTest.php`) sans
 * dépendre de `migrate:rollback`/`migrate` (fragile en SQLite) ni du
 * `UserGroupService` (dépendances LDAP/repository). La logique est pure SQL
 * cross-driver (`insertOrIgnore`/`updateOrInsert`), idempotente et rejouable.
 *
 * Invariants garantis :
 * - **Aucun membre perdu** : les pivots des lignes redondantes sont reportés
 *   sur la survivante AVANT toute suppression (`insertOrIgnore`, PK composite).
 * - **Survivante déterministe** (D1) : ligne nue `X` préexistante > `Classe_X`
 *   > `Equipe_X` > `PP_X` (ordre D2 de 4.13).
 * - **Garde anti-collision** sur `user_groups.name` UNIQUE : on ne renomme
 *   jamais vers un `name` déjà pris — si la ligne nue existe, c'est ELLE la
 *   survivante (pas un rename).
 * - **`is_head_teacher`** posé `true` pour les membres issus de la ligne
 *   `PP_<X>` (avant suppression de cette ligne), `false` pour les autres.
 * - **`Equipe_` orphelin (D3)** : une ligne `Equipe_<Y>` sans `Classe_`/`PP_`
 *   héritée et sans ligne nue classe/équipe préexistante n'est PAS fusionnée ;
 *   elle est juste renommée au nom nu `Y` (type `equipe`).
 * - **Idempotente** : un 2e run (ou un run sur une base déjà foldée) est un
 *   no-op (plus de ligne préfixée à fusionner).
 */
class MergeLegacyUserGroups
{
    /**
     * Préfixes de fold, dans l'ordre de priorité canonique (D2/D1 de 4.13).
     * `Classe_` est canonique ; `Equipe_` puis `PP_` en fallback déterministe.
     *
     * @var array<int,string>
     */
    private const FOLD_PREFIXES = ['Classe_', 'Equipe_', 'PP_'];

    /**
     * Exécute la fusion. Retourne un petit compte-rendu (utile aux tests et au
     * log de la migration).
     *
     * @return array{merged_bases:int, removed_rows:int, head_teachers_flagged:int, renamed_orphans:int, skipped_collisions:int}
     */
    public function __invoke(): array
    {
        $report = [
            'merged_bases' => 0,
            'removed_rows' => 0,
            'head_teachers_flagged' => 0,
            'renamed_orphans' => 0,
            'skipped_collisions' => 0,
        ];

        // Story 42.1 — cette action est référencée par la migration 4.14
        // (2026_06_25), ANTÉRIEURE à la colonne `role` (2026_07_13). Sur une
        // base pré-42.1 (ou un `migrate` fresh où 4.14 tourne avant la colonne),
        // la colonne `role` n'existe pas encore → on GARDE toute écriture de
        // `role` derrière `Schema::hasColumn`. Sans la colonne : comportement
        // 4.14 strictement intact. Avec : miroir `role` ⇔ `is_head_teacher`.
        $hasRoleColumn = Schema::hasColumn('user_group_user', 'role');

        // 1) Charger toutes les lignes une fois, indexer par baseKey (lower).
        //    On groupe à la fois les lignes préfixées (Classe_/Equipe_/PP_) ET
        //    les éventuelles lignes nues de type classe/équipe préexistantes —
        //    ces dernières sont la survivante prioritaire (D1).
        $rows = DB::table('user_groups')
            ->select(['id', 'name', 'type', 'ad_guid', 'ad_dn'])
            ->get();

        /** @var array<string, array<int, object>> $byBase */
        $byBase = [];

        foreach ($rows as $row) {
            $name = (string) $row->name;
            $prefix = $this->foldPrefixOf($name);

            if ($prefix !== null) {
                // Ligne préfixée → base = portion nue après le préfixe.
                $baseKey = mb_strtolower(substr($name, strlen($prefix)));
                $byBase[$baseKey][] = $row;
                continue;
            }

            // Ligne nue : ne participe au regroupement QUE si elle est de type
            // classe/équipe (une ligne nue `Cours_X` n'a pas de préfixe de fold
            // — `Cours_` n'est pas dans FOLD_PREFIXES — donc déjà écartée ; un
            // `Maths5A` type cours nu ne doit pas absorber un `Equipe_Maths5A`).
            $type = mb_strtolower((string) $row->type);
            if (in_array($type, ['classe', 'class', 'equipe', 'équipe'], true)) {
                $byBase[mb_strtolower($name)][] = $row;
            }
        }

        // 2) Pour chaque base, décider survivante + redondantes, puis fusionner.
        //    Chaque base est traitée dans SA PROPRE transaction : un échec sur
        //    une base laisse les bases déjà fusionnées intactes et n'abandonne
        //    JAMAIS une base à mi-chemin (report des pivots / rename / delete
        //    atomiques ensemble — sinon un re-run pourrait élire une autre
        //    survivante sur un état partiel et casser l'idempotence).
        foreach ($byBase as $group) {
            DB::transaction(function () use ($group, &$report, $hasRoleColumn): void {
                $bareName = $this->resolveBareName($group);

                $survivor = $this->chooseSurvivor($group);
                if ($survivor === null) {
                    return;
                }

                // Les autres lignes du groupe sont redondantes (à fusionner puis
                // supprimer). Une seule ligne dans le groupe = rien à fusionner ;
                // on traite quand même un éventuel rename (cas Equipe_ orphelin /
                // ligne préfixée isolée → renommer au nom nu).
                $redundant = array_values(array_filter(
                    $group,
                    static fn (object $r): bool => (int) $r->id !== (int) $survivor->id
                ));

                // --- Equipe_ orphelin (D3) : une SEULE ligne, préfixée Equipe_,
                //     pas d'ancre Classe_/PP_ ni de ligne nue → renommer en nom
                //     nu type equipe, sans fusion. (Si plusieurs lignes, on passe
                //     par le chemin de fusion standard ci-dessous.)
                if (count($redundant) === 0) {
                    $this->renameLonelyPrefixedRow($survivor, $bareName, $report, $hasRoleColumn);

                    return;
                }

                // --- Cas fusion : ≥ 2 lignes pour la base.
                // Sécuriser l'ordre : (a) repérer les membres PP_, (b) reporter
                // les pivots des redondantes vers la survivante, (c) marquer PP,
                // (d) renommer la survivante, (e) supprimer les redondantes.

                // (a) Membres du/des CN `PP_<base>` (peut y en avoir 0..n
                //     lignes ; en pratique une seule, mais on est défensif).
                $ppRowIds = [];
                foreach ($group as $r) {
                    if ($this->foldPrefixOf((string) $r->name) === 'PP_') {
                        $ppRowIds[] = (int) $r->id;
                    }
                }

                $ppUserIds = [];
                if (count($ppRowIds) > 0) {
                    $ppUserIds = DB::table('user_group_user')
                        ->whereIn('user_group_id', $ppRowIds)
                        ->pluck('user_id')
                        ->map(static fn ($id): int => (int) $id)
                        ->all();
                }

                // (b) Report des pivots des redondantes → survivante (UNION
                //     idempotente, PK composite). On lit tous les user_id des
                //     redondantes et on insère sur la survivante.
                $redundantIds = array_map(static fn (object $r): int => (int) $r->id, $redundant);

                $redundantUserIds = DB::table('user_group_user')
                    ->whereIn('user_group_id', $redundantIds)
                    ->pluck('user_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->unique()
                    ->all();

                if (count($redundantUserIds) > 0) {
                    // Story 42.1 — rôle miroir des membres reportés : `member`
                    // par défaut, `manager` pour les profs (lecture COLONNE
                    // `users.role`, zéro LDAP). Les PP passeront `owner` à
                    // l'étape (c) ci-dessous. Sans colonne `role` : `false`
                    // uniquement (comportement 4.14).
                    $roleByUser = [];
                    if ($hasRoleColumn) {
                        $roleByUser = DB::table('users')
                            ->whereIn('id', $redundantUserIds)
                            ->pluck('role', 'id');
                    }

                    $insertRows = array_map(
                        static function (int $uid) use ($survivor, $hasRoleColumn, $roleByUser): array {
                            $row = [
                                'user_group_id' => (int) $survivor->id,
                                'user_id' => $uid,
                                'is_head_teacher' => false,
                            ];
                            if ($hasRoleColumn) {
                                $row['role'] = UserGroupUserPivot::defaultRoleForGlobalRole(
                                    $roleByUser[$uid] ?? null
                                );
                            }

                            return $row;
                        },
                        $redundantUserIds
                    );

                    // insertOrIgnore : ON CONFLICT DO NOTHING (PG) / INSERT OR
                    // IGNORE (SQLite) sur la PK composite — pas de doublon, pas
                    // d'écrasement d'un flag déjà posé.
                    DB::table('user_group_user')->insertOrIgnore($insertRows);
                }

                // (c) Marquer is_head_teacher=true pour les membres PP sur la
                //     survivante (ils sont désormais tous présents sur la
                //     survivante via le report (b) ou y étaient déjà).
                if (count($ppUserIds) > 0) {
                    // Story 42.1 — miroir : PP → `owner` en même temps que le
                    // flag (invariant `owner` ⇔ `is_head_teacher=true`).
                    $ppUpdate = ['is_head_teacher' => true];
                    if ($hasRoleColumn) {
                        $ppUpdate['role'] = UserGroupUserPivot::ROLE_OWNER;
                    }

                    $flagged = DB::table('user_group_user')
                        ->where('user_group_id', (int) $survivor->id)
                        ->whereIn('user_id', $ppUserIds)
                        ->update($ppUpdate);

                    $report['head_teachers_flagged'] += (int) $flagged;
                }

                // (d) Renommer la survivante au nom nu + type classe + GUID/DN
                //     canoniques. Garde anti-collision : si la survivante porte
                //     DÉJÀ le nom nu (ligne nue préexistante), pas de rename.
                $this->promoteSurvivor($survivor, $bareName, 'classe');

                // (e) Supprimer les redondantes (leurs pivots cascade en prod
                //     via la FK user_group_user.user_group_id → déjà reportés
                //     en (b), aucun membre perdu).
                $deleted = DB::table('user_groups')->whereIn('id', $redundantIds)->delete();
                $report['removed_rows'] += (int) $deleted;
                $report['merged_bases']++;
            });
        }

        return $report;
    }

    /**
     * Choisit la ligne survivante d'un groupe de base selon D1 :
     * ligne nue préexistante (type classe/équipe) > `Classe_` > `Equipe_` > `PP_`.
     *
     * @param array<int, object> $group
     */
    private function chooseSurvivor(array $group): ?object
    {
        // 1) Ligne nue préexistante (pas de préfixe de fold) = prioritaire.
        foreach ($group as $row) {
            if ($this->foldPrefixOf((string) $row->name) === null) {
                return $row;
            }
        }

        // 2) Sinon, premier préfixe disponible dans l'ordre canonique.
        foreach (self::FOLD_PREFIXES as $prefix) {
            foreach ($group as $row) {
                if (str_starts_with((string) $row->name, $prefix)) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * Nom nu de la base : la portion nue d'une ligne quelconque du groupe.
     * On préfère dériver depuis une ligne préfixée (strip), sinon le nom nu
     * d'une ligne nue présente. Préserve la casse d'origine (pas la baseKey
     * lower utilisée seulement pour le regroupement).
     *
     * @param array<int, object> $group
     */
    private function resolveBareName(array $group): string
    {
        // 1) Si une ligne NUE préexiste, son `name` EST le nom nu canonique (et
        //    c'est la survivante D1) — on s'aligne dessus pour éviter toute
        //    divergence de casse avec le lookup post-sync de 4.13.
        foreach ($group as $row) {
            if ($this->foldPrefixOf((string) $row->name) === null) {
                return (string) $row->name;
            }
        }

        // 2) Sinon, stripper depuis le CN le PLUS PRIORITAIRE disponible
        //    (Classe_ > Equipe_ > PP_, ordre canonique de `chooseSurvivor`).
        //    Déterministe quel que soit l'ordre de retour SQL (pas d'ORDER BY
        //    sur le get()) et BYTE-IDENTIQUE à `UserGroupService::stripClasseLikePrefix`
        //    → la migration et le sync produisent le même nom nu (cf. M3).
        foreach (self::FOLD_PREFIXES as $prefix) {
            foreach ($group as $row) {
                if (str_starts_with((string) $row->name, $prefix)) {
                    return substr((string) $row->name, strlen($prefix));
                }
            }
        }

        // Théorique : groupe vide de préfixe ET sans ligne nue détectée.
        return (string) $group[0]->name;
    }

    /**
     * Promeut la survivante : nom nu + type + GUID/DN canoniques. Si la
     * survivante est une ligne préfixée, ses propres `ad_guid`/`ad_dn`
     * correspondent déjà au CN le plus prioritaire disponible (D1) — on les
     * conserve. On ne touche au `name` que s'il diffère (anti-collision : la
     * cible nue n'existe pas comme AUTRE ligne, sinon elle aurait été choisie
     * comme survivante).
     */
    private function promoteSurvivor(object $survivor, string $bareName, string $type): void
    {
        $updates = [];

        if ((string) $survivor->name !== $bareName) {
            $updates['name'] = $bareName;
        }

        if (mb_strtolower((string) $survivor->type) !== $type) {
            $updates['type'] = $type;
        }

        if (count($updates) > 0) {
            DB::table('user_groups')->where('id', (int) $survivor->id)->update($updates);
        }
    }

    /**
     * Renomme une ligne préfixée ISOLÉE (pas de fusion) vers son nom nu en
     * conservant son type métier (D3 : `Equipe_` orphelin → `equipe`). No-op si
     * la ligne est déjà nue. Garde anti-collision : si une AUTRE ligne porte
     * déjà le nom nu cible, on n'écrit rien (laisse la base en l'état plutôt
     * que de violer l'unicité — situation non attendue, la ligne nue aurait
     * dû être regroupée avec elle).
     */
    private function renameLonelyPrefixedRow(object $row, string $bareName, array &$report, bool $hasRoleColumn = false): void
    {
        $prefix = $this->foldPrefixOf((string) $row->name);

        if ($prefix === null) {
            // Déjà nue (ligne nue préexistante isolée) : rien à faire.
            return;
        }

        // Anti-collision : une autre ligne occupe-t-elle déjà le nom nu ?
        $collision = DB::table('user_groups')
            ->where('name', $bareName)
            ->where('id', '!=', (int) $row->id)
            ->exists();

        if ($collision) {
            // Situation non attendue (la ligne nue homonyme aurait dû être
            // regroupée). On n'écrit rien plutôt que de violer l'unicité, MAIS
            // on TRACE — sinon une ligne préfixée résiduelle reste invisible et
            // indiagnosticable sur le parc fédéré (75 étab).
            Log::warning('[MergeLegacyUserGroups] Collision de nom nu — ligne préfixée laissée en l\'état', [
                'row_id' => (int) $row->id,
                'name' => (string) $row->name,
                'bare_name' => $bareName,
            ]);
            $report['skipped_collisions']++;

            return;
        }

        // Type métier : Equipe_ orphelin → equipe (D3) ; Classe_/PP_ isolé →
        // classe (cohérent avec le fold qui produit type=classe).
        $type = $prefix === 'Equipe_' ? 'equipe' : 'classe';

        DB::table('user_groups')
            ->where('id', (int) $row->id)
            ->update(['name' => $bareName, 'type' => $type]);

        // Un `PP_<X>` ISOLÉ (sans Classe_/Equipe_/nue associée) reste
        // sémantiquement un groupe de professeurs principaux : ses membres
        // SONT des PP. On pose le flag d'arête, sinon 4.15 (écriture SQL→AD)
        // raterait ces PP tant qu'aucun `syncFromAd` n'a reposé le flag.
        if ($prefix === 'PP_') {
            // Story 42.1 — miroir `owner` ⇔ `is_head_teacher` (garde hasColumn).
            $ppUpdate = ['is_head_teacher' => true];
            if ($hasRoleColumn) {
                $ppUpdate['role'] = UserGroupUserPivot::ROLE_OWNER;
            }

            $flagged = DB::table('user_group_user')
                ->where('user_group_id', (int) $row->id)
                ->update($ppUpdate);

            $report['head_teachers_flagged'] += (int) $flagged;
        }

        $report['renamed_orphans']++;
    }

    /**
     * Retourne le préfixe de fold du nom, ou null. Casse stricte (préfixes AD
     * réels `Classe_`/`Equipe_`/`PP_`, identique à `UserGroupService`).
     */
    private function foldPrefixOf(string $name): ?string
    {
        foreach (self::FOLD_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return $prefix;
            }
        }

        return null;
    }
}
