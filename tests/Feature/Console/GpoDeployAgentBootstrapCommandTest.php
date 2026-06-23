<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Gpo\AgentBootstrapDeployResult;
use App\Services\Gpo\AgentBootstrapPublisher;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 27.16 — mapping résultat → exit code de `gpo:deploy-agent-bootstrap`.
 *
 * Le câblage scripts (`update.sh`/`install.sh`) exige que la commande soit
 * NON BLOQUANTE : skip et même échec doivent sortir en 0 par défaut (fail-soft).
 * `--strict` est le seul mode où un échec réel sort en 1 (diagnostic/CI).
 */
final class GpoDeployAgentBootstrapCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function bindPublisherReturning(AgentBootstrapDeployResult $result, bool $expectForce = false, bool $expectDryRun = false): void
    {
        $mock = Mockery::mock(AgentBootstrapPublisher::class);
        $mock->shouldReceive('deploy')->once()->with($expectForce, $expectDryRun)->andReturn($result);
        $this->app->instance(AgentBootstrapPublisher::class, $mock);
    }

    #[Test]
    public function skip_result_exits_zero(): void
    {
        $this->bindPublisherReturning(AgentBootstrapDeployResult::skipped('admin_passwd absent', 'op-1'));

        $this->artisan('gpo:deploy-agent-bootstrap')
            ->expectsOutputToContain('Skip')
            ->assertExitCode(0);
    }

    #[Test]
    public function deployed_result_exits_zero(): void
    {
        $this->bindPublisherReturning(AgentBootstrapDeployResult::deployed('{GUID}', 'OU=computers,DC=localdev,DC=fr', 'op-2'));

        $this->artisan('gpo:deploy-agent-bootstrap')
            ->assertExitCode(0);
    }

    #[Test]
    public function failed_result_exits_zero_in_fail_soft_default(): void
    {
        $this->bindPublisherReturning(AgentBootstrapDeployResult::failed('boom', 'op-3'));

        $this->artisan('gpo:deploy-agent-bootstrap')
            ->assertExitCode(0);
    }

    #[Test]
    public function failed_result_exits_one_in_strict_mode(): void
    {
        $this->bindPublisherReturning(AgentBootstrapDeployResult::failed('boom', 'op-4'));

        $this->artisan('gpo:deploy-agent-bootstrap --strict')
            ->assertExitCode(1);
    }

    #[Test]
    public function dry_run_forwards_flag_to_publisher(): void
    {
        $this->bindPublisherReturning(
            AgentBootstrapDeployResult::dryRun('OU=computers,DC=localdev,DC=fr', 'op-5'),
            expectForce: false,
            expectDryRun: true,
        );

        $this->artisan('gpo:deploy-agent-bootstrap --dry-run')
            ->assertExitCode(0);
    }

    #[Test]
    public function force_flag_is_forwarded_to_publisher(): void
    {
        $this->bindPublisherReturning(
            AgentBootstrapDeployResult::deployed('{GUID}', 'OU=computers,DC=localdev,DC=fr', 'op-6'),
            expectForce: true,
            expectDryRun: false,
        );

        $this->artisan('gpo:deploy-agent-bootstrap --force')
            ->assertExitCode(0);
    }
}
