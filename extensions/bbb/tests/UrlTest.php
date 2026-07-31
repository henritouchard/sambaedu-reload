<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SambaEdu\ExtBbb\Env;
use SambaEdu\ExtBbb\Url;

/**
 * Story 57.1 — **LE PIÈGE N°1, VERROUILLÉ PAR TEST.**
 *
 * Le proxy Apache RETIRE `/ext/bbb` : le backend reçoit des chemins NUS, mais
 * doit émettre des URL PRÉFIXÉES. Ces deux moitiés se contredisent dès qu'on les
 * oublie — d'où un générateur unique, et un test qui vérifie qu'il est bien le
 * seul.
 */
final class UrlTest extends TestCase
{
    protected function tearDown(): void
    {
        Url::reset();
    }

    #[Test]
    public function every_generated_url_carries_the_base_path(): void
    {
        Url::configure('/ext/bbb');

        self::assertSame('/ext/bbb/', Url::to('/'));
        self::assertSame('/ext/bbb/login', Url::to('/login'));
        self::assertSame('/ext/bbb/oidc/callback', Url::to('/oidc/callback'));
        self::assertSame('/ext/bbb/admin/servers', Url::to('/admin/servers'));
        self::assertSame('/ext/bbb/assets/app.css', Url::to('/assets/app.css'));
    }

    #[Test]
    public function an_empty_base_path_is_a_legitimate_value(): void
    {
        // Développement hors proxy (`php -S` direct) : le code ne doit jamais
        // supposer le préfixe non vide.
        Url::configure('');

        self::assertSame('/', Url::to('/'));
        self::assertSame('/login', Url::to('/login'));
    }

    #[Test]
    public function a_path_without_leading_slash_never_produces_a_glued_url(): void
    {
        Url::configure('/ext/bbb');

        self::assertSame('/ext/bbb/login', Url::to('login'));
        self::assertSame('/ext/bbb/', Url::to(''));
    }

    #[Test]
    public function a_trailing_slash_in_the_base_path_is_normalized(): void
    {
        Url::configure('/ext/bbb/');

        self::assertSame('/ext/bbb/login', Url::to('/login'));
    }

    #[Test]
    public function generating_a_url_before_configuration_fails_loudly(): void
    {
        // Un repli silencieux sur `''` produirait des liens qui SORTENT de
        // l'extension — cassés en production, verts en test.
        Url::reset();

        $this->expectException(RuntimeException::class);
        Url::to('/login');
    }

    #[Test]
    public function the_only_sambaedu_url_the_extension_knows_is_its_issuer(): void
    {
        $env = Env::capture([
            'SE5_EXT_BASE_PATH' => '/ext/bbb',
            'SE5_OIDC_ISSUER' => 'https://se5.example.test/',
            'STATE_DIRECTORY' => sys_get_temp_dir(),
        ]);

        self::assertSame('https://se5.example.test', Url::backToSambaEdu($env));
    }

    // =====================================================================
    // Aucune URL absolue en dur : le scan
    // =====================================================================

    #[Test]
    public function no_view_ever_writes_an_absolute_url_by_hand(): void
    {
        $views = glob(dirname(__DIR__) . '/views/*.php') ?: [];

        // Méta-contrôle : un chemin cassé ferait passer la boucle à vide.
        self::assertGreaterThanOrEqual(4, count($views), 'le scan doit couvrir les vues RÉELLES');

        $offenders = [];

        foreach ($views as $view) {
            $content = (string) file_get_contents($view);

            // Tout attribut d'URL doit être une EXPRESSION PHP (donc issue de
            // `bbb_url()`), jamais un littéral.
            if (preg_match_all('/\s(?:href|src|action)="(?!<\?)([^"]*)"/', $content, $matches) > 0) {
                $offenders[basename($view)] = $matches[1];
            }
        }

        self::assertSame(
            [],
            $offenders,
            'URL littérale dans une vue : elle sortirait du préfixe /ext/bbb dès la première installation réelle',
        );
    }

    #[Test]
    public function the_source_tree_never_hardcodes_the_extension_prefix(): void
    {
        // `/ext/bbb` ne doit apparaître nulle part en dur dans le CODE : il vient
        // de `SE5_EXT_BASE_PATH`. Les seules occurrences légitimes sont
        // documentaires (commentaires) — on ne scanne donc que le code exécuté.
        $offenders = [];

        foreach (self::sourceFiles() as $file) {
            $stripped = self::stripComments((string) file_get_contents($file));

            if (str_contains($stripped, '/ext/bbb')) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, 'préfixe /ext/bbb en dur : il doit venir de l\'environnement');
    }

    /** @return list<string> */
    private static function sourceFiles(): array
    {
        $files = [];
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($directory as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function stripComments(string $code): string
    {
        $out = '';

        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
