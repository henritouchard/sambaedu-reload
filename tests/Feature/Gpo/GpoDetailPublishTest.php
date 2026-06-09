<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Dto\GpoSummary;
use App\Gpo\Support\GpoTemplateRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\Support\FakesGpoService;
use Tests\TestCase;
use App\Models\User;

/**
 * Tests Feature — Publication de l'étage 2 (SYSVOL) depuis la page détail GPO
 * `/admin/settings/gpo/{guid}`.
 *
 * Couvre la généralisation de `gpo-maj.php` (publish de n'importe quelle GPO
 * templatée) :
 *  - GPO avec template → bouton + encart "publiable" ; `confirmPublish` appelle
 *    le shim `legacy.import_gpo` avec (displayName, archive).
 *  - GPO sans template → pas de bouton, encart "non publiable" explicatif.
 *  - Side effect gated `server.admin`.
 *
 * Le binding `legacy.import_gpo` est mocké (closure) pour ne PAS exécuter
 * samba-tool/smbclient en test — iso stratégie WpkgGpoSynchronizer.
 */
class GpoDetailPublishTest extends TestCase
{
    use DatabaseTransactions;
    use BootstrapsSpatieTables;

    private const VALID_GUID = '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}';
    private const COMPONENT = 'pages::admin.settings.gpo.[guid].index';

    private string $templatesDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->bootstrapSpatieTables();

