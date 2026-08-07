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

        // DEUX GROUPES QUI MÈNENT AU MÊME DOSSIER — cas RÉEL, mesuré sur instance.
        //
        // Le nom du dossier se dérive du nom du groupe, dépouillé de son préfixe.
        // Un groupe résiduel littéralement nommé « Classe_3emeA » et le groupe
        // « 3emeA » produisent donc la MÊME cible. Sans cette garde, la seconde
        // écriture heurtait l'unicité de la ligne et l'opérateur recevait une
        // erreur de base de données brute — illisible, et surtout muette sur ce
        // qu'il faut réparer : la donnée, pas le code.
        $occupant = NetworkShare::where('directory_name', $plan->rootPath)->first();
        if ($occupant !== null) {
            throw new \RuntimeException(sprintf(
                'le dossier « %s » est déjà celui du partage #%d%s : deux groupes mènent au même '
                . 'emplacement. Le groupe #%d (« %s ») ne peut donc pas matérialiser son arbre — '
                . 'vérifiez s\'il ne fait pas doublon avec un groupe résiduel préfixé.',
                $plan->rootPath,
                $occupant->id,
                $occupant->user_group_id !== null ? ' (groupe #' . $occupant->user_group_id . ')' : '',
                (int) $group->id,
                (string) $group->name,
            ));
        }

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
     * @return array{share:NetworkShare|null,materialized:bool,skipped:bool,reason:string|null}
     */
    public function materialize(UserGroup $group, DirectoryTemplate $template, bool $direct = false): array
    {
        // SAUTÉE N'EST PAS ÉCHOUÉE, et les confondre rend la sortie illisible.
        //
        // Une instance réelle porte des groupes de type « classe » auxquels
        // l'annuaire ne fait correspondre AUCUN groupe système — vestiges d'import,
        // classes déchets. Le refus d'écrire une entrée sur un nom inconnu est
        // correct et voulu ; en faire un échec ne l'est pas. Le chemin historique
        // les compte à part depuis toujours, avec leur motif ; mesuré sur instance,
        // les mêler aux vrais échecs noyait DEUX problèmes réels sous trois cents
        // lignes de bruit. On pose donc le même pré-contrôle, et la même catégorie.
        // **Le pré-contrôle ne vaut QUE hors requête**, parce qu'il interroge les
        // bases de noms du serveur : un appel système n'a rien à faire dans le
        // cycle d'un écran, et la règle vaut ici comme partout ailleurs depuis que
        // la pose est enfilée. Les écrans et les observateurs enfilent donc sans
        // sonder ; leur cas est d'ailleurs différent, une classe qu'on vient de
        // créer voit ses groupes d'annuaire naître avec elle.
        $missing = $direct ? app(ShareService::class)->unresolvedClassGroups($group) : [];
        if ($missing !== []) {
            return [
                'share' => null,
                'materialized' => false,
                'skipped' => true,
                'reason' => sprintf(
                    'l\'annuaire ne connaît pas %s — aucun arbre n\'a été créé.',
                    implode(' ni ', array_map(static fn (string $g): string => '« ' . $g . ' »', $missing)),
                ),
            ];
        }

        try {
            $share = $this->ensureShare($group, $template);
        } catch (Throwable $e) {
            return ['share' => null, 'materialized' => false, 'skipped' => false, 'reason' => $e->getMessage()];
        }

        $ok = $direct
            ? $this->shares->provision($share, 'system')
            : $this->shares->queueReconciliation($share);

        return [
            'share' => $share,
            'materialized' => $ok,
            'skipped' => false,
            // Un rapport dont TOUS les nœuds sont en échec n'a pas de « raison de
            // préparation » : la préparation a réussi, c'est la pose qui décline.
            // Aller la chercher dans le rapport évite le « voir la fiche du
            // partage » qui oblige l'opérateur à quitter sa console pour trois
            // cents classes.
            'reason' => $ok ? null : ($this->shares->lastFailure($share) ?? $this->firstNodeReason($share)),
        ];
    }

    /**
     * Le premier motif DISTINCT rendu par les nœuds du dernier rapport, borné.
     *
     * Les nœuds d'un même arbre déclinent presque toujours pour la même raison ;
     * répéter cinq fois la même phrase n'apprend rien de plus qu'une fois.
     */
    private function firstNodeReason(NetworkShare $share): ?string
    {
        $report = $this->shares->lastReport($share);
        if ($report === null) {
            return null;
        }

        $reasons = [];
        foreach ($report['nodes'] as $node) {
            $detail = trim((string) ($node['detail'] ?? ''));
            if ($detail !== '') {
                $reasons[$detail] = true;
            }
        }

        if ($reasons === []) {
            return null;
        }

        $first = (string) array_key_first($reasons);
        $extra = count($reasons) - 1;

        return $extra > 0 ? $first . sprintf(' (+%d autre(s) motif(s))', $extra) : $first;
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
