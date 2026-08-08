<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Story 62.3 — **aucune vue ne dit plus comment un rôle d'arête se LIT.**
 *
 * C'est la garde structurelle de la bascule code→donnée. Le vocabulaire des rôles
 * est administrable depuis 62.1, ses traductions par type depuis 62.3 : une vue
 * qui écrit « Enseignant » en dur, ou qui fige `member|manager|owner` dans trois
 * `<option>`, annule silencieusement tout le mécanisme — l'administrateur renomme,
 * et l'écran ne bouge pas.
 *
 * Ce n'est pas une crainte théorique. Au moment d'écrire cette story, DEUX sites
 * survivaient à l'inventaire de 62.1, tous deux introuvables par la recherche
 * d'une classe supprimée :
 *  - `admin/shares/index.blade.php` — un `match` d'aperçu d'audience
 *    (`manager => 'encadrants'`, `owner => 'responsables'`, `default => 'membres'`)
 *    qui rendait « membres » n'importe quel rôle personnalisé ;
 *  - `users/groups/[id]/_partials/edit-form.blade.php` — deux `<option>` en dur
 *    (« Élève », « Prof ») proposées quel que soit le type du groupe.
 *
 * **Deux règles, deux formes du même défaut.**
 *
 *  1. **Bras de `match` / entrées de tableau** associant une clé de rôle à une
 *     CHAÎNE LITTÉRALE de la liste des libellés connus. Calibrée sur cette liste
 *     plutôt que sur « toute chaîne » pour ne pas attraper les associations
 *     légitimes qui ne sont pas des libellés (une classe CSS, un jeton
 *     technique) ;
 *  2. **`<option value="member|manager|owner">`** — quel que soit son contenu.
 *     Celle-ci est délibérément plus stricte : après 62.3, l'inventaire d'un
 *     select de rôle vient de `RoleCatalog::assignableKeys()`, jamais d'une liste
 *     écrite à la main. Une `<option>` à valeur figée, même avec un libellé
 *     dynamique, rend un rôle du catalogue INATTRIBUABLE à l'écran — c'était
 *     exactement l'état de `members-table` avant cette story.
 *
 * Style : `PHPUnit\Framework\TestCase` PUR, scan TEXTUEL de fichiers — patron de
 * {@see PlanNamespaceIsolationTest}. Et comme lui, chaque règle est adossée à un
 * MÉTA-TEST : un scan qui ne détecte rien parce qu'il ne regarde rien passerait
 * sinon éternellement au vert.
 *
 * **Ce qu'elle n'attrape PAS, et c'est voulu** — les faux positifs recensés :
 *  - `components/molecules/wallpaper-card.blade.php` : `'owner' => $this->ownerType`
 *    n'est pas un libellé, c'est une clé de payload ;
 *  - les `match` de STYLE sur les clés de TYPE (`typeBadgeClass()`) : autre
 *    vocabulaire, autre catalogue ;
 *  - les `match` sur le rôle GLOBAL `users.role` (`prof|eleve|admin|autre` —
 *    `user-header`, `profile-form`, filtres de `users-table`) : ce vocabulaire-là
 *    n'est PAS celui de l'arête et sort du périmètre de l'epic ;
 *  - les textes statiques légitimes (`<th>Professeur principal</th>` de la section
 *    professeur principal, titres, commentaires Blade) : ils ne traduisent aucune
 *    clé, ils nomment une colonne.
 */
class NoEdgeRoleLabelLiteralsInViewsTest extends TestCase
{
    private const VIEWS_DIR = 'resources/views';

    /**
     * Les clés d'arête dont la traduction est désormais de la DONNÉE.
     *
     * Le plancher historique suffit : ce sont les seules qu'une vue écrite avant
     * 62.1 pouvait connaître, et un rôle créé au catalogue n'a par construction
     * jamais été écrit en dur nulle part.
     *
     * @var list<string>
     */
    private const EDGE_ROLE_KEYS = ['member', 'manager', 'owner'];

    /**
     * Les libellés qui ont RÉELLEMENT été écrits en dur dans ce dépôt, plus ceux
     * du catalogue livré.
     *
     * @var list<string>
     */
    private const FORBIDDEN_LABELS = [
        'Élève',
        'Enseignant',
        'Professeur principal',
        'Porteur',
        'Référent',
        'Membre',
        'Gestionnaire',
        'Propriétaire',
        'Prof',
        'encadrants',
        'responsables',
        'membres',
    ];

