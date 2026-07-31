<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Enums\ExtensionType;
use App\Services\Extensions\ExtensionManifestValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Story 57.1 — **FR33 : « ZÉRO ACCÈS PRIVILÉGIÉ » EST UNE PROPRIÉTÉ, PAS UNE
 * INTENTION.**
 *
 * L'AC3 de la story dit : « l'extension ne consomme que SSO + claims ». Tant que
 * cette phrase n'échoue pas bruyamment le jour où quelqu'un la contredit, elle
 * ne vaut rien. Ce fichier la rend mécanique.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  POURQUOI LE VERROU EST ICI, DANS LA SUITE DU CORE
 *
 *  `extensions/bbb/` vit dans le dépôt SE5 (décision D4) : c'est commode pour
 *  le cycle de développement, et c'est exactement ce qui rend la dérive facile.
 *  Un jour, quelqu'un aura « juste besoin » de lire un modèle plutôt que
 *  d'appeler l'API — et l'extension cessera d'être une extension. Le test vit
 *  donc dans la suite du CORE, où personne ne peut le désactiver en même temps
 *  qu'il écrit le code fautif : la suite autonome de l'extension
 *  (`extensions/bbb/phpunit.xml`) ne le connaît pas.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Trois volets :
 *
 *  1. **La quarantaine** — aucun fichier de `extensions/bbb/**` (hors `vendor/`)
 *     ne nomme les internes de SE5 ;
 *  2. **Le scanner mord** — chaque règle est éprouvée sur son aiguille, et un
 *     code honnête ne déclenche rien (méta-tests) ;
 *  3. **Le manifest réel** passe le validateur v1 et respecte les invariants de
 *     VIE de l'extension.
 *
 * ⚠️ Ce fichier NE TOUCHE PAS {@see ExtensionIsolationTest} : celui-là couvre le
 * témoin SSO, le registre et l'autoload. Périmètres disjoints, verrous distincts.
 */
class ExtensionBbbIsolationTest extends TestCase
{
    /**
     * Les frontières de la quarantaine. Chaque entrée : `étiquette => regex`.
     *
     * ⚠️ Le scan porte sur le TEXTE des fichiers, **commentaires compris**.
     * C'est volontaire : un docblock qui cite un modèle de SE5 finit toujours
     * par être copié en `use`. La contrainte pousse à parler des voisins par
     * leur nom court — ce qui est de toute façon la bonne façon de décrire un
     * voisin dont on n'a pas le droit de dépendre.
     *
     * ⚠️ Le jeu de règles est CELUI DE L'ÉNONCÉ (FR33 / décision D4), ni plus ni
     * moins. Les règles « conteneur » d'`ExtensionIsolationTest` (`app(`,
     * `resolve(`) n'ont aucun sens ici : il n'y a pas de conteneur dans du PHP
     * nu, et `app(` frapperait des identifiants légitimes.
     *
     * @var array<string, string>
     */
    private const QUARANTINE_RULES = [
        'espace de noms applicatif de SE5' => '/App\\\\/',
        'framework de SE5' => '/Illuminate\\\\/',
        'annuaire LDAP' => '/LdapRecord/',
        'appel direct au query builder' => '/\bDB::/',

        // Lookbehind `(?<!\w)` seul, et pas `(?<![\w\\])` : `\auth()` et
        // `\Illuminate\Support\Facades\Auth::` sont du PHP légal (résolution
        // globale, FQCN inline). Exclure l'antislash de tête laisserait donc
        // passer exactement la syntaxe qu'un contributeur pressé emploie.
        'utilisateur connecté (helper)' => '/(?<!\w)auth\s*\(/',
        'utilisateur connecté (façade)' => '/(?<!\w)Auth::/',
        'magasin d\'état serveur de SE5' => '/(?<!\w)session\s*\(/',
    ];

    /**
     * Le code PARFAITEMENT LÉGITIME de l'extension qui ne doit RIEN déclencher.
     *
     * C'est la nuance la plus importante du fichier. La session **native** de
     * PHP est l'infrastructure d'hébergement de l'extension — elle n'a rien à
     * voir avec le magasin d'état serveur de SE5, qui, lui, est interdit. Le
     * lookbehind fait le tri : après `session` vient `_`, jamais `(`.
     *
     * Si un jour quelqu'un « resserrait » la règle en `/session/`, ces
     * contrôles positifs tomberaient — et c'est ce qu'on veut, plutôt qu'une
     * extension privée de sa propre session.
     *
     * @var list<string>
     */
    private const LEGITIMATE_FORMS = [
        'session_start();',
        'session_name(self::COOKIE_NAME);',
        'session_set_cookie_params(["path" => "/ext/bbb"]);',
        'session_regenerate_id(true);',
        'session_destroy();',
        '$_SESSION["identity"] = $claims;',
        'if (session_status() === PHP_SESSION_ACTIVE) { return true; }',
        '$statement = $this->pdo->prepare("SELECT * FROM servers");',
        'new BigBlueButton($baseUrl, $secret, $opts);',
        '$client = new CurlHttpClient();',
    ];

