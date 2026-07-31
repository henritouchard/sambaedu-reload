<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Services\Extensions\ExtensionManifestValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Story 55.3 — **FR24 : la capacité NÉGATIVE est contractuelle.**
 *
 * « Aucun code d'extension ne s'exécute dans le processus SE5, aucun
 * identifiant de base ou d'annuaire n'est exposé aux extensions » n'est pas une
 * intention : c'est une propriété qui doit échouer bruyamment le jour où
 * quelqu'un la contredit. Ce fichier la rend mécanique, en trois volets :
 *
 *  1. **La quarantaine du témoin** — `app/OidcWitness/` ne peut pas tricher.
 *     C'est le volet le plus important : la valeur démonstrative de l'app-témoin
 *     repose ENTIÈREMENT sur le fait qu'elle n'a QUE le contrat public. Un
 *     témoin qui lirait un modèle validerait la connexion SE5, pas le SSO.
 *  2. **Le manifest ne porte rien d'exécutable** — la forme normalisée rendue
 *     par le validateur est une liste FERMÉE de métadonnées ; et le registre ne
 *     contient ni `eval(`, ni inclusion dynamique dérivée d'un manifest.
 *  3. **L'autoload ne s'étend à aucun répertoire d'extensions** — installer une
 *     extension ne doit jamais rendre du code tiers chargeable in-process.
 *
 * Style : `PHPUnit\Framework\TestCase` PUR (aucune application bootstrapée),
 * scan de fichiers — patron `OidcRoutesTest`. Chaque scan est adossé à un
 * **méta-test** : un scan qui ne détecte rien parce qu'il ne regarde rien
 * passerait sinon éternellement au vert.
 *
 * ⚠️ Ce que ce fichier NE prouve PAS : l'isolation par **processus** (NFR4
 * complet). Elle appartient aux extensions de type `app` (Epics 56/57). Ici,
 * c'est l'isolation par **CONTRAT** qui est verrouillée.
 */
class ExtensionIsolationTest extends TestCase
{
    /**
     * Les frontières de la quarantaine. Chaque entrée : `étiquette => regex`.
     *
     * ⚠️ Le scan porte sur le TEXTE des fichiers, commentaires compris. C'est
     * volontaire : un docblock qui cite `App\Models\User` finirait par être
     * copié en `use`. La contrainte pousse à décrire les classes du fournisseur
     * par leur nom court — ce qui est de toute façon la bonne façon de parler
     * d'un voisin dont on n'a pas le droit de dépendre.
     *
     * @var array<string, string>
     */
    private const QUARANTINE_RULES = [
        'modèles Eloquent de SE5' => '/App\\\\Models\\\\/',
        'services applicatifs de SE5' => '/App\\\\Services\\\\/',
        'internes du fournisseur OIDC' => '/App\\\\Auth\\\\Oidc\\\\/',
        'façade base de données' => '/Illuminate\\\\Support\\\\Facades\\\\DB\b/',
        'couche base de données' => '/Illuminate\\\\Database\\\\/',
        'appel direct au query builder' => '/\bDB::/',
        'annuaire LDAP' => '/LdapRecord/',

        // ⚠️ Correctif review 55.3 (#1) — le lookbehind était `(?<![\w\\])`,
        // c'est-à-dire qu'il excluait aussi un ANTISLASH de tête. Or
        // `\auth()`, `\session()` et `\Illuminate\Support\Facades\Auth::` sont
        // du PHP parfaitement légal (résolution globale / FQCN inline) : la
        // règle laissait donc passer exactement la syntaxe qu'un contributeur
        // pressé emploie. Le lookbehind ne doit exclure QUE les identifiants
        // qui se terminent par le motif (`myAuth::`, `getauth(`), donc `\w`
        // seul.
        'utilisateur connecté (helper)' => '/(?<!\w)auth\s*\(/',
        'utilisateur connecté (façade)' => '/(?<!\w)Auth::/',
        'magasin d\'état serveur de SE5' => '/(?<!\w)session\s*\(/',

        // Les règles ci-dessus portent sur le SITE D'APPEL ; un alias d'import
        // (`use Illuminate\Support\Facades\Auth as CurrentUser;`) les esquive
        // toutes. Mais un alias exige d'écrire le FQCN dans le `use` — ces
        // règles-ci le cueillent là. Volontairement CIBLÉES et non un préfixe
        // `Facades\` global : le témoin utilise légitimement `Cache`, `Log` et
        // `Cookie`, qui ne donnent accès ni à l'identité SE5 ni à ses données.
        'façade utilisateur (import)' => '/Illuminate\\\\Support\\\\Facades\\\\Auth\b/',
        'façade session (import)' => '/Illuminate\\\\Support\\\\Facades\\\\Session\b/',
        'contrats d\'authentification' => '/Illuminate\\\\Contracts\\\\Auth\\\\/',

        // Le conteneur était un angle mort TOTAL : `app('db')`, `app(User::class)`
        // ou `resolve(Guard::class)` atteignent n'importe quoi sans jamais
        // écrire le nom interdit en toutes lettres. Le témoin n'en a aucun
        // usage — il reçoit ses collaborateurs par injection de constructeur.
        'résolution par le conteneur' => '/(?<!\w)app\s*\(/',
        'résolution par le conteneur (resolve)' => '/(?<!\w)resolve\s*\(/',
    ];

