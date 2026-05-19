<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Config;

use App\Auth\V1\Support\JwtErrorCodes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\Concerns\SeedsWorkstationConfig;
use Tests\TestCase;

/**
 * Story 16.13 — AC5.2 (`/api/v1/workstation-config/shortcuts`).
 *
 * ≥5 tests : 401 missing/expired/wrong_tier, 404 unknown, 200 happy path.
 */
class ShortcutsApiV1Test extends TestCase
{
    use IssuesWorkstationJwt;
    use SeedsWorkstationConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTestKeyPair();
        $this->ensureAuthV1Tables();
        $this->seedWorkstationContextSchemas();
        Cache::store('array')->flush();

        if (empty(config('app.key'))) {
            config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        }

        // ShortcutCompilerService requiert table `shortcuts` + pivot.
        if (! Schema::hasTable('shortcuts')) {
            Schema::create('shortcuts', function (Blueprint $t): void {
                $t->id();
                $t->string('name');
                $t->string('target')->nullable();
                $t->string('arguments')->nullable();
                $t->string('icon')->nullable();
                $t->boolean('is_dynamic')->default(false);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('shortcut_assignables')) {
            Schema::create('shortcut_assignables', function (Blueprint $t): void {
                $t->id();
                $t->morphs('assignable');
                $t->unsignedBigInteger('shortcut_id');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('compiled_shortcuts')) {
            Schema::create('compiled_shortcuts', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('shortcut_id');
                $t->string('os');
                $t->string('compiled_path')->nullable();
                $t->timestamps();
            });
        }
    }

    #[Test]
    public function missing_authorization_returns_401_missing(): void
    {
        $this->getJson('/api/v1/workstation-config/shortcuts')
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_MISSING]);
    }

    #[Test]
    public function expired_jwt_returns_401_expired(): void
    {
        $emitted = $this->issueTestJwt([
            'sub' => $this->seededWorkstationUuid,
            'iat' => Carbon::now()->subDays(2)->getTimestamp(),
            'exp' => Carbon::now()->subDay()->getTimestamp(),
        ]);

        $this->getJson('/api/v1/workstation-config/shortcuts', ['Authorization' => 'Bearer ' . $emitted['token']])
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_EXPIRED]);
    }

    #[Test]
    public function wrong_tier_returns_401_wrong_tier(): void
    {
        $emitted = $this->issueTestJwt([
            'sub' => $this->seededWorkstationUuid,
            'tier' => 'controlhub',
        ]);

        $this->getJson('/api/v1/workstation-config/shortcuts', ['Authorization' => 'Bearer ' . $emitted['token']])
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_WRONG_TIER]);
    }

    #[Test]
    public function unknown_workstation_returns_404(): void
    {
        $emitted = $this->issueTestJwt(['sub' => '99999999-9999-4999-9999-999999999999']);

        $this->getJson(
            '/api/v1/workstation-config/shortcuts?action=logon&os=linux',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        )->assertStatus(404);
    }

    #[Test]
    public function happy_path_logon_action_returns_200_script(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $response = $this->getJson(
            '/api/v1/workstation-config/shortcuts?action=logon&os=linux&user=jdoe',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        );

        $response->assertOk();
        $contentType = (string) $response->headers->get('Content-Type');
        $this->assertStringContainsString('text/plain', $contentType);
    }

    #[Test]
    public function missing_action_returns_400(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $this->getJson(
            '/api/v1/workstation-config/shortcuts?os=linux',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        )->assertStatus(400);
    }
}
