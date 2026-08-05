<?php

declare(strict_types=1);

namespace App\Services\Filesystem;

use App\Models\DirectoryTemplate;
use App\Models\NetworkShare;
use App\Models\UserGroup;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 60.5 — LE POINT D'ENTRÉE UNIQUE de l'arbre NEUF d'un groupe.
 *
 * Trois déclencheurs veulent la même chose — la création d'un groupe, un
 * changement d'appartenance, une commande de peuplement — et ils passent tous par
 * ici. Ce n'est pas de la commodité : c'est ce qui garantit qu'un arbre est
 * TOUJOURS matérialisé de la même façon, et qu'il n'existe pas de « second chemin »
 * qui produirait un partage à moitié relié.
 *
 * ---------------------------------------------------------------------------
 * **CE SERVICE N'ÉCRIT RIEN SUR LE DISQUE, ET NE CONNAÎT PAS L'ARBRE HISTORIQUE.**
 *
 * Il crée (ou retrouve) une LIGNE de partage reliée à son groupe et à sa recette,
 * puis délègue la matérialisation à l'orchestrateur, qui délègue au backend. La
 * zone dans laquelle cet arbre vit est portée par la recette et traduite tout en
 * bas, dans la garde de chemin. L'arbre de classe historique, lui, continue d'être
 * gouverné par son chemin figé — deux autorités, deux zones DISJOINTES, aucun
 * conflit possible. C'est pourquoi les deux voies ne doivent surtout pas être
 * « factorisées ».
 *
 * ---------------------------------------------------------------------------
 * **LE DÉCLENCHEUR EST SCOPÉ AUX RECETTES D'ARBRE, ET C'EST LE POINT DÉLICAT.**
 *
 * Deux recettes s'accrochent au type `classe` : l'arbre de partage, et la recette
 * plate « profs → élèves ». Si la création d'un groupe matérialisait toute recette
 * accrochée, chacune des 302 classes d'une instance en place naîtrait avec un
 * partage plat que personne n'a demandé. Accrochage = éligibilité à
 * l'auto-résolution ; matérialisation automatique = propriété des seules recettes
 * d'ARBRE ({@see DirectoryTemplate::materializesOnGroupCreation()}).
 *
 * **La suppression d'un groupe ne déprovisionne RIEN** (D9 — aucune destruction
 * implicite). La ligne du partage survit, son lien de groupe passe à `null`, et
 * l'administrateur décide depuis l'écran des partages. Un arbre qui disparaîtrait
 * du disque parce qu'une ligne de groupe a été supprimée serait exactement le geste
 * que tout l'epic refuse.
 */
class ClassTreeShareService
{
    /**
     * Interrupteur de la matérialisation automatique.
     *
     * **Un flag DÉDIÉ, jamais celui d'un autre canal.** Le patron du dépôt est
     * explicite depuis la story 42.2 : chaque canal son flag. Réutiliser celui de
     * la projection d'annuaire suspendrait la matérialisation à chaque
     * synchronisation, c'est-à-dire précisément quand des groupes naissent.
     */
    public static bool $enabled = true;

    public static function disable(): void
    {
        self::$enabled = false;
    }

    public static function enable(): void
    {
        self::$enabled = true;
    }

    public function __construct(private readonly NetworkShareService $shares)
    {
    }

    /**
     * La recette d'ARBRE que ce groupe matérialise, ou `null`.
     *
     * `null` est l'état NORMAL de la quasi-totalité des groupes : l'appelant ne
     * doit pas le traiter comme une anomalie.
     */
    public function recipeFor(UserGroup $group): ?DirectoryTemplate
    {
        $type = is_string($group->type) ? trim((string) $group->type) : '';
        if ($type === '') {
            return null;
        }

        $template = DirectoryTemplate::attachedTo($type);

        return $template !== null && $template->materializesOnGroupCreation() ? $template : null;
    }

    /** La ligne de partage d'arbre de ce groupe, si elle existe déjà. */
    public function existingShareFor(UserGroup $group, DirectoryTemplate $template): ?NetworkShare
    {
        return NetworkShare::query()
            ->where('directory_template_id', $template->id)
            ->where('user_group_id', $group->id)
            ->first();
    }

    /**
     * Tous les partages d'ARBRE déjà matérialisés d'un groupe.
     *
     * Sert au déclencheur d'appartenance, qui réconcilie l'EXISTANT et ne crée
     * jamais rien : un changement de membre n'est pas une demande de partage.
     *
     * @return \Illuminate\Support\Collection<int, NetworkShare>
     */
    public function existingSharesOf(UserGroup $group): \Illuminate\Support\Collection
    {
        return NetworkShare::query()
            ->whereNotNull('directory_template_id')
            ->where('user_group_id', $group->id)
            ->get();
    }