    /**
     * Formes d'ÉVASION connues, à faire mordre par le méta-test.
     *
     * Chaque entrée a été vérifiée comme NON détectée par le jeu de règles
     * d'origine (review 55.3 #1). Les garder ici en dur est ce qui empêche la
     * régression : quelqu'un qui « simplifierait » une regex sans y penser
     * ferait rougir le méta-test, pas seulement le scan nominal.
     *
     * @var list<string>
     */
    private const KNOWN_EVASIONS = [
        '$u = \auth()->user();',
        '$u = \Illuminate\Support\Facades\Auth::user();',
        '$v = \session()->get("x");',
        'use Illuminate\Support\Facades\Auth as CurrentUser;',
        'use Illuminate\Support\Facades\Session as Store;',
        '$db = app("db");',
        '$g = resolve(\Illuminate\Contracts\Auth\Guard::class);',
    ];

    /** Racine du dépôt (ce fichier vit dans `tests/Architecture/`). */
    private static function repoPath(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . ltrim($relative, '/');
    }

    /**
     * LE scanner. Rend les étiquettes des règles violées par `$content`.
     *
     * Extrait en méthode pour être exerçable sur une aiguille de test : c'est
     * ce qui rend le méta-test possible (cf.
     * {@see self::the_quarantine_scanner_actually_detects_a_violation()}).
     *
     * @return list<string>
     */
    private function quarantineViolations(string $content): array
    {
        $violations = [];

        foreach (self::QUARANTINE_RULES as $label => $pattern) {
            if (preg_match($pattern, $content) === 1) {
                $violations[] = $label;
            }
        }

        return $violations;
    }

    // =====================================================================
    // Volet 1 — la quarantaine de l'app-témoin
    // =====================================================================

