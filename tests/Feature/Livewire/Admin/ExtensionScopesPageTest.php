<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\Extension;
use App\Models\ExtensionAuditLog;
use App\Models\ExtensionSource;
use App\Models\OidcClient;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 56.4 (AC1, AC2) — la fiche d'extension : **demandés vs accordés**, et
 * la révocation.
 *
 * Fichier NOUVEAU, volontairement : {@see ExtensionDetailPageTest} (54.1/54.2)
 * et {@see ExtensionAppOperationsPageTest} (56.3) restent VERBATIM. Qu'elles
 * passent inchangées est la preuve que ce volet s'ajoute sans rien déplacer.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  DEUX LISTES, DEUX SENS
 *
 *  « Demandés » vient du manifest : ce que l'extension DÉCLARE vouloir.
 *  « Accordés » vient du client OIDC : ce qu'elle REÇOIT. L'écart entre les
 *  deux est précisément l'information que la fiche doit rendre lisible — les
 *  confondre reviendrait à afficher un consentement que personne n'a donné.
 * ══════════════════════════════════════════════════════════════════════════
 */
class ExtensionScopesPageTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'pages::admin.extensions.[id].index';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->admin = User::query()->create([
            'login' => 'extension-scopes-admin',
            'role' => 'autre',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);
    }

    /** @param list<string> $abilities */
    private function grant(array $abilities): void
    {
        Gate::before(fn ($user, string $ability) => in_array($ability, $abilities, true) ? true : null);
    }

    /**
     * Une `app` INSTALLÉE, dont le manifest demande `$requested` et dont le
     * client OIDC actif porte `$granted`.
     *
     * @param  list<string>  $requested
     * @param  list<string>  $granted
     */
    private function installedAppWithClient(array $requested, array $granted): Extension
    {
        $source = ExtensionSource::factory()->remote('https://depot.example.test/extensions')->create();

        $extension = Extension::factory()
            ->for($source, 'source')
            ->app()
            ->withInstallBlock()
            ->withManifestExtras($requested, [])
            ->installed(9101)
            ->create();

        OidcClient::factory()
            ->grantedScopes($granted)
            ->create([
                'extension_id' => $extension->id,
                'extension_key' => $extension->key,
            ]);

        return $extension;
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC1 — la fiche montre les DEUX informations, distinctes
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function the_page_shows_requested_and_granted_scopes_as_two_distinct_blocks(): void
    {
        $this->grant(['server.admin']);

        $extension = $this->installedAppWithClient(['profile', 'groups'], ['profile', 'groups']);

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertSee('Demandées par le manifest')
            ->assertSee('Réellement accordées')
            ->assertSeeHtml('data-testid="scopes-list"')
            ->assertSeeHtml('data-testid="granted-scopes-list"')
            ->assertSeeHtml('data-testid="revoke-scope-profile"')
            ->assertSeeHtml('data-testid="revoke-scope-groups"');
    }

    /**
     * L'écart demandés/accordés est VISIBLE : c'est ce qui permet à un admin
     * de constater qu'il a révoqué quelque chose, et à un intégrateur de
     * comprendre pourquoi son extension n'obtient pas ce qu'elle demande.
     */
    #[Test]
    public function a_revoked_scope_stays_listed_as_requested_but_disappears_from_granted(): void
    {
        $this->grant(['server.admin']);

        $extension = $this->installedAppWithClient(['profile', 'groups'], ['profile']);

        $component = Livewire::test(self::PAGE, ['id' => $extension->id]);

        self::assertSame(['profile', 'groups'], $component->get('extension.scopes'));
        self::assertSame(['profile'], $component->get('extension.granted_scopes'));

        $component->assertSeeHtml('data-testid="revoke-scope-profile"')
            ->assertDontSeeHtml('data-testid="revoke-scope-groups"');
    }

    #[Test]
    public function an_extension_without_an_active_oidc_client_shows_no_granted_block(): void
    {
        $this->grant(['server.admin']);

        // Une `link` intégrée : elle n'installe rien, donc n'a aucun client.
        $extension = Extension::factory()->fromBundled()->link('/doc')->integrated()->create();

        $component = Livewire::test(self::PAGE, ['id' => $extension->id]);

        self::assertNull($component->get('extension.granted_scopes'));
        $component->assertDontSeeHtml('data-testid="granted-scopes-block"');
    }

    #[Test]
    public function a_client_stripped_of_every_scope_shows_an_explicit_empty_state(): void
    {
        $this->grant(['server.admin']);

        $extension = $this->installedAppWithClient(['profile', 'groups'], []);

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertSeeHtml('data-testid="granted-scopes-block"')
            ->assertSeeHtml('data-testid="no-granted-scopes"');
    }

    /**
     * Un client RÉVOQUÉ (extension désinstallée) n'est plus une source de
     * vérité : la fiche n'affiche pas ses scopes comme s'ils valaient encore.
     */
    #[Test]
    public function a_disabled_client_is_ignored_by_the_granted_block(): void
    {
        $this->grant(['server.admin']);

        $extension = $this->installedAppWithClient(['profile'], ['profile']);
        OidcClient::query()->where('extension_key', $extension->key)->update(['enabled' => false]);

        self::assertNull(
            Livewire::test(self::PAGE, ['id' => $extension->id])->get('extension.granted_scopes'),
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC2 — la révocation : modale, acte, audit, toast
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function revoking_a_scope_updates_the_client_and_writes_an_audit_line(): void
    {
        $this->grant(['server.admin']);

        $extension = $this->installedAppWithClient(['profile', 'groups'], ['profile', 'groups']);

        $component = Livewire::test(self::PAGE, ['id' => $extension->id])
            ->call('askRevokeScope', 'groups')
            ->assertSet('isRevokeScopeOpen', true)
            ->assertSet('scopeToRevoke', 'groups')
            ->call('confirmRevokeScope')
            ->assertSet('isRevokeScopeOpen', false)
            ->assertDispatched('toastMagic', status: 'success');

        // L'état RÉEL, en base — pas seulement ce que la page affiche.
        self::assertSame(
            ['profile'],
            OidcClient::query()->where('extension_key', $extension->key)->firstOrFail()->grantedScopes(),
        );

        // …et ce que la page affiche, remis en phase.
        self::assertSame(['profile'], $component->get('extension.granted_scopes'));

        $log = ExtensionAuditLog::query()
            ->where('action', ExtensionAuditLog::ACTION_SCOPE_REVOKE)
            ->firstOrFail();

        self::assertSame($extension->key, $log->extension_key);
        self::assertSame('groups', $log->details);
        self::assertSame($this->admin->id, $log->actor_user_id);
        self::assertSame($this->admin->login, $log->actor_login);
    }

    /**
     * TOUS les clients actifs de la clé sont traités — un fantôme laissé par
     * une installation antérieure continuerait sinon de servir la donnée
     * révoquée (patron `remove()` 56.2).
     */
    #[Test]
    public function every_enabled_client_of_the_key_is_stripped_not_only_the_displayed_one(): void
    {
        $this->grant(['server.admin']);

        $extension = $this->installedAppWithClient(['profile', 'groups'], ['profile', 'groups']);

        $ghost = OidcClient::factory()
            ->grantedScopes(['profile', 'groups'])
            ->create(['extension_id' => $extension->id, 'extension_key' => $extension->key]);

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->call('askRevokeScope', 'groups')
            ->call('confirmRevokeScope');

        foreach (OidcClient::query()->where('extension_key', $extension->key)->get() as $client) {
            self::assertSame(['profile'], $client->grantedScopes(), 'client #'.$client->id);
        }

        self::assertSame(['profile'], $ghost->fresh()?->grantedScopes());

        // UN acte, UNE ligne d'audit : la trace décrit la décision de l'admin,
        // pas le nombre de clients qu'elle a touchés.
        self::assertSame(
            1,
            ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_SCOPE_REVOKE)->count(),
        );
    }

    /**
     * Écran PÉRIMÉ (second admin, onglet dupliqué) : no-op signalé en `info`,
     * page rafraîchie, et ZÉRO ligne d'audit — un no-op n'est pas un acte
     * (patron review 54.2 #2).
     */
    #[Test]
    public function revoking_an_already_revoked_scope_is_a_signalled_no_op_without_audit(): void
    {
        $this->grant(['server.admin']);

        $extension = $this->installedAppWithClient(['profile', 'groups'], ['profile', 'groups']);

        $component = Livewire::test(self::PAGE, ['id' => $extension->id])
            ->call('askRevokeScope', 'groups');

        // Un autre admin passe avant.
        OidcClient::query()->where('extension_key', $extension->key)->update(['granted_scopes' => json_encode(['profile'])]);

        $component->call('confirmRevokeScope')->assertDispatched('toastMagic', status: 'info');

        self::assertSame(
            0,
            ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_SCOPE_REVOKE)->count(),
        );
        self::assertSame(['profile'], $component->get('extension.granted_scopes'));
    }

    /**
     * L'ouverture de la modale relit l'état RÉEL : un scope disparu entre le
     * rendu et le clic ne produit pas une confirmation qui ne servirait à rien.
     */
    #[Test]
    public function asking_to_revoke_a_scope_that_is_gone_never_opens_the_modal(): void
    {
        $this->grant(['server.admin']);

        $extension = $this->installedAppWithClient(['profile', 'groups'], ['profile']);

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->call('askRevokeScope', 'groups')
            ->assertSet('isRevokeScopeOpen', false)
            ->assertDispatched('toastMagic', status: 'info');
    }

    /**
     * `openid` n'est jamais accordé, donc jamais révocable — même en forçant
     * l'appel Livewire, qui est traité comme une entrée hostile.
     */
    #[Test]
    public function openid_can_never_be_revoked_even_by_a_forged_call(): void
    {
        $this->grant(['server.admin']);

        $extension = $this->installedAppWithClient(['profile', 'groups'], ['profile', 'groups']);

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->set('scopeToRevoke', 'openid')
            ->call('confirmRevokeScope')
            ->assertDispatched('toastMagic', status: 'info');

        self::assertSame(
            ['groups', 'profile'],
            OidcClient::query()->where('extension_key', $extension->key)->firstOrFail()->grantedScopes(),
        );
        self::assertSame(
            0,
            ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_SCOPE_REVOKE)->count(),
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // Sécurité — la garde vit dans CHAQUE méthode (defense-in-depth)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function every_revocation_method_is_forbidden_without_server_admin(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installedAppWithClient(['profile'], ['profile']);

        // Chaque méthode est éprouvée sur un composant FRAIS : un 403 invalide
        // le snapshot Livewire, et enchaîner sur la même instance ne testerait
        // plus la garde mais la mécanique de rendu.
        foreach (['askRevokeScope' => ['profile'], 'confirmRevokeScope' => [], 'closeRevokeScope' => []] as $method => $args) {
            // Monté AVEC le droit (la garde de route a déjà été franchie),
            // puis la délégation est retirée : c'est exactement le scénario
            // que la garde par méthode doit couvrir.
            $this->grant(['server.admin']);
            $component = Livewire::test(self::PAGE, ['id' => $extension->id]);

            // Le Gate résolu porte encore le `before()` du montage : on le
            // jette du conteneur pour que la délégation soit RÉELLEMENT
            // retirée — un `Gate::before(fn () => null)` ne fait que s'ajouter
            // à la pile, il n'annule rien.
            $this->app->forgetInstance(GateContract::class);
            Gate::clearResolvedInstances();

            $component->call($method, ...$args)->assertForbidden();
        }

        self::assertSame(
            ['profile'],
            OidcClient::query()->where('extension_key', $extension->key)->firstOrFail()->grantedScopes(),
        );
    }

    /**
     * CONTRE-ÉPREUVE du test précédent : avec le droit, les mêmes appels
     * passent. Sans elle, un composant cassé qui répondrait 403 à TOUT
     * validerait la garde sans rien prouver.
     */
    #[Test]
    public function the_same_calls_succeed_with_server_admin(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installedAppWithClient(['profile'], ['profile']);

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->call('askRevokeScope', 'profile')
            ->assertSet('isRevokeScopeOpen', true)
            ->call('closeRevokeScope')
            ->assertSet('isRevokeScopeOpen', false)
            ->assertSet('scopeToRevoke', '');
    }

    /**
     * NFR3 — la fiche parle de SCOPES, jamais d'identifiants de client. Ni le
     * `client_id`, ni le hash du secret n'ont à traverser une vue : ce sont des
     * éléments d'exploitation, et les afficher les ferait finir dans une capture
     * d'écran de ticket.
     */
    #[Test]
    public function the_page_never_exposes_the_client_id_nor_the_secret_hash(): void
    {
        $this->grant(['server.admin']);

        $extension = $this->installedAppWithClient(['profile', 'groups'], ['profile', 'groups']);
        $client = OidcClient::query()->where('extension_key', $extension->key)->firstOrFail();

        $component = Livewire::test(self::PAGE, ['id' => $extension->id]);

        // Contrôle POSITIF adossé : la page rend bien QUELQUE CHOSE de ce
        // client — sans lui, une page blanche passerait aussi.
        $component->assertSeeHtml('data-testid="granted-scopes-list"');

        $component->assertDontSee($client->client_id)
            ->assertDontSee($client->client_secret_hash);
    }
}