        // Répertoire de templates isolé par test (override config).
        $this->templatesDir = sys_get_temp_dir() . '/gpo-templates-' . uniqid('', true) . '/';
        File::makeDirectory($this->templatesDir, 0755, true);
        config(['sambaedu.gpo.templates_dir' => $this->templatesDir]);
    }

    protected function tearDown(): void
    {
        if ($this->templatesDir !== '' && is_dir($this->templatesDir)) {
            File::deleteDirectory($this->templatesDir);
        }
        Mockery::close();
        $this->cleanupSpatieTables();
        parent::tearDown();
    }

    private function makeAdmin(string $login = 'admin-publish'): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function makeGpoSummary(string $displayName, string $name = self::VALID_GUID): GpoSummary
    {
        return new GpoSummary(
            name: $name,
            displayName: $displayName,
            versionNumber: 3,
            dn: 'CN=' . $name . ',CN=Policies,CN=System,DC=example,DC=org',
            path: '\\\\example.org\\sysvol\\example.org\\Policies\\' . $name,
        );
    }

    /** Contenu d'un GPT.INI ; `$withCse=false` → template invalide (sans extensions CSE). */
    private function gptIni(string $displayName, int $version = 5, bool $withCse = true): string
    {
        $ini = "[General]\ndisplayName={$displayName}\nVersion={$version}\n";
        if ($withCse) {
            $ini .= "[CSE]\ngPCMachineExtensionNames=[{12345-CSE}]\n";
        }
        return $ini;
    }

    /**
     * Crée une template en forme **répertoire** sous `sambaedu-gpo/<name>/`
     * (emplacement réel résolu par le legacy `unzip_gpo`/`get_gpo_template_info`).
     */
    private function makeTemplate(string $name, int $version = 5, bool $withCse = true): void
    {
        File::makeDirectory($this->templatesDir . 'sambaedu-gpo/' . $name, 0755, true);
        File::put($this->templatesDir . 'sambaedu-gpo/' . $name . '/GPT.INI', $this->gptIni($name, $version, $withCse));
    }

    /** Crée une template en forme **archive** `<dir>/<name>.zip`. */
    private function makeZipTemplate(string $name, int $version = 5, bool $withCse = true): void
    {
        $zip = new \ZipArchive();
        $zip->open($this->templatesDir . $name . '.zip', \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('GPT.INI', $this->gptIni($name, $version, $withCse));
        $zip->close();
    }

    private function bindGpo(string $displayName): void
    {
        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpoSummary($displayName))
            ->withContainersFor(self::VALID_GUID, [])
            ->bind($this->app);
    }

    // =========================================================================
    // GPO publiable (template présent)
    // =========================================================================

    #[Test]
    public function it_shows_publish_cta_when_a_template_matches_the_gpo(): void
    {
        $this->actingAs($this->makeAdmin('admin-publishable'));
        $this->makeTemplate('se4_wpkg');
        $this->bindGpo('se4_wpkg');

        Livewire::test(self::COMPONENT, ['guid' => self::VALID_GUID])
            ->assertStatus(200)
            ->assertSet('isPublishable', true)
            ->assertSeeHtml('data-testid="open-publish-modal"')
            ->assertSeeHtml('data-testid="publishable-note"');
    }

    /**
     * Binde un faux `legacy.import_gpo` qui enregistre ses arguments dans
     * `$sink->args` (objet pour propagation fiable du capture). Retourne le sink.
     */
    private function captureImportGpo(mixed $return = null): object
    {
        $sink = (object) ['args' => null, 'called' => false];
        $this->app->bind('legacy.import_gpo', function () use ($sink, $return) {
            return function (...$args) use ($sink, $return) {
                $sink->called = true;
                $sink->args = $args;
                return $return;
            };
        });
        return $sink;
    }

    #[Test]
    public function it_publishes_etage2_via_legacy_import_gpo_binding(): void
    {
        $this->actingAs($this->makeAdmin('admin-confirm-publish'));
        $this->makeTemplate('se4_wpkg');
        $this->bindGpo('se4_wpkg');

        $sink = $this->captureImportGpo(); // succès best-effort (return null)

        Livewire::test(self::COMPONENT, ['guid' => self::VALID_GUID])
            ->call('confirmPublish')
            ->assertSet('isPublishModalOpen', false)
            ->assertSet('isPublishing', false)
            ->assertDispatched('toastMagic', fn ($name, $params) => ($params['status'] ?? null) === 'success');

        // import_gpo($config, $displayName, $archive, $update=true, $force=false)
        $this->assertSame('se4_wpkg', $sink->args[1] ?? null, 'displayName transmis à import_gpo');
        $this->assertSame('se4_wpkg', $sink->args[2] ?? null, 'archive (nom répertoire) transmise à import_gpo');
        $this->assertTrue($sink->args[3] ?? null, 'update=true');
        $this->assertFalse($sink->args[4] ?? null, 'force=false par défaut');
    }

    #[Test]
    public function it_surfaces_an_error_toast_when_import_fails(): void
    {
        $this->actingAs($this->makeAdmin('admin-publish-fail'));
        $this->makeTemplate('se4_wpkg');
        $this->bindGpo('se4_wpkg');

        // import_gpo retourne false → GpoPublisher lève RuntimeException.
        $this->captureImportGpo(return: false);

        Livewire::test(self::COMPONENT, ['guid' => self::VALID_GUID])
            ->call('confirmPublish')
            ->assertSet('isPublishing', false)
            ->assertDispatched('toastMagic', fn ($name, $params) => ($params['status'] ?? null) === 'error');
    }

    #[Test]
    public function force_flag_is_propagated_to_import_gpo(): void
    {
        $this->actingAs($this->makeAdmin('admin-publish-force'));
        $this->makeTemplate('se4_wpkg');
        $this->bindGpo('se4_wpkg');

        $sink = $this->captureImportGpo();

        Livewire::test(self::COMPONENT, ['guid' => self::VALID_GUID])
            ->set('forceFlag', true)
            ->call('confirmPublish');

        $this->assertTrue($sink->args[4] ?? null, 'force=true propagé');
    }

    // =========================================================================
    // GPO non publiable (aucune template)
    // =========================================================================

    #[Test]
    public function it_hides_publish_cta_and_explains_when_no_template_matches(): void
    {
        $this->actingAs($this->makeAdmin('admin-not-publishable'));
        // Aucune template créée → 'redirections' n'a pas de source.
        $this->bindGpo('redirections');

        Livewire::test(self::COMPONENT, ['guid' => self::VALID_GUID])
            ->assertStatus(200)
            ->assertSet('isPublishable', false)
            ->assertDontSeeHtml('data-testid="open-publish-modal"')
            ->assertSeeHtml('data-testid="not-publishable-note"');
    }

    #[Test]
    public function publishing_a_gpo_without_template_throws_and_toasts_error(): void
    {
        $this->actingAs($this->makeAdmin('admin-publish-notmpl'));
        $this->bindGpo('redirections');

        // Le binding ne doit jamais être appelé : pas de template → garde amont.
        $sink = $this->captureImportGpo();

        Livewire::test(self::COMPONENT, ['guid' => self::VALID_GUID])
            ->call('confirmPublish')
            ->assertDispatched('toastMagic', fn ($name, $params) => ($params['status'] ?? null) === 'error');

        $this->assertFalse($sink->called, 'import_gpo ne doit pas être invoqué pour une GPO sans template');
    }

    // =========================================================================
    // Registry — unité légère via le binding réel
    // =========================================================================

    #[Test]
    public function registry_resolves_directory_template_case_insensitively(): void
    {
        $this->makeTemplate('SE4_WPKG', version: 7); // forme répertoire sous sambaedu-gpo/
        $registry = $this->app->make(GpoTemplateRegistry::class);

        $this->assertTrue($registry->isPublishable('se4_wpkg'));
        $template = $registry->templateFor('se4_wpkg');
        $this->assertNotNull($template);
        $this->assertSame('SE4_WPKG', $template->archive, 'forme répertoire → archive = nom nu (résolu sous sambaedu-gpo/ par le legacy)');
        $this->assertSame(7, $template->version);
        $this->assertNull($registry->templateFor('inexistante'));
    }

    #[Test]
    public function registry_resolves_zip_template_with_filename_as_archive(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('ext-zip absent');
        }
        $this->makeZipTemplate('se4_applications', version: 4);
        $registry = $this->app->make(GpoTemplateRegistry::class);

        $template = $registry->templateFor('se4_applications');
        $this->assertNotNull($template);
        $this->assertSame('se4_applications.zip', $template->archive, 'forme archive → archive = nom de fichier .zip');
    }

    #[Test]
    public function registry_rejects_template_without_cse_section(): void
    {
        // GPT.INI sans [CSE] → invalide pour import_gpo/get_gpo_template_info (F1).
        $this->makeTemplate('se4_broken', withCse: false);
        $registry = $this->app->make(GpoTemplateRegistry::class);

        $this->assertFalse($registry->isPublishable('se4_broken'));
    }

    #[Test]
    public function registry_ignores_archive_without_allowed_prefix(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('ext-zip absent');
        }
        // Archive valide (CSE présent) mais nom hors préfixe se4_/etab_ → ignorée (F7).
        $this->makeZipTemplate('random_thing');
        $registry = $this->app->make(GpoTemplateRegistry::class);

        $this->assertNull($registry->templateFor('random_thing'));
    }

    #[Test]
    public function registry_ignores_directory_without_gpt_ini(): void
    {
        File::makeDirectory($this->templatesDir . 'sambaedu-gpo/se4_empty', 0755, true);
        $registry = $this->app->make(GpoTemplateRegistry::class);

        $this->assertNull($registry->templateFor('se4_empty'));
    }
}