    /** Racine du dépôt (ce fichier vit dans `tests/Architecture/`). */
    private static function repoPath(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . ltrim($relative, '/');
    }

    /**
     * LE scanner. Rend les étiquettes des règles violées par `$content`.
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
    // Volet 1 — la quarantaine de l'extension BBB
    // =====================================================================

    #[Test]
    public function the_bbb_extension_never_reaches_into_sambaedu(): void
    {
        $root = realpath(self::repoPath('extensions/bbb'));
        self::assertNotFalse($root, 'extensions/bbb/ doit exister');

        $finder = (new Finder())
            ->files()
            ->in($root)
            // `vendor/` est du code TIERS installé par composer : il n'est pas
            // couvert par le contrat de l'extension, et il embarque son propre
            // vocabulaire.
            ->exclude(['vendor', 'var', 'build'])
            ->name(['*.php']);

        $inspected = 0;
        $offenders = [];

        foreach ($finder as $file) {
            $inspected++;

            $violations = $this->quarantineViolations((string) $file->getContents());

            if ($violations !== []) {
                $offenders[$file->getRelativePathname()] = $violations;
            }
        }

        // Méta-test #1 : sans ce garde-fou, un répertoire renommé ou déplacé
        // ferait passer le test À VIDE, indéfiniment — et au vert.
        self::assertGreaterThanOrEqual(
            20,
            $inspected,
            'le garde-fou doit inspecter le squelette RÉEL de l\'extension, pas un répertoire vide',
        );

        self::assertSame(
            [],
            $offenders,
            "QUARANTAINE ROMPUE (FR33). L'extension BBB ne prouve le système d'extensions QUE si elle "
            . "n'a accès à rien d'autre que le contrat public : SSO et claims. Fichiers fautifs : "
            . json_encode($offenders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    #[Test]
    public function the_bbb_extension_declares_no_dependency_on_the_core_framework(): void
    {
        // La quarantaine textuelle ne dit rien du `composer.json` : une
        // extension pourrait n'écrire aucun nom interdit ET tirer tout Laravel.
        // La dépendance UNIQUE est le fork BigBlueButton (décision D2).
        $composer = json_decode(
            (string) file_get_contents(self::repoPath('extensions/bbb/composer.json')),
            true,
        );

        self::assertIsArray($composer);

        $required = array_keys((array) ($composer['require'] ?? []));
        $runtime = array_values(array_filter(
            $required,
            static fn (string $name): bool => $name !== 'php' && ! str_starts_with($name, 'ext-'),
        ));

        self::assertSame(['sambaedu/bigbluebutton-api-php'], $runtime);

        foreach (array_merge($required, array_keys((array) ($composer['require-dev'] ?? []))) as $package) {
            self::assertStringNotContainsString('laravel/', $package);
            self::assertStringNotContainsString('illuminate/', $package);
        }
    }

    // =====================================================================
    // Volet 2 — les méta-tests : le scanner mord, et seulement où il faut
    // =====================================================================

    #[Test]
    public function the_quarantine_scanner_actually_detects_a_violation(): void
    {
        // Les aiguilles sont construites par CONCATÉNATION : le fichier de test
        // est lui-même scanné par d'autres garde-fous, et surtout une aiguille
        // écrite d'un bloc finirait par être copiée-collée dans du vrai code.
        $needles = [
            'espace de noms applicatif de SE5' => 'use App' . '\\Models\\User;',
            'framework de SE5' => 'use Illuminate' . '\\Support\\Facades\\Cache;',
            'annuaire LDAP' => 'use Ldap' . 'Record\\Models\\ActiveDirectory\\User;',
            'appel direct au query builder' => '$rows = D' . 'B::table("users")->get();',
            'utilisateur connecté (helper)' => '$user = au' . 'th()->user();',
            'utilisateur connecté (façade)' => '$user = Au' . 'th::user();',
            'magasin d\'état serveur de SE5' => 'ses' . 'sion()->put("k", 1);',
        ];

        // Une règle sans aiguille serait une règle qu'on n'a jamais vue mordre.
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
    }

    #[Test]
    public function known_evasion_forms_are_still_caught(): void
    {
        // Formes LÉGALES en PHP qui esquivent une règle naïve : antislash de
        // résolution globale, FQCN inline, alias d'import. Chacune doit produire
        // AU MOINS une violation — peu importe laquelle.
        $evasions = [
            '$u = \auth()->user();',
            '$u = \Illuminate\Support\Facades\Auth::user();',
            '$v = \session()->get("x");',
            'use Illuminate\Support\Facades\Auth as CurrentUser;',
            'use App\Models\User as Personne;',
            '$rows = \DB::table("users")->get();',
        ];

        foreach ($evasions as $evasion) {
            self::assertNotSame(
                [],
                $this->quarantineViolations($evasion),
                sprintf('FORME D\'ÉVASION NON DÉTECTÉE : « %s »', $evasion),
            );
        }
    }

    #[Test]
    public function the_native_php_session_is_never_mistaken_for_the_sambaedu_store(): void
    {
        // ⚠️ LE contrôle positif inverse, et le plus important du fichier : un
        // scanner qui répondrait « tout est fautif » passerait tous les tests
        // ci-dessus. Ici, on exige qu'il se TAISE sur le code que l'extension
        // écrit légitimement — au premier chef la session NATIVE de PHP, qui est
        // son infrastructure d'hébergement et non le magasin de SE5.
        foreach (self::LEGITIMATE_FORMS as $legitimate) {
            self::assertSame(
                [],
                $this->quarantineViolations($legitimate),
                sprintf(
                    'FAUX POSITIF : « %s » est du code légitime d\'extension. Une règle trop large '
                    . 'priverait l\'extension de sa propre session — ou pousserait à la contourner.',
                    $legitimate,
                ),
            );
        }
    }

    #[Test]
    public function the_textual_scan_has_a_documented_residual_limit(): void
    {
        // Un scan textuel ne peut pas être exhaustif : un nom de classe
        // reconstitué à l'exécution passera toujours. Ce test ne prétend donc pas
        // rendre la triche impossible — il la rend DÉLIBÉRÉE ET VISIBLE EN REVUE,
        // ce qui est l'objectif atteignable. Si cette forme devenait détectée,
        // mettre à jour ce commentaire plutôt que supprimer le test.
        $obfuscated = '$fqcn = "Ap" . "p\\\\Models\\\\User"; $rows = $fqcn::query()->get();';

        self::assertSame([], $this->quarantineViolations($obfuscated));
    }

    // =====================================================================
    // Volet 3 — le manifest RÉEL de l'extension
    // =====================================================================

    /**
     * @return array<string, mixed>
     */
    private static function manifest(): array
    {
        $path = realpath(self::repoPath('extensions/bbb/manifest.json'));
        self::assertNotFalse($path, 'extensions/bbb/manifest.json doit exister');

        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded, 'manifest illisible');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    #[Test]
    public function the_real_manifest_passes_the_v1_validator(): void
    {
        $raw = self::manifest();
        $normalized = (new ExtensionManifestValidator())->validate($raw);

        self::assertSame('bbb', $normalized['id']);
        self::assertSame(ExtensionType::App, $normalized['type']);
        self::assertSame('/ext/bbb', $normalized['entry_url']);

        // Aucune clé hors du contrat FERMÉ.
        self::assertSame([], array_diff(array_keys($raw), array_keys($normalized)));
    }

