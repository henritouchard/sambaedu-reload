<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Services;

use App\Ipxe\Services\IpxeEnrollmentMenuBuilder;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.3 — AC4.1 / T2.4.
 *
 * Tests unitaires du builder qui prépare les variables Blade des 5 menus
 * d'enrollment.
 */
class IpxeEnrollmentMenuBuilderTest extends TestCase
{
    private IpxeEnrollmentMenuBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        $this->builder = new IpxeEnrollmentMenuBuilder();
    }

    #[Test]
    public function it_builds_name_menu_variables_for_unknown_workstation(): void
    {
        $vars = $this->builder->buildNameMenuVariables(
            null,
            'aa:bb:cc:dd:ee:01',
            '11111111-1111-1111-1111-111111111111',
            'legacy',
            '192.168.1.10',
            'http://se4fs.lan',
        );

        self::assertSame('aa:bb:cc:dd:ee:01', $vars['mac']);
        self::assertSame('11111111-1111-1111-1111-111111111111', $vars['uuid']);
        self::assertSame('legacy', $vars['platform']);
        self::assertSame('192.168.1.10', $vars['ip']);
        self::assertSame('', $vars['currentName']);
        self::assertSame('http://se4fs.lan', $vars['serverBaseUrl']);
        self::assertSame(10000, $vars['menuTimeoutMs']);
    }

    #[Test]
    public function it_builds_name_menu_variables_for_known_workstation(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-known-1',
            'uuid' => '22222222-2222-2222-2222-222222222222',
            'mac' => 'aa:bb:cc:dd:ee:02',
            'status' => 'active',
        ]);

        $vars = $this->builder->buildNameMenuVariables(
            $ws,
            'aa:bb:cc:dd:ee:02',
            '22222222-2222-2222-2222-222222222222',
            'legacy',
            '192.168.1.11',
            'http://se4fs.lan/',
        );

        self::assertSame('pc-known-1', $vars['currentName']);
        // rtrim '/' appliqué.
        self::assertSame('http://se4fs.lan', $vars['serverBaseUrl']);
    }

    #[Test]
    public function it_builds_room_menu_variables_with_active_physical_groups_only(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-r-1',
            'uuid' => '33333333-3333-3333-3333-333333333333',
            'mac' => 'aa:bb:cc:dd:ee:03',
            'status' => 'active',
        ]);
        $physical = WorkstationGroup::create([
            'name' => 'salle-101',
            'is_physical' => true,
            'is_active' => true,
        ]);
        $logical = WorkstationGroup::create([
            'name' => 'parc-x',
            'is_physical' => false,
            'is_active' => true,
        ]);
        $archived = WorkstationGroup::create([
            'name' => 'salle-archivee',
            'is_physical' => true,
            'is_active' => true,
            'archived_at' => now(),
        ]);

        $vars = $this->builder->buildRoomMenuVariables($ws, 'http://se4fs.lan');

        $names = array_column($vars['availableRooms'], 'name');
        self::assertContains('salle-101', $names);
        self::assertNotContains('parc-x', $names);
        self::assertNotContains('salle-archivee', $names);
    }

    #[Test]
    public function it_builds_room_menu_variables_with_current_room_metadata(): void
    {
        $room = WorkstationGroup::create([
            'name' => 'salle-actuelle',
            'is_physical' => true,
            'is_active' => true,
        ]);
        $ws = Workstation::create([
            'name' => 'pc-current-room',
            'uuid' => '44444444-4444-4444-4444-444444444444',
            'mac' => 'aa:bb:cc:dd:ee:04',
            'status' => 'active',
            'physical_room_id' => $room->id,
        ]);

        $vars = $this->builder->buildRoomMenuVariables($ws, 'http://se4fs.lan');

        self::assertNotNull($vars['currentRoom']);
        self::assertSame('salle-actuelle', $vars['currentRoom']['name']);
        // is_current flag positionné dans availableRooms.
        $entries = array_filter($vars['availableRooms'], fn ($r) => $r['name'] === 'salle-actuelle');
        self::assertCount(1, $entries);
        self::assertTrue(array_values($entries)[0]['is_current']);
    }

    #[Test]
    public function it_builds_room_menu_variables_caps_at_max_rooms_in_menu_config(): void
    {
        config()->set('ipxe.enrollment.max_rooms_in_menu', 3);
        $ws = Workstation::create([
            'name' => 'pc-r-cap',
            'uuid' => '55555555-5555-5555-5555-555555555555',
            'mac' => 'aa:bb:cc:dd:ee:05',
            'status' => 'active',
        ]);
        for ($i = 1; $i <= 5; $i++) {
            WorkstationGroup::create([
                'name' => "salle-cap-$i",
                'is_physical' => true,
                'is_active' => true,
            ]);
        }

        $vars = $this->builder->buildRoomMenuVariables($ws, 'http://se4fs.lan');

        self::assertCount(3, $vars['availableRooms']);
        self::assertTrue($vars['truncated']);
    }

    #[Test]
    public function it_builds_parc_add_menu_variables_excludes_already_attached(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-parc-a',
            'uuid' => '66666666-6666-6666-6666-666666666666',
            'mac' => 'aa:bb:cc:dd:ee:06',
            'status' => 'active',
        ]);
        $available = WorkstationGroup::create([
            'name' => 'parc-libre',
            'is_physical' => false,
            'is_active' => true,
        ]);
        $attached = WorkstationGroup::create([
            'name' => 'parc-deja',
            'is_physical' => false,
            'is_active' => true,
        ]);
        $ws->groups()->attach($attached->id);

        $vars = $this->builder->buildParcAddMenuVariables($ws->fresh(['groups']), 'http://se4fs.lan');

        $names = array_column($vars['availableParcs'], 'name');
        self::assertContains('parc-libre', $names);
        self::assertNotContains('parc-deja', $names);
    }

    #[Test]
    public function it_builds_parc_remove_menu_variables_lists_only_currently_attached(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-parc-r',
            'uuid' => '77777777-7777-7777-7777-777777777777',
            'mac' => 'aa:bb:cc:dd:ee:07',
            'status' => 'active',
        ]);
        $attached = WorkstationGroup::create([
            'name' => 'parc-attache',
            'is_physical' => false,
            'is_active' => true,
        ]);
        WorkstationGroup::create([
            'name' => 'parc-libre',
            'is_physical' => false,
            'is_active' => true,
        ]);
        $ws->groups()->attach($attached->id);

        $vars = $this->builder->buildParcRemoveMenuVariables($ws->fresh(['groups']), 'http://se4fs.lan');

        $names = array_column($vars['currentParcs'], 'name');
        self::assertSame(['parc-attache'], $names);
    }

    #[Test]
    public function it_sanitizes_ascii_in_room_names_via_question_mark(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-ascii',
            'uuid' => '88888888-8888-8888-8888-888888888888',
            'mac' => 'aa:bb:cc:dd:ee:08',
            'status' => 'active',
        ]);
        // Nom avec accent (volontaire — vérifie qu'il est sanitizé).
        WorkstationGroup::create([
            'name' => 'sallé-éé',
            'is_physical' => true,
            'is_active' => true,
        ]);

        $vars = $this->builder->buildRoomMenuVariables($ws, 'http://se4fs.lan');

        $names = array_column($vars['availableRooms'], 'name');
        // Les chars hors 0x20-0x7E sont remplacés par `?`.
        self::assertSame(['sall?-??'], $names);
    }
}
