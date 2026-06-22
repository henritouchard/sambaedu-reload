<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Iso\Services;

use App\Ipxe\Iso\Exceptions\WindowsIsoValidationException;
use App\Ipxe\Iso\Services\WindowsIsoUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 3.6 — AC2.* — Tests unitaires de WindowsIsoUrlValidator.
 *
 * Couvre :
 *  - URLs valides (Win10 / Win11 — extraction iso_name + version_num).
 *  - URLs invalides (scheme HTTP, allowlist host, path traversal, shell
 *    injection, newline, backtick, localhost, internal IP, userinfo, etc.).
 *
 * Anti-SSRF/RCE : ≥10 payloads malicieux via dataProvider.
 */
class WindowsIsoUrlValidatorTest extends TestCase
{
    private WindowsIsoUrlValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        // Allowlist fixe pour les tests (pas de dépendance .env).
        // `download.prss.microsoft.com` couvre les sous-domaines software./software-static.
        config(['ipxe.iso_management.allowed_url_hosts' => [
            'download.prss.microsoft.com',
            'software-download.microsoft.com',
            'download.microsoft.com',
        ]]);
        $this->validator = new WindowsIsoUrlValidator();
    }

    /* =================================================================
     * Cas valides
     * ================================================================= */

    #[Test]
    public function it_accepts_a_valid_win11_url_on_prss_microsoft(): void
    {
        $url = 'https://software-static.download.prss.microsoft.com/dbazure/abc/Win11_24H2.iso';
        $result = $this->validator->validate($url);

        self::assertSame($url, $result['url']);
        self::assertSame('Win11_24H2.iso', $result['iso_name']);
        self::assertSame('Win11', $result['version']);
        self::assertSame('11', $result['version_num']);
    }

    #[Test]
    public function it_accepts_a_valid_win10_url_on_download_microsoft(): void
    {
        $url = 'https://download.microsoft.com/sources/Win10_22H2.iso';
        $result = $this->validator->validate($url);

        self::assertSame('Win10_22H2.iso', $result['iso_name']);
        self::assertSame('Win10', $result['version']);
        self::assertSame('10', $result['version_num']);
    }

    #[Test]
    public function it_accepts_an_url_on_a_subdomain_of_an_allowed_host(): void
    {
        // Design D5 — sous-domaines Microsoft acceptés. Voir runbook 3.6-13.
        // host `secure.download.microsoft.com` ends-with `.download.microsoft.com`
        // → allowed via `str_ends_with($host, '.'.$allowed)`.
        // Décision design (cf. _bmad-output/codeReviews/3-6.md #3 / #12) :
        // tout sous-domaine `*.download.microsoft.com` est intentionnellement
        // accepté (Microsoft contrôle ses sous-domaines + admin restreint
        // `server.admin`). Le test négatif `microsoft.com.evil.com` confirme
        // que les attaques par sous-domaine d'allowlist sont bloquées.
        $url = 'https://secure.download.microsoft.com/path/Win11_25H1.iso';
        $result = $this->validator->validate($url);

        self::assertSame('Win11_25H1.iso', $result['iso_name']);
        self::assertSame('11', $result['version_num']);
    }

    /* =================================================================
     * Cas invalides (≥10 data-providers)
     * ================================================================= */

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideInvalidUrls(): array
    {
        return [
            // Anti-SSRF / scheme
            'http non https'        => ['http://software-download.microsoft.com/Win11.iso',     'HTTPS'],
            'ftp scheme'            => ['ftp://software-download.microsoft.com/Win11.iso',     'HTTPS'],
            'no scheme'             => ['//software-download.microsoft.com/Win11.iso',          'HTTPS'],

            // Anti-SSRF / allowlist host
            'host evil.com'         => ['https://evil.com/Win11.iso',                           'non autorisé'],
            'localhost'             => ['https://localhost/Win11.iso',                          'non autorisé'],
            'internal IP'           => ['https://192.168.1.1/Win11.iso',                        'non autorisé'],
            'attacker bare ms'      => ['https://microsoft.com/Win11.iso',                      'non autorisé'],
            'fake subdomain trick'  => ['https://microsoft.com.evil.com/Win11.iso',             'non autorisé'],

            // Anti-RCE / extraction iso_name
            'no Win prefix'         => ['https://download.microsoft.com/Office.iso',            'Win(10|11'],
            'no iso extension'      => ['https://download.microsoft.com/Win11_24H2.exe',        'Win(10|11'],
            'path traversal'        => ['https://download.microsoft.com/../../etc/Win11.iso',   'séquence interdite'],
            'path traversal encoded'=> ['https://download.microsoft.com/%2e%2e/etc/Win11.iso',  'séquence interdite'],

            // Anti-RCE / shell injection (caractères interdits par regex)
            'shell ; injection'     => ["https://download.microsoft.com/Win11.iso;curl evil",   'Win(10|11'],
            'shell & injection'     => ['https://download.microsoft.com/Win11.iso&pwn',         'Win(10|11'],
            'shell $() injection'   => ['https://download.microsoft.com/Win11$(curl evil).iso', 'Win(10|11'],
            'backtick injection'    => ['https://download.microsoft.com/Win11`evil`.iso',       'Win(10|11'],

            // Anti-control-char / null byte / newline
            'newline injection'     => ["https://download.microsoft.com/Win11.iso\n;rm -rf /",  'contrôle'],
            'null byte injection'   => ["https://download.microsoft.com/Win11.iso\x00.txt",     'contrôle'],
            'carriage return'       => ["https://download.microsoft.com/Win11\r.iso",           'contrôle'],

            // Anti-userinfo trick
            'userinfo trick'        => ['https://allowed@evil.com/Win11.iso',                   'authentification'],

            // Versions exotiques (Win7/Win8 explicitement rejetés)
            'Win7'                  => ['https://download.microsoft.com/Win7_SP1.iso',          'Win(10|11'],

            // Empty / too long
            'empty url'             => ['',                                                     'longueur'],
        ];
    }

    #[Test]
    #[DataProvider('provideInvalidUrls')]
    public function it_rejects_invalid_urls(string $url, string $expectedMessageSubstring): void
    {
        try {
            $this->validator->validate($url);
            self::fail('Attendait une WindowsIsoValidationException pour URL : ' . $url);
        } catch (WindowsIsoValidationException $e) {
            self::assertStringContainsString(
                $expectedMessageSubstring,
                $e->getMessage(),
                'Message d\'erreur attendu pour URL ' . $url . ' : "' . $expectedMessageSubstring . '" — reçu : ' . $e->getMessage(),
            );
        }
    }

    #[Test]
    public function it_accepts_the_new_software_prss_host(): void
    {
        // Microsoft sert désormais depuis `software.download.prss.microsoft.com`
        // (l'ancien était `software-static.download.prss.microsoft.com`).
        $url = 'https://software.download.prss.microsoft.com/dbazure/Win11_25H2_French_x64_v2.iso';

        $result = $this->validator->validate($url);

        self::assertSame('Win11_25H2_French_x64_v2.iso', $result['iso_name']);
        self::assertSame('Win11', $result['version']);
    }

    #[Test]
    public function it_accepts_a_signed_url_with_query_string(): void
    {
        // URL signée réelle (token + paramètres après `.iso`).
        $url = 'https://software.download.prss.microsoft.com/dbazure/Win11_25H2_French_x64_v2.iso'
            . '?t=b6350761-ef2f-44f0-875c-797215ff8359&P1=1782222087&P2=602&P3=2'
            . '&P4=VbGxxtau%2bFKGWwJBQ%2fUiaKoED%3d%3d';

        // Couche 1 (regex Livewire) doit matcher l'URL complète (query incluse).
        self::assertSame(1, preg_match(WindowsIsoUrlValidator::URL_PATH_REGEX, $url),
            'La couche 1 doit accepter une URL signée avec query string.');

        // Couche 2 (service) extrait l'iso_name du PATH seul, la query est ignorée.
        $result = $this->validator->validate($url);
        self::assertSame('Win11_25H2_French_x64_v2.iso', $result['iso_name']);
        self::assertSame('Win11', $result['version']);
        // L'URL stockée/curlée conserve la query signée (obligatoire au download).
        self::assertSame($url, $result['url']);
    }

    #[Test]
    public function url_path_regex_still_rejects_injection_after_iso(): void
    {
        // La query autorisée commence par `?` ou `#` ; un `;`/`&`/espace après
        // `.iso` reste rejeté par la couche 1.
        foreach ([
            'https://download.microsoft.com/Win11.iso;curl evil',
            'https://download.microsoft.com/Win11.iso&pwn',
            "https://download.microsoft.com/Win11.iso\n;rm -rf /",
        ] as $bad) {
            self::assertSame(0, preg_match(WindowsIsoUrlValidator::URL_PATH_REGEX, $bad),
                "La couche 1 doit rejeter : {$bad}");
        }
    }

    #[Test]
    public function it_rejects_url_longer_than_2048_chars(): void
    {
        // 2049 chars d'URL — `str_repeat('x', 2000)` dans le path.
        $url = 'https://download.microsoft.com/' . str_repeat('x', 2050) . '/Win11.iso';
        $this->expectException(WindowsIsoValidationException::class);
        $this->expectExceptionMessage('longueur');
        $this->validator->validate($url);
    }

    /* =================================================================
     * Dépôt manuel — validateUploadFilename()
     * ================================================================= */

    #[Test]
    public function it_validates_a_safe_upload_filename_and_derives_version(): void
    {
        $result = $this->validator->validateUploadFilename('Win11_24H2_French_x64.iso', 'Win11');

        self::assertSame('Win11_24H2_French_x64.iso', $result['iso_name']);
        self::assertSame('Win11', $result['version']);
        self::assertSame('11', $result['version_num']);
    }

    #[Test]
    public function it_accepts_a_custom_filename_with_explicit_version(): void
    {
        // Le nom n'a pas à suivre la convention Microsoft (≠ flux URL) :
        // la version vient du select.
        $result = $this->validator->validateUploadFilename('custom-image_v2.iso', 'Win10');

        self::assertSame('custom-image_v2.iso', $result['iso_name']);
        self::assertSame('Win10', $result['version']);
        self::assertSame('10', $result['version_num']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function badUploadFilenameProvider(): iterable
    {
        yield 'path traversal'        => ['../../etc/passwd.iso', 'Win11'];
        yield 'slash'                 => ['dir/Win11.iso', 'Win11'];
        yield 'backslash'             => ['dir\\Win11.iso', 'Win11'];
        yield 'double dot embedded'   => ['Win11..iso', 'Win11'];
        yield 'no iso extension'      => ['Win11_24H2.img', 'Win11'];
        yield 'space'                 => ['Win 11.iso', 'Win11'];
        yield 'shell metachar'        => ['Win11;rm.iso', 'Win11'];
        yield 'newline'               => ["Win11\n.iso", 'Win11'];
        yield 'null byte'             => ["Win11\0.iso", 'Win11'];
    }

    #[Test]
    #[DataProvider('badUploadFilenameProvider')]
    public function it_rejects_unsafe_upload_filenames(string $filename, string $version): void
    {
        $this->expectException(WindowsIsoValidationException::class);
        $this->validator->validateUploadFilename($filename, $version);
    }

    #[Test]
    public function it_rejects_unsupported_version_on_upload(): void
    {
        $this->expectException(WindowsIsoValidationException::class);
        $this->validator->validateUploadFilename('Win7_sp1.iso', 'Win7');
    }
}
