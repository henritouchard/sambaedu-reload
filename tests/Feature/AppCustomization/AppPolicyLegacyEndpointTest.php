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
 * Tests feature — endpoints legacy `/gpo/firefox_out.php` et `/gpo/thunderbird_out.php`.
 *
 * Story 4.8 — AC 9.
 */
class AppPolicyLegacyEndpointTest extends TestCase
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

    private function validId(): string
    {
        return str_repeat('a', 32);
    }

    #[Test]
    public function firefox_out_empty_id_returns_empty_200(): void
    {
        $response = $this->post('/gpo/firefox_out.php', [
            'id' => '',
        ]);
        $response->assertStatus(200);
        // Fidèle legacy `firefox_out.php:9-10` : exit() = body strictement vide.
        $this->assertSame('', (string) $response->getContent());
    }

    #[Test]
    public function firefox_out_invalid_id_returns_400(): void
    {
        $response = $this->post('/gpo/firefox_out.php', [
            'id' => 'not-md5',
        ]);
        $response->assertStatus(400);
    }

    #[Test]
    public function firefox_out_unknown_context_returns_404(): void
    {
        $this->seedContext(str_repeat('c', 32), []);
        $response = $this->post('/gpo/firefox_out.php', [
            'id' => $this->validId(),
            'os' => 'linux',
        ]);
        $response->assertStatus(404);
    }

    #[Test]
    public function firefox_out_valid_linux_returns_json_policies(): void
    {
        $this->seedContext($this->validId(), [
            'user' => ['cn' => 'jdoe'],
            'machine' => ['cn' => 'post01'],
            'salle' => '',
            'list_u' => [],
            'os' => 'linux',
            'time' => time(),
        ]);

        $response = $this->post('/gpo/firefox_out.php', [
            'id' => $this->validId(),
            'os' => 'linux',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $payload = json_decode($response->getContent(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('policies', $payload);
        $this->assertArrayHasKey('Proxy', $payload['policies']);
    }

    #[Test]
    public function firefox_out_valid_windows_returns_json_policies(): void
    {
        $this->seedContext($this->validId(), [
            'user' => ['cn' => 'jdoe'],
            'machine' => ['cn' => 'post01'],
            'salle' => '',
            'list_u' => [],
            'os' => 'windows',
            'time' => time(),
        ]);

        $response = $this->post('/gpo/firefox_out.php', [
            'id' => $this->validId(),
            'os' => 'windows',
        ]);

        $response->assertOk();
    }

    #[Test]
    public function thunderbird_out_valid_id_returns_json_policies(): void
    {
        $this->seedContext($this->validId(), [
            'user' => ['cn' => 'jdoe'],
            'machine' => ['cn' => 'post01'],
            'salle' => '',
            'list_u' => [],
            'os' => 'linux',
            'time' => time(),
        ]);

        $response = $this->post('/gpo/thunderbird_out.php', [
            'id' => $this->validId(),
        ]);

        $response->assertOk();
        $payload = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('policies', $payload);
        $this->assertArrayHasKey('Proxy', $payload['policies']);
    }

    #[Test]
    public function no_cache_headers_are_present(): void
    {
        $this->seedContext($this->validId(), [
            'user' => ['cn' => 'jdoe'],
            'machine' => ['cn' => 'p'],
        ]);

        $response = $this->post('/gpo/firefox_out.php', [
            'id' => $this->validId(),
            'os' => 'linux',
        ]);

        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );
    }
}
