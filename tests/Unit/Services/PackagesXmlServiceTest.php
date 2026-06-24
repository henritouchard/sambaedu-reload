<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Services\AppStore\PackagesXmlService;
use Tests\TestCase;
use Tests\Traits\CreatesAppStoreSchema;
use PHPUnit\Framework\Attributes\Test;

class PackagesXmlServiceTest extends TestCase
{
    use CreatesAppStoreSchema;

    private PackagesXmlService $service;
    private string $testStoragePath;

    private string $testPackagesXmlPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAppStoreSchema();

        $this->testStoragePath = sys_get_temp_dir() . '/test-wpkg-' . uniqid();
        mkdir($this->testStoragePath, 0755, true);

        $this->testPackagesXmlPath = $this->testStoragePath . '/packages.xml';

        config(['sambaedu.wpkg.storage_path' => $this->testStoragePath]);
        config(['sambaedu.wpkg.packages_xml_path' => $this->testPackagesXmlPath]);

        $this->service = new PackagesXmlService();
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if (file_exists($this->testPackagesXmlPath)) {
            unlink($this->testPackagesXmlPath);
        }
        // Also clean any subdirectory-based paths
        $this->cleanDir($this->testStoragePath);

        $this->dropAppStoreSchema();
        parent::tearDown();
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->cleanDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    #[Test]
    public function it_is_instantiable(): void
    {
        $this->assertInstanceOf(PackagesXmlService::class, $this->service);
    }

    #[Test]
    public function it_has_regenerate_method(): void
    {
        $this->assertTrue(method_exists($this->service, 'regenerate'));
    }

    #[Test]
    public function regenerate_creates_packages_xml_with_installed_apps(): void
    {
        Application::create([
            'app_id' => 'test-app',
            'name' => 'Test App',
            'version' => '1.0',
            'status' => ApplicationStatus::Installed,
            'xml' => '<package id="test-app" name="Test App" revision="1.0"/>',
        ]);

        $this->service->regenerate();

        $xmlPath = $this->testPackagesXmlPath;
        $this->assertFileExists($xmlPath);

        $content = file_get_contents($xmlPath);
        $this->assertStringContainsString('test-app', $content);
        $this->assertStringContainsString('Test App', $content);
    }

    #[Test]
    public function regenerate_creates_empty_xml_when_no_installed_apps(): void
    {
        // Ensure no installed apps
        Application::where('status', ApplicationStatus::Installed)->delete();

        $this->service->regenerate();

        $xmlPath = $this->testPackagesXmlPath;
        $this->assertFileExists($xmlPath);

        $dom = new \DOMDocument();
        $dom->load($xmlPath);
        $root = $dom->documentElement;
        $this->assertEquals('packages', $root->tagName);
        $this->assertEquals(0, $root->childNodes->length);
    }

    #[Test]
    public function regenerate_sorts_packages_alphabetically_by_app_id(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();

        Application::create([
            'app_id' => 'zfirefox',
            'name' => 'Firefox',
            'status' => ApplicationStatus::Installed,
            'xml' => '<package id="zfirefox" name="Firefox" revision="1.0"/>',
        ]);
        Application::create([
            'app_id' => 'alibre',
            'name' => 'LibreOffice',
            'status' => ApplicationStatus::Installed,
            'xml' => '<package id="alibre" name="LibreOffice" revision="1.0"/>',
        ]);
        Application::create([
            'app_id' => 'mchrome',
            'name' => 'Chrome',
            'status' => ApplicationStatus::Installed,
            'xml' => '<package id="mchrome" name="Chrome" revision="1.0"/>',
        ]);

        $this->service->regenerate();

        $dom = new \DOMDocument();
        $dom->load($this->testPackagesXmlPath);
        $packages = $dom->getElementsByTagName('package');

        $this->assertEquals(3, $packages->length);
        $this->assertEquals('alibre', $packages->item(0)->getAttribute('id'));
        $this->assertEquals('mchrome', $packages->item(1)->getAttribute('id'));
        $this->assertEquals('zfirefox', $packages->item(2)->getAttribute('id'));
    }

