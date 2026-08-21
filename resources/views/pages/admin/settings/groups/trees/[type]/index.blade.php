<?php

use App\Components\Traits\WithReturnBack;
use App\Components\Traits\WithToasts;
use App\Enums\FileBackendName;
use App\Enums\PlanAnchor;
use App\Enums\PlanNodeNature;
use App\Exceptions\Filesystem\InvalidTreeSpecException;
use App\Exceptions\Filesystem\PlanResolutionException;
use App\Models\DirectoryTemplate;
use App\Models\GroupTypeRole;
use App\Models\UserGroup;
use App\Services\Filesystem\Backend\FileBackend;
use App\Services\Filesystem\Backend\FileBackendRegistry;
use App\Services\Filesystem\Backend\GrantRendering;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use App\Services\Filesystem\FileLocationService;
use App\Services\Filesystem\PlanStateComparator;
use App\Services\Filesystem\TreePlanService;
use App\Support\GroupTypeCatalog;
use App\Support\RoleCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * L'arborescence d'un type de groupe : la liste des dossiers créés pour chaque
 * groupe de ce type, et ce que chaque audience y peut faire.
 *
 * **Cet écran CONSOMME, il ne remodèle rien.** Le moteur entier vit ailleurs : la
 * recette et ses validations ({@see DirectoryTemplate}), les quatre verbes et la
 * matrice de dégradation, la traversée dérivée et l'atteignabilité, le backend
 * d'aperçu et son registre.
 *
 * ---------------------------------------------------------------------------
 * **TROIS AUTORITÉS, AUCUNE DUPLIQUÉE.**
 *
 *  1. **l'écran PROPOSE** — les jetons, les natures, les audiences, les zones
 *     viennent des constantes et des catalogues RÉELS, jamais d'une liste
 *     recopiée ici ;
 *  2. **le modèle REFUSE** — `assertValidTreeSpec()` enchaîne motif, nœuds,
 *     octrois et atteignabilité. Ses messages sont déjà en français métier et
 *     nomment le chemin fautif : on les ATTRAPE et on les MONTRE, jamais on ne
 *     les reformule ;
 *  3. **le backend DÉCLARE** — ce qu'il sait rendre d'une liste de verbes est SA
 *     propriété. L'écran l'interroge par le CONTRAT (voir {@see self::renderingOf()}),
 *     il ne redit pas la règle et ne nomme aucune autorité.
 *
 * ---------------------------------------------------------------------------
 * **L'ÉTAT DU FORMULAIRE EST LE JSON STOCKÉ.** `$rolesSpec` et `$nodesSpec` sont
 * les tableaux relus tels quels, mutés de façon CIBLÉE par les actions. Aucune
 * renormalisation à l'ouverture ni à l'enregistrement : c'est ce qui rend
 * « ouvrir ne modifie rien » vrai sans effort, et ce qui préserve les clés
 * facultatives (`activable`, `suspendable`), l'ordre des nœuds et celui des
 * octrois exactement là où ils sont.
 *
 * **L'ARBRE EST UNE VUE, JAMAIS UN TRI.** {@see self::getTreeRowsProperty()}
 * ordonne les nœuds par chemin pour les afficher ; `$nodesSpec` garde l'ordre
 * stocké. Trier le tableau lui-même ferait de l'ouverture de la page une
 * modification de la recette.
 *
 * **LE CHEMIN NE SE SAISIT PLUS.** Un dossier se crée SOUS un parent désigné et
 * ne porte qu'un nom de segment ; le chemin complet en découle. C'est ce qui
 * supprime la classe d'erreur « chemin profond dont le parent n'est pas
 * déclaré » : elle n'est plus composable.
 *
 * **Le grisé n'a JAMAIS le droit de décocher.** Une recette stockée qui porte une
 * combinaison inexprimable est VALIDE (le vocabulaire du plan est neutre, et un
 * autre plan de fichiers la rendra nativement). L'écran l'affiche telle quelle,
 * marquée et expliquée. Le grisé empêche de COMPOSER la combinaison au clic ; il
 * ne réécrit pas un octroi que personne n'a touché.
 *
 * **Ce que l'écran ne fait pas, et pourquoi** : aucun champ de traversée (elle est
 * DÉRIVÉE par le backend — l'admin pose ses octrois profonds, la validation
 * garantit l'atteignabilité) ; aucun champ d'interdiction, aucune priorité (un
 * octroi est positif, et le vocabulaire de clés fermé les refuserait de toute
 * façon) ; aucune conformité ni observation (l'aperçu n'observe rien, et l'écran
 * de dérive des partages reste le seul endroit qui compare) ; aucun mode, aucun
 * chemin absolu (l'aperçu ne vise aucun endroit réel).
 *
 * Sécurité : `server.admin` au `mount()` ET à chaque écriture ET à l'aperçu.
 */
new #[Title('Arborescence')] class extends Component {
    use WithReturnBack;
    use WithToasts;

    /**
     * Le jeton de la zone par DÉFAUT d'une recette d'arbre créée ici.
     *
     * `classes` et pas `reseau` : cet écran ne crée que des ARBRES, et les arbres
     * vivent dans la zone qui leur est dédiée. La zone reste saisissable — c'est
     * un défaut, pas une contrainte.
     */
    private const DEFAULT_ANCHOR = PlanAnchor::Classes;

    /**
     * Le jeton qui DÉSIGNE « tout le groupe » dans la liste des audiences.
     *
     * Préfixé `@`, exactement comme `TREE_ROLE_MEMBER` (`@member`) l'est dans le
     * modèle — et pour la MÊME raison : une clé de rôle du catalogue est un slug
     * `[a-z][a-z0-9_]*` (`GroupRole::KEY_PATTERN`), donc un rôle libellé « Groupe »
     * produit la clé `groupe`. Sans le préfixe, ce rôle écraserait silencieusement
     * l'entrée sentinelle du menu, et l'ajouter écrirait une résolution `self` à la
     * place de `edge_role` : une audience qui ne vise pas ce que l'administrateur
     * a demandé, stockée sans un mot.
     *
     * Le jeton ne sert qu'au MENU. La clé STOCKÉE reste `groupe`.
     */
    private const AUDIENCE_WHOLE_GROUP = '@groupe';

    /** La clé écrite dans `roles_spec` pour l'audience « tout le groupe ». */
    private const AUDIENCE_WHOLE_GROUP_KEY = 'groupe';

    /** Le nom proposé à un dossier neuf, suffixé tant qu'il est déjà pris. */
    private const NEW_NODE_SEGMENT = 'nouveau_dossier';

    /** Provenance pour le bouton retour — voir {@see WithReturnBack}. */
    #[Url]
    public ?string $from = null;

    /**
     * Identité de la recette éditée (`null` = création).
     *
     * `#[Locked]` : sans ça, un payload forgé ferait porter l'enregistrement sur
     * une AUTRE recette que celle ouverte.
     */
    #[Locked]
    public ?int $editId = null;

    /** Type de groupe porté par l'URL — jamais re-saisi. */
    #[Locked]
    public string $typeKey = '';

    public string $newKey = '';

    public string $label = '';

    public string $pathPattern = '';

    public string $rootAnchor = '';

    /**
     * `roles_spec` TEL QUE STOCKÉ. Les entrées existantes ne sont jamais réécrites.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $rolesSpec = [];

    /**
     * `nodes_spec` TEL QUE STOCKÉ, muté de façon ciblée par les actions.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $nodesSpec = [];

    /** Audience en attente d'ajout : `groupe`, ou une clé du catalogue de rôles. */
    public string $pendingAudience = '';

    /**
     * Index du nœud visé depuis l'arbre : sa fiche est surlignée et amenée à
     * l'écran. Confort d'affichage SEUL — aucune écriture n'en dépend.
     */
    public ?int $focusedNode = null;

    // --- Aperçu ---------------------------------------------------------------

    public ?int $previewGroupId = null;

    /** @var array<string, mixed> */
    public array $previewData = [];

    public string $previewError = '';

    public function mount(string $type): void
    {
        abort_unless(Gate::allows('server.admin'), 403);
        abort_unless(GroupTypeCatalog::isKnown($type), 404, 'Type de groupe inconnu.');

        $this->typeKey = $type;
        $this->rootAnchor = self::DEFAULT_ANCHOR->value;

        $template = DirectoryTemplate::attachedTo($type);

        if ($template !== null) {
            $this->editId = (int) $template->id;
            $this->label = (string) $template->label;
            $this->pathPattern = (string) ($template->path_pattern ?? '');
            $this->rootAnchor = (string) ($template->root_anchor ?? self::DEFAULT_ANCHOR->value);
            // TELS QUE STOCKÉS : aucune clé ajoutée, aucune retirée, aucun ordre
            // touché.
            $this->rolesSpec = $template->roles_spec ?? [];
            $this->nodesSpec = $template->nodes_spec ?? [];
        }

        $this->previewGroupId = $this->firstGroupIdOfType($type);
    }

    public function backUrl(): string
    {
        return $this->resolveBack(route('admin.settings.groups', ['tab' => 'trees']));
    }

    public function getTypeLabelProperty(): string
    {
        return GroupTypeCatalog::label($this->typeKey);
    }

    public function getTypeIconProperty(): string
    {
        return GroupTypeCatalog::icon($this->typeKey);
    }

    /** Combien de groupes de ce type porteront cette arborescence. */
    public function getGroupCountProperty(): int
    {
        return UserGroup::query()
            ->whereRaw('LOWER(type) = ?', [mb_strtolower($this->typeKey)])
            ->count();
    }

    // =========================================================================
    // L'ARBRE : une VUE ordonnée de `nodesSpec`, jamais un tri de `nodesSpec`
    // =========================================================================

    /**
     * Les nœuds dans l'ordre où un arbre se lit — un parent, puis ses enfants —
     * chacun avec sa profondeur, son nom de segment et le résumé de ses octrois.
     *
     * L'ordre se calcule segment par segment plutôt que par comparaison de
     * chaînes : `a-bis` se glisserait sinon entre `a` et `a/enfant`, parce que
     * `-` précède `/` dans l'ordre des octets.
     *
     * @return list<array<string, mixed>>
     */
    public function getTreeRowsProperty(): array
    {
        $entries = [];

        foreach ($this->nodesSpec as $index => $node) {
            if (! is_array($node)) {
                continue;
            }

            $path = (string) ($node['path'] ?? '');
            $entries[] = [
                'index' => (int) $index,
                'path' => $path,
                'keys' => $this->isRootPath($path) ? [] : explode('/', $path),
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            $depth = min(count($a['keys']), count($b['keys']));

            for ($i = 0; $i < $depth; $i++) {
                $cmp = strcmp($a['keys'][$i], $b['keys'][$i]);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return count($a['keys']) <=> count($b['keys']);
        });

        $rows = [];

        foreach ($entries as $entry) {
            $node = $this->nodesSpec[$entry['index']];
            $path = $entry['path'];
            $natureValue = is_string($node['nature'] ?? null) ? (string) $node['nature'] : '';
            $nature = PlanNodeNature::tryFrom($natureValue);
            $isRoot = $this->isRootPath($path);

            $rows[] = [
                'index' => $entry['index'],
                'path' => $path,
                'segment' => $isRoot
                    ? ($this->pathPattern !== '' ? $this->pathPattern : 'racine')
                    : (string) (end($entry['keys']) ?: ''),
                'label' => (string) ($node['label'] ?? ''),
                'depth' => count($entry['keys']),
                'is_root' => $isRoot,
                'nature' => $natureValue,
                'nature_label' => $nature?->label() ?? $natureValue,
                'nature_tone' => $this->natureTone($nature),
                'audiences' => $this->audienceDigest($node),
            ];
        }

        return $rows;
    }

    /**
     * Ce que chaque audience peut faire sur un nœud, sous les deux formes dont
     * l'arbre a besoin : les quatre pastilles, et la phrase du survol.
     *
     * L'initiale d'un verbe est DÉCOUPÉE dans son libellé, jamais recopiée : une
     * table de correspondance ici deviendrait fausse le jour où un verbe est
     * renommé, et personne ne le verrait.
     *
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private function audienceDigest(array $node): array
    {
        $grantsByRole = [];
        foreach ((array) ($node['grants'] ?? []) as $grant) {
            if (is_array($grant) && is_string($grant['role'] ?? null)) {
                $grantsByRole[(string) $grant['role']] = $grant;
            }
        }

        $digest = [];

        foreach ($this->columnRoleKeys($node) as $roleKey) {
            $grant = $grantsByRole[$roleKey] ?? null;
            $verbs = $grant === null
                ? []
                : array_values(array_filter(
                    (array) ($grant['verbs'] ?? []),
                    static fn (mixed $v): bool => is_string($v),
                ));

            $marks = [];
            $granted = [];
            foreach (PlanGrant::VERBS as $verb) {
                $verbLabel = PlanStateComparator::verbLabel($verb);
                $on = in_array($verb, $verbs, true);

                $marks[] = [
                    'letter' => mb_strtoupper(mb_substr($verbLabel, 0, 1)),
                    'label' => $verbLabel,
                    'on' => $on,
                ];

                if ($on) {
                    $granted[] = $verbLabel;
                }
            }

            $digest[] = [
                'role' => $roleKey,
                'label' => $this->audienceLabel($roleKey),
                'marks' => $marks,
                'has_grant' => $grant !== null,
                'suspendable' => (bool) ($grant['suspendable'] ?? false),
                'summary' => $granted === [] ? '' : implode(' · ', $granted),
            ];
        }

        return $digest;
    }

    /**
     * La couleur du dossier, dérivée de sa nature.
     *
     * La classe est rendue ENTIÈRE et non composée à la vue (`text-{{ $tone }}`) :
     * le générateur de feuilles de style lit ces fichiers en texte et ne produit
     * que les classes qu'il y VOIT écrites. Une classe assemblée à l'affichage
     * n'existerait pas dans la feuille livrée, et le dossier serait incolore.
     */
    private function natureTone(?PlanNodeNature $nature): string
    {
        return match ($nature) {
            PlanNodeNature::Activable => 'text-warning',
            PlanNodeNature::ParMembre => 'text-accent',
            PlanNodeNature::ContenuLibre => 'text-base-content/40',
            default => 'text-primary',
        };
    }

    private function isRootPath(string $path): bool
    {
        return $path === '' || $path === PlanNode::ROOT_PATH;
    }

    /** Vise une fiche depuis l'arbre. Aucune écriture — le clic ne fait que désigner. */
    public function focusNode(int $index): void
    {
        $this->focusedNode = array_key_exists($index, $this->nodesSpec) ? $index : null;
    }

    // =========================================================================
    // Créer, renommer, retirer un dossier — le chemin DÉCOULE de la place
    // =========================================================================

    /**
     * Un dossier neuf SOUS le nœud désigné.
     *
     * C'est le seul geste de création avec {@see self::addNode()}, et il est la
     * raison d'être de l'écran : le chemin du parent n'est jamais retapé, donc un
     * enfant ne peut pas naître sous un parent que la recette ne déclare pas.
     */
    public function addChildNode(int $parentIndex): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        if (! array_key_exists($parentIndex, $this->nodesSpec)) {
            return;
        }

        $parentPath = (string) ($this->nodesSpec[$parentIndex]['path'] ?? '');
        $prefix = $this->isRootPath($parentPath) ? '' : $parentPath . '/';

        $this->appendNode($prefix);
    }

    public function addNode(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->appendNode('');
    }

    /**
     * Le nœud neuf est AJOUTÉ EN FIN de `nodesSpec`, jamais inséré près de son
     * parent : l'ordre stocké est celui qu'on a lu, et l'arbre se charge de
     * l'afficher au bon endroit.
     */
    private function appendNode(string $prefix): void
    {
        $this->nodesSpec[] = [
            'path' => $prefix . $this->freeSegmentUnder($prefix),
            'label' => '',
            'nature' => PlanNodeNature::Partagee->value,
            'grants' => [],
        ];

        $this->focusedNode = array_key_last($this->nodesSpec);
    }

    /** Un nom de segment libre sous ce préfixe — suffixé tant qu'il est pris. */
    private function freeSegmentUnder(string $prefix): string
    {
        $taken = [];
        foreach ($this->nodesSpec as $node) {
            if (is_array($node) && is_string($node['path'] ?? null)) {
                $taken[(string) $node['path']] = true;
            }
        }

        $segment = self::NEW_NODE_SEGMENT;
        $suffix = 1;

        while (isset($taken[$prefix . $segment])) {
            $suffix++;
            $segment = self::NEW_NODE_SEGMENT . '_' . $suffix;
        }

        return $segment;
    }

    /**
     * Renomme le DERNIER segment du chemin d'un nœud, et emporte ses enfants.
     *
     * Sans la propagation, les enfants resteraient accrochés à un parent que plus
     * rien ne déclare et l'enregistrement les refuserait en nommant un chemin que
     * personne n'a saisi.
     *
     * La racine ne se renomme pas ici : son nom est le motif de chemin de la
     * recette, et il a son propre champ.
     */
    public function renameSegment(int $index, string $segment): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        if (! array_key_exists($index, $this->nodesSpec)) {
            return;
        }

        $old = (string) ($this->nodesSpec[$index]['path'] ?? '');
        if ($this->isRootPath($old)) {
            return;
        }

        $segment = trim($segment);
        $cut = strrpos($old, '/');
        $new = ($cut === false ? '' : substr($old, 0, $cut + 1)) . $segment;

        if ($new === $old) {
            return;
        }

        $this->nodesSpec[$index]['path'] = $new;

        if ($segment === '') {
            return;
        }

        foreach ($this->nodesSpec as $i => $node) {
            $path = is_array($node) ? (string) ($node['path'] ?? '') : '';

            if ($i !== $index && str_starts_with($path, $old . '/')) {
                $this->nodesSpec[$i]['path'] = $new . '/' . substr($path, strlen($old) + 1);
            }
        }
    }

    /**
     * Retire un dossier ET sa descendance.
     *
     * Un arbre ne sait pas garder un enfant dont le parent est parti : le laisser
     * produirait une recette que l'enregistrement refuse, sur un chemin que
     * l'administrateur n'a pas touché. Le nombre de dossiers emportés est DIT.
     */
    public function removeNode(int $index): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        if (! array_key_exists($index, $this->nodesSpec)) {
            return;
        }

        $path = (string) ($this->nodesSpec[$index]['path'] ?? '');
        $descendants = [];

        if (! $this->isRootPath($path)) {
            foreach ($this->nodesSpec as $i => $node) {
                $other = is_array($node) ? (string) ($node['path'] ?? '') : '';
                if ($i !== $index && str_starts_with($other, $path . '/')) {
                    $descendants[] = $i;
                }
            }
        }

        foreach ([$index, ...$descendants] as $i) {
            unset($this->nodesSpec[$i]);
        }

        $this->nodesSpec = array_values($this->nodesSpec);
        $this->focusedNode = null;

        if ($descendants !== []) {
            $this->toastInfo(sprintf(
                'Le dossier a été retiré avec %d sous-dossier%s.',
                count($descendants),
                count($descendants) > 1 ? 's' : '',
            ));
        }
    }

    // =========================================================================
    // Saisie : mutations CIBLÉES du JSON
    // =========================================================================

    /**
     * Les seules conversions d'entrée de tout l'écran, et elles ne portent QUE sur
     * ce que l'utilisateur vient de taper.
     *
     * Le plafond est un nombre d'OCTETS en base ; un champ texte rend une chaîne.
     * Sans cette conversion ciblée, chaque saisie de plafond produirait un refus
     * (« doit être un nombre d'octets strictement positif ») sur une valeur
     * pourtant juste. Une valeur non numérique est laissée TELLE QUELLE : c'est le
     * modèle qui la refuse, en nommant le nœud — l'écran ne corrige pas une saisie
     * fausse en silence.
     */
    public function updated(string $property, mixed $value): void
    {
        if (preg_match('/^nodesSpec\.(\d+)\.plafond$/', $property, $matches) === 1) {
            $index = (int) $matches[1];
            $raw = is_string($value) ? trim($value) : $value;

            if ($raw === '' || $raw === null) {
                unset($this->nodesSpec[$index]['plafond']);

                return;
            }

            if (is_int($raw) || (is_string($raw) && preg_match('/^\d+$/', $raw) === 1)) {
                $this->nodesSpec[$index]['plafond'] = (int) $raw;
            }

            return;
        }

        if (preg_match('/^nodesSpec\.(\d+)\.nature$/', $property, $matches) === 1) {
            $this->syncActivableFlag((int) $matches[1]);
        }
    }

    /**
     * La nature est la SOURCE UNIQUE — le drapeau `activable` la suit.
     *
     * Le modèle refuse la contradiction ({@see DirectoryTemplate::assertValidTreeSpec()}),
     * mais seulement à la validation : sans cette synchronisation, chaque
     * changement de nature sur un nœud portant le drapeau deviendrait un refus
     * incompréhensible. Le drapeau n'est jamais AJOUTÉ à un nœud qui ne le portait
     * pas — synchronisé ou retiré, jamais inventé.
     */
    private function syncActivableFlag(int $index): void
    {
        if (! array_key_exists($index, $this->nodesSpec) || ! array_key_exists('activable', $this->nodesSpec[$index])) {
            return;
        }

        if (($this->nodesSpec[$index]['nature'] ?? null) === PlanNodeNature::Activable->value) {
            $this->nodesSpec[$index]['activable'] = true;

            return;
        }

        unset($this->nodesSpec[$index]['activable']);
    }

    /** Insère un jeton du vocabulaire FERMÉ dans le chemin d'un nœud. */
    public function insertPlaceholder(int $index, string $token): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        if (! array_key_exists($index, $this->nodesSpec) || ! $this->isKnownPlaceholder($token)) {
            return;
        }

        $this->nodesSpec[$index]['path'] = (string) ($this->nodesSpec[$index]['path'] ?? '') . '{' . $token . '}';
    }

    /** Insère un jeton du vocabulaire FERMÉ dans le motif de chemin de la recette. */
    public function insertPatternPlaceholder(string $token): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        if (! in_array($token, DirectoryTemplate::TREE_PLACEHOLDERS, true)) {
            return;
        }

        $this->pathPattern .= '{' . $token . '}';
    }

    private function isKnownPlaceholder(string $token): bool
    {
        return in_array($token, DirectoryTemplate::TREE_PLACEHOLDERS, true)
            || $token === DirectoryTemplate::PLACEHOLDER_MEMBER_LOGIN;
    }

    /**
     * Retire le rôle d'arête d'un nœud qui n'énumère plus de membres.
     *
     * Le sélecteur n'existe que sur un nœud par membre ; sans ce bouton, un nœud
     * dont on a changé la nature porterait une clé que le modèle refuse et que
     * plus aucun champ ne permettrait d'atteindre. On ne le retire pas D'OFFICE
     * (ce serait réécrire ce que personne n'a touché) — on rend le retrait
     * possible, et on dit pourquoi.
     */
    public function clearEdgeRole(int $index): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        if (array_key_exists($index, $this->nodesSpec)) {
            unset($this->nodesSpec[$index]['edge_role']);
        }
    }

    private function firstGroupIdOfType(string $typeKey): ?int
    {
        $group = UserGroup::query()
            ->whereRaw('LOWER(type) = ?', [mb_strtolower($typeKey)])
            ->orderBy('name')
            ->orderBy('id')
            ->first();

        return $group === null ? null : (int) $group->id;
    }

    /** Le nom saisissable d'un dossier : le DERNIER segment de son chemin. */
    private function segmentOf(string $path): string
    {
        if ($this->isRootPath($path)) {
            return '';
        }

        $cut = strrpos($path, '/');

        return $cut === false ? $path : substr($path, $cut + 1);
    }

    /**
     * La place du dossier, telle qu'elle se LIT : le motif de la recette puis les
     * segments parents. Jamais un chemin réel — l'emplacement est un savoir du
     * serveur.
     */
    private function parentDisplayOf(string $path): string
    {
        $root = $this->pathPattern !== '' ? $this->pathPattern : 'racine';

        if ($this->isRootPath($path)) {
            return '';
        }

        $cut = strrpos($path, '/');

        return $cut === false ? $root : $root . ' / ' . str_replace('/', ' / ', substr($path, 0, $cut));
    }

    // =========================================================================
    // La matrice rôles × verbes
    // =========================================================================

    /**
     * Coche ou décoche un verbe pour une audience sur un nœud.
     *
     * Trois règles, et la troisième est la seule qui refuse :
     *  - décocher le DERNIER verbe retire l'octroi (une ligne toute décochée n'est
     *    pas un octroi vide — le modèle refuse la liste vide, et l'écran ne
     *    propose pas ce que le modèle refuse) ;
     *  - cocher un verbe sur une audience sans octroi CRÉE l'octroi, au patron
     *    exact des recettes livrées (`role` + `verbs`, rien d'autre) ;
     *  - AJOUTER un verbe que le mécanisme d'exécution ne saura jamais rendre est
     *    REFUSÉ ici comme il est grisé à l'écran — un payload forgé ne compose pas
     *    ce que le clic ne compose pas. Le RETRAIT, lui, est toujours possible :
     *    c'est ce qui rend une recette déjà stockée réparable au lieu d'être
     *    amputée en silence.
     */
    public function toggleVerb(int $index, string $roleKey, string $verb): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        if (! array_key_exists($index, $this->nodesSpec) || ! in_array($verb, PlanGrant::VERBS, true)) {
            return;
        }
        if (! in_array($roleKey, $this->columnRoleKeys($this->nodesSpec[$index]), true)) {
            return;
        }

        $grants = is_array($this->nodesSpec[$index]['grants'] ?? null) ? $this->nodesSpec[$index]['grants'] : [];
        $position = null;
        foreach ($grants as $i => $grant) {
            if (is_array($grant) && ($grant['role'] ?? null) === $roleKey) {
                $position = $i;
                break;
            }
        }

        $current = $position === null
            ? []
            : array_values(array_filter(
                (array) ($grants[$position]['verbs'] ?? []),
                static fn (mixed $v): bool => is_string($v),
            ));

        if (in_array($verb, $current, true)) {
            $wanted = array_values(array_filter($current, static fn (string $v): bool => $v !== $verb));
        } else {
            $wanted = array_values(array_filter(
                PlanGrant::VERBS,
                static fn (string $v): bool => $v === $verb || in_array($v, $current, true),
            ));

            $rendering = $this->renderingOf($this->nodesSpec[$index] ?? [], $wanted);
            if ($rendering?->forbids($verb)) {
                $this->toastError($rendering->inexpressible[$verb]);

                return;
            }
        }

        if ($wanted === []) {
            if ($position !== null) {
                unset($grants[$position]);
                $this->nodesSpec[$index]['grants'] = array_values($grants);
            }

            return;
        }

        if ($position === null) {
            $grants[] = ['role' => $roleKey, 'verbs' => $wanted];
            $this->nodesSpec[$index]['grants'] = array_values($grants);

            return;
        }

        $grants[$position]['verbs'] = $wanted;
        $this->nodesSpec[$index]['grants'] = array_values($grants);
    }

    /** Bascule le drapeau `suspendable` d'un octroi. */
    public function toggleSuspendable(int $index, string $roleKey): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        if (! array_key_exists($index, $this->nodesSpec)) {
            return;
        }

        $grants = is_array($this->nodesSpec[$index]['grants'] ?? null) ? $this->nodesSpec[$index]['grants'] : [];

        foreach ($grants as $i => $grant) {
            if (! is_array($grant) || ($grant['role'] ?? null) !== $roleKey) {
                continue;
            }

            if ((bool) ($grant['suspendable'] ?? false)) {
                unset($grants[$i]['suspendable']);
            } else {
                $grants[$i]['suspendable'] = true;
            }

            $this->nodesSpec[$index]['grants'] = array_values($grants);

            return;
        }
    }

    // =========================================================================
    // Les audiences de la recette
    // =========================================================================

    /**
     * Les audiences proposées à l'ajout : « tout le groupe », puis un rôle du
     * catalogue ATTRIBUABLE dans ce type.
     *
     * Les colonnes de la matrice, elles, sont les rôles DE LA RECETTE — pas cette
     * liste. Confondre les deux ferait disparaître les colonnes des recettes
     * livrées, dont les clés (`equipe`, `classe`) ne sont pas des clés de rôle
     * d'arête.
     *
     * @return array<string, string> clé d'audience => libellé proposé
     */
    public function getAudienceOptionsProperty(): array
    {
        $options = [self::AUDIENCE_WHOLE_GROUP => 'Tout le groupe'];

        foreach (RoleCatalog::assignableKeys($this->typeKey) as $roleKey) {
            if (! $this->ownerIsOfferedHere($roleKey)) {
                continue;
            }

            $options[$roleKey] = sprintf('Les membres portant « %s »', RoleCatalog::label($this->typeKey, $roleKey));
        }

        return $options;
    }

    /**
     * Review 62.3 #1 — `owner` porte la désignation du professeur principal : il ne
     * se déclare que sur `classe`, et la garde du modèle l'y confine. Le proposer
     * ailleurs offrirait un octroi que personne ne recevra jamais.
     *
     * Comparaison INSENSIBLE À LA CASSE, comme partout ailleurs sur l'accrochage.
     */
    private function ownerIsOfferedHere(string $roleKey): bool
    {
        if ($roleKey !== \App\Models\Pivot\UserGroupUserPivot::ROLE_OWNER) {
            return true;
        }

        return mb_strtolower($this->typeKey) === GroupTypeRole::OWNER_TYPE_KEY;
    }

    /**
     * Les rôles du catalogue que ce type NE propose PAS parce qu'il est FERMÉ.
     *
     * Un type sans déclaration rend TOUT le catalogue attribuable ; un type qui
     * déclare se restreint à ce qu'il déclare. Sans cette ligne d'aide, un
     * administrateur qui ne trouve pas un rôle conclut qu'il n'existe pas — au lieu
     * de conclure que ce type ne l'a pas déclaré (review 62.3 #1).
     *
     * @return array<string, string> clé => libellé
     */
    public function getUndeclaredRolesProperty(): array
    {
        $assignable = RoleCatalog::assignableKeys($this->typeKey);
        $missing = [];

        foreach (RoleCatalog::keys() as $roleKey) {
            if (in_array($roleKey, $assignable, true) || ! $this->ownerIsOfferedHere($roleKey)) {
                continue;
            }

            $missing[$roleKey] = RoleCatalog::label($this->typeKey, $roleKey);
        }

        return $missing;
    }

    /**
     * Ajoute une audience à `roles_spec`, au patron EXACT des recettes livrées.
     *
     * `verbs` vaut le plancher `lire` : les droits réels vivent aux NŒUDS, et une
     * audience qui naîtrait avec les quatre verbes accorderait, sur les partages
     * plats, plus que ce que quiconque a demandé.
     */
    public function addAudience(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $key = trim($this->pendingAudience);
        $options = $this->audienceOptions;

        if ($key === '' || ! array_key_exists($key, $options)) {
            $this->toastError('Choisissez une audience à ajouter.');

            return;
        }

        $isWholeGroup = $key === self::AUDIENCE_WHOLE_GROUP;
        // Le jeton du MENU et la clé STOCKÉE sont deux choses : la déduplication
        // porte sur la clé stockée, sinon un rôle de catalogue nommé `groupe`
        // pourrait s'ajouter à côté de « tout le groupe » et les octrois de nœud ne
        // sauraient plus laquelle des deux audiences ils visent.
        $storedKey = $isWholeGroup ? self::AUDIENCE_WHOLE_GROUP_KEY : $key;

        foreach ($this->rolesSpec as $role) {
            if (is_array($role) && ($role['key'] ?? null) === $storedKey) {
                $this->toastError(sprintf(
                    'La recette porte déjà une audience de clé « %s » : deux audiences ne peuvent pas partager '
                    . 'la même clé, les octrois de nœud ne sauraient plus laquelle ils visent.',
                    $storedKey,
                ));

                return;
            }
        }

        $this->rolesSpec[] = [
            'key' => $storedKey,
            'label' => $isWholeGroup ? $options[$key] : RoleCatalog::label($this->typeKey, $key),
            'maille' => UserGroup::class,
            'group_type' => $this->typeKey,
            'verbs' => [PlanGrant::VERB_LIRE],
            'cardinality' => 'one',
            'resolution' => $isWholeGroup
                ? ['strategy' => \App\Enums\RoleResolutionStrategy::Itself->value]
                : ['strategy' => \App\Enums\RoleResolutionStrategy::EdgeRole->value, 'edge_roles' => [$storedKey]],
        ];

        $this->pendingAudience = '';
    }

    /**
     * Retire une audience — REFUSÉ tant qu'elle porte des octrois, avec le
     * décompte (patron des refus 62.1/62.3).
     */
    public function removeAudience(string $roleKey): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $usage = 0;
        foreach ($this->nodesSpec as $node) {
            foreach ((array) ($node['grants'] ?? []) as $grant) {
                if (is_array($grant) && ($grant['role'] ?? null) === $roleKey) {
                    $usage++;
                }
            }
        }

        if ($usage > 0) {
            $this->toastError(sprintf(
                'Cette audience porte encore %d octroi%s dans l\'arborescence : décochez-les avant de la retirer.',
                $usage,
                $usage > 1 ? 's' : '',
            ));

            return;
        }

        $this->rolesSpec = array_values(array_filter(
            $this->rolesSpec,
            static fn (mixed $role): bool => ! is_array($role) || ($role['key'] ?? null) !== $roleKey,
        ));
    }

    // =========================================================================
    // Enregistrer
    // =========================================================================

    public function save(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->resetErrorBag();

        if (trim($this->label) === '') {
            $this->addError('label', 'Le libellé est requis.');

            return;
        }
        if (trim($this->pathPattern) === '') {
            $this->addError('pathPattern', 'Le motif de chemin est requis : c\'est lui qui nomme le dossier racine.');

            return;
        }

        if ($this->editId !== null) {
            $template = DirectoryTemplate::find($this->editId);
            if ($template === null) {
                $this->toastError('Arborescence introuvable — rechargez la page.');

                return;
            }
        } else {
            $template = new DirectoryTemplate();
            $key = $this->slugKey();

            if ($key === '') {
                $this->addError('newKey', 'Cette clé ne produit rien d\'utilisable : commencez par une lettre.');

                return;
            }
            if (DirectoryTemplate::where('key', $key)->exists()) {
                $this->addError('newKey', sprintf('La clé « %s » est déjà prise par une recette.', $key));

                return;
            }

            $template->key = $key;
        }

        $template->label = trim($this->label);
        $template->path_pattern = trim($this->pathPattern);
        $template->root_anchor = $this->rootAnchor;
        // L'accrochage vient du CONTEXTE d'ouverture, jamais d'une saisie : on
        // édite l'arborescence DE CE TYPE.
        $template->attached_group_type = $this->typeKey;
        $template->roles_spec = $this->rolesSpec;
        $template->nodes_spec = $this->nodesSpec;

        try {
            // La validation MÉTIER complète, AVANT toute écriture. Le hook `saving`
            // du modèle ne couvre que l'accrochage : sans cet appel, une recette
            // au nœud inatteignable serait persistée sans un mot.
            $template->assertValidTreeSpec();
            $template->save();
        } catch (InvalidTreeSpecException|PlanResolutionException $e) {
            // Message TEL QUEL : il est déjà en français métier et nomme le chemin
            // fautif. Le reformuler perdrait précisément ce qui sert.
            $this->addError('tree', $e->getMessage());

            return;
        } catch (QueryException $e) {
            // `assertSingleTreeAttachment()` est un check-then-act : deux
            // soumissions concurrentes peuvent se disputer la clé de la recette.
            // La perdante reçoit un message métier, jamais un SQLSTATE brut.
            $this->addError('tree', sprintf(
                'Les arborescences viennent d\'être modifiées ailleurs (type « %s »). Rouvrez la fenêtre pour '
                . 'repartir de l\'état courant.',
                $this->typeKey,
            ));

            return;
        } catch (\Throwable $e) {
            $this->addError('tree', $e->getMessage());

            return;
        }

        $this->toastSuccess(sprintf('L\'arborescence « %s » a été enregistrée.', (string) $template->label));

        // La page reste ouverte sur ce qu'on vient d'écrire, et une recette NEUVE
        // devient une recette éditée : sans ça, un second enregistrement tenterait
        // d'en créer une deuxième sur le même type, et l'invariant « un type = un
        // arbre » le refuserait sur un geste parfaitement légitime.
        $this->editId = (int) $template->id;
        $this->newKey = '';
    }

    /**
     * La clé d'une recette NEUVE : slug figé à la création (patron 62.1/62.2).
     *
     * La garde ne mord qu'à la SAISIE : une clé déjà stockée n'est jamais
     * réécrite, et l'édition ne la propose pas.
     */
    public function getPreviewKeyProperty(): string
    {
        return $this->slugKey();
    }

    private function slugKey(): string
    {
        $source = trim($this->newKey) !== '' ? $this->newKey : $this->label;
        $slug = trim(\Illuminate\Support\Str::slug($source, '_'), '_');
        $slug = ltrim($slug, '0123456789_');

        return substr($slug, 0, 50);
    }

    /**
     * `true` si enregistrer ARME la matérialisation des groupes FUTURS.
     *
     * Le dire au moment d'enregistrer, sobrement : une recette d'arbre accrochée
     * fait naître son arborescence avec chaque groupe créé ENSUITE. Les groupes
     * existants, eux, ne bougent pas — leur reprise reste une commande dédiée, et
     * cet écran ne la déclenche jamais.
     */
    public function getArmsFutureMaterializationProperty(): bool
    {
        return $this->typeKey !== '' && trim($this->pathPattern) !== '';
    }

    // =========================================================================
    // CE QUE LE BACKEND DÉCLARE SAVOIR RENDRE
    // =========================================================================

    /**
     * **LE POINT D'APPEL UNIQUE — la règle du grisé est la DÉCLARATION DU BACKEND.**
     *
     * Elle n'est PAS recopiée ici, et c'est délibéré : une règle redite dans une
     * vue est une règle qui divergera. L'écran ne lit que ce que le contrat expose
     * de NEUTRE, et ne nomme aucune autorité — c'est le registre qui la choisit.
     *
     * **Le nœud entier part avec la question.** Ce qu'un backend rend d'un octroi
     * peut dépendre de ses voisins : la condition appartient au backend, l'écran ne
     * la rejoue pas. D'où la sonde — un nœud jetable qui porte les octrois SAISIS,
     * sans passer par la validation de chemin de la recette, dont ce n'est pas
     * l'heure.
     *
     * La sonde distingue seulement la RACINE du reste : c'est la seule propriété de
     * chemin dont un modèle de permissions ait besoin (un espace et un dossier
     * n'offrent pas les mêmes rôles), et la seule que l'écran puisse affirmer avant
     * que le motif ne soit valide.
     *
     * `null` = la question n'a pas pu être posée. On n'affirme alors AUCUNE
     * dégradation et on ne grise rien : inventer une limite qu'aucune autorité n'a
     * déclarée serait pire que se taire.
     *
     * @param  array<string, mixed>  $node  le nœud TEL QUE SAISI
     * @param  list<string>  $verbs  les verbes de l'octroi examiné
     */
    private function renderingOf(array $node, array $verbs): ?GrantRendering
    {
        try {
            $grants = [];
            foreach ((array) ($node['grants'] ?? []) as $grant) {
                if (! is_array($grant) || ! is_string($grant['role'] ?? null)) {
                    continue;
                }
                $grantVerbs = array_values(array_filter(
                    (array) ($grant['verbs'] ?? []),
                    static fn (mixed $v): bool => is_string($v),
                ));
                if ($grantVerbs === []) {
                    continue;
                }

                $grants[] = new PlanGrant(
                    (string) $grant['role'],
                    PlanSubject::group(1),
                    $grantVerbs,
                    (bool) ($grant['suspendable'] ?? false),
                );
            }

            $isRoot = (string) ($node['path'] ?? '') === PlanNode::ROOT_PATH;

            $probe = new PlanNode(
                $isRoot ? PlanNode::ROOT_PATH : 'sonde',
                'sonde',
                // Une nature qui accepte les octrois suspendables : la sonde ne doit
                // refuser aucun état saisissable.
                PlanNodeNature::Activable,
                $grants,
            );

            return $this->executingBackend()
                ->rendering($probe, new PlanGrant('sonde', PlanSubject::group(1), $verbs));
        } catch (\Throwable $e) {
            // Dégradation JOURNALISÉE, jamais muette : une recette dont les octrois
            // sont illisibles, ou une autorité injoignable, ne doivent pas faire
            // disparaître l'écran.
            Log::warning(
                '[Arborescences] Le backend n\'a pas répondu — les notes de dégradation sont omises pour ce nœud.',
                ['exception' => $e->getMessage()],
            );

            return null;
        }
    }

    // =========================================================================
    // Le modèle de vue de l'éditeur
    // =========================================================================

    /**
     * Les colonnes de la matrice d'un nœud : les rôles DE LA RECETTE, plus le jeton
     * du membre énuméré sur les seuls nœuds par membre.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function columnRoleKeys(array $node): array
    {
        $keys = [];
        foreach ($this->rolesSpec as $role) {
            $key = is_array($role) ? ($role['key'] ?? null) : null;
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        if (($node['nature'] ?? null) === PlanNodeNature::ParMembre->value) {
            $keys[] = DirectoryTemplate::TREE_ROLE_MEMBER;
        }

        return $keys;
    }

    /** @see RoleCatalog::audienceLabel() — dérivé du catalogue dès que l'audience vise un rôle d'arête. */
    private function audienceLabel(string $roleKey): string
    {
        if ($roleKey === DirectoryTemplate::TREE_ROLE_MEMBER) {
            return 'Le membre (son propre dossier)';
        }

        foreach ($this->rolesSpec as $role) {
            if (is_array($role) && ($role['key'] ?? null) === $roleKey) {
                return RoleCatalog::audienceLabel($role, $this->typeKey !== '' ? $this->typeKey : null);
            }
        }

        return $roleKey;
    }

    /**
     * Tout ce que le Blade a besoin de savoir, calculé ici — la vue ne décide rien.
     *
     * @return list<array<string, mixed>>
     */
    public function getEditorNodesProperty(): array
    {
        $rendered = [];

        foreach ($this->nodesSpec as $index => $node) {
            if (! is_array($node)) {
                continue;
            }

            $natureValue = is_string($node['nature'] ?? null) ? (string) $node['nature'] : '';
            $nature = PlanNodeNature::tryFrom($natureValue);
            $isPerMember = $nature === PlanNodeNature::ParMembre;

            $grantsByRole = [];
            foreach ((array) ($node['grants'] ?? []) as $grant) {
                if (is_array($grant) && is_string($grant['role'] ?? null)) {
                    $grantsByRole[(string) $grant['role']] = $grant;
                }
            }

            $columns = [];
            $demoted = [];
            foreach ($this->columnRoleKeys($node) as $roleKey) {
                $grant = $grantsByRole[$roleKey] ?? null;
                $verbs = $grant === null
                    ? []
                    : array_values(array_filter(
                        (array) ($grant['verbs'] ?? []),
                        static fn (mixed $v): bool => is_string($v),
                    ));

                $cells = [];
                foreach (PlanGrant::VERBS as $verb) {
                    $checked = in_array($verb, $verbs, true);
                    $prospective = $checked
                        ? $verbs
                        : array_values(array_filter(
                            PlanGrant::VERBS,
                            static fn (string $v): bool => $v === $verb || in_array($v, $verbs, true),
                        ));

                    // Une case DÉJÀ COCHÉE n'est jamais grisée : le grisé empêche de
                    // composer, il ne réécrit pas un octroi stocké. Elle porte sa
                    // marque « non exprimable » et son explication, et reste
                    // décochable.
                    $prospectiveRendering = $this->renderingOf($node, $prospective);
                    $expressible = ! ($prospectiveRendering?->forbids($verb) ?? false);

                    $cells[] = [
                        'verb' => $verb,
                        'label' => PlanStateComparator::verbLabel($verb),
                        'checked' => $checked,
                        'disabled' => ! $checked && ! $expressible,
                        'inexpressible' => ! $expressible,
                        'reason' => $expressible ? '' : $prospectiveRendering->inexpressible[$verb],
                    ];
                }

                $analysis = $verbs === [] ? null : $this->renderingOf($node, $verbs);
                if ($analysis !== null && $analysis->demoted) {
                    $demoted[] = $this->audienceLabel($roleKey);
                }

                $columns[] = [
                    'role' => $roleKey,
                    'label' => $this->audienceLabel($roleKey),
                    'is_member_token' => $roleKey === DirectoryTemplate::TREE_ROLE_MEMBER,
                    'has_grant' => $grant !== null,
                    'cells' => $cells,
                    'suspendable' => (bool) ($grant['suspendable'] ?? false),
                    // Le drapeau reste PROPOSÉ sur un octroi qui le porte déjà,
                    // même si la nature ne l'accepte plus : sinon la contradiction
                    // stockée deviendrait irréparable à l'écran.
                    'suspendable_offered' => $grant !== null
                        && (($nature?->acceptsSuspendableGrants() ?? false) || (bool) ($grant['suspendable'] ?? false)),
                    'suspendable_orphan' => (bool) ($grant['suspendable'] ?? false)
                        && ! ($nature?->acceptsSuspendableGrants() ?? false),
                    'notes' => $this->grantNotes($analysis),
                ];
            }

            $plafond = $node['plafond'] ?? null;

            $rendered[] = [
                'index' => (int) $index,
                'path' => (string) ($node['path'] ?? ''),
                'segment' => $this->segmentOf((string) ($node['path'] ?? '')),
                'parent_display' => $this->parentDisplayOf((string) ($node['path'] ?? '')),
                'is_root' => $this->isRootPath((string) ($node['path'] ?? '')),
                'label' => (string) ($node['label'] ?? ''),
                'nature' => $natureValue,
                'nature_label' => $nature?->label() ?? $natureValue,
                'nature_help' => $this->natureHelp($nature),
                'depth' => $this->depthOf((string) ($node['path'] ?? '')),
                'is_per_member' => $isPerMember,
                'edge_role' => is_string($node['edge_role'] ?? null) ? (string) $node['edge_role'] : null,
                'edge_role_offered' => $isPerMember || array_key_exists('edge_role', $node),
                'edge_role_stale' => ! $isPerMember && array_key_exists('edge_role', $node),
                'plafond' => $plafond,
                'plafond_human' => is_int($plafond) ? $this->humanBytes($plafond) : '',
                'placeholders' => $this->placeholdersFor($isPerMember),
                'columns' => $columns,
                'node_note' => $demoted === []
                    ? null
                    : sprintf(
                        'Ce dossier accorde déjà la suppression à une autre audience : la restriction qui '
                        . 'approcherait « déposer sans effacer » ne peut donc pas s\'y poser, et l\'octroi de %s '
                        . 'sera rendu à ce que le partage sait exprimer.',
                        implode(', ', array_map(static fn (string $l): string => '« ' . $l . ' »', $demoted)),
                    ),
            ];
        }

        return $rendered;
    }

    /**
     * Les notes DÉCLARÉES d'un octroi : ce qui ne sera pas rendu, et ce qui ne le
     * sera qu'approximativement. Toutes dérivées de la réponse du backend.
     *
     * @return list<string>
     */
    private function grantNotes(?GrantRendering $analysis): array
    {
        if ($analysis === null) {
            return [];
        }

        $notes = [];

        if ($analysis->approximated) {
            $notes[] = 'Rendu approché : le déposant pourra encore retirer ses propres fichiers.';
        }

        if (! $analysis->isExact()) {
            $notes[] = sprintf(
                'Non rendu ici : %s. Ce qui est rendu : %s.',
                PlanStateComparator::accessLabel($analysis->missing),
                $analysis->rendered === [] ? 'rien' : PlanStateComparator::accessLabel($analysis->rendered),
            );
        }

        if ($analysis->differentiated) {
            $notes[] = 'Cette combinaison ne traite pas les dossiers et les fichiers de la même façon : '
                . 'l\'exécution la pose en deux temps.';
        }

        return $notes;
    }

    private function natureHelp(?PlanNodeNature $nature): string
    {
        return match ($nature) {
            PlanNodeNature::Partagee => 'Chemin fixe, droits dérivés des audiences de la recette. Le cas ordinaire.',
            PlanNodeNature::Activable => 'Chemin fixe dont certains octrois peuvent être SUSPENDUS. Fermer l\'espace '
                . 'vide l\'octroi ; le dossier et les données restent.',
            PlanNodeNature::ParMembre => 'Un dossier par membre portant le rôle d\'arête visé, avec un octroi '
                . 'nominatif. Le chemin doit porter le jeton du membre.',
            PlanNodeNature::ContenuLibre => 'Chemin fixe dont le plan gouverne les droits mais PAS les enfants : ce '
                . 'qui y est créé ensuite n\'est pas un écart.',
            default => '',
        };
    }

    /**
     * Le vocabulaire de substitution, avec sa description. FERMÉ, et lu depuis le
     * modèle : aucun jeton ne se devine, tous s'insèrent d'un clic.
     *
     * @return list<array{token: string, help: string}>
     */
    private function placeholdersFor(bool $isPerMember): array
    {
        $help = [
            DirectoryTemplate::PLACEHOLDER_GROUP_NAME => 'Le nom complet du groupe.',
            DirectoryTemplate::PLACEHOLDER_GROUP_BARE_NAME => 'Le nom du groupe sans son préfixe de type.',
            DirectoryTemplate::PLACEHOLDER_GROUP_MATIERE => 'La moitié « matière » du nom d\'un groupe matière × classe.',
            DirectoryTemplate::PLACEHOLDER_GROUP_CLASSE => 'La moitié « classe » du nom d\'un groupe matière × classe.',
            DirectoryTemplate::PLACEHOLDER_MEMBER_LOGIN => 'L\'identifiant du membre énuméré — sur un dossier par '
                . 'membre uniquement.',
        ];

        $tokens = DirectoryTemplate::TREE_PLACEHOLDERS;
        if ($isPerMember) {
            $tokens[] = DirectoryTemplate::PLACEHOLDER_MEMBER_LOGIN;
        }

        return array_map(
            static fn (string $token): array => ['token' => $token, 'help' => $help[$token] ?? ''],
            $tokens,
        );
    }

    /** La profondeur DÉCOULE du chemin — il n'y a aucun champ de profondeur. */
    private function depthOf(string $path): int
    {
        if ($path === '' || $path === PlanNode::ROOT_PATH) {
            return 0;
        }

        return substr_count($path, '/') + 1;
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) (int) $value : number_format($value, 1, ',', ' ')) . ' ' . $units[$unit];
    }

    /** Les natures proposées, libellés de l'ENUM — jamais un vocabulaire nouveau. */
    public function getNatureOptionsProperty(): array
    {
        $options = [];
        foreach (PlanNodeNature::cases() as $nature) {
            $options[$nature->value] = $nature->label();
        }

        return $options;
    }

    /** Les zones proposées, libellés de l'ENUM. */
    public function getAnchorOptionsProperty(): array
    {
        $options = [];
        foreach (PlanAnchor::cases() as $anchor) {
            $options[$anchor->value] = $anchor->label();
        }

        return $options;
    }

    /** Les rôles d'arête proposés sur un nœud par membre — depuis le catalogue. */
    public function getEdgeRoleOptionsProperty(): array
    {
        $options = [];
        foreach (RoleCatalog::assignableKeys($this->typeKey) as $roleKey) {
            $options[$roleKey] = RoleCatalog::label($this->typeKey, $roleKey);
        }

        return $options;
    }

    /** Les groupes d'essai possibles pour l'aperçu : ceux de ce type. */
    public function getTrialGroupsProperty(): array
    {
        return UserGroup::query()
            ->whereRaw('LOWER(type) = ?', [mb_strtolower($this->typeKey)])
            ->orderBy('name')
            ->orderBy('id')
            ->limit(200)
            ->get(['id', 'name'])
            ->map(static fn (UserGroup $group): array => ['id' => (int) $group->id, 'name' => (string) $group->name])
            ->all();
    }

    /** Les audiences de la recette, telles qu'elles y sont écrites. */
    public function getAudienceRowsProperty(): array
    {
        $rows = [];
        foreach ($this->rolesSpec as $role) {
            if (! is_array($role) || ! is_string($role['key'] ?? null)) {
                continue;
            }

            $rows[] = [
                'key' => (string) $role['key'],
                'label' => $this->audienceLabel((string) $role['key']),
                'strategy' => is_array($role['resolution'] ?? null)
                    ? (string) ($role['resolution']['strategy'] ?? '')
                    : '',
            ];
        }

        return $rows;
    }

    // =========================================================================
    // L'APERÇU — premier consommateur visible du backend d'aperçu
    // =========================================================================

    /**
     * Résout l'état du formulaire sur un groupe d'ESSAI et le fait décrire par le
     * backend d'aperçu.
     *
     * **Rien n'est persisté, jamais.** La recette prévisualisée est un CLONE en
     * mémoire (`forceFill` sur une instance neuve, jamais `fill()` sur la ligne
     * chargée : un `save()` accidentel ailleurs emporterait l'état du formulaire).
     * Le groupe, lui, DOIT être persisté — la résolution refuse un groupe sans
     * identité, et inventer un groupe fantôme donnerait un aperçu qui ne
     * ressemblerait à rien.
     *
     * **Prévisualiser, c'est déjà valider** : la résolution appelle la validation
     * de recette. Un état invalide rend donc le refus métier à la place du plan —
     * jamais un aperçu partiel.
     */
    public function preview(): void
    {
        abort_unless(Gate::allows('server.admin'), 403);

        $this->previewData = [];
        $this->previewError = '';

        $group = $this->previewGroupId === null ? null : UserGroup::find($this->previewGroupId);

        if ($group === null) {
            $this->previewError = 'Aucun groupe de ce type n\'existe encore : créez-en un pour voir l\'arborescence '
                . 'résolue. L\'enregistrement, lui, reste possible sans aperçu.';

            return;
        }

        $clone = (new DirectoryTemplate())->forceFill([
            'key' => $this->editId !== null ? $this->slugKeyOfEdited() : $this->slugKey(),
            'label' => trim($this->label),
            'path_pattern' => trim($this->pathPattern),
            'root_anchor' => $this->rootAnchor,
            'attached_group_type' => $this->typeKey,
            'roles_spec' => $this->rolesSpec,
            'nodes_spec' => $this->nodesSpec,
        ]);

        try {
            $plan = app(TreePlanService::class)->planUsing($group, $clone);
            $report = app(FileBackendRegistry::class)->get(FileBackendName::Preview)->provision($plan);
        } catch (InvalidTreeSpecException|PlanResolutionException $e) {
            $this->previewError = $e->getMessage();

            return;
        }

        $nodes = $this->foldPerMemberNodes($plan);
        $quota = $this->executingBackendQuotaDeclarations($plan);

        $rows = [];
        foreach ($nodes as $entry) {
            /** @var PlanNode $node */
            $node = $entry['node'];
            $reconciliation = $report->for($node->path);

            $rows[] = [
                'path' => $node->path,
                'label' => $node->label,
                'nature' => $node->nature->label(),
                'plafond' => $node->plafond === null ? '' : $this->humanBytes($node->plafond),
                'quota_declaration' => $quota[$node->path] ?? null,
                'outcome' => $reconciliation?->outcome->value ?? '',
                'outcome_label' => $reconciliation?->outcome->label() ?? '',
                'detail' => $reconciliation?->detail ?? '',
                'more' => $entry['more'],
                'grants' => array_map(
                    fn (PlanGrant $grant): array => [
                        'label' => $this->audienceLabel($grant->roleKey),
                        'verbs' => PlanStateComparator::accessLabel($grant->verbs),
                        'suspendable' => $grant->suspendable,
                    ],
                    $node->grants,
                ),
            ];
        }

        $this->previewData = [
            'group' => (string) $group->name,
            'root' => $plan->rootPath,
            'nodes' => $rows,
            'traversal' => $this->traversalNotes($plan, $nodes),
        ];
    }

    public function closePreview(): void
    {
        $this->previewData = [];
        $this->previewError = '';
    }

    private function slugKeyOfEdited(): string
    {
        return (string) (DirectoryTemplate::find($this->editId)?->key ?? 'apercu');
    }

    /**
     * Replie les dossiers PAR MEMBRE : un exemplaire, plus le décompte.
     *
     * La résolution énumère réellement les membres — une classe de trois cents
     * élèves produit trois cents nœuds. L'aperçu doit rester lisible ; il ne doit
     * pas pour autant CACHER l'échelle, d'où le décompte.
     *
     * @return list<array{node: PlanNode, more: int}>
     */
    private function foldPerMemberNodes(FilePlan $plan): array
    {
        $rows = [];
        $seen = [];

        foreach ($plan->nodes as $node) {
            if ($node->nature !== PlanNodeNature::ParMembre) {
                $rows[] = ['node' => $node, 'more' => 0];

                continue;
            }

            if (array_key_exists($node->label, $seen)) {
                $rows[$seen[$node->label]]['more']++;

                continue;
            }

            $seen[$node->label] = count($rows);
            $rows[] = ['node' => $node, 'more' => 0];
        }

        return $rows;
    }

    /**
     * La note d'atteignabilité, dérivée de la STRUCTURE DU PLAN — jamais d'un
     * appel au planificateur de traversée.
     *
     * La traversée est un savoir de BACKEND : le plan n'en porte aucune trace, et
     * l'importer ici ferait de la traversée un objet d'écran, exactement ce que la
     * story 62.5 a refusé. Ce qui se lit sur le plan, en revanche, est purement
     * structurel : une audience servie en profondeur et absente des octrois de ses
     * ancêtres passera par un couloir dérivé.
     *
     * @param  list<array{node: PlanNode, more: int}>  $folded
     * @return list<string>
     */
    private function traversalNotes(FilePlan $plan, array $folded): array
    {
        $grantedBy = [];
        foreach ($plan->nodes as $node) {
            foreach ($node->activeGrants() as $grant) {
                $grantedBy[$node->path][$grant->roleKey] = true;
            }
        }

        $declared = array_flip($plan->nodePaths());
        $notes = [];

        foreach ($folded as $entry) {
            $node = $entry['node'];
            if ($node->path === PlanNode::ROOT_PATH) {
                continue;
            }

            $ancestors = [];
            if (array_key_exists(PlanNode::ROOT_PATH, $declared)) {
                $ancestors[] = PlanNode::ROOT_PATH;
            }
            $segments = explode('/', $node->path);
            array_pop($segments);
            $current = '';
            foreach ($segments as $segment) {
                $current = $current === '' ? $segment : $current . '/' . $segment;
                if (array_key_exists($current, $declared)) {
                    $ancestors[] = $current;
                }
            }

            if ($ancestors === []) {
                continue;
            }

            foreach ($node->activeGrants() as $grant) {
                // Le NOMINATIF ne dérive jamais de couloir : son atteignabilité est
                // garantie par la validation de recette, pas par une dérivation.
                if ($grant->roleKey === DirectoryTemplate::TREE_ROLE_MEMBER) {
                    continue;
                }

                $coveredSomewhere = false;
                foreach ($ancestors as $ancestor) {
                    if (isset($grantedBy[$ancestor][$grant->roleKey])) {
                        $coveredSomewhere = true;
                        break;
                    }
                }

                if ($coveredSomewhere) {
                    continue;
                }

                $note = sprintf(
                    '« %s » n\'est servi qu\'en profondeur pour %s : le dossier sera rendu ATTEIGNABLE, le passage '
                    . 'par les dossiers parents étant dérivé automatiquement — il n\'accorde ni lecture ni dépôt '
                    . 'sur eux.',
                    $node->path,
                    '« ' . $this->audienceLabel($grant->roleKey) . ' »',
                );

                if (! in_array($note, $notes, true)) {
                    $notes[] = $note;
                }
            }
        }

        return $notes;
    }

    /**
     * **CE QUE LE BACKEND D'EXÉCUTION DÉCLARE DES PLAFONDS, par nœud.**
     *
     * Second (et dernier) point où l'écran interroge un backend plutôt que de
     * redire une règle. Il compte : le plafond d'un plan de fichiers distant porte
     * sur la ZONE ENTIÈRE, si bien qu'un plafond posé sur un SOUS-nœud y est
     * `non_exprimable` — une limite permanente du modèle, qu'il faut montrer et
     * expliquer, jamais masquer. Le serveur de fichiers historique, lui, déclare
     * autre chose (le mécanisme existe, SE5 ne le pilote pas encore) : c'est une
     * dette, pas une impossibilité, et les deux ne se disent pas pareil.
     *
     * L'écran n'énumère aucune de ces règles : il appelle, et rend le libellé et le
     * détail que le backend produit. Le jour où une arborescence sera servie
     * ailleurs, la déclaration changera sans que le Blade bouge.
     *
     * @return array<string, array{outcome: string, label: string, detail: string, model_limit: bool}>
     */
    private function executingBackendQuotaDeclarations(FilePlan $plan): array
    {
        if ($plan->cappedNodePaths() === []) {
            return [];
        }

        try {
            $report = app(FileBackendRegistry::class)->get($this->executingBackendName())->quota($plan);
        } catch (\Throwable $e) {
            // Dégradation JOURNALISÉE : sans réponse du backend, l'aperçu montre le
            // plafond sans sa déclaration plutôt que de disparaître.
            Log::warning(
                '[Arborescences] Déclaration de plafond illisible — l\'aperçu omet ce que le backend en dit.',
                ['exception' => $e->getMessage()],
            );

            return [];
        }

        $declarations = [];
        foreach ($report->entries as $entry) {
            $declarations[$entry->path] = [
                'outcome' => $entry->outcome->value,
                'label' => $entry->outcome->label(),
                'detail' => (string) ($entry->detail ?? ''),
                'model_limit' => $entry->outcome->isModelLimit(),
            ];
        }

        return $declarations;
    }

    /**
     * L'autorité qui EXÉCUTERA cette recette : celle que l'instance a décidée pour
     * l'espace partagé. C'est la même que lit la matérialisation d'un arbre de
     * classe, et c'est ce qui fait que l'écran annonce les limites de l'autorité
     * qui écrira — pas celles d'une autre.
     *
     * Elle gouverne tout ce que l'écran DÉCLARE : le plafond de l'aperçu comme le
     * grisé des verbes. Une décision illisible retombe sur l'aperçu, qui n'exécute
     * rien et ne contraint donc rien : se taire plutôt qu'annoncer les limites d'un
     * mécanisme au hasard.
     */
    /**
     * L'autorité, résolue UNE FOIS par requête : la matrice l'interroge une fois
     * par verbe et par audience, et la reconstruire à chaque case n'ajouterait rien
     * qu'un coût.
     */
    private ?FileBackend $resolvedBackend = null;

    private function executingBackend(): FileBackend
    {
        return $this->resolvedBackend ??= app(FileBackendRegistry::class)->get($this->executingBackendName());
    }

    private function executingBackendName(): FileBackendName
    {
        try {
            return FileLocationService::current()->espacePartage;
        } catch (\Throwable $e) {
            Log::warning(
                '[Arborescences] Autorité de l\'espace partagé illisible — l\'écran ne déclare aucune limite.',
                ['exception' => $e->getMessage()],
            );

            return FileBackendName::Preview;
        }
    }
};
?>

