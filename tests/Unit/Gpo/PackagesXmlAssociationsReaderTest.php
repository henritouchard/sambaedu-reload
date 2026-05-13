<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Services\PackagesXmlAssociationsReader;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `PackagesXmlAssociationsReader` — Story 16.3c AC6.6.
 */
class PackagesXmlAssociationsReaderTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpFile = sys_get_temp_dir() . '/packages-' . bin2hex(random_bytes(4)) . '.xml';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpFile)) {
            @unlink($this->tmpFile);
        }
        parent::tearDown();
    }

    #[Test]
    public function it_returns_empty_when_file_missing(): void
    {
        Log::shouldReceive('warning')->atLeast()->once();

        $reader = new PackagesXmlAssociationsReader();
        $result = $reader->read('/tmp/no-such-file-12345.xml');

        $this->assertSame([], $result);
    }

    #[Test]
    public function it_parses_association_elements_from_packages_xml(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <packages>
          <package id="firefox" name="Firefox">
            <Association ProgId="FirefoxHTML" Identifier=".html" type="file"/>
            <Association ProgId="FirefoxURL" Identifier="http" type="protocol"/>
          </package>
          <package id="thunderbird" name="Thunderbird">
            <Association ProgId="ThunderbirdEml" Identifier=".eml" type="file"/>
          </package>
        </packages>
        XML;
        file_put_contents($this->tmpFile, $xml);

        $reader = new PackagesXmlAssociationsReader();
        $result = $reader->read($this->tmpFile);

        $this->assertSame([
            'firefox' => [
                '.html' => ['ProgId' => 'FirefoxHTML', 'type' => 'file'],
                'http'  => ['ProgId' => 'FirefoxURL',  'type' => 'protocol'],
            ],
            'thunderbird' => [
                '.eml' => ['ProgId' => 'ThunderbirdEml', 'type' => 'file'],
            ],
        ], $result);
    }

    #[Test]
    public function it_defaults_type_to_file_when_attribute_missing(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0"?>
        <packages>
          <package id="onlyoffice">
            <Association ProgId="OnlyOfficeDocx" Identifier=".docx"/>
          </package>
        </packages>
        XML;
        file_put_contents($this->tmpFile, $xml);

        $reader = new PackagesXmlAssociationsReader();
        $result = $reader->read($this->tmpFile);

        $this->assertSame('file', $result['onlyoffice']['.docx']['type']);
    }

    #[Test]
    public function it_logs_warning_on_dom_load_failure(): void
    {
        // XML malformé (tag non fermé)
        file_put_contents($this->tmpFile, '<?xml version="1.0"?><packages><package id="x"><Association></packages>');

        Log::shouldReceive('warning')->atLeast()->once();

        $reader = new PackagesXmlAssociationsReader();
        $result = $reader->read($this->tmpFile);

        // DOM peut récupérer partiellement → tester juste qu'on retourne array (pas crash).
        $this->assertIsArray($result);
    }

    #[Test]
    public function it_skips_associations_without_progid_or_identifier(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0"?>
        <packages>
          <package id="incomplete">
            <Association ProgId="" Identifier=".x" type="file"/>
            <Association ProgId="X" Identifier="" type="file"/>
            <Association ProgId="Valid" Identifier=".valid" type="file"/>
          </package>
        </packages>
        XML;
        file_put_contents($this->tmpFile, $xml);

        $reader = new PackagesXmlAssociationsReader();
        $result = $reader->read($this->tmpFile);

        $this->assertArrayHasKey('incomplete', $result);
        $this->assertCount(1, $result['incomplete']);
        $this->assertArrayHasKey('.valid', $result['incomplete']);
    }

    #[Test]
    public function it_skips_packages_without_id_attribute(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0"?>
        <packages>
          <package>
            <Association ProgId="P" Identifier=".x" type="file"/>
          </package>
          <package id="valid">
            <Association ProgId="V" Identifier=".v" type="file"/>
          </package>
        </packages>
        XML;
        file_put_contents($this->tmpFile, $xml);

        $reader = new PackagesXmlAssociationsReader();
        $result = $reader->read($this->tmpFile);

        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayNotHasKey('', $result);
    }
}
