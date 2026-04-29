<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Facades\SEConfig;
use App\Models\QuotaRule;
use App\Models\User;
use App\Services\Filesystem\XfsQuotaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 5.1d — Tests unit de la priorité TYPE_DEFAULT_ITINERANT
 * dans XfsQuotaService::getEffectiveQuota.
 *
 * Couvre AC 1-4 :
 *   1. user externe sans règle USER/GROUP + règle DEFAULT_ITINERANT → reçoit l'itinérant
 *   2. user externe sans aucune règle itinérante → fallback sur default profil
 *   3. user interne → ignore DEFAULT_ITINERANT (utilise default profil)
 *   4. règle USER prime sur DEFAULT_ITINERANT (sécurité)
 *   5. règle GROUP prime sur DEFAULT_ITINERANT (sécurité)
 *   + bonus : login inconnu (User::where(...)->first() = null) → fallback profil
 *   + bonus : signature publique préservée (D5=A : pas de paramètre supplémentaire)
 */
class XfsQuotaServiceItinerantTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTables();
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('users');
            Schema::dropIfExists('quota_rules');
        }
        parent::tearDown();
    }

    private function ensureTables(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('login')->unique();
                $table->string('school_code')->nullable();
                $table->string('role')->default('eleve');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('quota_rules')) {
            Schema::create('quota_rules', function (Blueprint $table) {
                $table->id();
                $table->string('type', 30);
                $table->string('target')->nullable();
                $table->string('partition');
                $table->integer('quota_soft_mb')->default(0);
                $table->integer('quota_hard_mb')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
            $this->createdTables = true;
        }
    }

    private function makeService(): XfsQuotaService
    {
        return new XfsQuotaService();
    }

    private function createUser(string $login, ?string $schoolCode): User
    {
        return User::query()->create([
            'login' => $login,
            'school_code' => $schoolCode,
            'role' => 'eleve',
            'is_active' => true,
        ]);
    }

    private function createDefaultRule(string $type, int $soft, int $hard, string $partition = QuotaRule::PARTITION_HOME): QuotaRule
    {
        return QuotaRule::query()->create([
            'type' => $type,
            'target' => null,
            'partition' => $partition,
            'quota_soft_mb' => $soft,
            'quota_hard_mb' => $hard,
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // AC 1 — User externe sans règle → reçoit DEFAULT_ITINERANT
    // =========================================================================

    #[Test]
    public function it_applies_default_itinerant_when_user_is_external(): void
    {
        SEConfig::shouldReceive('getCurrentEstablishmentCode')->andReturn('0991229y');

        $this->createUser('alice', '0770001a'); // school_code différent → external
        $this->createDefaultRule(QuotaRule::TYPE_DEFAULT_ITINERANT, 200, 240);
        $this->createDefaultRule(QuotaRule::TYPE_DEFAULT_ELEVE, 500, 600);

        $result = $this->makeService()->getEffectiveQuota(
            'alice',
            QuotaRule::PARTITION_HOME,
            [],
            'eleve',
        );

        $this->assertSame('default', $result['source']);
        $this->assertSame('Défaut itinérants', $result['source_name']);
        $this->assertSame(200, $result['quota_soft_mb']);
        $this->assertSame(240, $result['quota_hard_mb']);
        $this->assertFalse($result['is_unlimited']);
    }

    // =========================================================================
    // AC 2 — Fallback profil si DEFAULT_ITINERANT absente
    // =========================================================================

    #[Test]
    public function it_falls_back_to_default_profile_when_itinerant_rule_missing(): void
    {
        SEConfig::shouldReceive('getCurrentEstablishmentCode')->andReturn('0991229y');

        $this->createUser('alice', '0770001a'); // external mais pas d'itinérant
        $this->createDefaultRule(QuotaRule::TYPE_DEFAULT_ELEVE, 500, 600);

        $result = $this->makeService()->getEffectiveQuota(
            'alice',
            QuotaRule::PARTITION_HOME,
            [],
            'eleve',
        );

        $this->assertSame('default', $result['source']);
        $this->assertSame('Défaut élèves', $result['source_name']);
        $this->assertSame(500, $result['quota_soft_mb']);
        $this->assertSame(600, $result['quota_hard_mb']);
    }

    // =========================================================================
    // AC 3 — User interne ignore DEFAULT_ITINERANT
    // =========================================================================

    #[Test]
    public function it_ignores_default_itinerant_for_internal_user(): void
    {
        SEConfig::shouldReceive('getCurrentEstablishmentCode')->andReturn('0991229y');

        $this->createUser('alice', '0991229y'); // school_code IDENTIQUE → internal
        $this->createDefaultRule(QuotaRule::TYPE_DEFAULT_ITINERANT, 200, 240);
        $this->createDefaultRule(QuotaRule::TYPE_DEFAULT_ELEVE, 500, 600);

        $result = $this->makeService()->getEffectiveQuota(
            'alice',
            QuotaRule::PARTITION_HOME,
            [],
            'eleve',
        );

        $this->assertSame('default', $result['source']);
        $this->assertSame('Défaut élèves', $result['source_name']);
        $this->assertSame(500, $result['quota_soft_mb']);
        $this->assertSame(600, $result['quota_hard_mb']);
    }

    // =========================================================================
    // AC 4 — Régression sécurité : USER prime sur DEFAULT_ITINERANT
    // =========================================================================

    #[Test]
    public function it_prioritizes_user_rule_over_default_itinerant_for_external_user(): void
    {
        SEConfig::shouldReceive('getCurrentEstablishmentCode')->andReturn('0991229y');

        $this->createUser('alice', '0770001a'); // external
        // Règle user explicite : 1000/1200
        QuotaRule::query()->create([
            'type' => QuotaRule::TYPE_USER,
            'target' => 'alice',
            'partition' => QuotaRule::PARTITION_HOME,
            'quota_soft_mb' => 1000,
            'quota_hard_mb' => 1200,
            'is_active' => true,
        ]);
        $this->createDefaultRule(QuotaRule::TYPE_DEFAULT_ITINERANT, 200, 240);

        $result = $this->makeService()->getEffectiveQuota(
            'alice',
            QuotaRule::PARTITION_HOME,
            [],
            'eleve',
        );

        $this->assertSame('user', $result['source']);
        $this->assertSame('alice', $result['source_name']);
        $this->assertSame(1000, $result['quota_soft_mb']);
        $this->assertSame(1200, $result['quota_hard_mb']);
    }

    // =========================================================================
    // AC 5 — Régression sécurité : GROUP prime sur DEFAULT_ITINERANT
    // =========================================================================

    #[Test]
    public function it_prioritizes_group_rule_over_default_itinerant_for_external_user(): void
    {
        SEConfig::shouldReceive('getCurrentEstablishmentCode')->andReturn('0991229y');

        $this->createUser('alice', '0770001a'); // external
        QuotaRule::query()->create([
            'type' => QuotaRule::TYPE_GROUP,
            'target' => 'profs',
            'partition' => QuotaRule::PARTITION_HOME,
            'quota_soft_mb' => 1500,
            'quota_hard_mb' => 1800,
            'is_active' => true,
        ]);
        $this->createDefaultRule(QuotaRule::TYPE_DEFAULT_ITINERANT, 200, 240);

        $result = $this->makeService()->getEffectiveQuota(
            'alice',
            QuotaRule::PARTITION_HOME,
            ['profs'],
            'eleve',
        );

        $this->assertSame('group', $result['source']);
        $this->assertSame('profs', $result['source_name']);
        $this->assertSame(1500, $result['quota_soft_mb']);
        $this->assertSame(1800, $result['quota_hard_mb']);
    }

    // =========================================================================
    // Bonus — login inconnu → fallback profil silencieux
    // =========================================================================

    #[Test]
    public function it_falls_back_to_profile_when_username_not_found_in_users(): void
    {
        SEConfig::shouldReceive('getCurrentEstablishmentCode')->andReturn('0991229y');

        // Pas d'user créé → User::where('login', 'ghost')->first() = null
        $this->createDefaultRule(QuotaRule::TYPE_DEFAULT_ITINERANT, 200, 240);
        $this->createDefaultRule(QuotaRule::TYPE_DEFAULT_ELEVE, 500, 600);

        $result = $this->makeService()->getEffectiveQuota(
            'ghost',
            QuotaRule::PARTITION_HOME,
            [],
            'eleve',
        );

        $this->assertSame('default', $result['source']);
        $this->assertSame('Défaut élèves', $result['source_name']);
        $this->assertSame(500, $result['quota_soft_mb']);
    }

    // =========================================================================
    // Bonus — Signature publique préservée (D5=A)
    // =========================================================================

    #[Test]
    public function it_preserves_public_signature_for_get_effective_quota(): void
    {
        $reflection = new \ReflectionMethod(XfsQuotaService::class, 'getEffectiveQuota');
        $params = $reflection->getParameters();

        $this->assertCount(4, $params, 'getEffectiveQuota doit conserver exactement 4 paramètres (D5=A).');
        $this->assertSame('username', $params[0]->getName());
        $this->assertSame('partition', $params[1]->getName());
        $this->assertSame('userGroups', $params[2]->getName());
        $this->assertSame('userProfile', $params[3]->getName());
        $this->assertTrue($params[2]->isOptional());
        $this->assertTrue($params[3]->isOptional());
    }
}
