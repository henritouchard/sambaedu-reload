<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Federated;

use App\Auth\Federated\ExternalIdentityLifecycleService;
use App\Auth\Federated\Jwt\FederatedUserClaims;
use App\Models\ExternalIdentity;
use App\Models\User;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\IssuesFederatedJwt;
use Tests\TestCase;

/**
 * Story 20.2 — Unit du `ExternalIdentityLifecycleService`.
 *
 * Couvre AC2-13, AC16 : reconcile (création/réutilisation/sync profil D-3),
 * gardes révocation/anonymisation (403, D-4), deactivate, softDeleteWithReason,
 * anonymize (PII vidée, external_sub→anon:<sha256>, idempotent, jamais
 * hard-delete, User lié désactivé).
 */
class ExternalIdentityLifecycleServiceTest extends TestCase
{
    use IssuesFederatedJwt;

    private ExternalIdentityLifecycleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureFederatedTables();
        $this->service = new ExternalIdentityLifecycleService();
    }

    /**
     * Forge un DTO de claims validé (le verifier est hors-scope ici).
     *
     * @param array<string,mixed> $overrides
     */
    private function claims(array $overrides = []): FederatedUserClaims
    {
        $now = Carbon::now()->getTimestamp();
        $d = array_merge([
            'sub' => 'ext-sub',
            'jti' => 'jti-1',
            'kid' => 'kid-1',
            'iss' => 'idp-test',
            'aud' => 'se5-instance-test',
            'tier' => 'federated-user',
            'role' => 'technicien',
            'login' => 'tech.externe',
            'name' => 'Tech Externe',
            'email' => 'tech@example.org',
            'iat' => $now,
            'exp' => $now + 600,
        ], $overrides);

        return new FederatedUserClaims(
            sub: (string) $d['sub'],
            jti: (string) $d['jti'],
            kid: (string) $d['kid'],
            iss: (string) $d['iss'],
            aud: (string) $d['aud'],
            tier: (string) $d['tier'],
            role: (string) $d['role'],
            login: (string) $d['login'],
            name: (string) $d['name'],
            email: (string) $d['email'],
            iat: (int) $d['iat'],
            exp: (int) $d['exp'],
        );
    }

    #[Test]
    public function reconcile_creates_active_identity_on_first_login(): void
    {
        $identity = $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-new']));

        $this->assertSame('ext-new', $identity->external_sub);
        $this->assertTrue($identity->is_active);
        $this->assertNull($identity->anonymized_at);
        $this->assertNotNull($identity->last_login_at);
        $this->assertSame(1, ExternalIdentity::where('external_sub', 'ext-new')->count());
    }

    #[Test]
    public function reconcile_reuses_same_identity_on_reconnection(): void
    {
        $first = $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-reuse', 'jti' => 'a']));
        $firstLogin = $first->last_login_at;

        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $second = $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-reuse', 'jti' => 'b']));
        Carbon::setTestNow();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ExternalIdentity::where('external_sub', 'ext-reuse')->count());
        $this->assertTrue($second->last_login_at->greaterThan($firstLogin));
    }

    #[Test]
    public function reconcile_overwrites_profile_when_claim_present(): void
    {
        $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-p', 'name' => 'Ancien Nom']));

        $updated = $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-p', 'name' => 'Nouveau Nom', 'email' => 'new@example.org']));

        $this->assertSame('Nouveau Nom', $updated->name);
        $this->assertSame('new@example.org', $updated->email);
    }

    #[Test]
    public function reconcile_preserves_profile_when_claim_empty(): void
    {
        $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-pre', 'name' => 'Nom Stocké', 'email' => 'keep@example.org']));

        // Claim name/email vides → la valeur stockée est préservée.
        $updated = $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-pre', 'name' => '', 'email' => '']));

        $this->assertSame('Nom Stocké', $updated->name);
        $this->assertSame('keep@example.org', $updated->email);
    }

    #[Test]
    public function reconcile_never_changes_role_or_is_active_via_profile_sync(): void
    {
        $identity = $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-acc']));
        $this->assertTrue($identity->is_active);

        // Le claim `role` ne doit jamais toucher l'état d'accès de l'identité
        // (séparation identité/accès D-3). is_active reste piloté hors profil.
        $again = $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-acc', 'role' => 'autre-role']));
        $this->assertTrue($again->is_active);
    }

    #[Test]
    public function reconcile_refuses_deactivated_identity_with_403(): void
    {
        $identity = $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-deact']));
        $this->service->deactivate($identity, 'admin');

        $this->expectException(HttpException::class);
        try {
            $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-deact']));
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            // Pas de réactivation silencieuse.
            $this->assertFalse((bool) ExternalIdentity::where('external_sub', 'ext-deact')->value('is_active'));
            throw $e;
        }
    }

    #[Test]
    public function reconcile_refuses_soft_deleted_identity_with_403(): void
    {
        $identity = $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-sd']));
        $this->service->softDeleteWithReason($identity, 'admin');

        try {
            $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-sd']));
            $this->fail('Expected 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // Toujours résolvable via withTrashed() (audit 20.4).
        $this->assertNotNull(ExternalIdentity::withTrashed()->where('external_sub', 'ext-sd')->first()->deleted_at);
    }

    #[Test]
    public function reconcile_refuses_anonymized_identity_with_403(): void
    {
        // D-4 : anti-résurrection. On simule une identité anonymisée portant
        // encore l'ancien sub clair (cas pathologique) pour prouver que le
        // garde explicite `anonymized_at` suffit, indépendamment de D-5.
        $identity = $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-anon']));
        $identity->anonymized_at = Carbon::now();
        $identity->save();

        try {
            $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-anon']));
            $this->fail('Expected 403 identity_anonymized');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    #[Test]
    public function deactivate_does_not_erase_identity(): void
    {
        $identity = $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-keep', 'name' => 'Garde Moi']));

        $this->service->deactivate($identity, 'litige');

        $fresh = ExternalIdentity::where('external_sub', 'ext-keep')->first();
        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->is_active);
        $this->assertSame('litige', $fresh->deactivated_reason);
        // PII intacte (désactivation ≠ anonymisation).
        $this->assertSame('Garde Moi', $fresh->name);
        $this->assertNull($fresh->anonymized_at);
    }

    #[Test]
    public function deactivate_truncates_overlong_reason_to_column_length(): void
    {
        // P-8 (review 20.2) : `deactivated_reason` est varchar(255). SQLite ne
        // contraint pas, MySQL prod lèverait. Le motif est tronqué côté service.
        $identity = $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-long']));

        $this->service->deactivate($identity, str_repeat('x', 500));

        $fresh = ExternalIdentity::where('external_sub', 'ext-long')->first();
        $this->assertSame(255, mb_strlen($fresh->deactivated_reason));
    }

    #[Test]
    public function anonymize_clears_pii_rewrites_sub_and_keeps_row(): void
    {
        $identity = $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-rgpd', 'name' => 'Jean', 'email' => 'jean@x.fr', 'login' => 'jean']));
        $id = $identity->id;

        $this->service->anonymize($identity);

        $fresh = ExternalIdentity::withTrashed()->find($id);
        $this->assertNotNull($fresh, 'la ligne survit (jamais hard-delete)');
        $this->assertNull($fresh->name);
        $this->assertNull($fresh->email);
        $this->assertNull($fresh->login);
        $this->assertFalse($fresh->is_active);
        $this->assertNotNull($fresh->anonymized_at);
        $this->assertNotNull($fresh->deleted_at, 'anonymisée = soft-deletée');
        // D-5 : external_sub réécrit en anon:<sha256(sub original)>.
        $this->assertSame('anon:' . hash('sha256', 'ext-rgpd'), $fresh->external_sub);
        // L'ancien sub clair ne matche plus.
        $this->assertNull(ExternalIdentity::withTrashed()->where('external_sub', 'ext-rgpd')->first());
    }

    #[Test]
    public function anonymize_is_idempotent(): void
    {
        $identity = $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-idem']));
        $this->service->anonymize($identity);

        $afterFirst = ExternalIdentity::withTrashed()->find($identity->id);
        $subAfterFirst = $afterFirst->external_sub;
        $anonAtAfterFirst = $afterFirst->anonymized_at;

        // Rejouer ne doit PAS re-hasher (anon:anon:...) ni bouger anonymized_at.
        $this->service->anonymize($afterFirst);

        $afterSecond = ExternalIdentity::withTrashed()->find($identity->id);
        $this->assertSame($subAfterFirst, $afterSecond->external_sub);
        $this->assertTrue($anonAtAfterFirst->equalTo($afterSecond->anonymized_at));
        $this->assertStringStartsNotWith('anon:anon:', $afterSecond->external_sub);
    }

    #[Test]
    public function anonymize_deactivates_linked_user_without_breaking_fk(): void
    {
        $identity = $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-user']));
        // `source` / `external_identity_id` ne sont pas mass-assignable (parité
        // 20.1 provisionUser) → on les pose en attributs directs.
        $user = new User();
        $user->login = 'ext:ext-user';
        $user->source = 'federated';
        $user->external_identity_id = $identity->id;
        $user->role = 'federated';
        $user->is_active = true;
        $user->save();

        $this->service->anonymize($identity);

        $freshUser = User::find($user->id);
        $this->assertNotNull($freshUser, 'le User survit (FK intacte)');
        $this->assertFalse($freshUser->is_active, 'accès coupé (AC12)');
        $this->assertSame($identity->id, $freshUser->external_identity_id, 'FK non orpheline');
    }

    #[Test]
    public function anonymize_never_hard_deletes(): void
    {
        $identity = $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-nd']));
        $id = $identity->id;

        $this->service->anonymize($identity);

        // forceDelete jamais appelé : la ligne est trouvable withTrashed().
        $this->assertSame(1, ExternalIdentity::withTrashed()->where('id', $id)->count());
    }

    #[Test]
    public function logs_carry_no_pii_in_lifecycle_actions(): void
    {
        // AC16 : on capture les logs du channel et on vérifie qu'aucune PII
        // (name/email/login clair) n'y figure, seulement id + hash de sub.
        $identity = $this->service->reconcileOnLogin($this->claims(['sub' => 'ext-log', 'name' => 'Secret Nom', 'email' => 'secret@x.fr', 'login' => 'secretlogin']));

        $captured = [];
        \Illuminate\Support\Facades\Log::shouldReceive('channel')->andReturnSelf();
        \Illuminate\Support\Facades\Log::shouldReceive('info')->andReturnUsing(function ($msg, $ctx = []) use (&$captured): void {
            $captured[] = json_encode($ctx);
        });
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->andReturnNull();
        \Illuminate\Support\Facades\Log::shouldReceive('error')->andReturnNull();

        $this->service->deactivate($identity, 'r');
        $this->service->anonymize($identity);

        $all = implode("\n", $captured);
        $this->assertStringNotContainsString('Secret Nom', $all);
        $this->assertStringNotContainsString('secret@x.fr', $all);
        $this->assertStringNotContainsString('secretlogin', $all);
    }
}
