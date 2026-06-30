<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Exceptions\Filesystem\NetworkShareLetterCollisionException;
use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\DrivesStateProvider;
use App\Services\Filesystem\NetworkShareService;
use App\Services\Filesystem\NetworkShareValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 34.2 — Tests Unit du validateur prédictif (pure lecture, T5/AC4/AC6).
 */
class NetworkShareValidatorTest extends TestCase
{
    use RefreshDatabase;

    private NetworkShareValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        Queue::fake();
        $this->validator = app(NetworkShareValidator::class);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function assign(NetworkShare $share, string $type, int $id, string $access = 'ro'): void
    {
        NetworkShareAssignable::create([
            'network_share_id' => $share->id,
            'assignable_type' => $type,
            'assignable_id' => $id,
            'access' => $access,
        ]);
    }

    // === Source unique RESERVED_LETTERS =====================================

    #[Test]
    public function reserved_letters_mirror_the_providers_canonical_set(): void
    {
        self::assertSame(
            DrivesStateProvider::RESERVED_LETTERS,
            $this->validator->reservedLetters(),
            'Le validateur doit consommer le foyer canonique du provider (source unique).'
        );
    }

    #[Test]
    public function detects_reserved_letters_in_various_forms(): void
    {
        foreach (['K', 'k', 'K:', ' H ', 'I:', 'L', 'A:', 'd'] as $reserved) {
            self::assertTrue($this->validator->isReservedLetter($reserved), "{$reserved} doit être réservée");
        }
        foreach ([null, '', 'P:', 'm', 'Z:'] as $free) {
            self::assertFalse($this->validator->isReservedLetter($free), json_encode($free) . ' ne doit pas être réservée');
        }
    }

    // === Format directory_name (source unique NetworkShareService) ==========

    #[Test]
    public function directory_name_pattern_matches_the_service_rule(): void
    {
        $svc = app(NetworkShareService::class);
        foreach (['direction', 'echange_2024', 'a.b-c_d'] as $valid) {
            self::assertSame(1, preg_match(NetworkShareService::DIRECTORY_NAME_PATTERN, $valid));
            self::assertTrue($svc->isValidDirectoryName($valid));
        }
        foreach (['.hidden', 'with space', 'a/b', 'eve;rm', ''] as $invalid) {
            self::assertNotSame(1, preg_match(NetworkShareService::DIRECTORY_NAME_PATTERN, $invalid));
            self::assertFalse($svc->isValidDirectoryName($invalid));
        }
    }

    // === Règle (a) WG-montage-seul → warning ================================

    #[Test]
    public function wg_only_assignment_yields_a_non_blocking_warning(): void
    {
        $share = NetworkShare::factory()->create(['letter' => 'P:']);
        $wg = WorkstationGroup::create(['name' => 'salle-101', 'is_physical' => true, 'is_active' => true]);
        $this->assign($share, WorkstationGroup::class, $wg->id);

        $warnings = $this->validator->warnings($share);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('parc', strtolower($warnings[0]));
    }

    #[Test]
    public function wg_plus_user_grant_yields_no_warning(): void
    {
        $share = NetworkShare::factory()->create(['letter' => 'P:']);
        $wg = WorkstationGroup::create(['name' => 'salle-102', 'is_physical' => true, 'is_active' => true]);
        $user = User::create(['login' => 'alice', 'role' => 'prof', 'is_active' => true]);
        $this->assign($share, WorkstationGroup::class, $wg->id);
        $this->assign($share, User::class, $user->id, 'rw');

        self::assertSame([], $this->validator->warnings($share));
    }

    #[Test]
    public function user_only_assignment_yields_no_warning(): void
    {
        $share = NetworkShare::factory()->create(['letter' => 'P:']);
        $user = User::create(['login' => 'bob', 'role' => 'prof', 'is_active' => true]);
        $this->assign($share, User::class, $user->id);

        self::assertSame([], $this->validator->warnings($share));
    }

    // === Règle (b) collision de lettre → erreur =============================

    #[Test]
    public function same_explicit_letter_overlapping_audience_is_a_collision(): void
    {
        $user = User::create(['login' => 'carol', 'role' => 'prof', 'is_active' => true]);

        $a = NetworkShare::factory()->create(['name' => 'Direction', 'letter' => 'P:']);
        $b = NetworkShare::factory()->create(['name' => 'Projets', 'letter' => 'P:']);
        $this->assign($a, User::class, $user->id);
        $this->assign($b, User::class, $user->id);

        $collisions = $this->validator->letterCollisions($b);
        self::assertCount(1, $collisions);
        self::assertSame('P:', $collisions[0]['letter']);

        $this->expectException(NetworkShareLetterCollisionException::class);
        $this->validator->assertNoLetterCollision($b);
    }

    #[Test]
    public function same_letter_disjoint_audience_is_not_a_collision(): void
    {
        $u1 = User::create(['login' => 'dave', 'role' => 'prof', 'is_active' => true]);
        $u2 = User::create(['login' => 'erin', 'role' => 'prof', 'is_active' => true]);

        $a = NetworkShare::factory()->create(['letter' => 'P:']);
        $b = NetworkShare::factory()->create(['letter' => 'P:']);
        $this->assign($a, User::class, $u1->id);
        $this->assign($b, User::class, $u2->id);

        self::assertSame([], $this->validator->letterCollisions($b));
        $this->validator->assertNoLetterCollision($b); // ne lève pas
    }

    #[Test]
    public function distinct_letters_same_audience_is_not_a_collision(): void
    {
        $user = User::create(['login' => 'frank', 'role' => 'prof', 'is_active' => true]);
        $a = NetworkShare::factory()->create(['letter' => 'P:']);
        $b = NetworkShare::factory()->create(['letter' => 'Q:']);
        $this->assign($a, User::class, $user->id);
        $this->assign($b, User::class, $user->id);

        self::assertSame([], $this->validator->letterCollisions($b));
    }

    #[Test]
    public function auto_letter_never_collides(): void
    {
        $user = User::create(['login' => 'grace', 'role' => 'prof', 'is_active' => true]);
        $a = NetworkShare::factory()->create(['letter' => 'P:']);
        $b = NetworkShare::factory()->create(['letter' => null]); // auto
        $this->assign($a, User::class, $user->id);
        $this->assign($b, User::class, $user->id);

        self::assertSame([], $this->validator->letterCollisions($b));
    }

    #[Test]
    public function collision_via_shared_user_group_audience(): void
    {
        $group = UserGroup::create(['name' => 'classe-6a', 'type' => 'classe']);
        $a = NetworkShare::factory()->create(['letter' => 'R:']);
        $b = NetworkShare::factory()->create(['letter' => 'R:']);
        $this->assign($a, UserGroup::class, $group->id);
        $this->assign($b, UserGroup::class, $group->id);

        self::assertCount(1, $this->validator->letterCollisions($b));
    }

    // === suggestNextFreeLetter ==============================================

    #[Test]
    public function suggests_first_free_safe_letter_skipping_used_and_reserved(): void
    {
        self::assertSame('M:', $this->validator->suggestNextFreeLetter());
        NetworkShare::factory()->create(['letter' => 'M:']);
        NetworkShare::factory()->create(['letter' => 'N:']);
        self::assertSame('O:', $this->validator->suggestNextFreeLetter());
    }
}
