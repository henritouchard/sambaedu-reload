<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\RoamingProfileService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 26.3 — Tests de la commande `profiles:snapshot` (AC #1, #7).
 *
 * Couvre :
 *   - persistance du snapshot (colonne profile_snapshot + SystemSetting orphans)
 *     quand le scan remonte des données ;
 *   - fail-soft : scan impossible (/home/profiles absent → scanProfileSizes null)
 *     → exit FAILURE, snapshot précédent conservé.
 *
 * Le service est stubbé (sous-classe anonyme) pour piloter le scan sans FS réel
 * — pattern stub container cohérent avec AdminSettingsProfilsItinerantsTabTest.
 */
class ProfilesSnapshotCommandTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdUsers = false;
    private bool $createdSettings = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        // Ne drop QUE les tables créées par ce test (flag par table).
        if ($this->createdSettings) {
            Schema::dropIfExists('system_settings');
        }
        if ($this->createdUsers) {
            Schema::dropIfExists('users');
        }
        parent::tearDown();
    }

    private function createTables(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('login')->unique();
                $table->string('role')->default('eleve');
                $table->boolean('is_active')->default(true);
                $table->json('profile_snapshot')->nullable();
                $table->timestamps();
            });
            $this->createdUsers = true;
        }

        if (!Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->json('value')->nullable();
                $table->timestamps();
            });
            $this->createdSettings = true;
        }
    }

    /**
     * Bind un service qui renvoie $sizes au scan (ou null pour fail-soft) tout
     * en gardant la vraie logique persistSnapshot/detectOrphans.
     *
     * @param  array<string,int>|null  $sizes
     */
    private function bindScan(?array $sizes): void
    {
        $stub = new class($sizes) extends RoamingProfileService {
            /** @var array<string,int>|null */
            private ?array $fakeSizes;

            public function __construct(?array $sizes)
            {
                $this->fakeSizes = $sizes;
            }

            public function scanProfileSizes(): ?array
            {
                return $this->fakeSizes;
            }
        };

        $this->app->instance(RoamingProfileService::class, $stub);
    }

    #[Test]
    public function it_persists_snapshot_on_successful_scan(): void
    {
        User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);

        $this->bindScan([
            'alice.V6' => 209715200, // 200 Mo
            'orphan99.V2' => 5000000,
        ]);

        $exit = $this->artisan('profiles:snapshot')->run();

        $this->assertSame(0, $exit);

        $alice = User::query()->where('login', 'alice')->first();
        $this->assertNotNull($alice->profile_snapshot);
        $this->assertEquals(200.0, $alice->profile_snapshot['size_mb']);

        $orphans = SystemSetting::get(RoamingProfileService::ORPHANS_SETTING_KEY);
        $this->assertSame(['orphan99.V2'], $orphans['dirs']);
    }

    #[Test]
    public function it_is_fail_soft_when_scan_impossible(): void
    {
        // Snapshot précédent posé (simule la veille).
        SystemSetting::set(RoamingProfileService::ORPHANS_SETTING_KEY, [
            'dirs' => ['previous.V1'],
            'captured_at' => 'yesterday',
        ]);

        $this->bindScan(null); // scan impossible

        $exit = $this->artisan('profiles:snapshot')->run();

        $this->assertSame(1, $exit, 'Exit FAILURE attendu quand le scan échoue.');

        // Le snapshot précédent doit être CONSERVÉ (pas effacé).
        $orphans = SystemSetting::get(RoamingProfileService::ORPHANS_SETTING_KEY);
        $this->assertSame(['previous.V1'], $orphans['dirs']);
    }
}