<x-organisms.page :title="'Arborescence — ' . $this->typeLabel"
    icon="fa-solid fa-folder-tree"
    description="Les dossiers créés pour chaque groupe de ce type, et ce que chaque audience y peut faire."
    :back="$this->backUrl()"
    backText="Retour aux arborescences">

    <x-slot:actions>
        <span class="badge badge-outline badge-lg" data-testid="tree-group-count">
            {{ $this->groupCount }} {{ $this->groupCount > 1 ? 'groupes' : 'groupe' }}
        </span>
        <a href="{{ $this->backUrl() }}" class="btn btn-ghost">Annuler</a>
        <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled"
            wire:target="save" data-testid="save-tree">
            <span wire:loading.remove wire:target="save"><i class="fa-solid fa-floppy-disk"></i> Enregistrer</span>
            <span wire:loading wire:target="save"><span class="loading loading-spinner loading-xs"></span> Enregistrement…</span>
        </button>
    </x-slot:actions>

    <div class="flex flex-col gap-6">

        @error('tree')
            <div class="alert alert-error text-sm" data-testid="tree-error">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>{{ $message }}</span>
            </div>
        @enderror

        @include('pages::admin.settings.groups._partials.tree-editor.identity')
        @include('pages::admin.settings.groups._partials.tree-editor.audiences')
        @include('pages::admin.settings.groups._partials.tree-editor.tree')
        @include('pages::admin.settings.groups._partials.tree-editor.nodes')
        @include('pages::admin.settings.groups._partials.tree-editor.preview')

        @if ($this->armsFutureMaterialization)
            <p class="text-xs text-base-content/60" data-testid="materialization-notice">
                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                Les groupes de ce type créés <strong>ensuite</strong> porteront cette arborescence. Les groupes
                existants ne sont pas touchés par l'enregistrement : leur reprise reste une commande dédiée.
            </p>
        @endif

    </div>
</x-organisms.page>
