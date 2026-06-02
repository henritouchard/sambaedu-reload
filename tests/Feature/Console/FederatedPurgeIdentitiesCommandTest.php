<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ExternalIdentity;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesFederatedJwt;
use Tests\TestCase;

/**
 * Story 20.2 — Feature de la commande `federated:purge-identities`.
 *
 * Couvre AC8-10, AC13 + edge cases (calqués `TrashPurgeCommandTest`) :
 *   - sélection par last_login_at + pii_ttl_days
 *   - identité encore active récemment → conservée
 *   - anonymize_enabled=false → no-op safe (exit 0, rien modifié) (AC9)
 *   - pii_ttl_days <= 0 sans --force → no-op safe (exit 0) (AC9)
 *   - --dry-run n'écrit rien (AC10)
 *   - anonymise effectivement (PII vidée, anonymized_at posé) (AC8)
 *   - ne hard-delete jamais (withTrashed() retrouve la ligne) (AC8)
 *   - fail-soft (un échec n'arrête pas la boucle) (AC13)
 */
class FederatedPurgeIdentitiesCommandTest extends TestCase
{
    use IssuesFederatedJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureFederatedTables();
        // Par défaut : rétention ACTIVE + TTL 365 j (les tests qui veulent le
        // garde-fou OFF surchargent explicitement).
        config([
            'federated_auth.retention.anonymize_enabled' => true,
            'federated_auth.retention.pii_ttl_days' => 365,
        ]);
    }

    private function makeIdentity(string $sub, ?Carbon $lastLogin, bool $active = true): ExternalIdentity
    {
        $identity = new ExternalIdentity();
        $identity->external_sub = $sub;
        $identity->issuer = 'idp-test';
        $identity->name = 'Nom ' . $sub;
        $identity->email = $sub . '@example.org';
        $identity->login = $sub;
        $identity->is_active = $active;
        $identity->last_login_at = $lastLogin;
        $identity->save();

        return $identity;
    }

    #[Test]
    public function it_anonymizes_identity_past_retention_ttl(): void
    {
        $expired = $this->makeIdentity('ext-old', Carbon::now()->subDays(400));

        $this->artisan('federated:purge-identities')
            ->expectsOutputToContain('Anonymisées : 1')
            ->assertExitCode(0);

        $fresh = ExternalIdentity::withTrashed()->find($expired->id);
        $this->assertNull($fresh->name);
        $this->assertNull($fresh->email);
        $this->assertNull($fresh->login);
        $this->assertNotNull($fresh->anonymized_at);
        $this->assertFalse($fresh->is_active);
        $this->assertSame('anon:' . app(\App\Auth\Federated\ExternalIdentityLifecycleService::class)->hashSub('ext-old'), $fresh->external_sub);
    }

    #[Test]
    public function it_keeps_recently_active_identity(): void
    {
        $recent = $this->makeIdentity('ext-recent', Carbon::now()->subDays(10));

        $this->artisan('federated:purge-identities')
            ->expectsOutputToContain('Anonymisées : 0')
            ->assertExitCode(0);

        $fresh = ExternalIdentity::find($recent->id);
        $this->assertNull($fresh->anonymized_at);
        $this->assertSame('Nom ext-recent', $fresh->name);
    }

    #[Test]
    public function it_is_noop_when_anonymize_disabled(): void
    {
        config(['federated_auth.retention.anonymize_enabled' => false]);
        $expired = $this->makeIdentity('ext-disabled', Carbon::now()->subDays(400));

        $this->artisan('federated:purge-identities')
            ->expectsOutputToContain('Rétention désactivée')
            ->assertExitCode(0);

        // Rien modifié (PII intacte).
        $fresh = ExternalIdentity::find($expired->id);
        $this->assertNull($fresh->anonymized_at);
        $this->assertSame('Nom ext-disabled', $fresh->name);
    }

    #[Test]
    public function dry_run_lists_candidates_even_when_anonymize_disabled(): void
    {
        // P-1 (review 20.2) : un --dry-run est sans effet de bord ; il doit
        // énumérer les candidats MÊME toggle OFF (état par défaut), pour audit
        // préventif DPO avant activation. Il avertit que c'est une simulation et
        // ne modifie rien.
        config(['federated_auth.retention.anonymize_enabled' => false]);
        $expired = $this->makeIdentity('ext-dry-off', Carbon::now()->subDays(400));

        $this->artisan('federated:purge-identities --dry-run')
            ->expectsOutputToContain('SIMULATION')
            ->expectsOutputToContain('[DRY-RUN] Candidats à anonymiser : 1')
            ->assertExitCode(0);

        // Rien modifié (simulation).
        $fresh = ExternalIdentity::find($expired->id);
        $this->assertNull($fresh->anonymized_at);
        $this->assertSame('Nom ext-dry-off', $fresh->name);
        $this->assertSame('ext-dry-off', $fresh->external_sub);
    }

    #[Test]
    public function it_is_noop_when_ttl_zero_without_force(): void
    {
        config(['federated_auth.retention.pii_ttl_days' => 0]);
        $expired = $this->makeIdentity('ext-ttl0', Carbon::now()->subDays(400));

        $this->artisan('federated:purge-identities')
            ->expectsOutputToContain('pii_ttl_days non configuré')
            ->assertExitCode(0);

        $this->assertNull(ExternalIdentity::find($expired->id)->anonymized_at);
    }

    #[Test]
    public function it_purges_with_force_when_ttl_zero(): void
    {
        config(['federated_auth.retention.pii_ttl_days' => 0]);
        $expired = $this->makeIdentity('ext-force', Carbon::now()->subDays(1));

        $this->artisan('federated:purge-identities --force')
            ->expectsOutputToContain('Mode --force')
            ->assertExitCode(0);

        $this->assertNotNull(ExternalIdentity::withTrashed()->find($expired->id)->anonymized_at);
    }

    #[Test]
    public function dry_run_modifies_nothing(): void
    {
        $expired = $this->makeIdentity('ext-dry', Carbon::now()->subDays(400));

        $this->artisan('federated:purge-identities --dry-run')
            ->expectsOutputToContain('[DRY-RUN]')
            ->assertExitCode(0);

        $fresh = ExternalIdentity::find($expired->id);
        $this->assertNull($fresh->anonymized_at);
        $this->assertSame('Nom ext-dry', $fresh->name);
        $this->assertSame('ext-dry', $fresh->external_sub);
    }

    #[Test]
    public function it_never_hard_deletes(): void
    {
        $expired = $this->makeIdentity('ext-survive', Carbon::now()->subDays(400));

        $this->artisan('federated:purge-identities')->assertExitCode(0);

        // La ligne survit (withTrashed) — jamais forceDelete.
        $this->assertSame(1, ExternalIdentity::withTrashed()->where('id', $expired->id)->count());
        $this->assertSame(0, ExternalIdentity::where('id', $expired->id)->count(), 'soft-deletée hors withTrashed');
    }

    #[Test]
    public function it_selects_only_expired_identities(): void
    {
        $old = $this->makeIdentity('ext-a', Carbon::now()->subDays(400));
        $recent = $this->makeIdentity('ext-b', Carbon::now()->subDays(10));

        $this->artisan('federated:purge-identities')
            ->expectsOutputToContain('Anonymisées : 1')
            ->assertExitCode(0);

        $this->assertNotNull(ExternalIdentity::withTrashed()->find($old->id)->anonymized_at);
        $this->assertNull(ExternalIdentity::find($recent->id)->anonymized_at);
    }

    #[Test]
    public function it_is_fail_soft_and_continues_on_error(): void
    {
        // Une identité déjà anonymisée n'est plus candidate (anonymized_at != null),
        // donc pour tester le fail-soft on injecte un service qui jette sur la 1re
        // identité et réussit sur la 2e.
        $first = $this->makeIdentity('ext-fail', Carbon::now()->subDays(400));
        $second = $this->makeIdentity('ext-ok', Carbon::now()->subDays(400));

        $stub = new class extends \App\Auth\Federated\ExternalIdentityLifecycleService {
            public function anonymize(ExternalIdentity $identity): void
            {
                if ($identity->external_sub === 'ext-fail') {
                    throw new \RuntimeException('boom');
                }
                parent::anonymize($identity);
            }
        };
        $this->app->instance(\App\Auth\Federated\ExternalIdentityLifecycleService::class, $stub);

        $this->artisan('federated:purge-identities')
            ->expectsOutputToContain('Anonymisées : 1. Erreurs : 1.')
            ->assertExitCode(0);

        // La 2e a bien été anonymisée malgré l'échec de la 1re (fail-soft).
        $this->assertNotNull(ExternalIdentity::withTrashed()->find($second->id)->anonymized_at);
        $this->assertNull(ExternalIdentity::find($first->id)?->anonymized_at);
    }

    #[Test]
    public function it_returns_failure_when_all_anonymizations_fail(): void
    {
        $this->makeIdentity('ext-allfail', Carbon::now()->subDays(400));

        $stub = new class extends \App\Auth\Federated\ExternalIdentityLifecycleService {
            public function anonymize(ExternalIdentity $identity): void
            {
                throw new \RuntimeException('boom');
            }
        };
        $this->app->instance(\App\Auth\Federated\ExternalIdentityLifecycleService::class, $stub);

        $this->artisan('federated:purge-identities')->assertExitCode(1);
    }
}
