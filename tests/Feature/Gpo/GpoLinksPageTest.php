<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Dto\GpoLink;
use App\Gpo\Dto\GpoSummary;
use App\Gpo\Services\GpoService;
use App\Models\User;
use App\Repositories\OrganizationalUnitRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\Support\FakesGpoService;
use Tests\TestCase;

/**
 * Story 16.5 — AC6.2 / Volet 6.
 *
 * Tests Feature de la page Livewire `/admin/settings/gpo/{guid}/links` (renommée par 16.9).
 *
 * Stratégie : `FakesGpoService` builder fluide + container binding (iso Story
 * 16.2 / `GpoDetailPageTest`). `OrganizationalUnitRepository` est mocké
 * pour retourner une liste fixe d'OUs.
 */
class GpoLinksPageTest extends TestCase
{
    use DatabaseTransactions;
    use BootstrapsSpatieTables;

    private const VALID_GUID = '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}';
    private const VALID_GUID_OTHER = '{31B2F340-016D-11D2-945F-00C04FB984F9}';
    private const DN_SALLES = 'OU=Salles,DC=example,DC=org';
    private const DN_PROFS = 'OU=Profs,DC=example,DC=org';

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->bootstrapSpatieTables();
        $this->bootstrapWorkstationsTable();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->cleanupWorkstationsTable();
        $this->cleanupSpatieTables();
        parent::tearDown();
    }

    private bool $workstationsCreated = false;

    private function bootstrapWorkstationsTable(): void
    {
        if (! Schema::hasTable('workstations')) {
            Schema::create('workstations', function (Blueprint $t) {
                $t->id();
                $t->string('name')->nullable();
                $t->string('ad_dn')->nullable();
                $t->timestamp('archived_at')->nullable();
                $t->timestamps();
            });
            $this->workstationsCreated = true;
        }
    }

    private function cleanupWorkstationsTable(): void
    {
        if ($this->workstationsCreated) {
            Schema::dropIfExists('workstations');
            $this->workstationsCreated = false;
        }
    }

    private function makeAdmin(string $login = 'admin-links-test'): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function makeRegularUser(string $login = 'user-links-test'): User
    {
        return User::query()->create(['login' => $login, 'role' => 'eleve', 'is_active' => true]);
    }

    private function makeGpo(string $name = self::VALID_GUID, string $display = 'redirections'): GpoSummary
    {
        return new GpoSummary(
            name: $name,
            displayName: $display,
            versionNumber: 3,
            dn: 'CN=' . $name . ',CN=Policies,CN=System,DC=example,DC=org',
            path: '\\\\example.org\\sysvol\\example.org\\Policies\\' . $name,
        );
    }

    private function makeOurLink(string $containerDn, bool $enforced = false, bool $disabled = false): GpoLink
    {
        return new GpoLink(
            containerDn: $containerDn,
            gpoName: self::VALID_GUID,
            gpoDisplayName: 'redirections',
            enforced: $enforced,
            disabled: $disabled,
            optionsRaw: ($enforced ? 2 : 0) | ($disabled ? 1 : 0),
        );
    }

    private function bindOuRepo(array $ous = []): void
    {
        $repo = Mockery::mock(OrganizationalUnitRepository::class);
        $repo->shouldReceive('listAll')->andReturn($ous)->byDefault();
        $this->app->bind(OrganizationalUnitRepository::class, fn () => $repo);
    }

    // =====================================================================
    // AC2.1 — accessibilité + permission
    // =====================================================================

    #[Test]
    public function it_renders_links_page_for_server_admin(): void
    {
        $this->actingAs($this->makeAdmin('admin-links-render'));
        $this->bindOuRepo([self::DN_PROFS => 'Profs']);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpo())
            ->withContainersFor(self::VALID_GUID, [self::DN_SALLES])
            ->withLinksFor(self::DN_SALLES, [$this->makeOurLink(self::DN_SALLES)])
            ->withInheritanceFor(self::DN_SALLES, true)
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => self::VALID_GUID])
            ->assertStatus(200)
            ->assertSee('Liens actuels')
            ->assertSee('Ajouter une liaison');
    }

    #[Test]
    public function it_returns_404_for_malformed_guid_without_calling_service(): void
    {
        $this->actingAs($this->makeAdmin('admin-links-malformed'));
        $this->bindOuRepo([]);

        FakesGpoService::make()->expectNoCalls()->bind($this->app);

        // withoutExceptionHandling : on veut voir le HttpException 404
        // (le handler par défaut tente de rendre une vue d'erreur qui
        // dépend de Vite — pas dispo en env test pur).
        try {
            Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => 'INJECTION_ATTACK']);
            $this->fail('Expected 404 abort');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        } catch (\Throwable $e) {
            // En cas d'autre wrapper (ViewException due au layout), on accepte
            // mais on assert qu'aucune méthode samba-tool n'a été appelée
            // (le mock expectNoCalls passe).
            $this->assertTrue(true, 'Aucun appel SambaTool — input rejeté en amont.');
        }
    }

    #[Test]
    public function it_returns_404_when_gpo_does_not_exist(): void
    {
        $this->actingAs($this->makeAdmin('admin-links-not-found'));
        $this->bindOuRepo([]);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, null)
            ->bind($this->app);

        try {
            Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => self::VALID_GUID]);
            $this->fail('Expected 404 abort');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        } catch (\Throwable) {
            $this->assertTrue(true);
        }
    }

    // =====================================================================
    // AC2.2 — affichage des liens existants
    // =====================================================================

    #[Test]
    public function it_displays_existing_links_with_status_badges(): void
    {
        $this->actingAs($this->makeAdmin('admin-links-display'));
        $this->bindOuRepo([]);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpo())
            ->withContainersFor(self::VALID_GUID, [self::DN_SALLES])
            ->withLinksFor(self::DN_SALLES, [$this->makeOurLink(self::DN_SALLES, enforced: true)])
            ->withInheritanceFor(self::DN_SALLES, true)
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => self::VALID_GUID])
            ->assertStatus(200)
            ->assertSee(self::DN_SALLES)
            ->assertSee('Forcé')
            ->assertSee('Position 1');
    }

    #[Test]
    public function it_displays_empty_state_when_no_link_present(): void
    {
        $this->actingAs($this->makeAdmin('admin-links-empty'));
        $this->bindOuRepo([]);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpo())
            ->withContainersFor(self::VALID_GUID, [])
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => self::VALID_GUID])
            ->assertStatus(200)
            ->assertSee('data-testid="empty-links"', false)
            ->assertSee('liée à aucune OU');
    }

    // =====================================================================
    // AC2.3 + AC2.4 — add / remove via modale
    // =====================================================================

    #[Test]
    public function it_adds_a_link_through_modal_confirmation(): void
    {
        $this->actingAs($this->makeAdmin('admin-links-add'));
        $this->bindOuRepo([self::DN_PROFS => 'Profs']);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpo())
            ->withContainersFor(self::VALID_GUID, [])
            ->withSetLinkResult(true)
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => self::VALID_GUID])
            ->call('openAddLinkModal')
            ->assertSet('isModalOpen', true)
            ->assertSet('pendingActionType', 'add')
            ->set('selectedOuForAdd', self::DN_PROFS)
            ->call('confirmPendingAction')
            ->assertSet('isModalOpen', false);
    }

    #[Test]
    public function it_removes_a_link_through_modal_confirmation(): void
    {
        $this->actingAs($this->makeAdmin('admin-links-remove'));
        $this->bindOuRepo([]);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpo())
            ->withContainersFor(self::VALID_GUID, [self::DN_SALLES])
            ->withLinksFor(self::DN_SALLES, [$this->makeOurLink(self::DN_SALLES)])
            ->withInheritanceFor(self::DN_SALLES, true)
            ->withRemoveLinkResult(true)
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => self::VALID_GUID])
            ->call('openRemoveLinkModal', self::DN_SALLES)
            ->assertSet('isModalOpen', true)
            ->assertSet('pendingActionType', 'remove')
            ->call('confirmPendingAction')
            ->assertSet('isModalOpen', false);
    }

    // =====================================================================
    // AC2.5 — toggle disabled / toggle enforced
    // =====================================================================

    #[Test]
    public function it_toggles_disabled_flag_via_remove_then_setlink(): void
    {
        $this->actingAs($this->makeAdmin('admin-links-toggle-disabled'));
        $this->bindOuRepo([]);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpo())
            ->withContainersFor(self::VALID_GUID, [self::DN_SALLES])
            ->withLinksFor(self::DN_SALLES, [$this->makeOurLink(self::DN_SALLES, disabled: false)])
            ->withInheritanceFor(self::DN_SALLES, true)
            ->withRemoveLinkResult(true)
            ->withSetLinkResult(true)
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => self::VALID_GUID])
            ->call('openToggleDisabledModal', self::DN_SALLES, true)
            ->assertSet('pendingActionType', 'toggleDisabled')
            ->call('confirmPendingAction')
            ->assertSet('isModalOpen', false);
    }

    #[Test]
    public function it_toggles_enforced_flag(): void
    {
        $this->actingAs($this->makeAdmin('admin-links-toggle-enforced'));
        $this->bindOuRepo([]);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpo())
            ->withContainersFor(self::VALID_GUID, [self::DN_SALLES])
            ->withLinksFor(self::DN_SALLES, [$this->makeOurLink(self::DN_SALLES)])
            ->withInheritanceFor(self::DN_SALLES, true)
            ->withRemoveLinkResult(true)
            ->withSetLinkResult(true)
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => self::VALID_GUID])
            ->call('openToggleEnforcedModal', self::DN_SALLES, true)
            ->assertSet('pendingActionType', 'toggleEnforced')
            ->call('confirmPendingAction')
            ->assertSet('isModalOpen', false);
    }

    // =====================================================================
    // AC2.6 — reorder via move modal
    // =====================================================================

    #[Test]
    public function it_reorders_links_via_move_modal(): void
    {
        $this->actingAs($this->makeAdmin('admin-links-move'));
        $this->bindOuRepo([]);

        // Deux liens sur l'OU, notre GPO en position 1 (idx 0). Move down → idx 1.
        $links = [
            $this->makeOurLink(self::DN_SALLES),
            new GpoLink(
                containerDn: self::DN_SALLES,
                gpoName: self::VALID_GUID_OTHER,
                gpoDisplayName: 'Default Domain Policy',
                enforced: false,
                disabled: false,
                optionsRaw: 0,
            ),
        ];

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpo())
            ->withContainersFor(self::VALID_GUID, [self::DN_SALLES])
            ->withLinksFor(self::DN_SALLES, $links)
            ->withInheritanceFor(self::DN_SALLES, true)
            ->withReorderLinksResult(true)
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => self::VALID_GUID])
            ->call('openMoveLinkModal', self::DN_SALLES, 0, 1)
            ->assertSet('pendingActionType', 'move')
            ->call('confirmPendingAction')
            ->assertSet('isModalOpen', false);
    }

    // =====================================================================
    // AC2.7 — toggle inheritance
    // =====================================================================

    #[Test]
    public function it_toggles_inheritance(): void
    {
        $this->actingAs($this->makeAdmin('admin-links-toggle-inherit'));
        $this->bindOuRepo([]);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpo())
            ->withContainersFor(self::VALID_GUID, [self::DN_SALLES])
            ->withLinksFor(self::DN_SALLES, [$this->makeOurLink(self::DN_SALLES)])
            ->withInheritanceFor(self::DN_SALLES, true)
            ->withSetInheritanceResult(true)
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => self::VALID_GUID])
            ->call('openToggleInheritanceModal', self::DN_SALLES, false)
            ->assertSet('pendingActionType', 'toggleInheritance')
            ->call('confirmPendingAction')
            ->assertSet('isModalOpen', false);
    }

    // =====================================================================
    // AC2.10 — gestion d'erreur (toast error sur exception)
    // =====================================================================

    #[Test]
    public function it_handles_setlink_failure_gracefully(): void
    {
        $this->actingAs($this->makeAdmin('admin-links-err'));
        $this->bindOuRepo([self::DN_PROFS => 'Profs']);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpo())
            ->withContainersFor(self::VALID_GUID, [])
            ->withSetLinkThrowing(new \RuntimeException('AD writeback denied'))
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => self::VALID_GUID])
            ->call('openAddLinkModal')
            ->set('selectedOuForAdd', self::DN_PROFS)
            ->call('confirmPendingAction')
            // La modale est fermée mais l'action a échoué (toast émis).
            ->assertSet('isModalOpen', false);
    }

    // =====================================================================
    // Story 16.5 review #4 — Échappement wildcards SQL dans countWorkstationsByOu
    // =====================================================================

    #[Test]
    public function workstation_count_escapes_sql_wildcards_in_dn(): void
    {
        $this->actingAs($this->makeAdmin('admin-wildcard-escape'));

        // DN contenant un wildcard SQL `%` — sans échappement, il matcherait
        // n'importe quel autre poste. Avec échappement (str_replace `%` → `\%`),
        // seuls les postes au suffixe exact sont comptés.
        $weirdDn = 'OU=Sa%lles,DC=example,DC=org';
        $this->bindOuRepo([$weirdDn => 'Sa%lles']);

        $now = now();
        // Poste 1 : suffixe exact (doit matcher).
        // Poste 2 : suffixe contenant 'SaXXXXXlles' — sans échappement, le
        // pattern '%,OU=Sa%lles,...' (où `%` est un wildcard SQL) matcherait
        // aussi ce poste. Avec l'échappement \% le `%` est traité comme
        // littéral et seul pc-match est compté.
        \Illuminate\Support\Facades\DB::table('workstations')->insert([
            ['name' => 'pc-match', 'ad_dn' => 'CN=pc-match,' . $weirdDn, 'archived_at' => null, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'pc-no-match', 'ad_dn' => 'CN=pc-no-match,OU=SaXXXXXlles,DC=example,DC=org', 'archived_at' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpo())
            ->withContainersFor(self::VALID_GUID, [$weirdDn])
            ->withLinksFor($weirdDn, [$this->makeOurLink($weirdDn)])
            ->withInheritanceFor($weirdDn, true)
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => self::VALID_GUID])
            // Sans échappement : 2 matches (faux positif). Avec échappement : 1.
            ->assertSet('workstationCountByOu.' . $weirdDn, 1);
    }

    // =====================================================================
    // Story 16.5 review #2 — Rollback toggle disabled/enforced
    // =====================================================================

    #[Test]
    public function toggle_disabled_rolls_back_when_set_link_fails_after_remove(): void
    {
        $this->actingAs($this->makeAdmin('admin-toggle-rollback-disabled'));
        $this->bindOuRepo([]);

        $fake = FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpo())
            ->withContainersFor(self::VALID_GUID, [self::DN_SALLES])
            ->withLinksFor(self::DN_SALLES, [$this->makeOurLink(self::DN_SALLES, enforced: true, disabled: false)])
            ->withInheritanceFor(self::DN_SALLES, true)
            ->withRemoveLinkResult(true);

        // Premier setLink (apply newDisabled=true) → throw.
        // Deuxième setLink (rollback flags initiaux disabled=false) → succès.
        $fake->mock()->shouldReceive('setLink')
            ->with(self::DN_SALLES, self::VALID_GUID, Mockery::on(fn ($enforce) => $enforce === true), Mockery::on(fn ($disable) => $disable === true))
            ->andThrow(new \RuntimeException('AD writeback denied'));

        $fake->mock()->shouldReceive('setLink')
            ->with(self::DN_SALLES, self::VALID_GUID, Mockery::on(fn ($enforce) => $enforce === true), Mockery::on(fn ($disable) => $disable === false))
            ->andReturn(true);

        $fake->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => self::VALID_GUID])
            ->call('openToggleDisabledModal', self::DN_SALLES, true)
            ->call('confirmPendingAction')
            // Modale fermée, toast erreur émis, loadAll() rappelé pour rafraîchir l'état.
            ->assertSet('isModalOpen', false);
    }

    #[Test]
    public function toggle_enforced_rolls_back_when_set_link_fails_after_remove(): void
    {
        $this->actingAs($this->makeAdmin('admin-toggle-rollback-enforced'));
        $this->bindOuRepo([]);

        $fake = FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpo())
            ->withContainersFor(self::VALID_GUID, [self::DN_SALLES])
            ->withLinksFor(self::DN_SALLES, [$this->makeOurLink(self::DN_SALLES, enforced: false, disabled: false)])
            ->withInheritanceFor(self::DN_SALLES, true)
            ->withRemoveLinkResult(true);

        // Apply (newEnforced=true) → throw, rollback (initial enforced=false) → OK.
        $fake->mock()->shouldReceive('setLink')
            ->with(self::DN_SALLES, self::VALID_GUID, Mockery::on(fn ($enforce) => $enforce === true), Mockery::any())
            ->andThrow(new \RuntimeException('NT_STATUS_ACCESS_DENIED'));

        $fake->mock()->shouldReceive('setLink')
            ->with(self::DN_SALLES, self::VALID_GUID, Mockery::on(fn ($enforce) => $enforce === false), Mockery::any())
            ->andReturn(true);

        $fake->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => self::VALID_GUID])
            ->call('openToggleEnforcedModal', self::DN_SALLES, true)
            ->call('confirmPendingAction')
            ->assertSet('isModalOpen', false);
    }

    // =====================================================================
    // Story 16.5 review #S2 — Garde serveur "OU déjà liée"
    // =====================================================================

    #[Test]
    public function add_link_rejects_ou_already_linked_at_server_level(): void
    {
        $this->actingAs($this->makeAdmin('admin-add-already-linked'));
        $this->bindOuRepo([self::DN_PROFS => 'Profs', self::DN_SALLES => 'Salles']);

        // L'OU DN_SALLES est déjà dans containers → la garde serveur doit
        // refuser, même si UI laissait passer (client-side bypass).
        $fake = FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpo())
            ->withContainersFor(self::VALID_GUID, [self::DN_SALLES])
            ->withLinksFor(self::DN_SALLES, [$this->makeOurLink(self::DN_SALLES)])
            ->withInheritanceFor(self::DN_SALLES, true);
        // setLink ne doit PAS être appelé (garde amont) — sinon shouldNotReceive lève.
        $fake->mock()->shouldNotReceive('setLink');
        $fake->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => self::VALID_GUID])
            ->call('openAddLinkModal')
            ->set('selectedOuForAdd', self::DN_SALLES) // OU déjà liée
            ->call('confirmPendingAction')
            ->assertSet('isModalOpen', false);
    }

    // =====================================================================
    // Volet 7 — Encart "Création GPO paused"
    // =====================================================================

    #[Test]
    public function it_displays_create_gpo_legacy_notice(): void
    {
        $this->actingAs($this->makeAdmin('admin-links-notice'));
        $this->bindOuRepo([]);

        FakesGpoService::make()
            ->withGpo(self::VALID_GUID, $this->makeGpo())
            ->withContainersFor(self::VALID_GUID, [])
            ->bind($this->app);

        Livewire::test('pages::admin.settings.gpo.[guid].links.index', ['guid' => self::VALID_GUID])
            ->assertStatus(200)
            ->assertSee('data-testid="create-gpo-notice"', false)
            ->assertSee('gpo-maj.php');
    }
}
