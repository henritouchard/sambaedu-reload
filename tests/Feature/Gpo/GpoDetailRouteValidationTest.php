<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Services\GpoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\Support\FakesGpoService;
use Tests\TestCase;
use App\Models\User;

/**
 * Tests Feature — Validation regex GUID anti-injection (Story 16.2, AC4.2).
 *
 * Vérifie que les GUIDs malformés retournent 404 avant tout appel samba-tool.
 * Couvre deux niveaux de défense :
 * - **Niveau routeur HTTP** (`->where('guid', ...)`) : test via `$this->get()`
 *   qui frappe la route réelle et confirme que la regex bloque effectivement
 *   sans dispatcher Livewire (Story 16.2 fix #1).
 * - **Niveau composant** (`mount()` defense-in-depth) : test via `Livewire::test()`
 *   qui contourne le routeur et vérifie que la SFC se protège elle-même.
 *
 * Note Fix #9 : la regex de route accepte désormais le GUID avec OU sans
 * accolades. Les tests de format invalide vérifient des inputs qui ne
 * matchent ni l'un ni l'autre (lettres hors hex, longueur incorrecte, etc.).
 */
class GpoDetailRouteValidationTest extends TestCase
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

    // =========================================================================
    // AC4.2 — Validation GUID anti-injection (niveau composant Livewire)
    // =========================================================================

    #[Test]
    public function it_returns_404_for_injection_string_without_calling_service(): void
    {
        $admin = $this->makeAdmin('admin-injection-test');
        $this->actingAs($admin);

        FakesGpoService::make()->expectNoCalls()->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].index', ['guid' => 'INJECTION; rm -rf /'])
            ->assertStatus(404);
    }

    #[Test]
    public function it_returns_404_for_garbage_guid_with_invalid_chars(): void
    {
        // Fix #9 : la regex tolère les accolades optionnelles, donc on ne
        // peut plus tester "sans accolades" comme cas invalide. On teste à
        // la place un input avec des caractères non-hex (Z) qui doit échouer.
        $admin = $this->makeAdmin('admin-garbage-test');
        $this->actingAs($admin);

        FakesGpoService::make()->expectNoCalls()->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].index', ['guid' => 'ZZZZZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZZZZZZZZZ'])
            ->assertStatus(404);
    }

    #[Test]
    public function it_returns_404_for_valid_format_guid_not_found_in_ad(): void
    {
        $admin = $this->makeAdmin('admin-notfound-test');
        $this->actingAs($admin);

        // GUID format valide mais GPO inexistante dans l'AD (get retourne null).
        // listContainers ne doit pas être appelé si get() retourne null.
        $fake = FakesGpoService::make()->withGpo(self::VALID_GUID, null);
        $fake->mock()->shouldNotReceive('listContainers');
        $fake->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].index', ['guid' => self::VALID_GUID])
            ->assertStatus(404);
    }

    // =========================================================================
    // Fix #1 — Validation regex au niveau routeur HTTP (defense in depth)
    // =========================================================================

    #[Test]
    public function the_route_regex_blocks_invalid_guid_at_http_level(): void
    {
        // Fix #1 : couvre la regex `->where('guid', ...)` au niveau routeur,
        // qu'aucun test ne couvrait — Livewire::test() contourne le routeur.
        // Le service ne doit JAMAIS être instancié pour un input invalide.
        $admin = $this->makeAdmin('admin-route-regex');
        $this->actingAs($admin);

        FakesGpoService::make()->expectNoCalls()->bind($this->app);

        $this->get('/admin/settings/gpo/INJECTION-NOT-A-GUID')
            ->assertStatus(404);
    }

    #[Test]
    public function the_route_regex_accepts_guid_without_braces_at_http_level(): void
    {
        // Fix #9 + #1 : la regex de route accepte le GUID sans accolades ;
        // au niveau HTTP, la route doit donc dispatcher (200 ou 404 métier
        // selon le retour du service) — pas un 404 routeur.
        // Bypass sambaedu.auth (vérifie $_SESSION['login'], non touché par
        // `actingAs`) — on garde `can:server.admin` actif pour valider la
        // chaîne route → middleware perm → composant.
        $admin = $this->makeAdmin('admin-route-nobrace');
        $this->actingAs($admin);
        $this->withoutMiddleware(\App\Http\Middleware\Auth\SambaEduAuth::class);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, null) // null → 404 métier propre
            ->bind($this->app);

        // La route a matché (sinon le service ne serait pas instancié) ;
        // Statut 404 métier car get() retourne null.
        $response = $this->get('/admin/settings/gpo/AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE');
        $this->assertSame(404, $response->getStatusCode(),
            'GUID sans accolades → la route doit matcher et appeler le service '
            . '(qui répond null → abort(404) propre).');
    }
}