    /**
     * Crée si besoin la ligne de partage d'arbre du groupe. IDEMPOTENT — un second
     * appel retrouve la ligne et ne touche à rien.
     *
     * Le nom de répertoire recopie le dernier segment de la racine du plan : c'est
     * une donnée d'AFFICHAGE et de recherche, pas la source du chemin réel, qui
     * vient toujours du plan et de sa zone. Les faire diverger n'aurait aucune
     * conséquence sur le disque — raison de plus pour qu'ils disent la même chose.
     */
    public function ensureShare(UserGroup $group, DirectoryTemplate $template): NetworkShare
    {
        $existing = $this->existingShareFor($group, $template);
        if ($existing !== null) {
            return $existing;
        }

        $plan = app(TreePlanService::class)->planUsing($group, $template);

        $share = new NetworkShare([
            'name' => $this->shareNameFor($group, $plan->rootPath),
            'directory_name' => $plan->rootPath,
            // Un partage d'arbre naît SANS LETTRE : rien ne dit qu'il doit être
            // monté, et attribuer une lettre par défaut consommerait en silence
            // une ressource rare et globale.
            'letter' => null,
        ]);
        $share->directory_template_id = $template->id;
        $share->user_group_id = $group->id;
        $share->save();

        return $share;
    }

    /**
     * Matérialise l'arbre du groupe : ligne de partage puis réconciliation.
     *
     * @param  bool  $direct  `true` hors requête (commandes) : la pose est
     *                        exécutée et son résultat rendu. `false` depuis un
     *                        écran ou un observateur : elle est ENFILÉE — la pose
     *                        est quadratique en nombre d'entrées, et le cycle d'une
     *                        requête n'est pas le bon endroit pour l'attendre.
     * @return array{share:NetworkShare|null,materialized:bool,reason:string|null}
     */
    public function materialize(UserGroup $group, DirectoryTemplate $template, bool $direct = false): array
    {
        try {
            $share = $this->ensureShare($group, $template);
        } catch (Throwable $e) {
            return ['share' => null, 'materialized' => false, 'reason' => $e->getMessage()];
        }

        $ok = $direct
            ? $this->shares->provision($share, 'system')
            : $this->shares->queueReconciliation($share);

        return [
            'share' => $share,
            'materialized' => $ok,
            'reason' => $ok ? null : $this->shares->lastFailure($share),
        ];
    }

    /**
     * Le geste des DÉCLENCHEURS : matérialiser sans jamais casser l'écriture qui
     * vient d'aboutir.
     *
     * **Fail-soft intégral, et journalisé.** Un groupe se crée pour des dizaines de
     * raisons qui n'ont rien à voir avec les fichiers ; faire échouer sa création
     * parce qu'un arbre n'a pas pu être préparé serait disproportionné. Mais un
     * échec silencieux serait pire : il est consigné, et l'écran du partage porte la
     * raison du dernier échec de préparation.
     */
    public function materializeQuietly(UserGroup $group): void
    {
        if (! self::$enabled) {
            return;
        }

        try {
            $template = $this->recipeFor($group);
            if ($template === null) {
                return;
            }

            $result = $this->materialize($group, $template);

            if (! $result['materialized']) {
                Log::warning('ClassTreeShareService: matérialisation de l\'arbre non engagée', [
                    'group_id' => $group->id,
                    'group_name' => $group->name,
                    'template' => $template->key,
                    'reason' => $result['reason'],
                ]);
            }
        } catch (Throwable $e) {
            Log::error('ClassTreeShareService: échec de matérialisation de l\'arbre', [
                'group_id' => $group->id,
                'group_name' => $group->name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Le geste du déclencheur d'APPARTENANCE : réconcilier les arbres EXISTANTS du
     * groupe, jamais en créer un.
     *
     * Un rattachement n'est pas une demande de partage. Si le groupe n'a pas encore
     * d'arbre, il n'y a rien à réconcilier — et le fabriquer ici serait la
     * matérialisation par surprise que la story refuse partout ailleurs.
     */
    public function reconcileExistingQuietly(UserGroup $group): void
    {
        if (! self::$enabled) {
            return;
        }

        try {
            foreach ($this->existingSharesOf($group) as $share) {
                $this->shares->queueReconciliation($share);
            }
        } catch (Throwable $e) {
            Log::error('ClassTreeShareService: réconciliation d\'arbre non enfilée', [
                'group_id' => $group->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Nom lisible du partage d'arbre — le nom du groupe reste l'information utile. */
    private function shareNameFor(UserGroup $group, string $rootPath): string
    {
        $display = trim((string) ($group->display_name ?? ''));
        $label = $display !== '' ? $display : (string) $group->name;

        return $label !== '' ? $label : $rootPath;
    }
}
