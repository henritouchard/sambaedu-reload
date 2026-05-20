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
 * Story 16.13 — AC5.2 (`/api/v1/workstation-config/thunderbird`).
 */
class ThunderbirdApiV1Test extends TestCase
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

        if (! Schema::hasTable('user_group_user')) {
            Schema::create('user_group_user', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('user_group_id');
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('app_customizations')) {
            Schema::create('app_customizations', function (Blueprint $t): void {
                $t->id();
                $t->string('app_kind', 32);
                $t->nullableMorphs('customizable');
                $t->json('policies_json');
                $t->boolean('is_default')->default(false);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
            });
        }

        config()->set('app-customizations.template_paths.thunderbird', [
            base_path('tests/fixtures/thunderbird/template.json'),
        ]);
        config()->set('app-customizations.export_fs_on_save', false);
    }

    #[Test]
    public function missing_authorization_returns_401_missing(): void
    {
        $this->getJson('/api/v1/workstation-config/thunderbird')
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

        $this->getJson('/api/v1/workstation-config/thunderbird', ['Authorization' => 'Bearer ' . $emitted['token']])
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

        $this->getJson('/api/v1/workstation-config/thunderbird', ['Authorization' => 'Bearer ' . $emitted['token']])
            ->assertStatus(401)
            ->assertJson(['code' => JwtErrorCodes::JWT_WRONG_TIER]);
    }

    #[Test]
    public function unknown_workstation_returns_404(): void
    {
        $emitted = $this->issueTestJwt(['sub' => '99999999-9999-4999-9999-999999999999']);

        $this->getJson('/api/v1/workstation-config/thunderbird', ['Authorization' => 'Bearer ' . $emitted['token']])
            ->assertStatus(404);
    }

    #[Test]
    public function happy_path_returns_200_json(): void
    {
        $this->seedWorkstationContext();
        $emitted = $this->issueTestJwt(['sub' => $this->seededWorkstationUuid]);

        $response = $this->getJson(
            '/api/v1/workstation-config/thunderbird?user=jdoe',
            ['Authorization' => 'Bearer ' . $emitted['token']],
        );

        $response->assertOk();
        $contentType = (string) $response->headers->get('Content-Type');
        $this->assertStringContainsString('application/json', $contentType);
    }
}