    #[Test]
    public function regenerate_strips_sambaedu_nodes_from_output(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();

        Application::create([
            'app_id' => 'test-strip',
            'name' => 'Test Strip',
            'status' => ApplicationStatus::Installed,
            'xml' => '<package id="test-strip" name="Test Strip" revision="1.0">
                <check type="file" condition="exists" path="%ProgramFiles%\test\test.exe"/>
                <install cmd="msiexec /i test.msi"/>
                <download url="http://example.com/test.msi" target="%TEMP%"/>
                <delete path="%TEMP%\test.msi"/>
                <untar file="%TEMP%\test.tar.gz" target="%ProgramFiles%\test"/>
                <unzip file="%TEMP%\test.zip" target="%ProgramFiles%\test"/>
            </package>',
        ]);

        $this->service->regenerate();

        $dom = new \DOMDocument();
        $dom->load($this->testPackagesXmlPath);

        $this->assertEquals(0, $dom->getElementsByTagName('download')->length);
        $this->assertEquals(0, $dom->getElementsByTagName('delete')->length);
        $this->assertEquals(0, $dom->getElementsByTagName('untar')->length);
        $this->assertEquals(0, $dom->getElementsByTagName('unzip')->length);
        // Standard WPKG nodes should remain
        $this->assertEquals(1, $dom->getElementsByTagName('check')->length);
        $this->assertEquals(1, $dom->getElementsByTagName('install')->length);
    }

    #[Test]
    public function regenerate_preserves_xml_recipe_in_database(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();

        $originalXml = '<package id="test-preserve" name="Test Preserve" revision="1.0">
            <download url="http://example.com/test.msi" target="%TEMP%"/>
            <delete path="%TEMP%\test.msi"/>
            <untar file="%TEMP%\test.tar.gz" target="%ProgramFiles%\test"/>
            <unzip file="%TEMP%\test.zip" target="%ProgramFiles%\test"/>
            <install cmd="msiexec /i test.msi"/>
        </package>';

        $app = Application::create([
            'app_id' => 'test-preserve',
            'name' => 'Test Preserve',
            'status' => ApplicationStatus::Installed,
            'xml' => $originalXml,
        ]);

        $this->service->regenerate();

        // Re-fetch from DB
        $app->refresh();
        $this->assertStringContainsString('<download', $app->xml);
        $this->assertStringContainsString('<delete', $app->xml);
        $this->assertStringContainsString('<untar', $app->xml);
        $this->assertStringContainsString('<unzip', $app->xml);
    }

