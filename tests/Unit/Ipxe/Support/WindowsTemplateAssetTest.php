<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Support;

use DOMDocument;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 3.5 — T1.7.
 *
 * Tests garde-fou sur l'asset projet `resources/ipxe/windows/unattend.xml`.
 *
 *  - Présence du fichier.
 *  - Bien formé XML (DOMDocument::load() OK).
 *  - Contient les placeholders attendus (`###_SE4FS_NAME_###`, `###_NAME_###`).
 *  - Contient les nodes critiques utilisés par {@see WindowsUnattendBuilder}
 *    (sinon le builder fait silencieusement les inserts dans le vide).
 */
class WindowsTemplateAssetTest extends TestCase
{
    private function templatePath(): string
    {
        return resource_path('ipxe/windows/unattend.xml');
    }

    #[Test]
    public function it_lists_required_template(): void
    {
        self::assertFileExists($this->templatePath());
        self::assertFileIsReadable($this->templatePath());
    }

    #[Test]
    public function it_template_is_well_formed_xml(): void
    {
        $xml = new DOMDocument();
        $loaded = $xml->load($this->templatePath());
        self::assertTrue($loaded, 'unattend.xml doit être un XML bien formé.');
    }

    #[Test]
    public function it_contains_expected_placeholders(): void
    {
        $content = (string) file_get_contents($this->templatePath());
        self::assertStringContainsString('###_SE4FS_NAME_###', $content);
        self::assertStringContainsString('###_NAME_###', $content);
        // Fix 2026-06-04 — uuid/mac requis dans le curl OOBE (résolution
        // UUID/MAC du controller /ipxe/windows/action).
        self::assertStringContainsString('###_UUID_###', $content);
        self::assertStringContainsString('###_MAC_###', $content);
    }

    #[Test]
    public function it_contains_required_xpath_targets(): void
    {
        $xml = new DOMDocument();
        $xml->load($this->templatePath());
        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('ns', 'urn:schemas-microsoft-com:unattend');

        // Le builder dépend de ces nodes — sans eux, les setNodeValue() ne
        // font rien silencieusement.
        $expected = [
            "/ns:unattend/ns:settings[@pass='windowsPE']/ns:component[@name='Microsoft-Windows-Setup']",
            '/ns:unattend/ns:settings/ns:component/ns:ComputerName',
            '/ns:unattend/ns:settings/ns:component/ns:AutoLogon/ns:Username',
            '/ns:unattend/ns:settings/ns:component/ns:UserAccounts/ns:AdministratorPassword/ns:Value',
            "/ns:unattend/ns:settings[@pass='specialize']",
            "/ns:unattend/ns:settings[@pass='oobeSystem']/ns:component[@name='Microsoft-Windows-Shell-Setup']/ns:UserAccounts/ns:LocalAccounts",
        ];

        foreach ($expected as $query) {
            $matches = $xpath->query($query);
            self::assertNotFalse($matches, "Query xpath invalide : {$query}");
            self::assertGreaterThan(
                0,
                $matches->length,
                "Node manquant dans template unattend.xml : {$query}",
            );
        }
    }
}
