<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 38.1 — Garde-fous de la relocalisation des statiques iPXE.
 *
 * Tests par lecture textuelle des scripts (patron `WpkgOutRoutesTest`,
 * `ScriptsOsNamespaceTest`) + vérification de l'existence/intégrité des assets
 * versionnés sous `resources/ipxe/static/` :
 *
 *  1. `scripts/setupApache.sh` : le bloc `/ipxe` ne référence plus
 *     `/var/www/sambaedu/ipxe`, pointe sur `$SER_ROOT/storage/ipxe/static` et
 *     conserve `FallbackResource /index.php`.
 *  2. `scripts/update.sh` : la fonction `ensure_ipxe_statics` est déclarée ET
 *     appelée dans le bloc principal.
 *  3. Les 6 fichiers de l'AC 1 existent sous `resources/ipxe/static/` (tailles
 *     > 0) ; les md5 des 2 binaires TFTP sont conformes à l'inventaire VM.
 */
class IpxeStaticAliasTest extends TestCase
{
    private function repoRoot(): string
    {
        $root = realpath(__DIR__ . '/../..');
        self::assertNotFalse($root, 'Racine du repo introuvable');

        return $root;
    }

    private function fileContent(string $relative): string
    {
        $path = $this->repoRoot() . '/' . $relative;
        self::assertFileExists($path, "$relative introuvable");

        return (string) file_get_contents($path);
    }

    /**
     * AC 3 — setupApache.sh : alias /ipxe repointé, plus de référence legacy,
     * FallbackResource conservé.
     */
    #[Test]
    public function setup_apache_repoints_ipxe_alias_to_storage(): void
    {
        $content = $this->fileContent('scripts/setupApache.sh');

        self::assertStringNotContainsString(
            '/var/www/sambaedu/ipxe',
            $content,
            'Le bloc /ipxe ne doit plus référencer /var/www/sambaedu/ipxe (legacy).',
        );

        self::assertMatchesRegularExpression(
            '#Alias /ipxe \$SER_ROOT/storage/ipxe/static#',
            $content,
            'L\'alias /ipxe doit pointer sur $SER_ROOT/storage/ipxe/static.',
        );

        self::assertMatchesRegularExpression(
            '#<Directory \$SER_ROOT/storage/ipxe/static>#',
            $content,
            'Le bloc <Directory> /ipxe doit cibler $SER_ROOT/storage/ipxe/static.',
        );

        // FallbackResource /index.php conservé (les routes Laravel priment sur
        // toute URL sans fichier physique).
        $blockStart = strpos($content, 'Alias /ipxe $SER_ROOT/storage/ipxe/static');
        self::assertNotFalse($blockStart, 'Bloc alias /ipxe introuvable');
        $blockEnd = strpos($content, '</Directory>', $blockStart);
        self::assertNotFalse($blockEnd, 'Fermeture du bloc /ipxe introuvable');
        $ipxeBlock = substr($content, $blockStart, $blockEnd - $blockStart);
        self::assertStringContainsString(
            'FallbackResource /index.php',
            $ipxeBlock,
            'Le bloc /ipxe doit conserver FallbackResource /index.php.',
        );
    }

    /**
     * AC 2 — update.sh : ensure_ipxe_statics déclarée ET appelée.
     */
    #[Test]
    public function update_script_declares_and_calls_ensure_ipxe_statics(): void
    {
        $content = $this->fileContent('scripts/update.sh');

        self::assertMatchesRegularExpression(
            '/^ensure_ipxe_statics\(\)\s*\{/m',
            $content,
            'La fonction ensure_ipxe_statics() doit être déclarée dans update.sh.',
        );

        self::assertMatchesRegularExpression(
            '/^\s*ensure_ipxe_statics\s*$/m',
            $content,
            'La fonction ensure_ipxe_statics doit être appelée dans le bloc principal.',
        );
    }

    /**
     * AC 1 — les 6 statiques versionnés existent (tailles > 0).
     */
    #[Test]
    public function versioned_ipxe_statics_exist(): void
    {
        $base = $this->repoRoot() . '/resources/ipxe/static';

        $files = [
            'boot.ipxe',
            'diconf/authorized_keys',
            'diconf/install_se4_from0.sh',
            'png/ipxe-se4.png',
            'undionly.kpxe',
            'snponly_x64.efi',
        ];

        foreach ($files as $relative) {
            $path = $base . '/' . $relative;
            self::assertFileExists($path, "resources/ipxe/static/$relative manquant");
            self::assertGreaterThan(
                0,
                (int) filesize($path),
                "resources/ipxe/static/$relative est vide",
            );
        }
    }

    /**
     * AC 1 — les md5 des 2 binaires TFTP sont conformes à l'inventaire VM
     * (copies réelles de /var/lib/tftpboot/).
     */
    #[Test]
    public function ipxe_binaries_have_expected_md5(): void
    {
        $base = $this->repoRoot() . '/resources/ipxe/static';

        $expected = [
            'undionly.kpxe'    => '49e53c73677941fd8d4f5e634fc4220f',
            'snponly_x64.efi'  => '3c745bf0c61d72f5e7326a271e34cae4',
        ];

        foreach ($expected as $file => $md5) {
            $path = $base . '/' . $file;
            self::assertFileExists($path, "resources/ipxe/static/$file manquant");
            self::assertSame(
                $md5,
                md5_file($path),
                "md5 inattendu pour resources/ipxe/static/$file (copie réelle du TFTP attendue).",
            );
        }
    }
}