    /**
     * Story 27.6 (Bug B / AC1, AC5) — recipes à racine <packages> WRAPPER × N →
     * le catalogue régénéré DOIT être à plat : UNE seule racine <packages> et N
     * <package> ENFANTS DIRECTS de la racine. Sur le code buggé (import du wrapper
     * <packages>), ce test échouerait : la racine contiendrait N <packages>
     * imbriqués et 0 <package> enfant direct (l'engine wpkg-se4.js verrait 0 package).
     */
    #[Test]
    public function regenerate_flattens_wrapper_recipes_into_single_packages_root(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();

        // Recipes à racine <packages> wrapper (cas courant des dépôts SE4).
        Application::create([
            'app_id' => 'aapp',
            'name' => 'A App',
            'status' => ApplicationStatus::Installed,
            'xml' => '<packages><package id="aapp" name="A App" revision="1.0"/></packages>',
        ]);
        Application::create([
            'app_id' => 'bapp',
            'name' => 'B App',
            'status' => ApplicationStatus::Installed,
            'xml' => '<packages><package id="bapp" name="B App" revision="2.0"/></packages>',
        ]);
        Application::create([
            'app_id' => 'capp',
            'name' => 'C App',
            'status' => ApplicationStatus::Installed,
            'xml' => '<packages><package id="capp" name="C App" revision="3.0"/></packages>',
        ]);

        $this->service->regenerate();

        $dom = new \DOMDocument();
        $dom->load($this->testPackagesXmlPath);

        // UNE seule racine <packages> (pas N <packages> imbriqués).
        $this->assertEquals(1, $dom->getElementsByTagName('packages')->length);

        // N <package> ENFANTS DIRECTS de la racine (à plat).
        $root = $dom->documentElement;
        $this->assertEquals('packages', $root->localName);
        $directPackages = [];
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'package') {
                $directPackages[] = $child->getAttribute('id');
            }
        }
        $this->assertCount(3, $directPackages);
        $this->assertSame(['aapp', 'bapp', 'capp'], $directPackages);
    }

    /**
     * Story 27.6 (AC1) — un recipe à racine <package> DIRECTE est aussi importé à
     * plat (cas (b) de la discrimination de racine).
     */
    #[Test]
    public function regenerate_handles_direct_package_root_recipe(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();

        Application::create([
            'app_id' => 'directapp',
            'name' => 'Direct App',
            'status' => ApplicationStatus::Installed,
            'xml' => '<package id="directapp" name="Direct App" revision="1.0"/>',
        ]);

        $this->service->regenerate();

        $dom = new \DOMDocument();
        $dom->load($this->testPackagesXmlPath);

        $this->assertEquals(1, $dom->getElementsByTagName('packages')->length);
        $root = $dom->documentElement;
        $this->assertEquals(1, $root->getElementsByTagName('package')->length);
        $directPackages = [];
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'package') {
                $directPackages[] = $child->getAttribute('id');
            }
        }
        $this->assertSame(['directapp'], $directPackages);
    }

    /**
     * Story 27.6 (AC1) — le strip des nœuds SambaEdu reste appliqué PAR <package>,
     * y compris quand le recipe est un wrapper <packages> (le strip opère sur le
     * <package> importé, pas sur le wrapper).
     */
    #[Test]
    public function regenerate_strips_sambaedu_nodes_from_wrapper_recipe_per_package(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();

        Application::create([
            'app_id' => 'wrapped-strip',
            'name' => 'Wrapped Strip',
            'status' => ApplicationStatus::Installed,
            'xml' => '<packages><package id="wrapped-strip" name="Wrapped Strip" revision="1.0">
                <check type="file" condition="exists" path="%ProgramFiles%\test\test.exe"/>
                <install cmd="msiexec /i test.msi"/>
                <download url="http://example.com/test.msi" target="%TEMP%"/>
                <delete path="%TEMP%\test.msi"/>
                <untar file="%TEMP%\test.tar.gz" target="%ProgramFiles%\test"/>
                <unzip file="%TEMP%\test.zip" target="%ProgramFiles%\test"/>
            </package></packages>',
        ]);

        $this->service->regenerate();

        $dom = new \DOMDocument();
        $dom->load($this->testPackagesXmlPath);

        // Strip effectif.
        $this->assertEquals(0, $dom->getElementsByTagName('download')->length);
        $this->assertEquals(0, $dom->getElementsByTagName('delete')->length);
        $this->assertEquals(0, $dom->getElementsByTagName('untar')->length);
        $this->assertEquals(0, $dom->getElementsByTagName('unzip')->length);
        // Nœuds WPKG standard conservés, à plat.
        $this->assertEquals(1, $dom->getElementsByTagName('check')->length);
        $this->assertEquals(1, $dom->getElementsByTagName('install')->length);
        // Toujours à plat : 1 racine <packages>, 1 <package> enfant direct.
        $this->assertEquals(1, $dom->getElementsByTagName('packages')->length);
        $this->assertEquals(1, $dom->documentElement->getElementsByTagName('package')->length);
    }

    /**
     * Story 27.6 (AC1, AC5 — lacune relevée en review #4) — un SEUL recipe à
     * wrapper <packages> contenant PLUSIEURS <package> → tous remontés à plat sous
     * l'unique racine. Exerce la collecte des <package> ENFANTS DIRECTS du wrapper
     * (et non `getElementsByTagName('package')` récursif, qui ramasserait des
     * <package> d'autres sous-arbres).
     */
    #[Test]
    public function regenerate_flattens_multiple_packages_within_a_single_wrapper(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();

        Application::create([
            'app_id' => 'firefox-suite',
            'name' => 'Firefox Suite',
            'status' => ApplicationStatus::Installed,
            'xml' => '<packages>'
                . '<package id="firefox-esr" name="Firefox ESR" revision="1.0"/>'
                . '<package id="firefox-lang-fr" name="Firefox FR" revision="1.0"/>'
                . '</packages>',
        ]);

        $this->service->regenerate();

        $dom = new \DOMDocument();
        $dom->load($this->testPackagesXmlPath);

        // Toujours UNE seule racine <packages>.
        $this->assertEquals(1, $dom->getElementsByTagName('packages')->length);

        // Les 2 <package> du wrapper sont à plat, enfants DIRECTS de la racine.
        $root = $dom->documentElement;
        $directPackages = [];
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'package') {
                $directPackages[] = $child->getAttribute('id');
            }
        }
        $this->assertSame(['firefox-esr', 'firefox-lang-fr'], $directPackages);
    }

    /**
     * Story 27.6 (AC1) — un recipe valide mais SANS <package> (ex. wrapper ne
     * contenant que des <check>) est skippé+loggé sans casser la génération des
     * autres apps.
     */
    #[Test]
    public function regenerate_skips_recipe_without_package_and_keeps_others(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();

        Application::create([
            'app_id' => 'no-package',
            'name' => 'No Package',
            'status' => ApplicationStatus::Installed,
            'xml' => '<packages><check type="file" condition="exists" path="%ProgramFiles%\test"/></packages>',
        ]);
        Application::create([
            'app_id' => 'real-app',
            'name' => 'Real App',
            'status' => ApplicationStatus::Installed,
            'xml' => '<package id="real-app" name="Real App" revision="1.0"/>',
        ]);

        $this->service->regenerate();

        $dom = new \DOMDocument();
        $dom->load($this->testPackagesXmlPath);

        $root = $dom->documentElement;
        $directPackages = [];
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'package') {
                $directPackages[] = $child->getAttribute('id');
            }
        }
        // Le recipe sans <package> est skippé ; l'app valide reste générée.
        $this->assertSame(['real-app'], $directPackages);
        $this->assertEquals(1, $dom->getElementsByTagName('packages')->length);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Story 27.19 — Livraison FULL HTTP des payloads. Transformation chirurgicale
    // du catalogue : seules les recettes %SOFTWARE% sont réécrites pour télécharger
    // en HTTP (download natif, target=%TEMP%) ; exclusion des archives extraites
    // serveur (untar/unzip) ; recettes sans %SOFTWARE% inchangées.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return \DOMElement le <package> régénéré (premier de la racine)
     */
    private function regenerateAndGetPackage(): \DOMElement
    {
        $this->service->regenerate();
        $dom = new \DOMDocument();
        $dom->load($this->testPackagesXmlPath);

        return $dom->getElementsByTagName('package')->item(0);
    }

    private function installApp(string $appId, string $xml): void
    {
        Application::create([
            'app_id' => $appId,
            'name' => $appId,
            'status' => ApplicationStatus::Installed,
            'xml' => $xml,
        ]);
    }

    #[Test]
    public function http_delivery_rewrites_download_url_and_target_for_software_recipe(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();
        config(['sambaedu.se4fs_name' => 'se4fs.lan']);

        $this->installApp('id_7zip', '<package id="id_7zip" name="7-Zip" revision="1.0">
            <check type="file" condition="exists" path="%WinDir%\7za.exe"/>
            <download url="http://deb.sambaedu.org/7-zip/7za.exe" saveto="packages/7-zip/7za.exe" sha256sum="deadbeef" md5sum="cafe"/>
            <install cmd="xcopy /Y %SOFTWARE%\7-zip\7za.exe %WinDir%\"/>
        </package>');

        $package = $this->regenerateAndGetPackage();

        $download = $package->getElementsByTagName('download')->item(0);
        $this->assertNotNull($download, 'Le <download> doit être conservé pour une recette %SOFTWARE%');
        $this->assertSame('http://se4fs.lan/wpkg/files/7-zip/7za.exe', $download->getAttribute('url'));
        $this->assertSame('7-zip\7za.exe', $download->getAttribute('target'));
        // saveto / hashes retirés (non lus par le moteur).
        $this->assertFalse($download->hasAttribute('saveto'));
        $this->assertFalse($download->hasAttribute('sha256sum'));
        $this->assertFalse($download->hasAttribute('md5sum'));
    }

    #[Test]
    public function http_delivery_rewrites_install_software_to_temp(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();

        $this->installApp('id_7zip', '<package id="id_7zip" name="7-Zip" revision="1.0">
            <download url="http://x/7za.exe" saveto="packages/7-zip/7za.exe"/>
            <install cmd="xcopy /Y %SOFTWARE%\7-zip\7za.exe %WinDir%\"/>
        </package>');

        $package = $this->regenerateAndGetPackage();
        $install = $package->getElementsByTagName('install')->item(0);

        $this->assertSame('xcopy /Y %TEMP%\7-zip\7za.exe %WinDir%\\', $install->getAttribute('cmd'));
        $this->assertStringNotContainsString('%SOFTWARE%', $install->getAttribute('cmd'));
    }

    #[Test]
    public function http_delivery_leaves_check_untouched(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();

        $this->installApp('id_7zip', '<package id="id_7zip" name="7-Zip" revision="1.0">
            <check type="file" condition="exists" path="%WinDir%\7za.exe"/>
            <download url="http://x/7za.exe" saveto="packages/7-zip/7za.exe"/>
            <install cmd="xcopy /Y %SOFTWARE%\7-zip\7za.exe %WinDir%\"/>
        </package>');

        $package = $this->regenerateAndGetPackage();
        $check = $package->getElementsByTagName('check')->item(0);

        $this->assertSame('file', $check->getAttribute('type'));
        $this->assertSame('exists', $check->getAttribute('condition'));
        $this->assertSame('%WinDir%\7za.exe', $check->getAttribute('path'));
    }

    #[Test]
    public function http_delivery_uses_se4fs_fallback_when_config_empty(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();
        config(['sambaedu.se4fs_name' => '']);

        $this->installApp('id_7zip', '<package id="id_7zip" name="7-Zip" revision="1.0">
            <download url="http://x/7za.exe" saveto="packages/7-zip/7za.exe"/>
            <install cmd="xcopy /Y %SOFTWARE%\7-zip\7za.exe %WinDir%\"/>
        </package>');

        $package = $this->regenerateAndGetPackage();
        $download = $package->getElementsByTagName('download')->item(0);

        $this->assertSame('http://se4fs/wpkg/files/7-zip/7za.exe', $download->getAttribute('url'));
    }

    #[Test]
    public function http_delivery_skips_recipe_without_software_var(): void
    {
        // Recette MSI classique (download + install msiexec) SANS %SOFTWARE% :
        // comportement iso-legacy → <download> strippé, install inchangé.
        Application::where('status', ApplicationStatus::Installed)->delete();

        $this->installApp('id_msi', '<package id="id_msi" name="MSI App" revision="1.0">
            <download url="http://x/app.msi" saveto="packages/app/app.msi"/>
            <install cmd="msiexec /i app.msi /qn"/>
        </package>');

        $package = $this->regenerateAndGetPackage();

        $this->assertSame(0, $package->getElementsByTagName('download')->length, 'download strippé si pas de %SOFTWARE%');
        $install = $package->getElementsByTagName('install')->item(0);
        $this->assertSame('msiexec /i app.msi /qn', $install->getAttribute('cmd'));
    }

    #[Test]
    public function http_delivery_excludes_server_extracted_untar_download(): void
    {
        // L'archive .tar.gz est extraite CÔTÉ SERVEUR (untar) : son <download> ne
        // doit JAMAIS être réactivé même si la recette utilise %SOFTWARE% pour le
        // fichier extrait. Le download du payload extrait (le binaire) reste, lui.
        Application::where('status', ApplicationStatus::Installed)->delete();
        config(['sambaedu.se4fs_name' => 'se4fs.lan']);

        $this->installApp('id_archive', '<package id="id_archive" name="Archive App" revision="1.0">
            <download url="http://x/bundle.tar.gz" saveto="packages/app/bundle.tar.gz" sha256sum="aa"/>
            <untar tarfile="packages/app/bundle.tar.gz" target="packages/app/"/>
            <install cmd="xcopy /Y %SOFTWARE%\app\extracted.exe %WinDir%\"/>
        </package>');

        $package = $this->regenerateAndGetPackage();

        // Le <download> de l'archive (source de l'untar) est strippé.
        $this->assertSame(0, $package->getElementsByTagName('download')->length, 'download d archive extraite serveur strippé');
        // L'untar est aussi strippé (post-traitement serveur).
        $this->assertSame(0, $package->getElementsByTagName('untar')->length);
        // L'install %SOFTWARE% est tout de même réécrit en %TEMP% (le fichier
        // extrait sera attendu sous %TEMP%, cohérent avec les autres downloads).
        $install = $package->getElementsByTagName('install')->item(0);
        $this->assertStringNotContainsString('%SOFTWARE%', $install->getAttribute('cmd'));
        // review #7 : vérifier la substitution effective (pas une simple cmd vidée).
        $this->assertStringContainsString('%TEMP%', $install->getAttribute('cmd'));
    }

    #[Test]
    public function http_delivery_appends_temp_purge_after_install(): void
    {
        // review #M2 — le payload téléchargé dans %TEMP% doit être supprimé APRÈS une
        // install réussie. Une <install> de purge est appendue EN DERNIER : le moteur
        // avorte le package au 1er install en échec, donc la purge ne tourne qu'après
        // succès. `cmd /c … & exit /b 0` ⇒ ne fait jamais échouer le package.
        Application::where('status', ApplicationStatus::Installed)->delete();

        $this->installApp('id_7zip', '<package id="id_7zip" name="7-Zip" revision="1.0">
            <download url="http://x/7za.exe" saveto="packages/7-zip/7za.exe"/>
            <install cmd="xcopy /Y %SOFTWARE%\7-zip\7za.exe %WinDir%\"/>
        </package>');

        $package = $this->regenerateAndGetPackage();
        $installs = $package->getElementsByTagName('install');

        // 2 <install> : l'install réel (réécrit %TEMP%) puis la purge, dans cet ordre.
        $this->assertSame(2, $installs->length, 'une <install> de purge appendue après l\'install réel');
        $this->assertStringContainsString('xcopy', $installs->item(0)->getAttribute('cmd'), 'l\'install réel reste en premier');
        $purge = $installs->item(1)->getAttribute('cmd');
        $this->assertSame('cmd /c del /F /Q "%TEMP%\7-zip\7za.exe" 2>nul & exit /b 0', $purge);
    }

    #[Test]
    public function http_delivery_rewrites_z_packages_recipe_like_software(): void
    {
        // La majorité des recettes legacy référencent `%Z%\packages\…` (≡ %SOFTWARE%,
        // même dossier local …\install\packages). Elles doivent être livrées en HTTP
        // de la même façon : download réécrit + install `%Z%\packages\X` → `%TEMP%\X`.
        Application::where('status', ApplicationStatus::Installed)->delete();
        config(['sambaedu.se4fs_name' => 'se4fs.lan']);

        $this->installApp('id_airy', '<package id="id_airy" name="Airy" revision="1.0">
            <check type="file" condition="exists" path="%ProgramFiles%\Airy\airy.exe"/>
            <download url="http://deb.sambaedu.org/airy.msi" saveto="packages/Airy/airy.msi" sha256sum="aa"/>
            <install cmd="msiexec /qn /i &quot;%Z%\packages\Airy\airy.msi&quot;"/>
        </package>');

        $package = $this->regenerateAndGetPackage();

        $download = $package->getElementsByTagName('download')->item(0);
        $this->assertNotNull($download, 'le <download> est conservé pour une recette %Z%\packages');
        $this->assertSame('http://se4fs.lan/wpkg/files/Airy/airy.msi', $download->getAttribute('url'));
        $this->assertSame('Airy\airy.msi', $download->getAttribute('target'));

        $install = $package->getElementsByTagName('install')->item(0);
        $this->assertSame('msiexec /qn /i "%TEMP%\Airy\airy.msi"', $install->getAttribute('cmd'));
        $this->assertStringNotContainsString('%Z%\packages', $install->getAttribute('cmd'));
    }

    #[Test]
    public function http_delivery_leaves_shared_tools_z_path_untouched(): void
    {
        // Les outils PARTAGÉS `%Z%\wpkg\tools\…` (7za archiveur, nircmd) ne sont PAS
        // des payloads par-app (pas de <download>) → JAMAIS réécrits en %TEMP%. Seul
        // le payload `%Z%\packages\…` de la recette est livré ; le chemin outil reste.
        Application::where('status', ApplicationStatus::Installed)->delete();
        config(['sambaedu.se4fs_name' => 'se4fs.lan']);

        $this->installApp('id_adnarn', '<package id="id_adnarn" name="AdnArn" revision="1.0">
            <download url="http://deb.sambaedu.org/adnarn.zip" saveto="packages/adnarn/adnarn.zip"/>
            <install cmd="%ComSpec% /C %Z%\wpkg\tools\7za.exe e -o&quot;%ProgramFiles%\adnarn&quot; -y -bd %Z%\packages\adnarn\adnarn.zip &gt;NUL"/>
        </package>');

        $package = $this->regenerateAndGetPackage();
        $cmd = $package->getElementsByTagName('install')->item(0)->getAttribute('cmd');

        // Le payload %Z%\packages\… est réécrit en %TEMP%\… (livrable HTTP).
        $this->assertStringContainsString('%TEMP%\adnarn\adnarn.zip', $cmd);
        $this->assertStringNotContainsString('%Z%\packages', $cmd);
        // L'outil partagé reste %Z%\wpkg\tools\… (follow-up : staging des outils).
        $this->assertStringContainsString('%Z%\wpkg\tools\7za.exe', $cmd);
    }

    #[Test]
    public function http_delivery_strips_download_when_saveto_outside_packages_tree(): void
    {
        // review #3 — l'alias Apache /wpkg/files ne sert QUE `.../install/packages`.
        // Un saveto hors `packages/` (déposé verbatim par PackageInstaller/Importer,
        // ex. `softwares/...`) n'est PAS atteignable → réécrire l'URL produirait un
        // 404 silencieux. On strippe le <download> (pas d'URL morte) ; l'install
        // %SOFTWARE% est tout de même réécrit en %TEMP% mais le warning serveur
        // signale l'absence de payload livrable.
        Application::where('status', ApplicationStatus::Installed)->delete();
        config(['sambaedu.se4fs_name' => 'se4fs.lan']);

        $this->installApp('id_soft', '<package id="id_soft" name="Soft App" revision="1.0">
            <download url="http://x/firefox.exe" saveto="softwares/firefox/firefox.exe"/>
            <install cmd="xcopy /Y %SOFTWARE%\firefox\firefox.exe %WinDir%\"/>
        </package>');

        $package = $this->regenerateAndGetPackage();

        // Aucun <download> ne survit (saveto non servable par l'alias) — pas d'URL 404.
        $this->assertSame(0, $package->getElementsByTagName('download')->length, 'download hors packages/ strippé');
        // L'install est tout de même réécrit en %TEMP% (cohérence de la transformation).
        $install = $package->getElementsByTagName('install')->item(0);
        $this->assertStringNotContainsString('%SOFTWARE%', $install->getAttribute('cmd'));
    }

    #[Test]
    public function http_delivery_excludes_server_extracted_unzip_but_keeps_other_download(): void
    {
        // Mix : une archive extraite serveur (unzip) ET un payload direct dans la
        // même recette %SOFTWARE%. Seul le download de l'archive est exclu ; le
        // payload direct est réécrit en HTTP.
        Application::where('status', ApplicationStatus::Installed)->delete();
        config(['sambaedu.se4fs_name' => 'se4fs.lan']);

        $this->installApp('id_mix', '<package id="id_mix" name="Mix App" revision="1.0">
            <download url="http://x/data.zip" saveto="packages/mix/data.zip"/>
            <unzip zipfile="packages/mix/data.zip" target="packages/mix/"/>
            <download url="http://x/setup.exe" saveto="packages/mix/setup.exe" sha256sum="bb"/>
            <install cmd="%SOFTWARE%\mix\setup.exe /S"/>
        </package>');

        $package = $this->regenerateAndGetPackage();

        $downloads = $package->getElementsByTagName('download');
        $this->assertSame(1, $downloads->length, 'seul le payload direct est conservé');
        $kept = $downloads->item(0);
        $this->assertSame('http://se4fs.lan/wpkg/files/mix/setup.exe', $kept->getAttribute('url'));
        $this->assertSame('mix\setup.exe', $kept->getAttribute('target'));
    }

    #[Test]
    public function http_delivery_rewrites_multiple_downloads_and_installs(): void
    {
        Application::where('status', ApplicationStatus::Installed)->delete();
        config(['sambaedu.se4fs_name' => 'se4fs.lan']);

        $this->installApp('id_multi', '<package id="id_multi" name="Multi App" revision="1.0">
            <download url="http://x/a.exe" saveto="packages/multi/a.exe"/>
            <download url="http://x/b.dll" saveto="packages/multi/sub/b.dll"/>
            <install cmd="xcopy /Y %SOFTWARE%\multi\a.exe %WinDir%\"/>
            <install cmd="copy %SOFTWARE%\multi\sub\b.dll %WinDir%\system32\"/>
        </package>');

        $package = $this->regenerateAndGetPackage();

        $downloads = $package->getElementsByTagName('download');
        $this->assertSame(2, $downloads->length);
        $this->assertSame('http://se4fs.lan/wpkg/files/multi/a.exe', $downloads->item(0)->getAttribute('url'));
        $this->assertSame('multi\a.exe', $downloads->item(0)->getAttribute('target'));
        $this->assertSame('http://se4fs.lan/wpkg/files/multi/sub/b.dll', $downloads->item(1)->getAttribute('url'));
        $this->assertSame('multi\sub\b.dll', $downloads->item(1)->getAttribute('target'));

        $installs = $package->getElementsByTagName('install');
        $this->assertStringNotContainsString('%SOFTWARE%', $installs->item(0)->getAttribute('cmd'));
        $this->assertStringNotContainsString('%SOFTWARE%', $installs->item(1)->getAttribute('cmd'));
    }

    #[Test]
    public function http_delivery_handles_backslash_saveto_normalization(): void
    {
        // Une recette legacy peut écrire saveto en séparateurs Windows. La
        // normalisation doit reconnaître l'archive extraite serveur (untar) et
        // exclure son download.
        Application::where('status', ApplicationStatus::Installed)->delete();

        $this->installApp('id_bs', '<package id="id_bs" name="Backslash App" revision="1.0">
            <download url="http://x/arch.tgz" saveto="packages\app\arch.tgz"/>
            <untar tarfile="packages/app/arch.tgz" target="packages/app/"/>
            <install cmd="xcopy %SOFTWARE%\app\x.exe %WinDir%\"/>
        </package>');

        $package = $this->regenerateAndGetPackage();
        $this->assertSame(0, $package->getElementsByTagName('download')->length);
    }

    #[Test]
    public function http_delivery_strips_delete_untar_unzip_but_not_download_software(): void
    {
        // Non-régression : delete/untar/unzip restent strippés (post-traitement
        // serveur) ; seul <download> change de traitement.
        Application::where('status', ApplicationStatus::Installed)->delete();

        $this->installApp('id_full', '<package id="id_full" name="Full App" revision="1.0">
            <download url="http://x/a.exe" saveto="packages/full/a.exe"/>
            <delete file="packages/full/old.exe"/>
            <install cmd="xcopy %SOFTWARE%\full\a.exe %WinDir%\"/>
        </package>');

        $package = $this->regenerateAndGetPackage();

        $this->assertSame(1, $package->getElementsByTagName('download')->length);
        $this->assertSame(0, $package->getElementsByTagName('delete')->length);
    }

    #[Test]
    public function regenerate_uses_config_packages_xml_path(): void
    {
        $customPath = sys_get_temp_dir() . '/test-custom-wpkg-' . uniqid() . '/custom/packages.xml';

        config(['sambaedu.wpkg.packages_xml_path' => $customPath]);
        $service = new PackagesXmlService();

        Application::create([
            'app_id' => 'test-path',
            'name' => 'Test Path',
            'status' => ApplicationStatus::Installed,
            'xml' => '<package id="test-path" name="Test Path" revision="1.0"/>',
        ]);

        $service->regenerate();

        $this->assertFileExists($customPath);

        // Cleanup
        unlink($customPath);
        rmdir(dirname($customPath));
        rmdir(dirname(dirname($customPath)));
    }
}
