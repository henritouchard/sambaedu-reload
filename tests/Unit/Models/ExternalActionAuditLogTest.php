<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\ExternalActionAuditLog;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesFederatedJwt;
use Tests\TestCase;

/**
 * Story 20.4 — Unit : modèle d'audit dénormalisé `ExternalActionAuditLog`.
 *
 * Couvre la fabrique `record()`, les scopes `scopeFederated`/`scopeForActor`
 * et la pose de `occurred_at` (D-6). Tests host SQLite uniquement.
 */
class ExternalActionAuditLogTest extends TestCase
{
    use IssuesFederatedJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureFederatedTables();
    }

    #[Test]
    public function record_persists_denormalised_fields(): void
    {
        $log = ExternalActionAuditLog::record(
            actorLogin: 'ext:sub-123',
            actorExternalSub: 'sub-123',
            actorName: 'Tech Externe',
            actorRole: 'technicien',
            httpMethod: 'POST',
            path: 'app/users/42/quota',
            statusCode: 200,
            routeName: 'app.users.quota.update',
            externalIdentityId: 7,
            userId: 11,
        );

        $this->assertDatabaseHas('external_action_audit_logs', [
            'id' => $log->id,
            'actor_login' => 'ext:sub-123',
            'actor_external_sub' => 'sub-123',
            'actor_name' => 'Tech Externe',
            'actor_role' => 'technicien',
            'source' => ExternalActionAuditLog::SOURCE_FEDERATED,
            'http_method' => 'POST',
            'route_name' => 'app.users.quota.update',
            'path' => 'app/users/42/quota',
            'status_code' => 200,
            'external_identity_id' => 7,
            'user_id' => 11,
        ]);
    }

    #[Test]
    public function record_sets_occurred_at(): void
    {
        $log = ExternalActionAuditLog::record(
            actorLogin: 'ext:sub-occ',
            actorExternalSub: 'sub-occ',
            actorName: 'X',
            actorRole: 'technicien',
            httpMethod: 'DELETE',
            path: 'app/x',
            statusCode: 204,
        );

        $this->assertNotNull($log->occurred_at);
        // `timestamps=false` (D-6) : aucune colonne created_at/updated_at gérée.
        $this->assertFalse($log->timestamps);
    }

    #[Test]
    public function scope_federated_filters_on_source(): void
    {
        ExternalActionAuditLog::record(
            actorLogin: 'ext:a', actorExternalSub: 'a', actorName: 'A', actorRole: 'technicien',
            httpMethod: 'POST', path: 'app/a', statusCode: 200,
        );
        // Ligne d'une autre origine (extensibilité Q-3) — ne doit pas remonter.
        ExternalActionAuditLog::record(
            actorLogin: 'svc:b', actorExternalSub: null, actorName: 'B', actorRole: null,
            httpMethod: 'POST', path: 'app/b', statusCode: 200, source: 'other',
        );

        $federated = ExternalActionAuditLog::query()->federated()->get();

        $this->assertCount(1, $federated);
        $this->assertSame('ext:a', $federated->first()->actor_login);
    }

    #[Test]
    public function scope_for_actor_filters_on_denormalised_login(): void
    {
        ExternalActionAuditLog::record(
            actorLogin: 'ext:zorro', actorExternalSub: 'zorro', actorName: 'Z', actorRole: 'technicien',
            httpMethod: 'POST', path: 'app/z1', statusCode: 200,
        );
        ExternalActionAuditLog::record(
            actorLogin: 'ext:zorro', actorExternalSub: 'zorro', actorName: 'Z', actorRole: 'technicien',
            httpMethod: 'DELETE', path: 'app/z2', statusCode: 204,
        );
        ExternalActionAuditLog::record(
            actorLogin: 'ext:autre', actorExternalSub: 'autre', actorName: 'Y', actorRole: 'technicien',
            httpMethod: 'POST', path: 'app/y', statusCode: 200,
        );

        $forZorro = ExternalActionAuditLog::query()->forActor('ext:zorro')->get();

        $this->assertCount(2, $forZorro);
        $this->assertSame(['ext:zorro', 'ext:zorro'], $forZorro->pluck('actor_login')->all());
    }
}
