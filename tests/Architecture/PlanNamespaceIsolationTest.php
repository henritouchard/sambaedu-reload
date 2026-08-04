<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Story 60.1 — **la coupe passe AVANT la dérivation des permissions, et c'est
 * STRUCTUREL.**
 *
 * Le service qui dérive les permissions concrètes d'un répertoire réseau existe
 * déjà, trois fichiers plus loin. C'est le piège le plus probable de tout l'epic :
 * il suffit d'un `use` pour qu'il remonte au-dessus de la ligne, et le plan cesse
 * alors d'être portable — il devient une description POSIX déguisée, que le
 * premier backend étranger contredira.
 *
 * Le test de garde jumeau ({@see \Tests\Unit\Services\Filesystem\Plan\PlanNeutralityGuardTest})
 * constate la neutralité sur la SORTIE. Celui-ci la verrouille sur les IMPORTS.
 * Les deux sont nécessaires : un plan peut sortir neutre aujourd'hui tout en
 * dépendant déjà de ce qu'il ne devrait pas connaître.
 *
 * Style : `PHPUnit\Framework\TestCase` PUR, scan de fichiers — patron des tests
 * d'architecture existants. Le scan est TEXTUEL et porte aussi sur les
 * commentaires : c'est volontaire. Un docblock qui cite une classe interdite
 * finit toujours par être copié en `use`, et décrire un voisin dont on n'a pas le
 * droit de dépendre par sa fonction plutôt que par son nom est de toute façon la
 * bonne façon d'en parler.
 *
 * Chaque règle est adossée à un MÉTA-TEST : un scan qui ne détecte rien parce
 * qu'il ne regarde rien passerait sinon éternellement au vert.
 *
 * **Ce que cette garde attrape, et ce qu'elle n'attrape pas.** Elle attrape le cas
 * qui arrive vraiment : un `use` ajouté par commodité, un appel écrit au fil de la
 * plume, un nom cité dans un commentaire qui prépare le terrain. Elle N'attrape PAS
 * un nom construit à l'exécution (`'Share' . 'Service'` passé à un conteneur) :
 * aucune sous-chaîne contiguë n'apparaît alors dans le fichier. Il faudrait pour
 * cela une analyse syntaxique, dont le coût n'est pas justifié — franchir la ligne
 * de cette façon suppose une intention, et une intention se voit en revue. La
 * garde est donc structurelle CONTRE L'ÉTOURDERIE, pas contre la volonté ; c'est
 * la propriété utile, et il vaut mieux l'énoncer que la laisser croire plus forte
 * qu'elle n'est.
 */
class PlanNamespaceIsolationTest extends TestCase
{
    /**
     * Le namespace sous garde.
     */
    private const PLAN_NAMESPACE_DIR = 'app/Services/Filesystem/Plan';

    /**
     * Frontières du namespace du plan. `étiquette => motif`.
     *
     * @var array<string, string>
     */
    private const FORBIDDEN_RULES = [
        // 1. Les services d'EXÉCUTION — la ligne de coupe elle-même.
        'service du partage de classes' => '/\bShareService\b/',
        'service de listes d\'accès' => '/\bAclService\b/',
        'service de provisionnement des répertoires réseau' => '/\bNetworkShareService\b/',

        // 2. Tout ce qui exécute.
        'exécution de processus (façade)' => '/(?<!\w)Process::|Illuminate\\\\Support\\\\Facades\\\\Process\b|Symfony\\\\Component\\\\Process\b/',
        'exécution système (fonctions)' => '/\b(shell_exec|passthru|proc_open|popen|system|exec)\s*\(/',
        'commandes de système de fichiers' => '/\b(setfacl|getfacl|chown|chgrp|sudo)\b/',
        'accès au système de fichiers' => '/\b(file_get_contents|file_put_contents|fopen|mkdir|rmdir|unlink|scandir|glob|realpath|is_dir|is_file)\s*\(/',

        // 3. Tout ce qui interroge — la résolution est PURE.
        'base de données (façade)' => '/(?<!\w)DB::|Illuminate\\\\Support\\\\Facades\\\\DB\b/',
        'requête Eloquent' => '/::(query|where|find|first|all)\s*\(/',
        'accès réseau' => '/(?<!\w)Http::|\bcurl_(init|exec|setopt)\s*\(/',

        // 4. Les modèles d'identité — un plan ne connaît que des identités
        //    internes ; charger un utilisateur ou un groupe serait à la fois une
        //    requête cachée et une invitation à écrire un login comme sujet.
        'modèle utilisateur' => '/App\\\\Models\\\\User\b/',
        'modèle groupe d\'utilisateurs' => '/App\\\\Models\\\\UserGroup\b/',
    ];

