<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Story 57.4 / **AR12 — L'EXTINCTION DU BBB LEGACY, PROUVÉE PLUTÔT QU'ANNONCÉE.**
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CE QUE CE FICHIER AFFIRME
 *
 *  Le critère d'acceptation final du système d'extensions dit : « plus aucun
 *  lien en dur vers les pages PHP du répertoire « bbb » ni vers la page
 *  publique « visio » du legacy n'existe (grep = 0) ; l'accès à la
 *  visioconférence passe exclusivement par la tuile ».
 *
 *  Un tel énoncé ne vaut que s'il échoue bruyamment le jour où quelqu'un le
 *  contredit — sans quoi il se re-contredit tout seul au premier copier-coller
 *  d'une vue ancienne. Trois volets :
 *
 *   1. **Le grep** — la surface de PRODUCTION du core ne porte plus aucune de
 *      ces deux formes ;
 *   2. **Les assertions structurelles** — parce qu'un grep vert ne prouve PAS
 *      l'extinction : le module in-process pourrait être encore là, l'exception
 *      de vérification anti-CSRF encore ouverte, et surtout les redirections
 *      qui ferment le repli vers le système de fichiers SE4 encore absentes ;
 *   3. **Les méta-tests** — le scanner mord réellement, et il ne mord ni ses
 *      propres chaînes ni les faux positifs nommés du terrain. Un scanner qui
 *      passerait sur une zone vide ou avec une expression cassée « prouverait »
 *      l'extinction pour la mauvaise raison — la signature de défaut de tout cet
 *      epic (la garantie qui n'existe que dans la vue).
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Patron : {@see ExtensionBbbIsolationTest} pour les méta-tests et le plancher
 * de fichiers, {@see LegacyTombstoneRoutesTest} pour la lecture textuelle.
 */
class LegacyBbbLinkExtinctionTest extends TestCase
{
    /**
     * La surface de PRODUCTION du core. Ce qui n'y est pas, et pourquoi :
     *
     *  - `extensions/` — c'est le SUCCESSEUR. `/ext/bbb/visio` y est la nouvelle
     *    route publique, pas un vestige ; l'y interdire reviendrait à interdire
     *    ce que la story livre ;
     *  - `tests/` — ce fichier et ses aiguilles y vivent ;
     *  - `docs/`, `userDoc/` — de la documentation, y compris historique. Le
     *    critère parle de LIENS SERVIS, pas de prose : une page qui raconte ce
     *    que SE4 faisait doit pouvoir citer ses anciennes adresses ;
     *  - `vendor/`, `node_modules/`, `storage/` — code tiers et fichiers
     *    engendrés.
     *
     * @var list<string>
     */
    private const SCANNED_DIRECTORIES = [
        'app',
        'resources',
        'routes',
        'config',
        'database',
        'public',
        'legacy',
    ];

    /**
     * Extensions de fichiers inspectées : tout ce qui peut porter un lien servi
     * à un navigateur. Les fontes, images et archives n'en portent pas, et les
     * lire à chaque exécution coûterait sans rien prouver.
     *
     * @var list<string>
     */
    private const SCANNED_EXTENSIONS = ['*.php', '*.js', '*.json', '*.css', '*.html', '*.svg', '*.xml'];

    /**
     * ⚠️ **AIGUILLES CONSTRUITES PAR CONCATÉNATION.** Ce fichier est du texte
     * comme un autre : une aiguille écrite d'un bloc se ferait détecter par
     * elle-même le jour où la zone scannée s'élargirait — et, plus sûrement,
     * finirait copiée-collée dans du vrai code.
     *
     * @return array<string, string>
     */
    private static function needles(): array
    {
        return [
            // 1. Un lien vers une page legacy : `/bbb/<page>.php`.
            'lien vers une page BBB legacy' => '~/bb' . 'b/\w+\.php~i',

            // 2. Le chemin public legacy « visio ». Le lookbehind laisse vivre
            //    celui de l'extension, qui est le SUCCESSEUR — et l'aiguille
            //    exige le slash de tête, sans quoi « provisioning »
            //    déclencherait.
            //
            //    ⚠️ Review 57.4 #2 — le lookbehind est ancré au préfixe COMPLET
            //    de l'extension et non aux trois dernières lettres de ce
            //    préfixe. Ancré sur ces trois lettres seules, il neutralisait
            //    n'importe quelle chaîne se terminant par elles : un chemin du
            //    genre « quelque-chose-qui-finit-pareil » suivi du chemin
            //    public legacy passait alors sous le radar. Le successeur a un
            //    chemin EXACT, c'est celui-là qu'on excepte — pas un suffixe
            //    qui se trouve lui ressembler. Les trois cas frontière sont
            //    dans `the_successor_exception_is_anchored_to_its_exact_path`,
            //    où ils sont écrits par concaténation, seule façon de les citer
            //    dans un fichier que ce scan lit lui-même.
            'chemin public « visio » legacy' => '~(?<!/ext/bb' . 'b)/vis' . 'io~i',
        ];
    }

    private static function repoPath(string $relative = ''): string
    {
        return dirname(__DIR__, 2) . ($relative !== '' ? '/' . ltrim($relative, '/') : '');
    }

    /**
     * LE scanner. Rend les étiquettes des aiguilles trouvées dans `$content`.
     *
     * @return list<string>
     */
    private function linksIn(string $content): array
    {
        $found = [];

        foreach (self::needles() as $label => $pattern) {
            if (preg_match($pattern, $content) === 1) {
                $found[] = $label;
            }
        }

        return $found;
    }

    // =====================================================================
    // Volet 1 — le grep
    // =====================================================================

    #[Test]
    public function the_core_no_longer_serves_a_single_link_to_the_legacy_bbb(): void
    {
        $directories = [];

        foreach (self::SCANNED_DIRECTORIES as $directory) {
            $path = realpath(self::repoPath($directory));

            self::assertNotFalse($path, $directory . '/ doit exister — sinon le scan porterait sur du vide');

            $directories[] = $path;
        }

        $finder = (new Finder())
            ->files()
            ->in($directories)
            ->exclude(['vendor', 'node_modules', 'storage'])
            ->name(self::SCANNED_EXTENSIONS);

        $inspected = 0;
        $offenders = [];

        foreach ($finder as $file) {
            $inspected++;

            $found = $this->linksIn((string) $file->getContents());

            if ($found !== []) {
                $offenders[$file->getRelativePathname()] = $found;
            }
        }

        // Méta-test #3 — LE PLANCHER. Une zone renommée, déplacée ou mal
        // filtrée ferait passer ce test à vide, indéfiniment, et au vert.
        self::assertGreaterThanOrEqual(
            800,
            $inspected,
            'le scan doit inspecter la surface RÉELLE du core, pas un répertoire vide',
        );

        self::assertSame(
            [],
            $offenders,
            "AR12 ROMPU. Un lien en dur vers le BBB legacy est revenu dans la surface de production du "
            . "core. L'accès à la visioconférence passe EXCLUSIVEMENT par la tuile du lanceur : l'extension "
            . '« Visioconférences » est le successeur intégral. Fichiers fautifs : '
            . json_encode($offenders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    // =====================================================================
    // Volet 2 — ce que le grep ne peut pas prouver
    // =====================================================================

    #[Test]
    public function the_in_process_legacy_module_is_gone_for_good(): void
    {
        // Le contre-modèle nommé de l'epic : du code SE4 exécuté DANS le Laravel
        // du core. Chaque page avait son successeur dans l'extension ; le module
        // n'a plus d'objet, et le laisser reviendrait à garder deux chemins vers
        // la même fonction, dont un sans autorisation côté serveur.
        self::assertDirectoryDoesNotExist(self::repoPath('legacy/modules/bbb'));
        self::assertFileDoesNotExist(self::repoPath('legacy/stubs/bbb.inc.php'));
        self::assertFileDoesNotExist(self::repoPath('legacy/stubs/_vendored/bbb.body.php'));

        // Les stubs PARTAGÉS, eux, restent : le module dhcp les consomme.
        self::assertFileExists(self::repoPath('legacy/stubs/functions.inc.php'));
        self::assertDirectoryExists(self::repoPath('legacy/modules/dhcp'));
    }

    #[Test]
    public function the_csrf_exemptions_died_with_the_module_they_exempted(): void
    {
        // Une exception de vérification anti-CSRF sans consommateur n'est pas un
        // vestige inoffensif : elle attend la première route SE5 dont le chemin
        // commencerait par ces lettres pour la priver de sa protection, en
        // silence.
        $source = (string) file_get_contents(self::repoPath('app/Http/Middleware/VerifyCsrfToken.php'));

        self::assertStringNotContainsString("'bb" . "b*'", $source);
        self::assertStringNotContainsString("'vis" . "io*'", $source);

        // Contrôle POSITIF : le fichier est bien celui qu'on croit, et il porte
        // toujours les exemptions des modules encore vivants.
        self::assertStringContainsString("'dhcp*'", $source);
    }

    #[Test]
    public function the_two_redirections_that_close_the_se4_filesystem_fallthrough_are_still_there(): void
    {
        // ⚠️ LE VERROU QU'UNE « SIMPLIFICATION » DU CONFIG FERAIT SAUTER SANS
        // QUE PERSONNE NE LE VOIE. Le module local supprimé, le catchall passe
        // à l'étape suivante : le proxy vers `/var/www/sambaedu/bbb/…`. Sans ces
        // deux entrées, l'interface SE4 d'origine reviendrait telle quelle sur
        // toute instance non débranchée.
        //
        // ⚠️ Lecture TEXTUELLE du fichier de configuration, et pas
        // `config(…)` : cette suite s'exécute sans application démarrée (patron
        // `LegacyTombstoneRoutesTest`). C'est la valeur EFFECTIVE, elle, que le
        // test Feature `LegacyBbbRoutesRedirectTest` éprouve — en frappant les
        // URL pour de vrai.
        $source = (string) file_get_contents(self::repoPath('config/sambaedu.php'));

        self::assertStringContainsString("'^bb" . "b(/|$)' => '/'", $source);
        self::assertStringContainsString("'^vis" . "io(/|$)' => '/'", $source);

        // Et le mécanisme qui les évalue est actif par défaut : une valeur par
        // défaut à `false` rendrait les deux entrées décoratives.
        self::assertStringContainsString("'block_migrated_routes' => env('LEGACY_BLOCK_MIGRATED_ROUTES', true)", $source);
    }

    #[Test]
    public function the_se4_extinction_inventory_of_epic_38_is_deliberately_left_alone(): void
    {
        // NON-OBJECTIF, écrit pour qu'on ne le « nettoie » pas par zèle :
        // l'allowlist d'observation de l'Epic 38 décrit les répertoires du
        // système de fichiers SE4 — pas la surface SE5. Y retirer `bbb` et
        // `visio` fausserait le verdict d'extinction d'un canal qui existe
        // encore sur les instances non débranchées.
        $source = (string) file_get_contents(
            self::repoPath('app/Console/Commands/Concerns/InteractsWithSe4Extinction.php')
        );

        self::assertStringContainsString("'bb" . "b'", $source);
        self::assertStringContainsString("'vis" . "io'", $source);
    }

    // =====================================================================
    // Volet 3 — les méta-tests : le scanner mord, et seulement où il faut
    // =====================================================================

    #[Test]
    public function the_scanner_really_bites_on_the_links_that_were_removed(): void
    {
        // Les formes RÉELLES des liens supprimés par cette story, plus celles
        // que le legacy fabriquait à l'exécution.
        $samples = [
            'lien vers une page BBB legacy' => [
                "{ title: 'Créer un salon', url: '/bb" . "b/create.php' }",
                "action=\"/bb" . "b/launch.php\" method=\"post\"",
                'href="https://se5.etab.fr/bb' . 'b/records.php"',
            ],
            'chemin public « visio » legacy' => [
                '$URL = "https://" . $_SERVER[\'HTTP_HOST\'] . "/vis' . 'io/?salon=$hash";',
                'https://se5.etab.fr/vis' . 'io?salon=x',
                '/vis' . 'io/',
            ],
        ];

        foreach ($samples as $label => $needles) {
            foreach ($needles as $needle) {
                self::assertContains(
                    $label,
                    $this->linksIn($needle),
                    sprintf('AIGUILLE AVEUGLE : « %s » aurait dû déclencher « %s »', $needle, $label),
                );
            }
        }
    }

    #[Test]
    public function the_scanner_never_bites_on_the_named_false_positives(): void
    {
        // ⚠️ LE contrôle inverse, et le plus important : un scanner qui
        // répondrait « tout est fautif » passerait le test précédent. Les quatre
        // cas ci-dessous sont RÉELS, relevés dans ce dépôt.
        $legitimate = [
            // « provisioning » contient `visio` — des dizaines d'occurrences
            // dans les vues d'administration des partages.
            'Le partage est en cours de provisioning.',
            '$share->deprovisioned_at = now();',
            'reprovision-shares',

            // Le SUCCESSEUR : la nouvelle route publique de l'extension.
            "'entry_url' => '/ext/bb" . "b/vis" . "io'",
            'https://se5.etab.fr/ext/bb' . 'b/vis' . 'io?g=jeton',

            // Les motifs de redirection eux-mêmes : SANS slash de tête, donc
            // hors de portée — c'est ce qui permet de les écrire dans un
            // fichier scanné.
            "'^bb" . "b(/|$)' => '/',",
            "'^vis" . "io(/|$)' => '/',",

            // Une chaîne hexadécimale d'identifiant de fixture.
            "'uuid' => 'bbbbbbbb-1111-2222-3333-444444444444'",
            'a1b2c3d4e5f60718293a4b5c6d7e8f90',

            // Et la mention en prose d'une page qui n'existe plus : un
            // commentaire historique n'est pas un lien servi.
            '// le module BBB legacy vivait autrefois sous legacy/modules',
        ];

        foreach ($legitimate as $sample) {
            self::assertSame(
                [],
                $this->linksIn($sample),
                sprintf(
                    'FAUX POSITIF : « %s » est légitime. Une aiguille trop large ferait échouer le core '
                    . 'pour du code parfaitement sain — et finirait débranchée.',
                    $sample,
                ),
            );
        }
    }

    #[Test]
    public function the_successor_exception_is_anchored_to_its_exact_path(): void
    {
        // ⚠️ Review 57.4 #2 — la nuance qui décide si ce test protège quelque
        // chose. Le lookbehind exceptant le successeur était ancré sur les trois
        // lettres `bb` . `b` et non sur son chemin complet : TOUTE chaîne
        // finissant par ces lettres se voyait exceptée, et un lien legacy
        // résiduel construit par concaténation serait passé sans que rien ne
        // bouge. Ces trois cas sont la frontière exacte.
        $mustBite = [
            'url("foo-bb' . 'b/vis' . 'io")',
            'https://exemple.test/redirectbb' . 'b/vis' . 'io',
            '$prefix . \'bb' . 'b\' . \'/vis' . 'io\'',
        ];

        foreach ($mustBite as $sample) {
            self::assertNotSame(
                [],
                $this->linksIn($sample),
                sprintf(
                    'ANGLE MORT : « %s » n\'est PAS le chemin du successeur, il finit seulement par les mêmes '
                    . 'lettres. L\'exception doit porter sur le chemin exact, pas sur un suffixe qui lui ressemble.',
                    $sample,
                ),
            );
        }
    }

    #[Test]
    public function the_scanner_does_not_detect_its_own_needles(): void
    {
        // Piège classique du test « grep = 0 » : le fichier qui porte les
        // aiguilles se détecte lui-même, et le seul remède qu'on trouve alors
        // est de s'exclure du scan — ce qui affaiblit le scan pour de bon. Ici
        // la concaténation suffit, et ce test le prouve sur le fichier RÉEL.
        self::assertSame([], $this->linksIn((string) file_get_contents(__FILE__)));
    }
}