    #[Test]
    public function the_manifest_declares_the_scopes_it_will_ever_need(): void
    {
        // `ext:update` ne re-négocie JAMAIS les scopes : les déclarer plus tard
        // imposerait un désinstaller/réinstaller au parc. `groups` est déclaré
        // MAINTENANT parce que la story suivante en dépend.
        $scopes = (new ExtensionManifestValidator())->validate(self::manifest())['scopes'];

        self::assertSame(['profile', 'groups'], $scopes);
        self::assertSame([], array_diff($scopes, ['profile', 'groups']), 'liste FERMÉE des scopes existants');
    }

    #[Test]
    public function the_redirect_paths_are_a_lifetime_invariant_of_the_extension(): void
    {
        // `ext:update` refuse tout changement de `redirect_paths`
        // (ERROR_REDIRECT_PATHS_CHANGED) : cette valeur est figée pour la VIE de
        // l'extension. La faire dépendre de quoi que ce soit serait une erreur
        // qu'on ne pourrait corriger que par une désinstallation.
        $install = (new ExtensionManifestValidator())->validate(self::manifest())['install'] ?? null;

        self::assertIsArray($install);
        self::assertSame(['/ext/bbb/oidc/callback'], $install['redirect_paths']);

        foreach ($install['redirect_paths'] as $path) {
            self::assertStringStartsWith('/ext/bbb/', $path);
        }
    }