    #[Test]
    public function no_view_maps_an_edge_role_key_to_a_hardcoded_label(): void
    {
        $violations = [];

        foreach ($this->viewFiles() as $path => $contents) {
            foreach ($this->matchArmViolations($contents) as $violation) {
                $violations[] = $path . ' → ' . $violation;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Une vue traduit une clé de rôle d'arête en littéral. Le libellé est de la DONNÉE depuis la "
            . "story 62.3 : passez par RoleCatalog::label(\$groupType, \$roleKey).\n"
            . implode("\n", $violations),
        );
    }

    #[Test]
    public function no_view_freezes_the_inventory_of_a_role_select(): void
    {
        $violations = [];

        foreach ($this->viewFiles() as $path => $contents) {
            foreach ($this->hardcodedOptionViolations($contents) as $violation) {
                $violations[] = $path . ' → ' . $violation;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Une vue fige l'inventaire d'un select de rôle. Les options viennent de "
            . "RoleCatalog::assignableKeys(\$groupType) depuis la story 62.3 — sans quoi un rôle créé au "
            . "catalogue reste inattribuable à l'écran.\n"
            . implode("\n", $violations),
        );
    }

    // =========================================================================
    // MÉTA-TESTS — la garde détecte-t-elle ce qu'elle prétend détecter ?
    // =========================================================================

    /**
     * Les DEUX sites réellement supprimés par la story, réinjectés tels quels.
     *
     * Si l'un d'eux cesse d'être détecté, la garde est devenue décorative.
     */
    #[Test]
    public function the_guard_catches_the_two_real_sites_this_story_removed(): void
    {
        // `admin/shares/index.blade.php`, aperçu d'audience, avant 62.3.
        $sharesMatch = <<<'BLADE'
            implode(', ', array_map(
                static fn (string $edgeRole): string => match ($edgeRole) {
                    'manager' => 'encadrants',
                    'owner' => 'responsables',
                    default => 'membres',
                },
                $resolution['edge_roles'],
            ))
            BLADE;

        $this->assertNotEmpty(
            $this->matchArmViolations($sharesMatch),
            'le match d\'aperçu d\'audience doit être détecté',
        );

        // `edit-form.blade.php`, select du rattachement, avant 62.3.
        $editFormOptions = <<<'BLADE'
            <option value="member" @selected($currentPendingRole === 'member')>Élève</option>
            <option value="manager" @selected($currentPendingRole === 'manager')>Prof</option>
            BLADE;

        $this->assertNotEmpty(
            $this->hardcodedOptionViolations($editFormOptions),
            'les options en dur du rattachement doivent être détectées',
        );

        // `members-table.blade.php`, inventaire figé à libellé dynamique : la
        // seconde règle l'attrape, la première non — c'est bien la raison d'être
        // des deux.
        $frozenInventory = '<option value="owner" @selected($m[\'edge_role\'] === \'owner\')>{{ $o[\'owner\'] }}</option>';
        $this->assertSame([], $this->matchArmViolations($frozenInventory));
        $this->assertNotEmpty($this->hardcodedOptionViolations($frozenInventory));
    }

    /** Les faux positifs recensés ne doivent JAMAIS être signalés. */
    #[Test]
    public function the_guard_spares_the_recorded_false_positives(): void
    {
        $cases = [
            // wallpaper-card : clé de payload, pas un libellé.
            "'owner' => \$this->ownerType . ':' . \$this->ownerId,",
            // `users.role` global : autre vocabulaire, hors périmètre.
            "match (\$user->role) { 'prof' => 'Enseignant', 'eleve' => 'Élève', default => 'Autre' }",
            // clés de TYPE de groupe, pas de rôle.
            "match (\$type) { 'classe' => 'badge-primary', 'projet' => 'badge-info' }",
            // texte statique légitime.
            '<th>Professeur principal</th>',
            // libellé lu de la donnée : la forme ATTENDUE après 62.3.
            '<option value="{{ $role }}">{{ $edgeRoleOptions[$role] }}</option>',
        ];

        foreach ($cases as $case) {
            $this->assertSame([], $this->matchArmViolations($case), 'faux positif (règle 1) : ' . $case);
            $this->assertSame([], $this->hardcodedOptionViolations($case), 'faux positif (règle 2) : ' . $case);
        }
    }

    /** Le scan lit-il vraiment des fichiers ? */
    #[Test]
    public function the_scan_actually_reads_the_view_tree(): void
    {
        $files = $this->viewFiles();

        $this->assertGreaterThan(200, count($files), 'le scan ne parcourt presque aucune vue');
        $this->assertArrayHasKey(
            'pages/users/groups/[id]/_partials/members-table.blade.php',
            $files,
            'la vue la plus concernée doit être dans le périmètre du scan',
        );
    }

    // =========================================================================
    // Détection
    // =========================================================================

    /**
     * Règle 1 — `'member'|'manager'|'owner' => '<libellé interdit>'`.
     *
     * @return list<string>
     */
    private function matchArmViolations(string $contents): array
    {
        $keys = implode('|', self::EDGE_ROLE_KEYS);
        $violations = [];

        // Clé quotée, `=>`, valeur quotée. La valeur est capturée pour être
        // confrontée à la liste des libellés — c'est ce qui rend la règle
        // calibrée plutôt que bavarde.
        $pattern = '/([\'"])(' . $keys . ')\1\s*=>\s*([\'"])([^\'"]*)\3/u';

        if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        foreach ($matches as $match) {
            $role = $match[2];
            $value = $match[4];

            foreach (self::FORBIDDEN_LABELS as $label) {
                if (mb_strtolower(trim($value)) === mb_strtolower($label)) {
                    $violations[] = sprintf('« %s » => « %s »', $role, $value);
                    break;
                }
            }
        }

        return $violations;
    }

    /**
     * Règle 2 — `<option value="member|manager|owner">`, quel que soit le contenu.
     *
     * @return list<string>
     */
    private function hardcodedOptionViolations(string $contents): array
    {
        $keys = implode('|', self::EDGE_ROLE_KEYS);
        $pattern = '/<option\s[^>]*value\s*=\s*([\'"])(' . $keys . ')\1/u';

        if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        return array_map(
            static fn (array $match): string => sprintf('<option value="%s">', $match[2]),
            $matches,
        );
    }

    /**
     * Toutes les vues Blade, indexées par chemin relatif à `resources/views`.
     *
     * @return array<string, string>
     */
    private function viewFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/' . self::VIEWS_DIR;

        $finder = (new Finder())->files()->in($root)->name('*.blade.php');

        $files = [];
        foreach ($finder as $file) {
            $files[$file->getRelativePathname()] = (string) $file->getContents();
        }

        return $files;
    }
}
