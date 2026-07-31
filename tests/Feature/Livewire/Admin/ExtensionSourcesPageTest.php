<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Enums\ExtensionSourceSyncStatus;
use App\Enums\ExtensionStatus;
use App\Models\Extension;
use App\Models\ExtensionAuditLog;
use App\Models\ExtensionSource;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 56.1 (AC1/AC3/AC5) — page `/admin/extensions/sources`.
 *
 * Couvre : la garde `server.admin` (403 + middleware de route), l'ajout par
 * modale (clé collée, refus http sans clé), les actions par source
 * (actualiser, activer/désactiver, retirer) et l'absence TOTALE d'action sur la
 * source embarquée.
 */
class ExtensionSourcesPageTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'pages::admin.extensions.sources.index';
    private const BASE = 'https://depot.example.test/extensions';

    private User $admin;

    /** @var array{public: string, secret: string} */
    private array $keys;

    /** @var array<string, array{body: string, status: int}|Closure> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->admin = User::query()->create([
            'login' => 'sources-admin',
            'role' => 'autre',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        $pair = sodium_crypto_sign_keypair();
        $this->keys = [
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
        $this->files = [];

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (! array_key_exists($url, $this->files)) {
                return Http::response('not found', 404);
            }
            $file = $this->files[$url];

            return $file instanceof Closure ? $file() : Http::response($file['body'], $file['status']);
        });
    }

    /** @param list<string> $abilities */
    private function grant(array $abilities): void
    {
        Gate::before(fn ($user, string $ability) => in_array($ability, $abilities, true) ? true : null);
    }

    /** @param list<array<string, mixed>> $extensions */
    private function publish(string $base = self::BASE, array $extensions = [], ?string $secret = null): void
    {
        $index = (string) json_encode(['index_version' => 1, 'extensions' => $extensions], JSON_UNESCAPED_SLASHES);

        $this->files[$base.'/index.json'] = ['body' => $index, 'status' => 200];
        $this->files[$base.'/index.json.sig'] = [
            'body' => base64_encode(sodium_crypto_sign_detached($index, (string) base64_decode($secret ?? $this->keys['secret'], true))),
            'status' => 200,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function manifest(string $id, array $overrides = []): array
    {
        return array_merge([
            'manifest_version' => 1,
            'id' => $id,
            'type' => 'link',
            'name' => 'Extension '.$id,
            'version' => '1.0.0',
            'entry_url' => '/'.$id,
            'visibility' => ['roles' => ['admin']],
        ], $overrides);
    }

    private function remoteSource(array $overrides = []): ExtensionSource
    {
        return ExtensionSource::factory()
            ->remote(self::BASE, $this->keys['public'])
            ->create($overrides);
    }

    // ── Sécurité ──────────────────────────────────────────────────────────

    #[Test]
    public function mount_is_forbidden_without_server_admin(): void
    {
        Livewire::test(self::PAGE)->assertForbidden();
    }

    #[Test]
    public function the_route_is_guarded_by_the_can_middleware(): void
    {
        $route = Route::getRoutes()->getByName('admin.extensions.sources');

        self::assertNotNull($route, 'la route admin.extensions.sources existe');
        self::assertContains('can:server.admin', $route->gatherMiddleware());
    }

    #[Test]
    public function every_action_is_forbidden_when_the_ability_is_revoked_after_mount(): void
    {
        // Defense-in-depth : la garde de `mount()` ne suffit pas — une ability
        // révoquée après le montage doit rester bloquée sur CHAQUE action
        // (patron 54.2).
        $source = $this->remoteSource();

        $allowed = true;
        Gate::before(function ($user, string $ability) use (&$allowed) {
            return ($ability === 'server.admin' && $allowed) ? true : null;
        });

        // Un composant NEUF par action : une réponse 403 casse le snapshot
        // Livewire, on ne peut pas enchaîner deux appels sur la même instance.
        $actions = [
            ['openAdd', []],
            ['addSource', []],
            ['refreshSource', [$source->id]],
            ['toggleSource', [$source->id, false]],
            ['askRemove', [$source->id]],
            ['confirmRemove', []],
        ];

        foreach ($actions as [$method, $arguments]) {
            $allowed = true;
            $component = Livewire::test(self::PAGE)->assertOk();

            $allowed = false;
            $component->call($method, ...$arguments)->assertForbidden();
        }

        self::assertNotNull($source->fresh());
        self::assertTrue($source->fresh()->enabled);
        self::assertSame(0, ExtensionAuditLog::query()->count());
    }

    // ── AC1 — ajout ───────────────────────────────────────────────────────

    #[Test]
    public function the_page_lists_the_sources_with_their_state(): void
    {
        $this->grant(['server.admin']);
        ExtensionSource::factory()->bundled()->create();
        $this->remoteSource(['name' => 'Dépôt partenaire']);

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertSee('Dépôt partenaire')
            ->assertSee(ExtensionSource::NAME_BUNDLED)
            ->assertSee('Tierce')
            ->assertSee('Officielle');
    }

    #[Test]
    public function an_admin_adds_a_source_with_a_pasted_key(): void
    {
        $this->grant(['server.admin']);
        $this->publish(extensions: [$this->manifest('agenda')]);

        Livewire::test(self::PAGE)
            ->call('openAdd')
            ->assertSet('isAddOpen', true)
            ->set('newName', 'Dépôt partenaire')
            ->set('newUrl', self::BASE)
            ->set('newPublicKey', $this->keys['public'])
            ->call('addSource')
            ->assertSet('isAddOpen', false)
            ->assertDispatched('toastMagic');

        $source = ExtensionSource::query()->where('url', self::BASE)->firstOrFail();
        self::assertSame(ExtensionSourceSyncStatus::Ok, $source->sync_status);
        self::assertSame(1, Extension::query()->count());
        self::assertSame(1, ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_SOURCE_ADD)->count());
    }

    #[Test]
    public function an_http_url_without_a_key_is_refused_and_the_modal_stays_open(): void
    {
        // L'admin doit pouvoir corriger sa saisie sans tout retaper.
        $this->grant(['server.admin']);

        Livewire::test(self::PAGE)
            ->call('openAdd')
            ->set('newName', 'Miroir LAN')
            ->set('newUrl', 'http://miroir.lan/extensions')
            ->call('addSource')
            ->assertSet('isAddOpen', true)
            ->assertSet('newUrl', 'http://miroir.lan/extensions')
            ->assertDispatched('toastMagic');

        self::assertSame(0, ExtensionSource::query()->count());
    }

    #[Test]
    public function a_source_whose_catalog_is_refused_is_created_and_reported(): void
    {
        $this->grant(['server.admin']);
        $other = sodium_crypto_sign_keypair();
        $this->publish(extensions: [$this->manifest('agenda')], secret: base64_encode(sodium_crypto_sign_secretkey($other)));

        Livewire::test(self::PAGE)
            ->call('openAdd')
            ->set('newName', 'Dépôt douteux')
            ->set('newUrl', self::BASE)
            ->set('newPublicKey', $this->keys['public'])
            ->call('addSource')
            ->assertSet('isAddOpen', false)
            ->assertDispatched('toastMagic');

        $source = ExtensionSource::query()->firstOrFail();
        self::assertSame(ExtensionSourceSyncStatus::Error, $source->sync_status);
        self::assertSame(0, Extension::query()->count());
    }

    // ── AC5 — actualiser ──────────────────────────────────────────────────

    #[Test]
    public function refreshing_a_source_reloads_its_catalog(): void
    {
        $this->grant(['server.admin']);
        $source = $this->remoteSource();
        $this->publish(extensions: [$this->manifest('agenda')]);

        Livewire::test(self::PAGE)
            ->call('refreshSource', $source->id)
            ->assertDispatched('toastMagic');

        self::assertSame(1, Extension::query()->count());
        self::assertSame(ExtensionSourceSyncStatus::Ok, $source->fresh()->sync_status);
    }

    #[Test]
    public function refreshing_an_unreachable_source_never_empties_the_catalog(): void
    {
        $this->grant(['server.admin']);
        $source = $this->remoteSource();
        $kept = Extension::factory()->create(['extension_source_id' => $source->id]);
        // Aucun fichier publié ⇒ 404.

        Livewire::test(self::PAGE)
            ->call('refreshSource', $source->id)
            ->assertDispatched('toastMagic');

        self::assertNotNull($kept->fresh());
        self::assertSame(ExtensionSourceSyncStatus::Unreachable, $source->fresh()->sync_status);
    }

    // ── AC3 — activer / désactiver / retirer ──────────────────────────────

    #[Test]
    public function an_admin_disables_and_reenables_a_source(): void
    {
        $this->grant(['server.admin']);
        $source = $this->remoteSource();

        Livewire::test(self::PAGE)
            ->call('toggleSource', $source->id, false)
            ->assertDispatched('toastMagic');
        self::assertFalse($source->fresh()->enabled);

        Livewire::test(self::PAGE)
            ->call('toggleSource', $source->id, true)
            ->assertDispatched('toastMagic');
        self::assertTrue($source->fresh()->enabled);
    }

    #[Test]
    public function removing_a_source_goes_through_the_confirmation_modal(): void
    {
        $this->grant(['server.admin']);
        $source = $this->remoteSource(['name' => 'Dépôt partenaire']);

        Livewire::test(self::PAGE)
            ->call('askRemove', $source->id)
            ->assertSet('isRemoveOpen', true)
            ->assertSet('removeTargetName', 'Dépôt partenaire')
            ->call('confirmRemove')
            ->assertSet('isRemoveOpen', false)
            ->assertDispatched('toastMagic');

        self::assertNull($source->fresh());
    }

    #[Test]
    public function a_double_click_on_the_confirmation_is_a_clean_no_op(): void
    {
        // Piège review 54.2 #1 : la cible ne doit pas être remise à zéro AVANT
        // l'appel au service, sinon le second clic produit « Source #0
        // introuvable » au lieu d'un no-op silencieux.
        $this->grant(['server.admin']);
        $source = $this->remoteSource();

        $component = Livewire::test(self::PAGE)
            ->call('askRemove', $source->id)
            ->call('confirmRemove');

        self::assertNull($source->fresh());

        $component->call('confirmRemove');   // le rejeu ne doit rien casser

        self::assertSame(1, ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_SOURCE_REMOVE)->count());
    }

    #[Test]
    public function removing_a_source_with_an_integrated_extension_is_refused(): void
    {
        $this->grant(['server.admin']);
        $source = $this->remoteSource();
        Extension::factory()->create([
            'extension_source_id' => $source->id,
            'status' => ExtensionStatus::Integrated,
            'name' => 'Agenda',
        ]);

        Livewire::test(self::PAGE)
            ->call('askRemove', $source->id)
            ->assertSet('removeTargetIntegrated', 1)
            ->call('confirmRemove')
            ->assertDispatched('toastMagic');

        self::assertNotNull($source->fresh(), 'la source survit au refus');
    }

    #[Test]
    public function the_bundled_source_exposes_no_action_at_all(): void
    {
        $this->grant(['server.admin']);
        $bundled = ExtensionSource::factory()->bundled()->create();

        Livewire::test(self::PAGE)
            ->assertDontSeeHtml('data-testid="remove-source-'.$bundled->id.'"')
            ->assertDontSeeHtml('data-testid="disable-source-'.$bundled->id.'"')
            ->assertDontSeeHtml('data-testid="refresh-source-'.$bundled->id.'"')
            ->assertSee('ni désactivée ni retirée');
    }

    #[Test]
    public function acting_on_the_bundled_source_by_id_is_refused_server_side(): void
    {
        // La garde n'est pas seulement l'absence de bouton : un id forgé côté
        // client ne doit rien pouvoir.
        $this->grant(['server.admin']);
        $bundled = ExtensionSource::factory()->bundled()->create();

        Livewire::test(self::PAGE)
            ->call('toggleSource', $bundled->id, false)
            ->assertDispatched('toastMagic');

        self::assertTrue($bundled->fresh()->enabled);
        self::assertSame(0, ExtensionAuditLog::query()->count());
    }

    #[Test]
    public function an_unknown_source_id_never_produces_a_500(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::PAGE)
            ->call('askRemove', 999_999)
            ->assertSet('isRemoveOpen', false)
            ->assertDispatched('toastMagic')
            ->call('refreshSource', 999_999)
            ->assertDispatched('toastMagic')
            ->call('toggleSource', 999_999, false)
            ->assertDispatched('toastMagic');
    }
}
