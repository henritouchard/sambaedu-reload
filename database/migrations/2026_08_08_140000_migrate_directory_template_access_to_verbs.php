<?php

declare(strict_types=1);

use App\Services\Filesystem\Plan\PlanGrant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Story 62.4 — LES RECETTES PASSENT AUX QUATRE VERBES. Une fois, ici, jamais
 * ailleurs.
 *
 * **Ce que cette migration fait.** Elle réécrit les deux endroits d'une recette
 * qui portent des droits — `roles_spec[].access` (les rôles, consommés par les
 * partages plats et par la matérialisation en assignations) et
 * `nodes_spec[].grants[].access` (les octrois d'un arbre) — en remplaçant la clé
 * `access` par la clé `verbs`, dont la valeur est une LISTE.
 *
 * **Le mappage, et pourquoi celui-là** (décision Henri Q3 = A, 2026-08-08) :
 *
 *  | avant  | après                                     |
 *  |--------|-------------------------------------------|
 *  | `ro`   | `['lire']`                                |
 *  | `rw`   | `['lire','editer','creer','supprimer']`   |
 *
 * C'est le SEUL mappage qui ne retire d'accès à personne. La doctrine de l'epic
 * est additive : une migration qui aurait profité du vocabulaire plus fin pour
 * resserrer les droits (« lecture/écriture, mais sans suppression ») aurait changé
 * le comportement d'instances en place SANS que personne ne l'ait demandé, et le
 * jour du déploiement, pas au moment où quelqu'un l'aurait décidé. La contrepartie
 * — des recettes maximalement permissives — est écrite au docblock du seeder et se
 * raffine à l'écran (story 62.6).
 *
 * **La preuve que rien ne bouge sur le disque** n'est pas ici : elle est dans les
 * référentiels figés du backend, qui compilent `['lire']` vers exactement l'entrée
 * d'hier et les quatre verbes vers exactement l'autre. Aucun caractère de ces
 * référentiels n'a changé — c'est ce qui fait de leur immobilité l'oracle de
 * « aucune recette ne perd d'accès ».
 *
 * **Pourquoi la conversion ne vit PAS dans la désérialisation.** L'accepter à la
 * relecture l'aurait fait vivre indéfiniment, et laissé deux vocabulaires
 * coexister dans les JSON stockés — ce que le garde-fou d'epic interdit
 * explicitement. Le vocabulaire de clés FERMÉ des octrois de nœud
 * ({@see \App\Models\DirectoryTemplate::TREE_GRANT_KEYS}) rend d'ailleurs une
 * recette non migrée BRUYANTE : elle est refusée avec « champ inconnu », jamais lue
 * de travers.
 *
 * **Idempotente** : une recette déjà migrée (clé `verbs` présente, clé `access`
 * absente) est laissée telle quelle, et la ligne n'est même pas réécrite.
 *
 * **`down()` est LOSSY, et le dit.** Redescendre au binaire écrase la distinction
 * que la montée a ouverte : toute liste portant un verbe de mutation redevient
 * `rw`, le reste redevient `ro`. Une recette qui aurait été raffinée entre-temps
 * (« déposer sans effacer ») remonterait donc en arrière avec le droit d'effacer.
 * C'est le meilleur retour possible sans perdre d'accès — c'est aussi la raison
 * pour laquelle redescendre n'est pas une opération anodine.
 */
return new class extends Migration
{
    /** Le mappage Q3, écrit une fois. */
    private const UP = [
        'ro' => [PlanGrant::VERB_LIRE],
        'rw' => [PlanGrant::VERB_LIRE, PlanGrant::VERB_EDITER, PlanGrant::VERB_CREER, PlanGrant::VERB_SUPPRIMER],
    ];

    public function up(): void
    {
        $this->rewrite(fn (array $spec): array => $this->toVerbs($spec));
    }

    public function down(): void
    {
        $this->rewrite(fn (array $spec): array => $this->toAccess($spec));
    }

    /**
     * Parcourt les recettes et réécrit leurs deux specs. Une ligne dont rien ne
     * change n'est PAS réécrite : une migration qui touche tout à chaque passage
     * rend impossible de voir ce qu'elle a réellement fait.
     *
     * @param  callable(array<int,mixed>): array<int,mixed>  $rewriteRoles
     */
    private function rewrite(callable $rewriteRoles): void
    {
        foreach (DB::table('directory_templates')->orderBy('id')->get(['id', 'roles_spec', 'nodes_spec']) as $row) {
            $roles = $this->decode($row->roles_spec);
            $nodes = $this->decode($row->nodes_spec);

            $newRoles = $roles === null ? null : $rewriteRoles($roles);
            $newNodes = $nodes === null ? null : $this->rewriteNodes($nodes, $rewriteRoles);

            $update = [];
            if ($newRoles !== null && $newRoles !== $roles) {
                $update['roles_spec'] = json_encode($newRoles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($newNodes !== null && $newNodes !== $nodes) {
                $update['nodes_spec'] = json_encode($newNodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if ($update !== []) {
                DB::table('directory_templates')->where('id', $row->id)->update($update);
            }
        }
    }

    /**
     * Les octrois d'un nœud portent exactement la même clé que les rôles : on
     * réutilise la même fonction, plutôt que d'en écrire deux qui pourraient
     * diverger.
     *
     * @param  array<int,mixed>  $nodes
     * @param  callable(array<int,mixed>): array<int,mixed>  $rewriteGrants
     * @return array<int,mixed>
     */
    private function rewriteNodes(array $nodes, callable $rewriteGrants): array
    {
        foreach ($nodes as $index => $node) {
            if (! is_array($node) || ! isset($node['grants']) || ! is_array($node['grants'])) {
                continue;
            }
            $nodes[$index]['grants'] = $rewriteGrants(array_values($node['grants']));
        }

        return $nodes;
    }

    /**
     * `access` → `verbs`. L'ORDRE DES CLÉS est préservé : `verbs` prend la place
     * qu'occupait `access`, pour que le diff d'une recette reste lisible.
     *
     * @param  array<int,mixed>  $specs
     * @return array<int,mixed>
     */
    private function toVerbs(array $specs): array
    {
        foreach ($specs as $index => $spec) {
            if (! is_array($spec) || ! array_key_exists('access', $spec)) {
                continue;
            }

            $access = is_string($spec['access']) ? $spec['access'] : 'ro';
            $verbs = self::UP[$access] ?? null;

            if ($verbs === null) {
                // Review 62.4 #5 — le repli sur « lire » restait la bonne réponse
                // (il RETIRE plutôt qu'il n'accorde : jamais un gain d'accès sur
                // une donnée qu'on ne comprend pas), mais il était MUET. Or toute
                // cette story tient sur « jamais une conversion silencieuse » : une
                // valeur d'accès qui n'est ni `ro` ni `rw` est une donnée corrompue,
                // et son passage à « lire » seul est précisément le genre de perte
                // qu'on doit pouvoir retrouver dans un journal après coup.
                Log::warning(
                    '[migration 62.4] Valeur d\'accès inconnue dans une recette — convertie en « lire » seul.',
                    ['access' => $spec['access'], 'index' => $index],
                );
                $verbs = self::UP['ro'];
            }

            $rebuilt = [];
            foreach ($spec as $key => $value) {
                if ($key === 'access') {
                    $rebuilt['verbs'] = $verbs;

                    continue;
                }
                $rebuilt[$key] = $value;
            }

            $specs[$index] = $rebuilt;
        }

        return $specs;
    }

    /**
     * `verbs` → `access`. LOSSY, voir le docblock de classe.
     *
     * @param  array<int,mixed>  $specs
     * @return array<int,mixed>
     */
    private function toAccess(array $specs): array
    {
        foreach ($specs as $index => $spec) {
            if (! is_array($spec) || ! array_key_exists('verbs', $spec)) {
                continue;
            }

            $verbs = is_array($spec['verbs']) ? $spec['verbs'] : [];
            $access = array_intersect(PlanGrant::MUTATION_VERBS, $verbs) !== [] ? 'rw' : 'ro';

            $rebuilt = [];
            foreach ($spec as $key => $value) {
                if ($key === 'verbs') {
                    $rebuilt['access'] = $access;

                    continue;
                }
                $rebuilt[$key] = $value;
            }

            $specs[$index] = $rebuilt;
        }

        return $specs;
    }

    /**
     * Les deux colonnes sont du JSON, et selon le moteur elles remontent en
     * chaîne ou déjà décodées. On ne suppose ni l'un ni l'autre.
     *
     * @return array<int,mixed>|null `null` = rien à réécrire
     */
    private function decode(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
};