    /**
     * Formes qu'un contributeur pressé écrirait, et que le scan DOIT voir.
     *
     * @var array<string, string>
     */
    private const NEEDLES = [
        'service du partage de classes' => 'use App\\Services\\Filesystem\\ShareService;',
        'service de listes d\'accès' => 'use App\\Services\\Filesystem\\AclService;',
        'service de provisionnement des répertoires réseau' => 'private NetworkShareService $shares;',
        'exécution de processus (façade)' => '$r = Process::run("setfacl -R");',
        'exécution système (fonctions)' => '$out = shell_exec("id -u");',
        'commandes de système de fichiers' => '// on posera un setfacl plus tard',
        'accès au système de fichiers' => 'if (is_dir($path)) { return true; }',
        'base de données (façade)' => '$rows = DB::table("user_groups")->get();',
        'requête Eloquent' => '$g = UserGroup::find($id);',
        'accès réseau' => '$r = Http::get("https://exemple");',
        'modèle utilisateur' => 'use App\\Models\\User;',
        'modèle groupe d\'utilisateurs' => 'use App\\Models\\UserGroup;',
    ];

    private static function repoPath(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . ltrim($relative, '/');
    }

    /** @return list<string> étiquettes des règles violées */
    private function violations(string $content): array
    {
        $violations = [];

        foreach (self::FORBIDDEN_RULES as $label => $pattern) {
            if (preg_match($pattern, $content) === 1) {
                $violations[] = $label;
            }
        }

        return $violations;
    }

    #[Test]
    public function the_plan_namespace_depends_on_nothing_that_executes_or_queries(): void
    {
        $dir = realpath(self::repoPath(self::PLAN_NAMESPACE_DIR));
        self::assertNotFalse($dir, self::PLAN_NAMESPACE_DIR . ' doit exister');

        $inspected = 0;
        $offenders = [];

        foreach ((new Finder())->files()->in($dir)->name('*.php') as $file) {
            $inspected++;

            $found = $this->violations((string) $file->getContents());
            if ($found !== []) {
                $offenders[$file->getRelativePathname()] = $found;
            }
        }

        // Méta-test : sans lui, un namespace renommé ferait passer la boucle à
        // vide, indéfiniment.
        self::assertGreaterThanOrEqual(
            6,
            $inspected,
            'la garde doit inspecter le namespace RÉEL du plan (motif, nœuds, octrois, sujets, contexte, résolveur)',
        );

        self::assertSame(
            [],
            $offenders,
            "LIGNE DE COUPE FRANCHIE. Le namespace du plan ne doit connaître AUCUN service d'exécution, "
            . "aucune requête et aucun modèle d'identité — la traduction vers un plan de fichiers concret "
            . 'appartient au contrat de backend (story 60.3), APRÈS cette ligne. Fichiers fautifs : '
            . json_encode($offenders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    #[Test]
    public function the_scanner_actually_detects_each_forbidden_form(): void
    {
        self::assertSame(
            array_keys(self::FORBIDDEN_RULES),
            array_keys(self::NEEDLES),
            'chaque règle doit avoir son aiguille de vérification',
        );

        foreach (self::NEEDLES as $label => $needle) {
            self::assertContains(
                $label,
                $this->violations($needle),
                sprintf('la règle « %s » ne détecte pas son aiguille : le scan est aveugle', $label),
            );
        }

        // Contrôle POSITIF inverse : du code honnête du plan ne déclenche rien.
        self::assertSame(
            [],
            $this->violations(
                '<?php namespace App\Services\Filesystem\Plan; '
                . 'use App\Models\DirectoryTemplate; '
                . 'final class X { public function f(string $p): bool { return preg_match("/a/", $p) === 1; } }'
            ),
        );
    }

    /**
     * La recette elle-même reste importable : elle est la DONNÉE d'entrée du
     * résolveur, pas un service d'exécution. Ce test empêche qu'on « durcisse »
     * la garde jusqu'à rendre le résolveur incapable de lire sa propre recette.
     */
    #[Test]
    public function the_recipe_model_stays_importable_by_design(): void
    {
        self::assertSame([], $this->violations('use App\Models\DirectoryTemplate;'));
    }
}
