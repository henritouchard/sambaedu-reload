<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\RoamingProfileService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 26.3 — Tests unitaires du volet nettoyage natif de RoamingProfileService.
 *
 * Couvre AC #1/#2/#3/#5/#6 :
 *   - scan mocké (du fake) → map dir=>bytes ;
 *   - extraction login depuis dir versionné (alice.V6 → alice) ;
 *   - détection orphelins (user présent = non-orphelin ; absent = orphelin),
 *     résolution Postgres-only LOWER(login), NFR7 ;
 *   - persistance snapshot (colonne profile_snapshot + SystemSetting orphans) ;
 *   - lecteurs cache (taille par login, count orphelins) — aucun FS ;
 *   - garde de sécurité de la purge (refus `..`, refus nom avec `/`, refus si
 *     user existe / re-vérification).
 *
 * Le FS (`du`) est mocké via Process::fake — aucun /home/profiles réel requis.
 * Les suppressions ne sont jamais exécutées sur un vrai FS dans ces tests :
 * la purge est testée au niveau des GARDES (rejets) qui interviennent AVANT
 * tout déplacement.
 */
class RoamingProfileCleanupTest extends TestCase
{
    use DatabaseTransactions;

    private RoamingProfileService $service;
    private bool $createdUsers = false;
    private bool $createdSettings = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
        $this->service = new RoamingProfileService();
    }

    protected function tearDown(): void
    {
        // Ne drop QUE les tables créées par ce test (flag par table) pour ne
        // jamais détruire une table pré-existante d'une autre suite.
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
                $table->json('quota_snapshot')->nullable();
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

    // --------------------------------------------------------------- login extraction

    #[Test]
    public function it_extracts_login_from_versioned_profile_dir(): void
    {
        $this->assertSame('alice', $this->service->loginFromProfileDir('alice.V6'));
        $this->assertSame('bob', $this->service->loginFromProfileDir('bob.V2.bak'));
        $this->assertSame('carol', $this->service->loginFromProfileDir('carol'));
    }

    // --------------------------------------------------------------- scan (du fake)

    #[Test]
    public function it_parses_du_output_and_filters_root_line(): void
    {
        $output = "1500000000\t/home/profiles\n"
            . "1000000\t/home/profiles/alice.V6\n"
            . "2000000\t/home/profiles/orphan99.V2\n";

        $sizes = $this->service->parseDuOutput($output, '/home/profiles');

        // La ligne du dossier racine lui-même doit être filtrée.
        $this->assertArrayNotHasKey('profiles', $sizes);
        $this->assertSame(1000000, $sizes['alice.V6']);
        $this->assertSame(2000000, $sizes['orphan99.V2']);
    }

    /**
     * AC #1 (review 26.3 #1) : `du` fusionne stderr (`2>&1`) et sort en code ≠ 0
     * dès qu'UN sous-dossier est illisible, tout en imprimant des tailles VALIDES
     * pour les autres. Les lignes d'erreur `du: …` n'ont pas de tabulation et
     * DOIVENT être ignorées → le snapshot partiel reste exploitable (sinon la
     * commande échouerait en permanence sur un /home/profiles réel).
     */
    #[Test]
    public function it_ignores_du_error_lines_and_keeps_valid_entries(): void
    {
        $output = "du: cannot read directory '/home/profiles/locked.V2': Permission denied\n"
            . "1500000000\t/home/profiles\n"
            . "1000000\t/home/profiles/alice.V6\n"
            . "du: cannot access '/home/profiles/ghost.V1': No such file or directory\n"
            . "2000000\t/home/profiles/bob.V2\n";

        $sizes = $this->service->parseDuOutput($output, '/home/profiles');

        $this->assertSame(['alice.V6' => 1000000, 'bob.V2' => 2000000], $sizes);
    }

    #[Test]
    public function it_returns_null_when_profiles_root_absent(): void
    {
        // /home/profiles n'existe pas sur l'hôte CI → fail-soft null.
        $this->assertNull($this->service->scanProfileSizes());
    }

    // --------------------------------------------------------------- orphans

    #[Test]
    public function it_detects_orphan_when_user_absent_and_keeps_existing_user(): void
    {
        User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);

        $orphans = $this->service->detectOrphans(['alice.V6', 'orphan99.V2', 'ghost']);

        $this->assertContains('orphan99.V2', $orphans);
        $this->assertContains('ghost', $orphans);
        $this->assertNotContains('alice.V6', $orphans, 'Un dossier dont le user existe ne doit JAMAIS être orphelin.');
    }

    #[Test]
    public function it_resolves_orphans_case_insensitively(): void
    {
        User::query()->create(['login' => 'Alice', 'role' => 'eleve', 'is_active' => true]);

        // Dossier en minuscules, compte en CamelCase → non-orphelin (LOWER).
        $orphans = $this->service->detectOrphans(['alice.V6']);
        $this->assertSame([], $orphans);
    }

    // --------------------------------------------------------------- persist + readers

    #[Test]
    public function it_persists_snapshot_to_column_and_settings(): void
    {
        User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);

        $result = $this->service->persistSnapshot([
            'alice.V6' => 209715200, // 200 Mo
            'orphan99.V2' => 5000000,
        ]);

        $this->assertSame(1, $result['users_updated']);
        $this->assertSame(1, $result['orphans']);

        $alice = User::query()->where('login', 'alice')->first();
        $this->assertNotNull($alice->profile_snapshot);
        $this->assertEquals(200.0, $alice->profile_snapshot['size_mb']);

        // Lecteur cache — aucun FS, cast float.
        $this->assertSame(200.0, $this->service->getProfileSizeForLogin('alice'));
        $this->assertNull($this->service->getProfileSizeForLogin('inconnu'));

        $this->assertSame(1, $this->service->getOrphanCount());
        $this->assertSame(['orphan99.V2'], $this->service->getOrphanProfiles());

        // La liste est bien dans SystemSetting (persistance survit au flush cache).
        $stored = SystemSetting::get(RoamingProfileService::ORPHANS_SETTING_KEY);
        $this->assertSame(['orphan99.V2'], $stored['dirs']);
    }

    #[Test]
    public function it_cumulates_multiple_versions_per_login(): void
    {
        User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);

        $this->service->persistSnapshot([
            'alice.V6' => 100 * 1024 * 1024,
            'alice.V2' => 50 * 1024 * 1024,
        ]);

        $this->assertSame(150.0, $this->service->getProfileSizeForLogin('alice'));
    }

    // --------------------------------------------------------------- purge guards

    #[Test]
    public function it_skips_purge_for_path_traversal_and_slash_names(): void
    {
        // Cache contient des entrées malveillantes/non-safe.
        SystemSetting::set(RoamingProfileService::ORPHANS_SETTING_KEY, [
            'dirs' => ['../../etc', 'foo/bar', 'evil;rm'],
            'captured_at' => 'x',
        ]);

        $result = $this->service->purgeOrphanProfiles();

        // Aucune entrée ne doit avoir été déplacée (toutes rejetées par les gardes).
        $this->assertSame(0, $result['moved']);
        $this->assertSame(0, $result['errors']);
        $this->assertGreaterThanOrEqual(1, $result['skipped']);
    }

    /**
     * AC #5 (review S1) : si `rename()` échoue (corbeille sur un autre
     * filesystem que /home/profiles — `EXDEV`, config prod fréquente), le
     * déplacement bascule sur `mv` (cross-device). On le vérifie via la méthode
     * `moveToTrash` exposée : source inexistante → rename false → repli `mv`.
     */
    #[Test]
    public function it_falls_back_to_mv_when_rename_fails_cross_device(): void
    {
        Process::fake(); // mv "réussit" (faux process), n'exécute rien.

        $service = new class () extends RoamingProfileService {
            public function exposeMoveToTrash(string $s, string $d): bool
            {
                return $this->moveToTrash($s, $d);
            }
        };

        // Source inexistante → rename() renvoie false → repli mv déclenché.
        $ok = $service->exposeMoveToTrash('/home/profiles/ghost.V1', '/home/admin/_Trash_users/ghost.V1.x');

        // is_dir(source) = false (inexistante) + mv fake successful → true.
        $this->assertTrue($ok);
        Process::assertRan(fn ($process) => str_contains($process->command, 'mv -f'));
    }

    #[Test]
    public function it_never_purges_a_dir_whose_user_exists(): void
    {
        // Compte recréé entre le snapshot et la purge : le cache liste alice.V6
        // comme orphelin (datant), mais le compte existe → ne JAMAIS supprimer.
        User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);

        SystemSetting::set(RoamingProfileService::ORPHANS_SETTING_KEY, [
            'dirs' => ['alice.V6'],
            'captured_at' => 'stale',
        ]);

        $result = $this->service->purgeOrphanProfiles();

        $this->assertSame(0, $result['moved'], 'Un compte réapparu ne doit jamais perdre son profil.');
        $this->assertSame(1, $result['skipped']);
    }
}
