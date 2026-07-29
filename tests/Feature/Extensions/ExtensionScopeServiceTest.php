<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions;

use App\Exceptions\ExtensionLifecycleException;
use App\Models\Extension;
use App\Models\ExtensionAuditLog;
use App\Models\ExtensionSource;
use App\Models\OidcClient;
use App\Models\User;
use App\Services\Extensions\ExtensionScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 56.4 — le service de révocation, éprouvé EN ISOLATION.
 *
 * La page Livewire ({@see \Tests\Feature\Livewire\Admin\ExtensionScopesPageTest})
 * couvre le parcours ; ce fichier-ci couvre les états que l'UI ne sait pas
 * produire — extension inconnue, extension sans client — et la classification
 * des refus. C'est là que se vérifie la règle de review 56.1 #1 : une garantie
 * qui n'existe que dans la vue n'est pas une garantie.
 */
class ExtensionScopeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ExtensionScopeService
    {
        return app(ExtensionScopeService::class);
    }

    /**
     * @param  list<string>  $granted
     */
    private function appWithClient(array $granted, bool $enabled = true): Extension
    {
        $source = ExtensionSource::factory()->remote('https://depot.example.test/extensions')->create();

        $extension = Extension::factory()
            ->for($source, 'source')
            ->app()
            ->withInstallBlock()
            ->installed(8600)
            ->create();

        OidcClient::factory()
            ->grantedScopes($granted)
            ->create([
                'extension_id' => $extension->id,
                'extension_key' => $extension->key,
                'enabled' => $enabled,
            ]);

        return $extension;
    }

    // ── Lecture ───────────────────────────────────────────────────────────

    #[Test]
    public function granted_scopes_are_null_when_the_extension_has_no_active_client(): void
    {
        $extension = $this->appWithClient(['profile'], enabled: false);

        // `null` ≠ `[]` : « pas de client » n'est pas « un client sans scope ».
        self::assertNull($this->service()->grantedScopesFor($extension));
    }

    #[Test]
    public function granted_scopes_come_from_the_most_recent_active_client(): void
    {
        $extension = $this->appWithClient(['profile']);

        OidcClient::factory()
            ->grantedScopes(['groups', 'profile'])
            ->create(['extension_id' => $extension->id, 'extension_key' => $extension->key]);

        self::assertSame(['groups', 'profile'], $this->service()->grantedScopesFor($extension));
    }

    // ── Révocation ────────────────────────────────────────────────────────

    #[Test]
    public function revoking_a_granted_scope_changes_the_state_and_traces_it(): void
    {
        $extension = $this->appWithClient(['profile', 'groups']);
        $actor = User::query()->create(['login' => 'admin.qa', 'role' => 'autre', 'is_active' => true]);

        $result = $this->service()->revokeScope((int) $extension->id, 'groups', $actor);

        self::assertSame(['changed' => true, 'status' => ExtensionScopeService::STATUS_REVOKED], $result);
        self::assertSame(['profile'], $this->service()->grantedScopesFor($extension->fresh()));

        $log = ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_SCOPE_REVOKE)->firstOrFail();
        self::assertSame('groups', $log->details);
        self::assertSame('admin.qa', $log->actor_login);
    }

    /** Acte CLI : pas d'acteur ⇒ `system`, jamais une trace anonyme. */
    #[Test]
    public function a_revocation_without_actor_is_traced_as_system(): void
    {
        $extension = $this->appWithClient(['profile']);

        $this->service()->revokeScope((int) $extension->id, 'profile', null);

        self::assertSame(
            ExtensionAuditLog::ACTOR_SYSTEM,
            ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_SCOPE_REVOKE)->firstOrFail()->actor_login,
        );
    }

    #[Test]
    public function a_scope_outside_the_closed_vocabulary_is_refused_without_audit(): void
    {
        $extension = $this->appWithClient(['profile', 'groups']);

        foreach (['openid', 'directory', ''] as $scope) {
            self::assertSame(
                ['changed' => false, 'status' => ExtensionScopeService::STATUS_UNSUPPORTED],
                $this->service()->revokeScope((int) $extension->id, $scope, null),
                'scope refusé attendu : « '.$scope.' »',
            );
        }

        self::assertSame(['groups', 'profile'], $this->service()->grantedScopesFor($extension->fresh()));
        self::assertSame(0, ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_SCOPE_REVOKE)->count());
    }

    #[Test]
    public function an_extension_without_any_active_client_reports_no_client(): void
    {
        $extension = $this->appWithClient(['profile'], enabled: false);

        self::assertSame(
            ['changed' => false, 'status' => ExtensionScopeService::STATUS_NO_CLIENT],
            $this->service()->revokeScope((int) $extension->id, 'profile', null),
        );
    }

    #[Test]
    public function an_already_revoked_scope_is_a_no_op_without_audit(): void
    {
        $extension = $this->appWithClient(['profile']);

        self::assertSame(
            ['changed' => false, 'status' => ExtensionScopeService::STATUS_NOT_GRANTED],
            $this->service()->revokeScope((int) $extension->id, 'groups', null),
        );
        self::assertSame(0, ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_SCOPE_REVOKE)->count());
    }

    #[Test]
    public function an_unknown_extension_fails_closed_with_a_named_exception(): void
    {
        $this->expectException(ExtensionLifecycleException::class);

        $this->service()->revokeScope(999_999, 'profile', null);
    }

    /**
     * Atomicité acte ↔ trace : si l'audit ne peut pas s'écrire, la révocation
     * est annulée. Une révocation sans trace serait pire qu'une révocation
     * refusée — FR36 veut savoir qui a retiré quoi.
     */
    #[Test]
    public function a_failing_audit_rolls_the_revocation_back(): void
    {
        $extension = $this->appWithClient(['profile', 'groups']);

        // Patron 54.2 : la table d'audit disparaît sous les pieds du service.
        \Illuminate\Support\Facades\Schema::drop('extension_audit_logs');

        try {
            $this->service()->revokeScope((int) $extension->id, 'groups', null);
            self::fail('une écriture d\'audit impossible doit remonter, pas être avalée');
        } catch (\Throwable) {
            // Attendu.
        }

        self::assertSame(
            ['groups', 'profile'],
            OidcClient::query()->where('extension_key', $extension->key)->firstOrFail()->grantedScopes(),
            'La révocation doit avoir été annulée avec sa trace.',
        );
    }
}
