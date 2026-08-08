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
     * Story 60.2 — l'ASSEMBLEUR, qui vit HORS du namespace pur.
     *
     * Il requête Eloquent — c'est sa raison d'être, et c'est pour cela qu'il n'est
     * pas dans le namespace du plan : la pureté du résolveur est ce qui rend ses
     * tests rapides et sa sortie rejouable, et on ne la dilue pas. Mais le sortir
     * du namespace sans rien à la place rouvrirait exactement le chemin que la
     * story 60.1 a fermé : celui qui va chercher la dérivation des noms de groupes
     * système « pour la réutiliser ». La ligne de coupe passe donc AUSSI par ici,
     * avec un sous-ensemble de règles : pas de service d'exécution, pas de
     * processus, pas de commande système.
     */
    private const ASSEMBLER_FILE = 'app/Services/Filesystem/TreePlanService.php';

    /**
     * Story 60.3 — LA LIGNE DE CONTRAT elle-même, et ce qui la borde.
     *
     * L'interface, les DTO de rapport et le backend qui n'exécute rien vivent
     * AU-DESSUS de la ligne : ils décrivent ce qu'un backend fait, ils ne le font
     * pas. Ils tombent donc sous les règles PURES — les mêmes que le namespace du
     * plan, plus une règle nommée sur les abstractions de fichiers du framework
     * (voir {@see FORBIDDEN_RULES}).
     *
     * Le double propagateur et le squelette jetable ne sont PAS ici : ce sont des
     * doubles de test, ils vivent sous `tests/`, et rien du contrat ne dépend
     * d'eux.
     *
     * @var string
     */
    private const CONTRACT_PURE_DIR = 'app/Services/Filesystem/Backend';

    /**
     * Le registre vit dans le même dossier mais N'EST PAS pur : il lit une
     * colonne. Il est scanné plus bas, avec les règles d'assembleur. L'exclure
     * ici plutôt que de relâcher les règles pures est ce qui évite d'affaiblir la
     * garde pour tout le dossier.
     */
    private const CONTRACT_PURE_EXCLUDED = 'FileBackendRegistry.php';

    /**
     * Story 60.4 → 61.3 — les sous-dossiers des IMPLÉMENTATIONS, qui vivent SOUS la
     * ligne.
     *
     * Un backend exécute : c'est sa fonction. Il a donc tout le vocabulaire concret
     * sous la main, et le scanner avec les règles pures reviendrait à interdire au
     * seul composant qui doit écrire de savoir écrire. On les exclut par leur
     * DOSSIER, pas en relâchant les règles pour tout le contrat — ce qui aurait
     * affaibli la garde là où elle compte.
     *
     * **La liste s'allonge d'une entrée à chaque backend réel, et c'est la seule
     * retouche que l'arrivée d'un backend impose ici.** Le second (story 61.3) parle
     * à une instance distante : il interroge la base pour ses identités et sort en
     * HTTP, deux choses que le contrat pur s'interdit et qu'aucun backend ne peut
     * s'interdire. Ses PROPRES gardes vivent dans le test de son namespace (aucun
     * shell-out, aucun partage, aucun claim de fédération, frontière des deux
     * plafonds).
     *
     * @var list<string>
     */
    private const CONTRACT_IMPLEMENTATION_DIR = ['Posix', 'Nextcloud'];

    /**
     * Story 60.3 — l'ASSEMBLEUR de plan de partage plat et le REGISTRE.
     *
     * Ces deux-là requêtent (l'un lit un pivot, l'autre lit une colonne et
     * demande au conteneur) : c'est leur raison d'être, et c'est pourquoi ils
     * vivent hors du namespace pur. Mais ils sont aussi le chemin le plus court
     * vers la dérivation des noms système « pour la réutiliser » — la ligne de
     * coupe passe donc aussi par eux, avec le sous-ensemble de règles qui porte
     * sur l'EXÉCUTION.
     *
     * @var list<string>
     */
    private const CONTRACT_ASSEMBLER_FILES = [
        'app/Services/Filesystem/SharePlanProjector.php',
        'app/Services/Filesystem/Backend/FileBackendRegistry.php',
    ];

    /**
     * Règles applicables à l'assembleur : celles qui portent sur l'EXÉCUTION.
     * Les règles de requête et de modèle d'identité en sont volontairement
     * absentes — l'assembleur requête, c'est son travail.
     *
     * @var list<string>
     */
    private const ASSEMBLER_RULES = [
        'service du partage de classes',
        'service de listes d\'accès',
        'service de provisionnement des répertoires réseau',
        'exécution de processus (façade)',
        'exécution système (fonctions)',
        'commandes de système de fichiers',
        // L'assembleur revendique « SQL seulement, JAMAIS L'ANNUAIRE » et « il ne
        // touche à aucun fichier ». Ces deux règles-là sont ce qui rend la phrase
        // vraie : sans elles, un appel réseau vers l'annuaire ou une lecture de
        // fichier ajoutés « pour dépanner » passeraient, pendant que le docblock
        // continuerait d'affirmer que c'est structurellement impossible. Une
        // garantie qui ne vit que dans le commentaire est la signature de défaut
        // que cet epic rencontre le plus souvent.
        'accès réseau',
        'accès au système de fichiers',
        // Story 60.3 — le faux ami. Il abstrait les OPÉRATIONS sur les fichiers,
        // pas les permissions ; et SE5 ne crée aucun fichier. S'y brancher
        // donnerait une dépendance inutile et une fausse impression de
        // portabilité, exactement là où la portabilité doit être vraie.
        'abstraction de fichiers du framework',
    ];

    // NB : « requête Eloquent », la façade de base de données et les deux modèles
    // d'identité restent HORS de cette liste — interroger SQL et charger un groupe
    // sont précisément le travail de l'assembleur. La revendication « SQL
    // seulement » n'interdit pas la base : elle interdit l'ANNUAIRE, qui se prend
    // par le réseau. Les y ajouter viderait le fichier de sa raison d'être.

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

        // 2bis. Story 60.3 — LE FAUX AMI, nommé. L'abstraction de fichiers du
        //       framework est la « réutilisation de l'existant » la plus tentante
        //       du chantier, et la plus fausse : elle couvre lire/écrire/lister,
        //       jamais les permissions — or c'est la partie difficile, et c'est
        //       la seule que SE5 provisionne. Un contrat qui s'y brancherait
        //       hériterait de sa portabilité apparente sans en tirer sa propriété.
        'abstraction de fichiers du framework' => '/(?<!\w)Storage::|Illuminate\\\\Support\\\\Facades\\\\Storage\b|Illuminate\\\\Contracts\\\\Filesystem\b|\bFlysystem\b/',

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
        'abstraction de fichiers du framework' => '$disk = Storage::disk("shares");',
        'base de données (façade)' => '$rows = DB::table("user_groups")->get();',
        'requête Eloquent' => '$g = UserGroup::find($id);',
        'accès réseau' => '$r = Http::get("https://exemple");',
        'modèle utilisateur' => 'use App\\Models\\User;',
        'modèle groupe d\'utilisateurs' => 'use App\\Models\\UserGroup;',
    ];

    // =========================================================================
    // Story 60.4 — LA COUPE PASSE AVANT LA DÉRIVATION DES PERMISSIONS
    // =========================================================================

    /**
     * Le dossier des services de fichiers, scanné à PLAT (profondeur 0).
     *
     * Les sous-dossiers en sont volontairement absents : `Acl/` porte le savoir de
     * format des listes d'accès, `Backend/Posix/` porte l'exécution. Ce sont les
     * DEUX ZONES AUTORISÉES — la coupe ne les traverse pas, elle les délimite.
     */
    private const ORCHESTRATOR_DIR = 'app/Services/Filesystem';

    /**
     * Le dossier du CONTRAT (`depth(0)` : les implémentations vivent dans ses
     * sous-dossiers et sont, elles, sous la ligne).
     */
    private const CONTRACT_DIR = 'app/Services/Filesystem/Backend';

    /**
     * Fichiers EXCLUS NOMMÉMENT du scan, avec la date de leur sort.
     *
     * Ce ne sont pas des trous dans la garde : ce sont des chemins figés, dont
     * chacun a une échéance écrite. Les nommer un par un est ce qui rend leur
     * disparition constatable — une exclusion par motif les aurait rendus
     * invisibles, et le jour où l'un d'eux meurt, personne ne l'aurait remarqué.
     *
     *  - `ShareService` — chemin figé du partage de classe depuis la story 5.2.
     *    **Il VIT, et son sort n'est plus celui qu'annonçait la story 60.4.** La
     *    story 60.5 a tranché contre l'écrasement de l'arbre historique : SE5 écrit
     *    désormais ses arbres de classe dans une racine NEUVE, les deux arbres
     *    COEXISTENT, et celui-ci reste le seul réellement servi aux établissements.
     *    Le supprimer aujourd'hui couperait le chemin qui alimente cet arbre-là.
     *    Son extinction appartient à la story de MIGRATION (bascule de l'arbre
     *    servi, rapatriement délibéré des données, descente de la dérivation des
     *    noms), qui n'a pas de calendrier promis. Zéro diff exigé par la 60.5.
     *  - `AclService` — garde de chemin et pose de droits de la baseline 5.2, même
     *    vie, même sort, même story de migration. Zéro diff exigé.
     *  - `HomeDirService` — répertoires personnels, hors du périmètre de l'epic 60
     *    (le plan de fichiers ne les gouverne pas encore).
     *  - `XfsQuotaService` — plafonds de zone. La story qui les brancherait au plan
     *    est SUSPENDUE (décision Q-D, 2026-08-04) ; d'ici là ce service reste le
     *    seul pilote des plafonds, hors de la ligne.
     *
     * **`DirectoryTemplateService` n'a PAS besoin d'exclusion**, et c'est une
     * information : la story 60.4 lui demandait de rester en base seule et de
     * déléguer. Il est donc SCANNÉ comme les autres, et il passe. Une exclusion
     * creuse aurait affirmé le contraire.
     *
     * @var list<string>
     */
    private const ORCHESTRATOR_LEGACY_EXCLUSIONS = [
        'ShareService.php',
        'AclService.php',
        'HomeDirService.php',
        'XfsQuotaService.php',
    ];

    /**
     * Fichiers HORS de ce dossier qui vivent quand même au-dessus de la ligne et
     * doivent donc être tenus par les mêmes règles.
     *
     * @var list<string>
     */
    private const ABOVE_THE_LINE_FILES = [
        'app/Jobs/ReconcileNetworkShareJob.php',
    ];

    /**
     * Les marqueurs du SERVEUR DE FICHIERS, interdits au-dessus de la ligne.
     *
     * Elles complètent les règles de pureté : celles-ci interdisaient les services
     * d'exécution PAR LEUR NOM de classe ; celles-là interdisent le VOCABULAIRE.
     * La descente de la story 60.4 aurait pu être cosmétique — déplacer la
     * dérivation des permissions dans un fichier neuf en laissant ses appelants
     * la manipuler au-dessus — et aucune règle existante ne l'aurait vu.
     *
     * @var array<string, string>
     */
    private const POSIX_MARKER_RULES = [
        'mode de permission' => '/(?<![\w-])(rwx|r-x|rw-)(?![\w-])/',
        'entrée de liste d\'accès' => '/(?<!\w)(default|user|group|mask|other)::/',
        'sujet nommé d\'une liste d\'accès' => '/(?<!\w)(user|group):[A-Za-z0-9_\\\\]/',
        'commandes de listes d\'accès' => '/\b(setfacl|getfacl)\b/',
        'commandes de propriété et de mode' => '/\b(chown|chgrp|chmod)\b/',
        'résolution de nom système' => '/\bgetent\b/',
        'élévation de privilège' => '/\bsudo\b/',
        'exécution de processus' => '/(?<!\w)Process::|Illuminate\\\\Support\\\\Facades\\\\Process\b/',
        // Sans exclusion sur les guillemets : un chemin codé en dur est
        // PRÉCISÉMENT une chaîne littérale, et l'exclure aurait rendu la règle
        // aveugle au seul cas qui arrive vraiment.
        'chemin absolu' => '#(?<![\w.])/(var|etc|srv|home|usr|opt)/#',
        'groupe d\'administration d\'annuaire' => '/domain(\\\\\\\\040|\s)admins/i',
        'nom d\'exécution du serveur applicatif' => '/\bwww-admin\b/',
    ];

    /**
     * Formes que le scan DOIT voir. Une règle nouvelle est le premier candidat à
     * l'aveuglement : elle passerait éternellement au vert sur des fichiers qui
     * n'ont jamais eu l'occasion de la violer.
     *
     * @var array<string, string>
     */
    private const POSIX_NEEDLES = [
        'mode de permission' => '$acls[] = "user:{$login}:rwx";',
        'entrée de liste d\'accès' => "\$base = ['user::rwx', 'mask::rwx'];",
        'sujet nommé d\'une liste d\'accès' => '$line = "group:classe_3sb:rx";',
        'commandes de listes d\'accès' => 'Process::run("setfacl -b " . $p);',
        'commandes de propriété et de mode' => '// puis un chown www-data',
        'résolution de nom système' => '$ok = Process::run("getent group x")->successful();',
        'élévation de privilège' => '$cmd = "sudo mkdir -p " . $path;',
        'exécution de processus' => '$r = Process::run($cmd);',
        'chemin absolu' => 'public static string $root = \'/var/sambaedu/Partages\';',
        'groupe d\'administration d\'annuaire' => '$acl = "group:domain\\\\040admins:rwx";',
        'nom d\'exécution du serveur applicatif' => 'sprintf("chown www-admin %s", $p)',
    ];

    private static function repoPath(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . ltrim($relative, '/');
    }

    /** @return list<string> étiquettes des règles POSIX violées */
    private function posixViolations(string $content): array
    {
        $violations = [];

        foreach (self::POSIX_MARKER_RULES as $label => $pattern) {
            if (preg_match($pattern, $content) === 1) {
                $violations[] = $label;
            }
        }

        return $violations;
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
     * Story 60.2 — l'assembleur requête, mais il n'exécute rien.
     */
    #[Test]
    public function the_assembler_queries_but_never_executes(): void
    {
        $path = self::repoPath(self::ASSEMBLER_FILE);
        self::assertFileExists($path, 'l\'assembleur doit exister là où la garde le cherche');

        $content = (string) file_get_contents($path);

        $found = array_values(array_intersect($this->violations($content), self::ASSEMBLER_RULES));

        self::assertSame(
            [],
            $found,
            'LIGNE DE COUPE FRANCHIE PAR L\'ASSEMBLEUR. Il assemble des identités internes depuis SQL et '
            . 'délègue au résolveur pur ; la dérivation des noms de groupes système et la pose des '
            . 'permissions appartiennent au contrat de backend, APRÈS cette ligne. Règles violées : '
            . json_encode($found, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        // Méta-test : la garde de l'assembleur doit inspecter un fichier qui a
        // réellement le contenu attendu — un fichier vide passerait sinon.
        self::assertStringContainsString(
            'class TreePlanService',
            $content,
            'la garde doit inspecter l\'assembleur RÉEL',
        );
    }

    /**
     * Story 60.2 — chaque règle appliquée à l'assembleur voit son aiguille. Sans
     * ce contrôle, une étiquette mal orthographiée dans la liste ci-dessus
     * rendrait la garde de l'assembleur silencieusement vide.
     */
    #[Test]
    public function the_assembler_rules_are_real_rules_with_working_needles(): void
    {
        foreach (self::ASSEMBLER_RULES as $label) {
            self::assertArrayHasKey($label, self::FORBIDDEN_RULES, 'règle inconnue : ' . $label);
            self::assertContains(
                $label,
                $this->violations(self::NEEDLES[$label]),
                sprintf('la règle « %s » ne détecte pas son aiguille', $label),
            );
        }

        // Et les règles VOLONTAIREMENT absentes le sont : l'assembleur a le droit
        // de requêter et de connaître les modèles d'identité.
        foreach (['requête Eloquent', 'base de données (façade)', 'modèle groupe d\'utilisateurs'] as $allowed) {
            self::assertNotContains($allowed, self::ASSEMBLER_RULES);
        }
    }

    /**
     * Story 60.3 — LA LIGNE DE CONTRAT est du même côté que le plan.
     *
     * L'interface, les DTO de rapport et le backend qui n'exécute rien DÉCRIVENT
     * ce qu'un backend fait ; ils ne le font pas. Le jour où l'un d'eux importe un
     * service d'exécution, la ligne n'existe plus : le contrat serait devenu le
     * premier backend déguisé, et le suivant devrait s'y conformer.
     */
    #[Test]
    public function the_backend_contract_depends_on_nothing_that_executes_or_queries(): void
    {
        $dir = realpath(self::repoPath(self::CONTRACT_PURE_DIR));
        self::assertNotFalse($dir, self::CONTRACT_PURE_DIR . ' doit exister');

        $inspected = 0;
        $offenders = [];

        $finder = (new Finder())
            ->files()
            ->in($dir)
            ->exclude(self::CONTRACT_IMPLEMENTATION_DIR)
            ->name('*.php')
            ->notName(self::CONTRACT_PURE_EXCLUDED);

        foreach ($finder as $file) {
            $inspected++;

            $found = $this->violations((string) $file->getContents());
            if ($found !== []) {
                $offenders[$file->getRelativePathname()] = $found;
            }
        }

        // Méta-test : l'interface, les cinq DTO et le backend d'aperçu.
        self::assertGreaterThanOrEqual(
            7,
            $inspected,
            'la garde doit inspecter le namespace RÉEL du contrat (interface, DTO de rapport, backend d\'aperçu)',
        );

        self::assertSame(
            [],
            $offenders,
            'LIGNE DE COUPE FRANCHIE PAR LE CONTRAT. Un contrat qui connaît un service d\'exécution, un '
            . 'processus ou une abstraction de fichiers n\'est plus un contrat : c\'est le premier backend, '
            . 'déguisé. Fichiers fautifs : '
            . json_encode($offenders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Story 60.3 — le PROJECTEUR et le REGISTRE requêtent, mais n'exécutent rien.
     */
    #[Test]
    public function the_contract_assemblers_query_but_never_execute(): void
    {
        foreach (self::CONTRACT_ASSEMBLER_FILES as $relative) {
            $path = self::repoPath($relative);
            self::assertFileExists($path, 'l\'assembleur doit exister là où la garde le cherche : ' . $relative);

            $content = (string) file_get_contents($path);
            $found = array_values(array_intersect($this->violations($content), self::ASSEMBLER_RULES));

            self::assertSame(
                [],
                $found,
                sprintf(
                    'LIGNE DE COUPE FRANCHIE PAR « %s ». Il assemble depuis SQL et délègue ; la dérivation '
                    . 'des noms système et la pose des permissions appartiennent à l\'implémentation de '
                    . 'backend, APRÈS cette ligne. Règles violées : %s',
                    $relative,
                    json_encode($found, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ),
            );

            // Méta-test : la garde doit inspecter un fichier qui a réellement le
            // contenu attendu — un fichier vide passerait sinon.
            self::assertStringContainsString('final class', $content, 'la garde doit inspecter le fichier RÉEL');
        }
    }

    /**
     * Story 60.3 — MÉTA-TEST de la règle NOMMÉE du faux ami.
     *
     * Elle est nouvelle, donc elle est le premier candidat à l'aveuglement : une
     * règle mal écrite passerait éternellement au vert sur un dossier qui n'a
     * jamais eu l'occasion de la violer.
     */
    #[Test]
    public function the_framework_filesystem_rule_sees_the_forms_it_claims_to_forbid(): void
    {
        $label = 'abstraction de fichiers du framework';

        foreach ([
            '$disk = Storage::disk("shares");',
            'use Illuminate\\Support\\Facades\\Storage;',
            'use Illuminate\\Contracts\\Filesystem\\Filesystem;',
            'use League\\Flysystem\\FilesystemOperator;',
            '// on pourrait passer par Flysystem plus tard',
        ] as $needle) {
            self::assertContains(
                $label,
                $this->violations($needle),
                'la règle du faux ami ne voit pas : ' . $needle,
            );
        }

        // Contrôle NÉGATIF : la règle ne doit pas mordre sur du vocabulaire
        // légitime — sinon on ne pourrait plus parler de plan de fichiers.
        foreach ([
            'final class FileBackendRegistry {}',
            '/** Le plan de fichiers est neutre. */',
            'public function inspect(FilePlan $plan): InspectionReport;',
        ] as $honest) {
            self::assertNotContains($label, $this->violations($honest), 'faux positif sur : ' . $honest);
        }
    }

    /**
     * Story 60.4 — LE VOCABULAIRE DU SERVEUR DE FICHIERS N'EXISTE QUE SOUS LA
     * LIGNE.
     *
     * C'est le piège numéro un du chantier, et il est cosmétique : on descend la
     * dérivation des permissions dans un fichier neuf, on garde ses appelants
     * au-dessus, et rien ne proteste — sauf que la ligne n'est plus nulle part. Les
     * règles de pureté existantes ne l'auraient pas vu : elles interdisent les
     * services d'exécution par leur NOM, pas leur VOCABULAIRE.
     *
     * Zones autorisées, et elles seules : le dossier des implémentations de
     * backend et le dossier du format des listes d'accès.
     */
    #[Test]
    public function no_file_above_the_line_speaks_the_file_server_vocabulary(): void
    {
        $dir = realpath(self::repoPath(self::ORCHESTRATOR_DIR));
        self::assertNotFalse($dir, self::ORCHESTRATOR_DIR . ' doit exister');

        $finder = (new Finder())->files()->in($dir)->depth(0)->name('*.php');
        foreach (self::ORCHESTRATOR_LEGACY_EXCLUSIONS as $excluded) {
            $finder->notName($excluded);
        }

        $scanned = [];
        $offenders = [];

        foreach ($finder as $file) {
            $scanned[] = $file->getFilename();
            $found = $this->posixViolations((string) $file->getContents());
            if ($found !== []) {
                $offenders[$file->getRelativePathname()] = $found;
            }
        }

        foreach (self::ABOVE_THE_LINE_FILES as $relative) {
            $path = self::repoPath($relative);
            self::assertFileExists($path, 'la garde doit trouver ' . $relative);
            $scanned[] = basename($relative);
            $found = $this->posixViolations((string) file_get_contents($path));
            if ($found !== []) {
                $offenders[$relative] = $found;
            }
        }

        // Le CONTRAT lui-même est au-dessus de la ligne. Ses objets de rapport
        // décrivent ce qu'un backend a fait sans jamais dire comment : un exemple
        // illustratif glissé dans un commentaire y ferait entrer le vocabulaire
        // d'une implémentation particulière, et la règle 60.3 voisine ne le
        // verrait pas — elle ne détecte que des noms de classe, pas de la prose.
        $contractDir = realpath(self::repoPath(self::CONTRACT_DIR));
        self::assertNotFalse($contractDir, self::CONTRACT_DIR . ' doit exister');

        foreach ((new Finder())->files()->in($contractDir)->depth(0)->name('*.php') as $file) {
            $scanned[] = $file->getFilename();
            $found = $this->posixViolations((string) $file->getContents());
            if ($found !== []) {
                $offenders['contrat/' . $file->getFilename()] = $found;
            }
        }

        // Méta-test de PÉRIMÈTRE : la garde doit voir les fichiers qui comptent.
        // L'orchestrateur des répertoires réseau et le comparateur d'état sont les
        // deux qui viennent d'être vidés de ce vocabulaire ; un renommage qui les
        // ferait sortir du scan doit tomber ici, pas passer au vert.
        foreach (['NetworkShareService.php', 'PlanStateComparator.php', 'SharePlanProjector.php', 'DirectoryTemplateService.php'] as $expected) {
            self::assertContains($expected, $scanned, 'la garde doit scanner ' . $expected);
        }

        self::assertSame(
            [],
            $offenders,
            'LIGNE DE COUPE FRANCHIE. Le vocabulaire du serveur de fichiers (commande, mode de permission, '
            . 'entrée de liste d\'accès, chemin absolu, nom d\'exécution) n\'a le droit d\'exister que sous '
            . 'la ligne de contrat — dans le dossier des implémentations de backend et dans celui du format '
            . 'des listes d\'accès. Fichiers fautifs : '
            . json_encode($offenders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Story 60.4 — MÉTA-TEST D'AIGUILLE de la règle ci-dessus.
     *
     * Chaque étiquette voit la forme qu'un contributeur pressé écrirait, et aucune
     * ne mord sur du vocabulaire honnête d'orchestrateur. Sans les deux moitiés, la
     * règle serait soit aveugle, soit inutilisable.
     */
    #[Test]
    public function the_file_server_vocabulary_rule_sees_the_forms_it_claims_to_forbid(): void
    {
        self::assertSame(
            array_keys(self::POSIX_MARKER_RULES),
            array_keys(self::POSIX_NEEDLES),
            'chaque règle du vocabulaire du serveur de fichiers doit avoir son aiguille',
        );

        foreach (self::POSIX_NEEDLES as $label => $needle) {
            self::assertContains(
                $label,
                $this->posixViolations($needle),
                sprintf('la règle « %s » ne détecte pas son aiguille : le scan est aveugle', $label),
            );
        }

        // Contrôles NÉGATIFS : le vocabulaire légitime d'un orchestrateur neutre ne
        // déclenche rien. Sans eux, une règle trop large rendrait la coupe
        // intenable et finirait par être désactivée — ce qui est pire qu'une règle
        // absente.
        foreach ([
            'public function provision(NetworkShare $share): bool',
            '$plan = $this->projector->project($share);',
            '$report = $this->registry->forShare($share)->provision($plan);',
            '/** Le plan de fichiers est neutre : ni mode, ni nom de groupe système. */',
            "return ['status' => 'conforme', 'nodes' => []];",
            'use App\\Services\\Filesystem\\Plan\\PlanSubject;',
            // Story 62.4 — AIGUILLE mise à jour, pas règle changée. Elle citait
            // l'ancienne constante d'accès binaire, qui n'existe plus ; son rôle
            // (un contrôle NÉGATIF : le vocabulaire honnête d'un orchestrateur ne
            // déclenche rien) est inchangé, et son équivalent en verbes le tient
            // aussi bien.
            '$grant->hasVerb(PlanGrant::VERB_SUPPRIMER)',
        ] as $honest) {
            self::assertSame([], $this->posixViolations($honest), 'faux positif sur : ' . $honest);
        }
    }

    /**
     * Story 60.4 — les deux ZONES AUTORISÉES portent bien ce vocabulaire.
     *
     * Contrôle inverse du précédent, et il n'est pas décoratif : si le backend du
     * serveur de fichiers ne contenait AUCUN marqueur, c'est que la descente
     * n'aurait rien descendu, et le test du dessus serait vert pour la pire des
     * raisons.
     */
    #[Test]
    public function the_execution_zone_is_where_that_vocabulary_actually_lives(): void
    {
        $backendDir = realpath(self::repoPath('app/Services/Filesystem/Backend/Posix'));
        self::assertNotFalse($backendDir, 'le dossier des implémentations de backend doit exister');

        $markers = [];
        $files = 0;
        foreach ((new Finder())->files()->in($backendDir)->name('*.php') as $file) {
            $files++;
            $markers = array_merge($markers, $this->posixViolations((string) $file->getContents()));
        }

        self::assertGreaterThanOrEqual(6, $files, 'la zone d\'exécution doit être réellement peuplée');
        self::assertContains('commandes de listes d\'accès', array_unique($markers));
        self::assertContains('mode de permission', array_unique($markers));
        self::assertContains('élévation de privilège', array_unique($markers));
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

    // =========================================================================
    // Story 60.5 — l'emplacement d'affichage : la seule chaîne que le contrat
    // laisse remonter, et la seule promesse qui n'était portée que par un mot
    // =========================================================================

    /**
     * Les consommateurs AUTORISÉS de l'emplacement d'affichage rendu par le
     * contrat. Liste FERMÉE, et courte par nature : c'est un texte à montrer.
     *
     * @var list<string>
     */
    private const LOCATION_CONSUMERS = [
        'resources/views/pages/admin/shares/[id]/index.blade.php',
    ];

    /**
     * **UNE CHAÎNE D'AFFICHAGE QUI RESSEMBLE À UNE ADRESSE UTILISABLE.**
     *
     * Le contrat le dit en toutes lettres : cette valeur se montre, elle ne se
     * consomme pas pour agir — un backend distant y répondra quelque chose qui
     * n'a aucun sens pour un système de fichiers local. Mais c'est, dans tout ce
     * contrat, la SEULE garantie qui ne repose que sur une phrase : le
     * constructeur des rapports est privé, leur complétude est vérifiée à la
     * construction, leur sérialisation LÈVE. Ici, rien — et la valeur de retour a
     * l'apparence d'un chemin qu'on pourrait passer à une commande.
     *
     * Cet epic a rencontré quatre fois la même signature de défaut : une garantie
     * vraie sur le chemin heureux et fausse ailleurs. On ferme donc la liste de
     * ceux qui appellent, plutôt que de compter sur la lecture du commentaire.
     */
    #[Test]
    public function the_display_location_is_only_ever_read_by_a_screen(): void
    {
        $callers = [];

        foreach ([
            (new Finder())->files()->in(realpath(self::repoPath('app')))->name('*.php'),
            (new Finder())->files()->in(realpath(self::repoPath('resources/views/pages')))->name('*.blade.php'),
        ] as $finder) {
            foreach ($finder as $file) {
                if (! str_contains((string) $file->getContents(), '->location(')) {
                    continue;
                }

                $relative = str_replace(self::repoPath(''), '', (string) $file->getRealPath());

                // Les implémentations RENDENT cette valeur : ce sont elles qui
                // savent où le plan vit. Elles ne la consomment pas.
                if (str_contains($relative, 'Services/Filesystem/Backend/')) {
                    continue;
                }

                $callers[] = $relative;
            }
        }

        sort($callers);
        $allowed = self::LOCATION_CONSUMERS;
        sort($allowed);

        self::assertSame(
            $allowed,
            $callers,
            'L\'EMPLACEMENT D\'AFFICHAGE A ÉTÉ CONSOMMÉ AILLEURS QUE PAR UN ÉCRAN. Le contrat le rend '
            . 'pour être MONTRÉ, jamais pour agir : un backend distant y répond une adresse qui n\'a aucun '
            . 'sens pour un système de fichiers local, et un appelant qui la traiterait comme un chemin '
            . 'marcherait tant qu\'un seul backend existe. Si ce nouvel appelant est légitime, il rejoint '
            . 'la liste fermée — après qu\'on a vérifié qu\'il ne fait qu\'afficher.',
        );
    }

    /**
     * MÉTA-TEST D'AIGUILLE : la garde ci-dessus doit réellement voir un appel.
     * Sans lui, une liste vide face à un balayage aveugle passerait au vert.
     */
    #[Test]
    public function the_display_location_guard_actually_sees_a_call(): void
    {
        $screen = (string) file_get_contents(self::repoPath(self::LOCATION_CONSUMERS[0]));

        self::assertStringContainsString(
            '->location(',
            $screen,
            'la garde de l\'emplacement d\'affichage ne surveille plus rien : son seul consommateur connu '
            . 'ne l\'appelle plus. Retirer la garde avec l\'appel, ou corriger la liste.',
        );
    }
}
