<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Dto\GpoSummary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\Support\FakesGpoService;
use Tests\TestCase;
use App\Models\User;

/**
 * Tests Feature — Publication étage 2 depuis le listing GPO
 * `/admin/settings/gpo` : bouton batch « Publier les publiables » + action
 * par ligne (dropdown unifié). Le shim `legacy.import_gpo` est mocké.
 */
class GpoIndexPublishTest extends TestCase
{
    use DatabaseTransactions;
    use BootstrapsSpatieTables;

    private const COMPONENT = 'pages::admin.settings.gpo.index';
    private string $templatesDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->bootstrapSpatieTables();

        $this->templatesDir = sys_get_temp_dir() . '/gpo-idx-templates-' . uniqid('', true) . '/';
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

    private function makeAdmin(string $login = 'admin-idx-publish'): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    /** Template forme répertoire sous sambaedu-gpo/<name>/ (emplacement réel legacy). */
    private function makeTemplate(string $name): void
    {
        File::makeDirectory($this->templatesDir . 'sambaedu-gpo/' . $name, 0755, true);
        File::put(
            $this->templatesDir . 'sambaedu-gpo/' . $name . '/GPT.INI',
            "[General]\ndisplayName={$name}\nVersion=5\n[CSE]\ngPCMachineExtensionNames=[{12345-CSE}]\n",
        );
    }

    /** 3 GPO : 2 publiables (templates créés) + 1 non publiable (redirections). */
    private function gpoCollection(): Collection
    {
        return collect([
            new GpoSummary(name: '{D418994B-0F25-4C3D-8627-4EB4F913BC12}', displayName: 'se4_applications', versionNumber: 5),
            new GpoSummary(name: '{AE623DCE-6333-4936-97FB-6FBD30D7D024}', displayName: 'se4_wpkg', versionNumber: 5),
            new GpoSummary(name: '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}', displayName: 'redirections', versionNumber: 0),
        ]);
    }

    private function bindGpos(): void
    {
        FakesGpoService::make()
            ->withGpos($this->gpoCollection())
            ->withDefaultLinks([])
            ->withDefaultInheritance(true)
            ->bind($this->app);
    }

    /** Capture les appels à import_gpo (accumulés). */
    private function captureImportGpo(mixed $return = null): object
    {
        $sink = (object) ['names' => []];
        $this->app->bind('legacy.import_gpo', function () use ($sink, $return) {
            return function (...$args) use ($sink, $return) {
                $sink->names[] = $args[1] ?? null; // displayName
                return $return;
            };
        });
        return $sink;
    }

    #[Test]
    public function it_shows_batch_publish_button_with_count_of_publishable_gpos(): void
    {
        $this->actingAs($this->makeAdmin('admin-batch-btn'));
        $this->makeTemplate('se4_applications');
        $this->makeTemplate('se4_wpkg');
        $this->bindGpos();

        Livewire::test(self::COMPONENT)
            ->assertStatus(200)
            ->assertSet('publishableCount', 2)
            ->assertSeeHtml('data-testid="publish-all-btn"');
    }

    #[Test]
    public function it_hides_batch_publish_button_when_no_template_matches(): void
    {
        $this->actingAs($this->makeAdmin('admin-no-batch'));
        // Aucun template → 0 publiable.
        $this->bindGpos();

        Livewire::test(self::COMPONENT)
            ->assertSet('publishableCount', 0)
            ->assertDontSeeHtml('data-testid="publish-all-btn"');
    }

    #[Test]
    public function it_batch_publishes_only_publishable_gpos(): void
    {
        $this->actingAs($this->makeAdmin('admin-batch-run'));
        $this->makeTemplate('se4_applications');
        $this->makeTemplate('se4_wpkg');
        $this->bindGpos();
        $sink = $this->captureImportGpo();

        Livewire::test(self::COMPONENT)
            ->call('openPublishAll')
            ->assertSet('publishAll', true)
            ->call('confirmPublish')
            ->assertSet('isPublishing', false)
            ->assertDispatched('toastMagic', fn ($name, $params) => ($params['status'] ?? null) === 'success');

        sort($sink->names);
        $this->assertSame(['se4_applications', 'se4_wpkg'], $sink->names, 'seules les GPO publiables sont importées');
        $this->assertNotContains('redirections', $sink->names);
    }

    #[Test]
    public function it_publishes_a_single_gpo_from_row_action(): void
    {
        $this->actingAs($this->makeAdmin('admin-row-publish'));
        $this->makeTemplate('se4_wpkg');
        $this->bindGpos();
        $sink = $this->captureImportGpo();

        Livewire::test(self::COMPONENT)
            ->call('openPublishOne', '{AE623DCE-6333-4936-97FB-6FBD30D7D024}', 'se4_wpkg')
            ->assertSet('publishAll', false)
            ->assertSet('publishTargetName', 'se4_wpkg')
            ->call('confirmPublish')
            ->assertDispatched('toastMagic', fn ($name, $params) => ($params['status'] ?? null) === 'success');

        $this->assertSame(['se4_wpkg'], $sink->names);
    }

    #[Test]
    public function batch_publish_reports_partial_failure(): void
    {
        $this->actingAs($this->makeAdmin('admin-batch-partial'));
        $this->makeTemplate('se4_applications');
        $this->makeTemplate('se4_wpkg');
        $this->bindGpos();
        // import_gpo retourne false → chaque publish lève → échec total.
        $this->captureImportGpo(return: false);

        Livewire::test(self::COMPONENT)
            ->call('openPublishAll')
            ->call('confirmPublish')
            ->assertDispatched('toastMagic', fn ($name, $params) => ($params['status'] ?? null) === 'error');
    }
}
