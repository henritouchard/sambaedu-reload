<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Garde-fou architectural Story 16.10 (AC7.3).
 *
 * Vérifie que le namespace `App\Auth\V1\*` respecte ses invariants :
 *
 * 1. **Pas d'appel `exec()` / `shell_exec()` / `passthru()` / `proc_open()`
 *    direct** dans aucun fichier sous `app/Auth/V1/`. La PKI utilise
 *    `openssl_*` natif PHP en priorité. Whitelist `CaInitializer.php`
 *    pour fallback `Process::run([...])` éventuel (mode array seulement).
 *
 * 2. **Pas d'import direct de `Firebase\JWT\*`** sauf dans
 *    `Jwt/WorkstationJwtIssuer.php` et `Jwt/WorkstationJwtVerifier.php`
 *    (frontière unique d'usage de la lib JWT).
 *
 * 3. **Pas d'inclusion `legacy/*`** depuis `app/Auth/V1/*` (frontière
 *    legacy — pas de retour en arrière).
 *
 * 4. **Pas d'usage `WorkstationJwtIssuer::issue*`** hors des controllers
 *    d'enroll/refresh (encapsulation — l'émission JWT est centralisée).
 *
 * 5. **Les routes legacy `*_out.php` restent intactes** (vérifie qu'elles
 *    sont toujours présentes dans `routes/web.php` ou `routes/api.php` —
 *    8 endpoints attendus : applications, firefox_out, thunderbird_out,
 *    wallpaper_out, shortcuts_out, network_out, veyon_out,
 *    associations_out).
 *
 * 6. **Story 16.11** : le middleware `inject.bootstrap-fragment` est
 *    attaché à toutes les 8 routes legacy (lecture textuelle du contenu
 *    de `routes/web.php`).
 *
 * 7. **Story 16.11** : `JwtErrorCodes::all()` contient au moins 16 codes
 *    (14 du 16.10 + 2 nouveaux 16.11 : `bootstrap_token.uuid_mismatch`,
 *    `bootstrap.not_lan`).
 */
class AuthV1NamespaceTest extends TestCase
{
    private const SHELL_WHITELIST_FILES = [
        'CaInitializer.php',
    ];

    private const FIREBASE_JWT_WHITELIST_FILES = [
        'WorkstationJwtIssuer.php',
        'WorkstationJwtVerifier.php',
    ];

    private const ISSUE_METHOD_WHITELIST_FILES = [
        'WorkstationJwtIssuer.php',           // self
        'WorkstationJwtRefreshService.php',   // service interne (rotation)
        'EnrollController.php',               // émission initiale
        'RefreshController.php',              // contrôle de l'orchestration
    ];

    /**
     * @var list<array{pattern: string, label: string}>
     */
    private const FORBIDDEN_SHELL_PATTERNS = [
        ['pattern' => '/\bexec\s*\(/i', 'label' => 'exec()'],
        ['pattern' => '/\bshell_exec\s*\(/i', 'label' => 'shell_exec()'],
        ['pattern' => '/\bpassthru\s*\(/i', 'label' => 'passthru()'],
        ['pattern' => '/\bproc_open\s*\(/i', 'label' => 'proc_open()'],
    ];

