<?php

declare(strict_types=1);

namespace Tests\Unit\Extensions;

use App\Exceptions\InvalidExtensionManifestException;
use App\Services\Extensions\ExtensionManifestValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 56.2 (AC1) — Extension ADDITIVE du manifest v1 : bloc `install` et
 * règle `entry_url` du type `app`.
 *
 * Le fichier {@see ExtensionManifestValidatorTest} (54.1) reste la référence du
 * contrat de base et n'est pas réécrit : on ajoute ici, à côté, ce que 56.2
 * introduit — plus une contre-épreuve explicite que le contrat v1 SANS bloc
 * `install` est resté valide verbatim (NFR11).
 */
class ExtensionManifestValidatorInstallBlockTest extends TestCase
{
    private ExtensionManifestValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ExtensionManifestValidator();
    }

    /** Manifest `link` v1 minimal — celui de 54.1, inchangé. */
    private function linkManifest(array $overrides = []): array
    {
        return array_merge([
            'manifest_version' => 1,
            'id' => 'doc',
            'type' => 'link',
            'name' => 'Documentation',
            'version' => '1.0.0',
            'entry_url' => '/doc',
            'icon' => 'fa-solid fa-book-open',
            'publisher' => 'SambaEdu',
            'description' => 'Documentation publique.',
            'scopes' => [],
            'dependencies' => [],
            'visibility' => ['roles' => ['admin', 'prof', 'eleve']],
        ], $overrides);
    }

    /** Manifest `app` conforme à AR3, avec son bloc `install`. */
    private function appManifest(array $overrides = [], mixed $install = null): array
    {
        $manifest = array_merge([
            'manifest_version' => 1,
            'id' => 'hello',
            'type' => 'app',
            'name' => 'Hello',
            'version' => '1.0.0',
            'entry_url' => '/ext/hello',
            'icon' => 'fa-solid fa-hand',
            'publisher' => 'QA',
            'description' => 'Extension de test.',
            'scopes' => [],
            'dependencies' => [],
            'visibility' => ['roles' => ['admin']],
        ], $overrides);

        if ($install !== null) {
            $manifest['install'] = $install;
        }

        return $manifest;
    }

    /** Bloc `install` nominal. */
    private function installBlock(array $overrides = []): array
    {
        return array_merge([
            'channel' => 'deb',
            'package' => 'packages/sambaedu-ext-hello_1.0.0_all.deb',
            'sha256' => str_repeat('a1', 32),
        ], $overrides);
    }

    // =====================================================================
    // Additivité (NFR11) — un manifest v1 sans bloc `install` est INCHANGÉ
    // =====================================================================

    #[Test]
    public function a_manifest_without_an_install_block_stays_valid_and_gains_no_key(): void
    {
        $normalized = $this->validator->validate($this->linkManifest());

        self::assertArrayNotHasKey('install', $normalized);
        self::assertSame('/doc', $normalized['entry_url']);
    }

    #[Test]
    public function an_app_manifest_without_an_install_block_is_valid_too(): void
    {
        // Le catalogue doit pouvoir AFFICHER une `app` non installable (56.1) :
        // c'est `ext:install` qui refuse fail-closed, pas le validateur.
        $normalized = $this->validator->validate($this->appManifest());

        self::assertArrayNotHasKey('install', $normalized);
    }

    #[Test]
    public function the_bundled_manifests_of_the_repository_remain_valid(): void
    {
        // Régression directe : les deux manifests réellement livrés par le
        // dépôt (54.2 `doc`, 55.3 `sso-demo`) ne doivent pas bouger d'un octet.
        $root = dirname(__DIR__, 3).'/resources/extensions';

        $found = 0;
        foreach ((array) glob($root.'/*/manifest.json') as $path) {
            $decoded = json_decode((string) file_get_contents((string) $path), true);
            self::assertIsArray($decoded, 'Manifest illisible : '.$path);

            $normalized = $this->validator->validate($decoded);
            self::assertArrayNotHasKey('install', $normalized);
            $found++;
        }

        self::assertGreaterThan(0, $found, 'Aucun manifest embarqué trouvé — la régression ne prouverait rien.');
    }

    // =====================================================================
    // AR3 — `type = app` ⇒ `entry_url === /ext/<id>`
    // =====================================================================

    #[Test]
    public function an_app_must_declare_the_provisioned_entry_url(): void
    {
        $normalized = $this->validator->validate($this->appManifest());

        self::assertSame('/ext/hello', $normalized['entry_url']);
    }

    #[Test]
    public function an_app_declaring_another_entry_url_is_rejected(): void
    {
        $this->expectException(InvalidExtensionManifestException::class);

        $this->validator->validate($this->appManifest(['entry_url' => '/hello']));
    }

    #[Test]
    public function an_app_pointing_to_another_extension_prefix_is_rejected(): void
    {
        $this->expectException(InvalidExtensionManifestException::class);

        $this->validator->validate($this->appManifest(['entry_url' => '/ext/autre']));
    }

    #[Test]
    public function an_app_pointing_to_an_external_url_is_rejected(): void
    {
        $this->expectException(InvalidExtensionManifestException::class);

        $this->validator->validate($this->appManifest(['entry_url' => 'https://exemple.test/hello']));
    }

    #[Test]
    public function a_link_may_still_point_anywhere_allowed_by_54_3(): void
    {
        // Contre-épreuve : la règle AR3 ne concerne QUE le type `app`.
        $normalized = $this->validator->validate($this->linkManifest(['entry_url' => 'https://exemple.test/doc']));

        self::assertSame('https://exemple.test/doc', $normalized['entry_url']);
    }

    // =====================================================================
    // Bloc `install` — chemin heureux
    // =====================================================================

    #[Test]
    public function a_well_formed_install_block_is_normalized(): void
    {
        $normalized = $this->validator->validate(
            $this->appManifest([], $this->installBlock(['redirect_paths' => ['/ext/hello/oidc/callback']]))
        );

        self::assertSame([
            'channel' => 'deb',
            'package' => 'packages/sambaedu-ext-hello_1.0.0_all.deb',
            'sha256' => str_repeat('a1', 32),
            'redirect_paths' => ['/ext/hello/oidc/callback'],
        ], $normalized['install']);
    }

    #[Test]
    public function redirect_paths_default_to_an_empty_list(): void
    {
        $normalized = $this->validator->validate($this->appManifest([], $this->installBlock()));

        self::assertSame([], $normalized['install']['redirect_paths']);
    }

    #[Test]
    public function a_link_may_also_carry_an_install_block_without_breaking_validation(): void
    {
        // Le validateur ne corrèle pas type et bloc `install` : c'est le moteur
        // qui refuse d'installer une `link` (message pointant le cycle 54.2).
        // Le prouver évite qu'une review « corrige » une règle inexistante.
        $normalized = $this->validator->validate($this->linkManifest([
            'install' => $this->installBlock(['package' => 'packages/x.deb']),
        ]));

        self::assertSame('deb', $normalized['install']['channel']);
    }

    // =====================================================================
    // Bloc `install` — refus
    // =====================================================================

    #[Test]
    public function an_install_block_that_is_not_an_object_is_rejected(): void
    {
        $this->expectException(InvalidExtensionManifestException::class);

        $this->validator->validate($this->appManifest([], ['deb', 'packages/x.deb']));
    }

    #[Test]
    public function an_unknown_channel_is_rejected(): void
    {
        $this->expectException(InvalidExtensionManifestException::class);

        $this->validator->validate($this->appManifest([], $this->installBlock(['channel' => 'snap'])));
    }

    #[Test]
    public function a_missing_channel_is_rejected(): void
    {
        $block = $this->installBlock();
        unset($block['channel']);

        $this->expectException(InvalidExtensionManifestException::class);

        $this->validator->validate($this->appManifest([], $block));
    }

    /** @return list<array{0: string}> */
    public static function rejectedPackagePaths(): array
    {
        return [
            'schéma http' => ['http://exemple.test/x.deb'],
            'schéma https' => ['https://exemple.test/x.deb'],
            'protocol-relative' => ['//exemple.test/x.deb'],
            'chemin absolu' => ['/packages/x.deb'],
            'remontée de répertoire' => ['packages/../../etc/passwd'],
            'remontée en tête' => ['../x.deb'],
            'segment courant' => ['packages/./x.deb'],
            'double séparateur' => ['packages//x.deb'],
            'query string' => ['packages/x.deb?token=abc'],
            'fragment' => ['packages/x.deb#frag'],
            'chaîne vide' => [''],
            'espace' => ['packages/mon paquet.deb'],
            'séparateur final' => ['packages/'],
            'schéma file' => ['file:///etc/passwd'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedPackagePaths')]
    public function a_package_path_outside_the_relative_contract_is_rejected(string $package): void
    {
        $this->expectException(InvalidExtensionManifestException::class);

        $this->validator->validate($this->appManifest([], $this->installBlock(['package' => $package])));
    }

    #[Test]
    public function a_plain_relative_package_path_is_accepted(): void
    {
        // Contre-épreuve du fournisseur ci-dessus : la règle refuse ce qu'elle
        // doit refuser, pas tout.
        $normalized = $this->validator->validate(
            $this->appManifest([], $this->installBlock(['package' => 'hello_1.0.0_all.deb']))
        );

        self::assertSame('hello_1.0.0_all.deb', $normalized['install']['package']);
    }

    /** @return list<array{0: mixed}> */
    public static function rejectedSha256(): array
    {
        return [
            'trop court' => [str_repeat('a', 63)],
            'trop long' => [str_repeat('a', 65)],
            'majuscules' => [strtoupper(str_repeat('ab', 32))],
            'non hexadécimal' => [str_repeat('z', 64)],
            'vide' => [''],
            'entier' => [12345],
            'null' => [null],
        ];
    }

    #[Test]
    #[DataProvider('rejectedSha256')]
    public function a_malformed_sha256_is_rejected(mixed $sha256): void
    {
        $this->expectException(InvalidExtensionManifestException::class);

        $this->validator->validate($this->appManifest([], $this->installBlock(['sha256' => $sha256])));
    }

    #[Test]
    public function redirect_paths_outside_the_extension_prefix_are_rejected(): void
    {
        $this->expectException(InvalidExtensionManifestException::class);

        $this->validator->validate($this->appManifest([], $this->installBlock([
            'redirect_paths' => ['/ext/autre/oidc/callback'],
        ])));
    }

    #[Test]
    public function a_redirect_path_pointing_to_an_external_host_is_rejected(): void
    {
        $this->expectException(InvalidExtensionManifestException::class);

        $this->validator->validate($this->appManifest([], $this->installBlock([
            'redirect_paths' => ['https://attaquant.test/callback'],
        ])));
    }

    #[Test]
    public function a_redirect_path_using_traversal_to_escape_the_prefix_is_rejected(): void
    {
        $this->expectException(InvalidExtensionManifestException::class);

        $this->validator->validate($this->appManifest([], $this->installBlock([
            'redirect_paths' => ['/ext/hello/../autre/callback'],
        ])));
    }

    #[Test]
    public function the_bare_prefix_without_trailing_segment_is_rejected(): void
    {
        // `/ext/hello` sans `/` final n'est pas dans `/ext/hello/…` : la borne
        // est un PRÉFIXE DE CHEMIN, pas un préfixe de chaîne — sinon
        // `/ext/helloworld/callback` passerait.
        $this->expectException(InvalidExtensionManifestException::class);

        $this->validator->validate($this->appManifest([], $this->installBlock([
            'redirect_paths' => ['/ext/hello'],
        ])));
    }

    #[Test]
    public function a_sibling_key_sharing_the_prefix_is_rejected(): void
    {
        $this->expectException(InvalidExtensionManifestException::class);

        $this->validator->validate($this->appManifest([], $this->installBlock([
            'redirect_paths' => ['/ext/helloworld/oidc/callback'],
        ])));
    }

    #[Test]
    public function redirect_paths_given_as_an_object_are_rejected(): void
    {
        $this->expectException(InvalidExtensionManifestException::class);

        $this->validator->validate($this->appManifest([], $this->installBlock([
            'redirect_paths' => ['first' => '/ext/hello/oidc/callback'],
        ])));
    }

    #[Test]
    public function duplicate_redirect_paths_are_deduplicated(): void
    {
        $normalized = $this->validator->validate($this->appManifest([], $this->installBlock([
            'redirect_paths' => ['/ext/hello/cb', '/ext/hello/cb', '/ext/hello/autre'],
        ])));

        self::assertSame(['/ext/hello/cb', '/ext/hello/autre'], $normalized['install']['redirect_paths']);
    }
}
