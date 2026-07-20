<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Dto\GpoSummary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\Support\FakesGpoService;
use Tests\TestCase;
use App\Models\User;

/**
 * Tests Feature — Permissions `server.admin` requises (Story 16.2, AC4.1).
 *
 * Vérifie le comportement 200/403 pour les deux pages GPO selon la présence
 * ou l'absence de la permission `server.admin`.
 */
class GpoPagePermissionTest extends TestCase
{
    use DatabaseTransactions;
    use BootstrapsSpatieTables;

    private const VALID_GUID = '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}';

    protected function setUp(): void
    {
        parent::setUp();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->bootstrapSpatieTables();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->cleanupSpatieTables();
        parent::tearDown();
    }

    private function makeAdmin(string $login): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function makeUser(string $login): User
    {
        return User::query()->create(['login' => $login, 'role' => 'eleve', 'is_active' => true]);
    }

    private function bindMockService(): void
    {
        $gpo = new GpoSummary(
            name: self::VALID_GUID,
            displayName: 'TestGPO',
            versionNumber: 1,
        );
        FakesGpoService::make()
            ->withGpos(collect([$gpo]))
            ->withGpo(self::VALID_GUID, $gpo)
            ->withContainersFor(self::VALID_GUID, [])
            ->bind($this->app);
    }

    // =========================================================================
    // AC4.1 — Permission server.admin
    // =========================================================================

    // Le listing `pages::admin.settings.gpo.index` a été remplacé par l'onglet
    // « GPO » de /admin/settings/migration (effectivité réelle au lieu du badge
    // « Active » = versionNumber > 0). La couverture de permission suit l'écran.

    #[Test]
    public function it_grants_200_on_gpos_tab_with_server_admin(): void
    {
        $admin = $this->makeAdmin('perm-admin-listing-200');
        $this->actingAs($admin);

        Livewire::test('pages::admin.settings.migration._partials.gpos-tab')
            ->assertStatus(200);
    }

    #[Test]
    public function it_grants_403_on_gpos_tab_without_server_admin(): void
    {
        $user = $this->makeUser('perm-user-listing-403');
        $this->actingAs($user);

        Livewire::test('pages::admin.settings.migration._partials.gpos-tab')
            ->assertStatus(403);
    }

    #[Test]
    public function it_grants_200_on_detail_page_with_server_admin(): void
    {
        $admin = $this->makeAdmin('perm-admin-detail-200');
        $this->actingAs($admin);
        $this->bindMockService();

        Livewire::test('pages::admin.settings.gpo.[guid].index', ['guid' => self::VALID_GUID])
            ->assertStatus(200);
    }

    #[Test]
    public function it_grants_403_on_detail_page_without_server_admin(): void
    {
        $user = $this->makeUser('perm-user-detail-403');
        $this->actingAs($user);

        Livewire::test('pages::admin.settings.gpo.[guid].index', ['guid' => self::VALID_GUID])
            ->assertStatus(403);
    }
}