    #[Test]
    public function the_install_block_respects_the_channel_contract(): void
    {
        $install = (new ExtensionManifestValidator())->validate(self::manifest())['install'] ?? null;

        self::assertIsArray($install);
        self::assertSame('deb', $install['channel']);

        // Chemin RELATIF strict : ni schéma, ni `..`, ni `/` initial.
        self::assertMatchesRegularExpression('#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$#', $install['package']);
        self::assertStringStartsWith('packages/', $install['package']);
        self::assertStringContainsString('sambaedu-ext-bbb', $install['package']);

        // 64 hexadécimaux MINUSCULES — le validateur refuse les majuscules, et
        // c'est une signature de défaut connue de l'Epic 56.
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $install['sha256']);
        self::assertSame(strtolower($install['sha256']), $install['sha256']);
    }

    #[Test]
    public function the_committed_sha256_is_a_placeholder_that_can_never_match_a_real_package(): void
    {
        // DÉCISION DE STORY, écrite plutôt que tue : le `sha256` réel n'est
        // connaissable qu'à la CONSTRUCTION du paquet. Le manifest commité porte
        // donc un remplissage de 64 zéros — forme valide (le validateur l'exige)
        // et impossible à confondre avec un vrai condensat.
        // `packaging/publish-test-repo.sh` l'écrase par la valeur réelle au
        // moment de la publication ; publier le manifest tel quel ferait échouer
        // l'installation à la frontière fail-closed, jamais après.
        $install = self::manifest()['install'];

        self::assertSame(str_repeat('0', 64), $install['sha256']);
    }

    #[Test]
    public function the_tile_is_visible_to_the_roles_the_ac_requires(): void
    {
        $roles = (new ExtensionManifestValidator())->validate(self::manifest())['visibility']['roles'];

        // L'AC exige au minimum professeurs et élèves.
        self::assertContains('prof', $roles);
        self::assertContains('eleve', $roles);

        // Et le vocabulaire reste celui des rôles MÉTIER.
        self::assertSame([], array_diff($roles, ['admin', 'prof', 'eleve', 'administratif']));
    }

    #[Test]
    public function the_package_name_matches_what_the_root_helper_will_verify(): void
    {
        // Le helper root exige `dpkg-deb --field <deb> Package` ===
        // `sambaedu-ext-<key>`. Un écart = refus côté root, après téléchargement
        // — c'est-à-dire une installation qui échoue tard et sans raison lisible.
        $control = (string) file_get_contents(self::repoPath('extensions/bbb/packaging/build-deb.sh'));

        self::assertStringContainsString('PKG="sambaedu-ext-${KEY}"', $control);
        self::assertStringContainsString('KEY="bbb"', $control);
        self::assertStringContainsString('Package: ${PKG}', $control);
    }

    #[Test]
    public function the_unit_never_enables_itself_and_keeps_its_state_through_a_volatile_uid(): void
    {
        // Deux invariants de l'unité livrée par le paquet :
        //  - le paquet n'active ni ne démarre JAMAIS son service (contrat 56.2) ;
        //  - `DynamicUser=yes` impose `StateDirectory=` : un `chown` figé au
        //    postinst désignerait un UID qui n'existe plus au redémarrage
        //    suivant.
        $unit = (string) file_get_contents(self::repoPath('extensions/bbb/packaging/sambaedu-ext-bbb.service'));

        self::assertStringContainsString('DynamicUser=yes', $unit);
        self::assertStringContainsString('StateDirectory=sambaedu-ext-bbb', $unit);
        self::assertStringContainsString('EnvironmentFile=/etc/sambaedu/extensions/bbb.env', $unit);
        self::assertStringContainsString('127.0.0.1:${SE5_EXT_PORT}', $unit);

        $postinst = (string) file_get_contents(self::repoPath('extensions/bbb/packaging/build-deb.sh'));
        self::assertStringNotContainsString('systemctl enable', $postinst);
        self::assertStringNotContainsString('systemctl start', $postinst);
    }
}
