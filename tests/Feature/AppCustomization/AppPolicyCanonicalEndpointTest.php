<?php

declare(strict_types=1);

namespace Tests\Feature\AppCustomization;

use App\Dto\AppCustomization\AppContext;
use App\Services\AppCustomization\Contracts\AppContextRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests feature — endpoint canonique `/api/policies/{kind}/{id}`.
 *
 * Story 4.8 — AC 10.
 */
class AppPolicyCanonicalEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Model::unguard();
        \App\Models\WorkstationGroup::flushEventListeners();
        \App\Models\UserGroup::flushEventListeners();
        \App\Models\User::flushEventListeners();

        if (empty(config('app.key'))) {
            config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        }

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('login')->unique();
            $t->string('password')->nullable();
            $t->string('role')->default('eleve');
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('ad_rights_bitmask')->default(0);
            $t->timestamps();
        });
        Schema::create('user_groups', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->string('type')->default('classe');
            $t->timestamps();
        });
        Schema::create('user_group_user', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('user_group_id');
            $t->timestamps();
        });
        Schema::create('workstation_groups', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('is_physical')->default(true);
            $t->timestamps();
        });
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

        config()->set('app-customizations.template_paths.firefox', [
            base_path('tests/fixtures/firefox/template.json'),
        ]);
        config()->set('app-customizations.template_paths.thunderbird', [
            base_path('tests/fixtures/thunderbird/template.json'),
        ]);
        config()->set('app-customizations.export_fs_on_save', false);
    }

    private function seedContext(string $id, array $context): void
    {
        $ctx = AppContext::fromApcuArray($context);
        $this->app->bind(AppContextRepository::class, function () use ($id, $ctx) {
            return new class($id, $ctx) implements AppContextRepository {
                public function __construct(private string $validId, private AppContext $ctx) {}

                public function findById(string $id): ?AppContext
                {
                    return $id === $this->validId ? $this->ctx : null;
                }
            };
        });
    }

    #[Test]
    public function unknown_kind_returns_404(): void
    {
        $response = $this->get('/api/policies/safari/' . str_repeat('a', 32));
        $response->assertStatus(404);
    }

    #[Test]
    public function canonical_firefox_returns_json(): void
    {
        $id = str_repeat('a', 32);
        $this->seedContext($id, [
            'user' => ['cn' => 'alice'],
            'machine' => ['cn' => 'post01'],
            'salle' => '',
            'list_u' => [],
            'os' => 'linux',
        ]);

        $response = $this->get('/api/policies/firefox/' . $id . '?os=linux');
        $response->assertOk();
        $payload = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('policies', $payload);
        $this->assertArrayHasKey('Proxy', $payload['policies']);
    }

    #[Test]
    public function canonical_thunderbird_returns_json(): void
    {
        $id = str_repeat('a', 32);
        $this->seedContext($id, [
            'user' => ['cn' => 'alice'],
            'machine' => ['cn' => 'post01'],
            'salle' => '',
            'list_u' => [],
        ]);

        $response = $this->get('/api/policies/thunderbird/' . $id);
        $response->assertOk();
        $payload = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('policies', $payload);
    }
}
