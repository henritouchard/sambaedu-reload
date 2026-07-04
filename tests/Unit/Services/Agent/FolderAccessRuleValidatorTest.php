<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Services\Agent\FolderAccessRuleValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 36.4 (AC6/D5) — validator prédictif : recouvrement de capacité
 * (littéral / map / jeton `@…`) sans faux positif sur identité différente +
 * avertissement `ad_dn` manquant.
 */
class FolderAccessRuleValidatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        UserGroupObserver::disableSync();
        DB::table('capability_projections')->delete();
        DB::table('capabilities')->delete();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function validator(): FolderAccessRuleValidator
    {
        return app(FolderAccessRuleValidator::class);
    }

    /**
     * @param  list<array<string,mixed>>  $aces
     */
    private function makeCapability(string $key, array $aces, bool $active = true): void
    {
        $cap = Capability::factory()->create(['key' => $key, 'is_active' => $active]);
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_FS_ACL,
            'spec' => ['aces' => $aces],
        ]);
    }

    #[Test]
    public function detects_a_literal_trustee_overlap(): void
    {
        $this->makeCapability('cap_lit', [
            ['path' => 'D:\\Ressources', 'ace_type' => 'deny', 'rights' => 'list_folder', 'applies_to' => 'folder_only', 'trustee' => 'Classe_3A'],
        ]);

        $overlaps = $this->validator()->capabilityOverlaps('D:\\Ressources', 'Classe_3A', 'deny');
        self::assertSame(['cap_lit'], $overlaps);
    }

    #[Test]
    public function overlap_is_case_insensitive_on_path_and_trustee(): void
    {
        $this->makeCapability('cap_ci', [
            ['path' => 'D:\\Ressources', 'ace_type' => 'deny', 'rights' => 'list_folder', 'applies_to' => 'folder_only', 'trustee' => 'Classe_3A'],
        ]);

        $overlaps = $this->validator()->capabilityOverlaps('d:\\ressources', 'classe_3a', 'DENY');
        self::assertSame(['cap_ci'], $overlaps);
    }

    #[Test]
    public function detects_a_map_value_overlap(): void
    {
        $this->makeCapability('cap_map', [
            ['path' => 'D:\\Ressources', 'ace_type' => 'deny', 'rights' => 'list_folder', 'applies_to' => 'folder_only', 'trustee' => ['on' => 'Classe_3A', 'off' => 'Classe_3A']],
        ]);

        self::assertSame(['cap_map'], $this->validator()->capabilityOverlaps('D:\\Ressources', 'Classe_3A', 'deny'));
    }

    #[Test]
    public function detects_an_audience_token_overlap_via_the_static_map(): void
    {
        // @eleves → Eleves (map statique) — pas de requête d'existence.
        $this->makeCapability('cap_tok', [
            ['path' => 'C:\\Program Files', 'ace_type' => 'deny', 'rights' => 'list_folder', 'applies_to' => 'folder_only', 'trustee' => '@eleves'],
        ]);

        self::assertSame(['cap_tok'], $this->validator()->capabilityOverlaps('C:\\Program Files', 'Eleves', 'deny'));
    }

    #[Test]
    public function no_false_positive_on_a_different_identity(): void
    {
        $this->makeCapability('cap_other', [
            ['path' => 'D:\\Ressources', 'ace_type' => 'deny', 'rights' => 'list_folder', 'applies_to' => 'folder_only', 'trustee' => 'Profs'],
        ]);

        // Trustee différent → pas de recouvrement.
        self::assertSame([], $this->validator()->capabilityOverlaps('D:\\Ressources', 'Classe_3A', 'deny'));
        // ace_type différent → pas de recouvrement.
        self::assertSame([], $this->validator()->capabilityOverlaps('D:\\Ressources', 'Profs', 'allow'));
    }

    #[Test]
    public function inactive_capability_is_ignored(): void
    {
        $this->makeCapability('cap_off', [
            ['path' => 'D:\\Ressources', 'ace_type' => 'deny', 'rights' => 'list_folder', 'applies_to' => 'folder_only', 'trustee' => 'Classe_3A'],
        ], active: false);

        self::assertSame([], $this->validator()->capabilityOverlaps('D:\\Ressources', 'Classe_3A', 'deny'));
    }

    #[Test]
    public function missing_ad_dn_is_flagged(): void
    {
        $withDn = UserGroup::factory()->create(['ad_dn' => 'CN=Classe_3A,OU=Groups']);
        $withoutDn = UserGroup::factory()->create(['ad_dn' => null]);

        self::assertFalse($this->validator()->missingAdDn($withDn->id));
        self::assertTrue($this->validator()->missingAdDn($withoutDn->id));
    }
}