    #[Test]
    public function the_witness_namespace_cannot_reach_anything_but_the_public_contract(): void
    {
        $namespaceDir = realpath(self::repoPath('app/OidcWitness'));
        self::assertNotFalse($namespaceDir, 'app/OidcWitness/ doit exister');

        $finder = (new Finder())->files()->in($namespaceDir)->name('*.php');

        $inspected = 0;
        $offenders = [];

        foreach ($finder as $file) {
            $inspected++;

            $violations = $this->quarantineViolations((string) $file->getContents());

            if ($violations !== []) {
                $offenders[$file->getRelativePathname()] = $violations;
            }
        }

        // Méta-test #1 : sans ce garde-fou, un namespace renommé ou déplacé
        // ferait passer le test À VIDE, indéfiniment.
        self::assertGreaterThanOrEqual(
            3,
            $inspected,
            'le garde-fou doit inspecter le namespace RÉEL du témoin',
        );

        self::assertSame(
            [],
            $offenders,
            "QUARANTAINE ROMPUE (FR24). L'app-témoin ne prouve quelque chose QUE si elle "
            . "n'a accès à rien d'autre que le contrat public OIDC. Fichiers fautifs : "
            . json_encode($offenders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Méta-test #2 — **le scan détecte réellement.**
     *
     * Une assertion d'ABSENCE ne vaut rien tant qu'on n'a pas prouvé que la
     * présence, elle, serait vue. On injecte donc les aiguilles dans une chaîne
     * de test (jamais dans le code du témoin) et on exige que chaque règle
     * morde.
     */
    #[Test]
    public function the_quarantine_scanner_actually_detects_a_violation(): void
    {
        $needles = [
            'modèles Eloquent de SE5' => 'use App' . '\\Models\\User;',
            'services applicatifs de SE5' => 'use App' . '\\Services\\Extensions\\ExtensionCatalogService;',
            'internes du fournisseur OIDC' => 'use App' . '\\Auth\\Oidc\\Services\\OidcClientRegistry;',
            'façade base de données' => 'use Illuminate' . '\\Support\\Facades\\DB;',
            'couche base de données' => 'use Illuminate' . '\\Database\\Eloquent\\Model;',
            'appel direct au query builder' => '$rows = D' . 'B::table("users")->get();',
            'annuaire LDAP' => 'use Ldap' . 'Record\\Models\\ActiveDirectory\\User;',
            'utilisateur connecté (helper)' => '$user = au' . 'th()->user();',
            'utilisateur connecté (façade)' => '$user = Au' . 'th::user();',
            'magasin d\'état serveur de SE5' => 'ses' . 'sion()->put("k", 1);',
            'façade utilisateur (import)' => 'use Illuminate' . '\\Support\\Facades\\Auth as CurrentUser;',
            'façade session (import)' => 'use Illuminate' . '\\Support\\Facades\\Session as Store;',
            'contrats d\'authentification' => 'use Illuminate' . '\\Contracts\\Auth\\Guard;',
            'résolution par le conteneur' => '$db = ap' . 'p("db");',
            'résolution par le conteneur (resolve)' => '$g = resol' . 've("auth.driver");',
        ];

        // Chaque règle est couverte par une aiguille : une règle sans aiguille
        // serait une règle qu'on n'a jamais vue mordre.
        self::assertSame(
            array_keys(self::QUARANTINE_RULES),
            array_keys($needles),
            'chaque règle de quarantaine doit avoir son aiguille de vérification',
        );

        foreach ($needles as $label => $needle) {
            self::assertContains(
                $label,
                $this->quarantineViolations($needle),
                sprintf('la règle « %s » ne détecte pas son aiguille : le scan est aveugle', $label),
            );
        }

        // Contrôle POSITIF inverse : un code honnête ne déclenche rien — sans
        // lui, un scanner qui répondrait « tout est fautif » passerait aussi.
        self::assertSame(
            [],
            $this->quarantineViolations(
                '<?php use GuzzleHttp\Client; $document = $this->http->getJson($issuer);'
            ),
        );

        // Correctif review 55.3 (#1) — LES FORMES D'ÉVASION.
        //
        // Les aiguilles ci-dessus prouvent que chaque règle mord sur SA syntaxe
        // canonique. Elles ne prouvaient rien sur les variantes légales que PHP
        // autorise — antislash de résolution globale, FQCN inline, alias
        // d'import, conteneur. Le jeu de règles d'origine laissait passer les
        // sept formes ci-dessous : le témoin pouvait lire la session SE5 sans
        // qu'aucun test ne bronche, et la propriété centrale de la story
        // (« la quarantaine est verrouillée par test, pas par convention »)
        // était fausse.
        //
        // Chacune doit désormais produire AU MOINS une violation. On n'exige
        // pas quelle règle mord : ce qui compte est qu'aucune de ces formes ne
        // traverse le scan en silence.
        foreach (self::KNOWN_EVASIONS as $evasion) {
            self::assertNotSame(
                [],
                $this->quarantineViolations($evasion),
                sprintf(
                    'FORME D\'ÉVASION NON DÉTECTÉE : « %s ». Le scan est textuel, donc '
                    . 'contournable par construction — mais pas par de la syntaxe PHP courante.',
                    $evasion,
                ),
            );
        }
    }

    /**
     * La limite RÉSIDUELLE, écrite plutôt que tue.
     *
     * Un scan textuel ne peut pas être exhaustif : un FQCN concaténé à
     * l'exécution (`("App"."\\Models\\User")::query()`), construit depuis une
     * constante, ou reconstitué par `base64_decode()`, passera toujours. Ce
     * test ne prétend donc pas rendre la triche impossible — il rend la triche
     * DÉLIBÉRÉE ET VISIBLE EN REVUE, ce qui est l'objectif atteignable.
     *
     * Ce test documente ce résidu et échouerait si quelqu'un le supprimait en
     * croyant la quarantaine hermétique. Même parti-pris que le canal de timing
     * assumé en review 55.2 : un écart connu et écrit vaut mieux qu'une
     * promesse absolue démentie par le code.
     */
    #[Test]
    public function the_textual_scan_has_a_documented_residual_limit(): void
    {
        $obfuscated = '$fqcn = "App" . "\\\\Models\\\\User"; $rows = $fqcn::query()->get();';

        self::assertSame(
            [],
            $this->quarantineViolations($obfuscated),
            'Si cette forme est désormais détectée, tant mieux — mettre à jour '
            . 'le commentaire de ce test plutôt que de le supprimer.',
        );
    }

    // =====================================================================
    // Volet 2 — le manifest ne porte rien d'exécutable
    // =====================================================================

    /**
     * La forme normalisée d'un manifest v1 est une liste FERMÉE de métadonnées.
     *
     * C'est là que se joue « aucun code d'extension ne s'exécute in-process » :
     * tant que le manifest ne peut porter ni commande, ni script, ni classe, ni
     * point d'accroche, il n'y a RIEN à exécuter. Le jour où une story ajouterait
     * une clé, ce test l'obligerait à regarder cette propriété en face.
     */
    #[Test]
    public function the_manifest_contract_carries_no_executable_field(): void
    {
        $normalized = (new ExtensionManifestValidator())->validate([
            'manifest_version' => 1,
            'id' => 'contract-probe',
            'type' => 'link',
            'name' => 'Sonde de contrat',
            'version' => '1.0.0',
            'entry_url' => '/probe',
            'visibility' => ['roles' => ['admin']],
        ]);

        $keys = array_keys($normalized);
        sort($keys);

        self::assertSame(
            [
                'dependencies', 'description', 'entry_url', 'icon', 'id',
                'manifest_version', 'name', 'publisher', 'scopes', 'type',
                'version', 'visibility',
            ],
            $keys,
            'Le manifest v1 est un contrat PUBLIC et FERMÉ : toute clé de plus doit être '
            . 'un acte délibéré, et jamais un champ exécutable (FR24 / AR1).',
        );

        // Défense en profondeur : même un nom de clé qui SUGGÉRERAIT de
        // l'exécution est refusé — c'est par là qu'une exécution in-process
        // rentrerait « sans y penser ».
        foreach (['command', 'cmd', 'script', 'exec', 'handler', 'hook', 'class', 'callback', 'run', 'bootstrap'] as $executable) {
            self::assertNotContains($executable, $keys, 'champ exécutable dans le manifest : ' . $executable);
        }
    }

    /**
     * Les manifests RÉELLEMENT embarqués dans le dépôt respectent le même
     * contrat fermé — y compris celui de l'app-témoin ajouté par 55.3.
     */
    #[Test]
    public function every_bundled_manifest_of_the_repository_stays_within_the_closed_contract(): void
    {
        $root = realpath(self::repoPath('resources/extensions'));
        self::assertNotFalse($root, 'resources/extensions/ doit exister');

        $manifests = glob($root . '/*/manifest.json') ?: [];

        // Méta-test : sans ça, un chemin cassé ferait passer la boucle à vide.
        self::assertGreaterThanOrEqual(2, count($manifests), 'le dépôt embarque au moins `doc` et `sso-demo`');

        $validator = new ExtensionManifestValidator();
        $ids = [];

        foreach ($manifests as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);
            self::assertIsArray($decoded, 'manifest illisible : ' . $path);

            $normalized = $validator->validate($decoded);
            $ids[] = $normalized['id'];

            self::assertSame(
                [],
                array_diff(array_keys($decoded), array_keys($normalized)),
                'clé hors contrat dans ' . $path,
            );
        }

        sort($ids);
        self::assertSame(['doc', 'sso-demo'], $ids);
    }

    #[Test]
    public function the_extension_registry_never_evaluates_anything_derived_from_a_manifest(): void
    {
        $files = [];

        $servicesDir = realpath(self::repoPath('app/Services/Extensions'));
        self::assertNotFalse($servicesDir);

        foreach ((new Finder())->files()->in($servicesDir)->name('*.php') as $file) {
            $files[$file->getRelativePathname()] = (string) $file->getContents();
        }

        foreach (['app/Models/Extension.php', 'app/Models/ExtensionSource.php'] as $model) {
            $path = realpath(self::repoPath($model));
            self::assertNotFalse($path, $model . ' doit exister');
            $files[$model] = (string) file_get_contents($path);
        }

        // Méta-test : 4 services + 2 modèles.
        self::assertGreaterThanOrEqual(5, count($files), 'le scan doit couvrir le registre réel');

        $dangerous = [
            'évaluation de code' => '/\beval\s*\(/',
            'inclusion dynamique' => '/\b(include|require)(_once)?\s*\(?\s*\$/',
            'appel dynamique de classe' => '/\bnew\s+\$/',
            'exécution système' => '/\b(shell_exec|passthru|proc_open|popen|system)\s*\(/',
        ];

        foreach ($files as $name => $content) {
            foreach ($dangerous as $label => $pattern) {
                if ($label === 'exécution système' && $name === self::PRIVILEGED_SEAM) {
                    // Exemption UNIQUE, NOMMÉE et compensée (Story 56.2) — voir
                    // le test suivant, qui impose à ce fichier des contraintes
                    // PLUS strictes que la règle générale.
                    continue;
                }

                self::assertSame(
                    0,
                    preg_match($pattern, $content),
                    sprintf('%s détectée dans %s — un manifest ne doit JAMAIS devenir du code', $label, $name),
                );
            }
        }
    }

    /**
     * Le SEUL fichier du registre autorisé à exécuter une commande système
     * (Story 56.2) : l'implémentation réelle du seam privilégié.
     */
    private const PRIVILEGED_SEAM = 'SudoExtensionHelperRunner.php';

    #[Test]
    public function the_only_privileged_seam_never_touches_a_manifest_and_escapes_everything(): void
    {
        // ── Pourquoi une exemption, et pourquoi elle ne trahit pas la règle ──
        //
        // La règle FR24 dit : « un manifest ne doit JAMAIS devenir du code ».
        // Le moteur d'installation (56.2) DOIT pourtant exécuter des commandes
        // privilégiées (apt, systemd, Apache) — mais il les exécute par UN seul
        // point de passage, dont le binaire est FIXE (une clé de configuration,
        // pas une donnée de manifest) et dont chaque argument est échappé.
        //
        // Plutôt que de relâcher la règle générale, on exempte ce fichier
        // NOMMÉMENT et on lui impose ici des contraintes que les autres n'ont
        // pas. Si un jour ce fichier interpolait quoi que ce soit venant d'un
        // manifest, ce test tomberait.
        $path = realpath(self::repoPath('app/Services/Extensions/'.self::PRIVILEGED_SEAM));
        self::assertNotFalse($path, 'le seam privilégié doit exister');

        $content = (string) file_get_contents($path);

        // 1. Il ne connaît PAS la notion de manifest, ni le modèle Extension.
        foreach (['manifest', 'Extension::', 'installBlock'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $content,
                'le seam privilégié ne doit rien savoir des manifests',
            );
        }

        // 2. Le binaire exécuté vient de la CONFIGURATION, jamais d'une donnée.
        self::assertStringContainsString("config(\n", $content);
        self::assertStringContainsString('extensions.install.helper_path', $content);

        // 3. Chaque argument est échappé, et l'appel passe par `sudo -n`.
        self::assertStringContainsString('escapeshellarg', $content);
        self::assertStringContainsString("'sudo', '-n'", $content);

        // 4. Un seul `proc_open`, pas une bibliothèque d'exécution.
        self::assertSame(1, preg_match_all('/\bproc_open\s*\(/', $content));
        self::assertSame(0, preg_match('/\b(shell_exec|passthru|popen|system)\s*\(/', $content));
    }

    // =====================================================================
    // Volet 3 — l'autoload ne s'étend à aucun répertoire d'extensions
    // =====================================================================

    #[Test]
    public function composer_autoload_never_maps_an_extension_directory(): void
    {
        $composer = json_decode((string) file_get_contents(self::repoPath('composer.json')), true);
        self::assertIsArray($composer);

        $paths = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach ((array) ($composer[$section] ?? []) as $map) {
                foreach ((array) $map as $target) {
                    foreach ((array) $target as $path) {
                        $paths[] = (string) $path;
                    }
                }
            }
        }

        // Méta-test : le tableau n'est pas vide (sinon la boucle ne prouve rien).
        self::assertNotEmpty($paths, 'composer.json déclare bien des chemins d\'autoload');

        foreach ($paths as $path) {
            self::assertStringNotContainsString(
                'resources/extensions',
                $path,
                'Un chemin d\'autoload vers les extensions rendrait du code tiers chargeable in-process (AR1/FR24)',
            );
            self::assertStringNotContainsString('/ext/', $path);
        }
    }
}
