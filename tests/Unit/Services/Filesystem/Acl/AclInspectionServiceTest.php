<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Acl;

use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Services\Filesystem\Acl\AclFormat;
use App\Services\Filesystem\Acl\AclInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Epic 34 — Tests du classifieur {@see AclInspectionService}.
 *
 * Le point le plus délicat couvert ici : le mapping INVERSE nom Unix disque →
 * UserGroup via forward-projection, y compris avec suffixe établissement fédéré
 * (`classe_6b-1229y`).
 */
class AclInspectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private AclInspectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        UserGroupObserver::disableSync();
        $this->service = app(AclInspectionService::class);
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    #[Test]
    public function validate_inspect_path_rejects_traversal_and_out_of_root(): void
    {
        self::assertTrue($this->service->validateInspectPath('/var/sambaedu/Classes/Classe_6A'));
        self::assertFalse($this->service->validateInspectPath('/etc/passwd'));
        self::assertFalse($this->service->validateInspectPath('/var/sambaedu/../etc'));
        self::assertFalse($this->service->validateInspectPath('/var/sambaedu/a b')); // espace
    }

    #[Test]
    public function classifies_user_and_group_entries_as_mappable(): void
    {
        $alice = User::factory()->create(['login' => 'alice']);
        $classe = UserGroup::factory()->create(['name' => '6a', 'type' => 'classe', 'ad_dn' => null]);

        $entries = AclFormat::parseEntries(<<<TXT
        user::rwx
        user:alice:rwx
        group:classe_6a:r-x
        group:domain\\040admins:rwx
        mask::rwx
        other::---
        default:user:alice:rwx
        TXT);

        $result = $this->service->classify($entries);

        // alice (rw) + classe_6a (ro) mappables ; l'entrée default:user:alice ignorée.
        self::assertCount(2, $result['mappable']);

        $byLabel = collect($result['mappable'])->keyBy('label');
        self::assertSame(User::class, $byLabel['alice']['target_type']);
        self::assertSame($alice->id, $byLabel['alice']['target_id']);
        self::assertSame('rw', $byLabel['alice']['access']);

        $groupLabel = $classe->display_name ?: $classe->name;
        self::assertSame(UserGroup::class, $byLabel[$groupLabel]['target_type']);
        self::assertSame($classe->id, $byLabel[$groupLabel]['target_id']);
        self::assertSame('ro', $byLabel[$groupLabel]['access']);

        // user::, group::owner, other::, mask::, domain admins → structurels.
        self::assertNotEmpty($result['structural']);
        self::assertEmpty($result['unmappable']);
    }

    #[Test]
    public function reverse_maps_group_with_federated_establishment_suffix(): void
    {
        // Groupe fédéré : le nom Unix réel porte le suffixe -1229y (dérivé de l'OU UAI).
        $group = UserGroup::factory()->create([
            'name' => '6b',
            'type' => 'classe',
            'ad_dn' => 'CN=6b,OU=Groups,OU=0991229y,DC=etab,DC=lan',
        ]);

        $entries = AclFormat::parseEntries("group:classe_6b-1229y:rwx\n");
        $result = $this->service->classify($entries);

        self::assertCount(1, $result['mappable']);
        self::assertSame($group->id, $result['mappable'][0]['target_id']);
        self::assertSame('rw', $result['mappable'][0]['access']);
    }

    #[Test]
    public function reports_unmappable_entries_with_reasons_never_guesses(): void
    {
        User::factory()->create(['login' => 'alice']);

        $entries = AclFormat::parseEntries(<<<TXT
        user:ghost:rwx
        group:inconnu_x:rwx
        user:alice:--x
        other::rwx
        TXT);

        $result = $this->service->classify($entries);

        $reasons = collect($result['unmappable'])->pluck('reason')->implode(' | ');
        self::assertStringContainsString('ghost', $reasons);          // user introuvable
        self::assertStringContainsString('inconnu_x', $reasons);      // groupe non rattaché
        self::assertStringContainsString('non représentable', $reasons); // alice --x (exécution seule)

        // other::rwx est STRUCTUREL mais annoté (accès non représentable), pas mappé.
        $otherNote = collect($result['structural'])->firstWhere('raw', 'other::rwx');
        self::assertNotNull($otherNote);
        self::assertStringContainsString('other', $otherNote['note']);

        // Aucune de ces 4 entrées ne devient une assignation.
        self::assertEmpty($result['mappable']);
    }

    #[Test]
    public function inspect_reads_via_getfacl_and_classifies(): void
    {
        User::factory()->create(['login' => 'bob']);

        Process::fake([
            'sudo getfacl *' => Process::result(output: "user::rwx\nuser:bob:rwx\nother::---\n", exitCode: 0),
        ]);

        $result = $this->service->inspect('/var/sambaedu/Partages/projet');

        self::assertNotNull($result);
        self::assertCount(1, $result['mappable']);
        self::assertSame('bob', $result['mappable'][0]['label']);
    }

    #[Test]
    public function inspect_returns_null_when_getfacl_fails(): void
    {
        Process::fake([
            'sudo getfacl *' => Process::result(output: '', errorOutput: 'no such file', exitCode: 1),
        ]);

        self::assertNull($this->service->inspect('/var/sambaedu/Partages/absent'));
    }
}