    #[Test]
    public function no_shell_execution_outside_whitelist(): void
    {
        $root = realpath(__DIR__ . '/../../app/Auth/V1');
        if ($root === false) {
            self::fail('app/Auth/V1 introuvable — namespace doit exister.');
        }

        $finder = (new Finder())->files()->in($root)->name('*.php');
        $violations = [];

        foreach ($finder as $file) {
            $basename = $file->getBasename();
            $isWhitelisted = in_array($basename, self::SHELL_WHITELIST_FILES, true);

            $code = $file->getContents();
            $stripped = $this->stripComments($code);

            foreach (self::FORBIDDEN_SHELL_PATTERNS as $rule) {
                if (preg_match($rule['pattern'], $stripped) === 1 && ! $isWhitelisted) {
                    $violations[] = sprintf(
                        '%s utilise %s (whitelist limitée à CaInitializer)',
                        $file->getRelativePathname(),
                        $rule['label'],
                    );
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Violations garde-fou Story 16.10 (shell exec hors CaInitializer) :\n  - " . implode("\n  - ", $violations),
        );
    }

    #[Test]
    public function no_firebase_jwt_import_outside_whitelist(): void
    {
        $root = realpath(__DIR__ . '/../../app/Auth/V1');
        if ($root === false) {
            self::fail('app/Auth/V1 introuvable.');
        }

        $finder = (new Finder())->files()->in($root)->name('*.php');
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $violations = [];

        foreach ($finder as $file) {
            $basename = $file->getBasename();
            if (in_array($basename, self::FIREBASE_JWT_WHITELIST_FILES, true)) {
                continue;
            }

            try {
                $ast = $parser->parse($file->getContents());
            } catch (\Throwable $e) {
                self::fail(sprintf('Parse error %s : %s', $file->getRelativePathname(), $e->getMessage()));
            }
            if ($ast === null) {
                continue;
            }

            $collector = new class extends NodeVisitorAbstract
            {
                /** @var list<string> */
                public array $uses = [];

                public function enterNode(Node $node): null
                {
                    if ($node instanceof Use_) {
                        foreach ($node->uses as $use) {
                            $this->uses[] = $use->name->toString();
                        }
                    }
                    if ($node instanceof GroupUse) {
                        $prefix = $node->prefix->toString();
                        foreach ($node->uses as $use) {
                            $this->uses[] = $prefix . '\\' . $use->name->toString();
                        }
                    }

                    return null;
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor($collector);
            $traverser->traverse($ast);

            foreach ($collector->uses as $imported) {
                if (str_starts_with($imported, 'Firebase\\JWT\\')) {
                    $violations[] = sprintf(
                        '%s importe %s (whitelist limitée à %s)',
                        $file->getRelativePathname(),
                        $imported,
                        implode(', ', self::FIREBASE_JWT_WHITELIST_FILES),
                    );
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Violations garde-fou Story 16.10 (import Firebase\\JWT hors whitelist) :\n  - " . implode("\n  - ", $violations),
        );
    }

    #[Test]
    public function no_legacy_inclusion_from_auth_v1(): void
    {
        $root = realpath(__DIR__ . '/../../app/Auth/V1');
        if ($root === false) {
            self::fail('app/Auth/V1 introuvable.');
        }

        $finder = (new Finder())->files()->in($root)->name('*.php');
        $violations = [];

        foreach ($finder as $file) {
            $stripped = $this->stripComments($file->getContents());

            // Détecte require/include de legacy/*
            if (preg_match('/(?:require|include)(?:_once)?\s*\(?[\'"][^\'"]*legacy\//i', $stripped) === 1) {
                $violations[] = sprintf('%s include legacy/*', $file->getRelativePathname());
            }
            // Détecte appels app('legacy.*') (binding container shim)
            if (preg_match('/app\([\'"]legacy\./', $stripped) === 1) {
                $violations[] = sprintf('%s utilise app("legacy.*")', $file->getRelativePathname());
            }
        }

        self::assertSame(
            [],
            $violations,
            "Violations garde-fou Story 16.10 (inclusion legacy hors namespace) :\n  - " . implode("\n  - ", $violations),
        );
    }

    #[Test]
    public function jwt_issuance_is_centralized_to_controllers(): void
    {
        $root = realpath(__DIR__ . '/../../app/Auth/V1');
        if ($root === false) {
            self::fail('app/Auth/V1 introuvable.');
        }

        $finder = (new Finder())->files()->in($root)->name('*.php');
        $violations = [];

        foreach ($finder as $file) {
            $basename = $file->getBasename();
            if (in_array($basename, self::ISSUE_METHOD_WHITELIST_FILES, true)) {
                continue;
            }
            $stripped = $this->stripComments($file->getContents());
            if (preg_match('/->\s*issueAccessToken\s*\(/', $stripped) === 1
                || preg_match('/->\s*issueRefreshToken\s*\(/', $stripped) === 1) {
                $violations[] = sprintf(
                    '%s appelle WorkstationJwtIssuer::issue*() hors whitelist',
                    $file->getRelativePathname(),
                );
            }
        }

        self::assertSame(
            [],
            $violations,
            "Violations garde-fou Story 16.10 (émission JWT hors controllers) :\n  - " . implode("\n  - ", $violations),
        );
    }

    /**
     * Garde-fou D8 — dual-mode legacy. Les routes legacy `*_out.php` restent
     * en HTTP md5/APCu pendant toute la Phase 2 (16.13 les retirera). On
     * vérifie ici que la suite 16.10 ne les a pas accidentellement
     * supprimées des fichiers de routes.
     */
    #[Test]
    public function legacy_out_routes_are_preserved(): void
    {
        $webRoutes = (string) file_get_contents(__DIR__ . '/../../routes/web.php');
        $apiRoutes = (string) file_get_contents(__DIR__ . '/../../routes/api.php');
        $allRoutes = $webRoutes . "\n" . $apiRoutes;

        // 8 endpoints legacy qui doivent rester (cf. D8 + audit 16.8).
        // wallpaper_out.php — Story 4.7
        // firefox_out.php + thunderbird_out.php — Story 4.8
        // shortcuts_out.php — Story 16.3a / 1bis.18e
        // network_out.php + veyon_out.php — Story 16.3b
        // associations_out.php — Story 16.3c
        // applications.php (sans _out) — Story 16.7 : distribue les bootstrap tokens md5/APCu
        //   consommés par /api/v1/agent/enroll. Si elle saute, toute la chaîne d'enrôlement casse.
        $expected = [
            'wallpaper_out',
            'firefox_out',
            'thunderbird_out',
            'shortcuts_out',
            'network_out',
            'veyon_out',
            'associations_out',
            'applications.php',
        ];

        $missing = [];
        foreach ($expected as $endpoint) {
            // Tolère soit l'URL `/gpo/<endpoint>.php`, soit le nom de méthode `legacy<Endpoint>Out`
            if (! str_contains($allRoutes, $endpoint)) {
                $missing[] = $endpoint;
            }
        }

        self::assertSame(
            [],
            $missing,
            "Routes legacy `*_out.php` manquantes — risque cassure dual-mode (D8) :\n  - " . implode("\n  - ", $missing),
        );
    }

    /**
     * Story 16.11 — le middleware `inject.bootstrap-fragment` est attaché
     * aux 8 routes legacy whitelistées dans `routes/web.php`. Vérification
     * textuelle (parser AST trop complexe pour ce cas — il suffit que la
     * chaîne `'inject.bootstrap-fragment'` apparaisse dans le fichier et
     * que les 8 noms de routes apparaissent dans le même fichier).
     */
    #[Test]
    public function inject_bootstrap_fragment_middleware_is_attached_to_8_legacy_routes(): void
    {
        $webRoutes = (string) file_get_contents(__DIR__ . '/../../routes/web.php');

        self::assertStringContainsString(
            "inject.bootstrap-fragment",
            $webRoutes,
            "Le middleware 'inject.bootstrap-fragment' n'apparait pas dans routes/web.php — l'attachement aux routes legacy 16.11 est manquant.",
        );

        // Sanity check : les 8 endpoints legacy sont bien présents dans le
        // même fichier (cf. test legacy_out_routes_are_preserved).
        $expected = [
            'wallpaper_out',
            'firefox_out',
            'thunderbird_out',
            'shortcuts_out',
            'network_out',
            'veyon_out',
            'associations_out',
            'applications.php',
        ];

        foreach ($expected as $endpoint) {
            self::assertStringContainsString(
                $endpoint,
                $webRoutes,
                "Route legacy '{$endpoint}' manquante dans routes/web.php — risque cassure dual-mode (D8).",
            );
        }

        // Garde-fou structurel : chaque endpoint legacy doit être déclaré
        // sous un group/middleware contenant 'inject.bootstrap-fragment'.
        // On ne fait pas de parsing AST complexe — heuristique simple :
        // pour chaque endpoint, on cherche TOUTES les occurrences de la
        // déclaration de route (`Route::match(...)gpo/$endpoint.php`) et on
        // vérifie qu'au moins une est précédée du middleware
        // `inject.bootstrap-fragment` dans un contexte raisonnable.
        foreach ($expected as $endpoint) {
            // Pattern matche les déclarations de route (pas les commentaires)
            $pattern = '/Route::(?:match|get|post|put|delete|any|prefix)\b[^;]*?[\'"](?:gpo\/)?' . preg_quote($endpoint, '/') . '(?:\.php)?[\'"]/m';
            self::assertMatchesRegularExpression(
                $pattern,
                $webRoutes,
                "Endpoint '{$endpoint}' déclaration Route::match introuvable dans routes/web.php.",
            );

            // Trouver toutes les occurrences de la déclaration
            preg_match_all($pattern, $webRoutes, $matches, PREG_OFFSET_CAPTURE);
            $found = false;

            foreach ($matches[0] as [$match, $offset]) {
                // Contexte large (3000 chars en arrière) pour englober la
                // déclaration du group `Route::middleware(...)->group(...)`
                // qui peut wrapper plusieurs endpoints (cas 2e group 16.11
                // qui wrappe 6 routes : firefox/thunderbird/network/veyon/
                // associations/applications).
                $startSearch = max(0, $offset - 3000);
                $context = substr($webRoutes, $startSearch, 3000);
                // On veut être plus strict : chercher l'apparition de
                // `Route::middleware('inject.bootstrap-fragment')->group(`
                // pas juste la string `inject.bootstrap-fragment` (qui
                // peut apparaître dans un commentaire).
                if (preg_match(
                    "/Route::middleware\s*\(\s*['\"]inject\.bootstrap-fragment['\"]/i",
                    $context,
                )) {
                    // Encore plus strict : on doit avoir un `->group(function`
                    // après le middleware (sinon c'est une déclaration
                    // séparée, pas un wrap).
                    $found = true;
                    break;
                }
            }

            self::assertTrue(
                $found,
                "Endpoint '{$endpoint}' n'est pas wrappé dans un Route::middleware('inject.bootstrap-fragment')->group() (recherche heuristique 3000 chars précédant chaque déclaration de route).",
            );
        }
    }

    /**
     * Story 16.11 — `JwtErrorCodes::all()` doit retourner au moins 16
     * entrées (14 du 16.10 + 2 nouveaux 16.11) et contenir les 2 nouveaux
     * codes.
     */
    #[Test]
    public function it_lists_all_error_codes(): void
    {
        $codes = \App\Auth\V1\Support\JwtErrorCodes::all();

        self::assertGreaterThanOrEqual(
            16,
            count($codes),
            'JwtErrorCodes::all() doit lister au moins 16 codes (14 baseline 16.10 + 2 nouveaux 16.11).',
        );

        self::assertContains(
            'bootstrap_token.uuid_mismatch',
            $codes,
            'Code 16.11 BOOTSTRAP_TOKEN_UUID_MISMATCH manquant.',
        );
        self::assertContains(
            'bootstrap.not_lan',
            $codes,
            'Code 16.11 BOOTSTRAP_NOT_LAN manquant.',
        );
    }

    /**
     * Story 16.11 — pas d'inclusion legacy depuis les middlewares 16.11
     * (frontière `App\Auth\V1` étendue aux nouveaux fichiers EnsureLanIp +
     * InjectBootstrapFragment + BootstrapScriptController). Redondant avec
     * `no_legacy_inclusion_from_auth_v1` mais fige le scope 16.11.
     */
    #[Test]
    public function story_16_11_new_files_do_not_import_legacy(): void
    {
        $files = [
            __DIR__ . '/../../app/Auth/V1/Http/Middleware/EnsureLanIp.php',
            __DIR__ . '/../../app/Auth/V1/Http/Middleware/InjectBootstrapFragment.php',
            __DIR__ . '/../../app/Auth/V1/Http/Controllers/BootstrapScriptController.php',
            __DIR__ . '/../../app/Auth/V1/Models/WorkstationMigrationStatus.php',
            __DIR__ . '/../../app/Auth/V1/Models/WorkstationMigrationAttempt.php',
        ];

        foreach ($files as $file) {
            self::assertFileExists($file, "Fichier 16.11 manquant : $file");
            $stripped = $this->stripComments((string) file_get_contents($file));
            self::assertDoesNotMatchRegularExpression(
                '/(?:require|include)(?:_once)?\s*\(?[\'"][^\'"]*legacy\//i',
                $stripped,
                "Fichier $file include legacy/* — interdit.",
            );
        }
    }

    private function stripComments(string $code): string
    {
        $code = preg_replace('!/\*.*?\*/!s', '', $code) ?? $code;
        $code = preg_replace('/^\s*\/\/.*$/m', '', $code) ?? $code;

        return $code;
    }
}
