<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Services;

use App\Ipxe\Enums\EnrollNameStatus;
use App\Ipxe\Services\IpxeHostnameSanitizer;
use App\Ipxe\Services\WorkstationEnrollmentService;
use App\Ldap\AdMachineManager;
use App\Models\MachineBootLog;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.3 — AC2.1-AC2.8 / T1.7.
 *
 * Tests unitaires du service de domaine d'enrollment iPXE :
 *
 *  - Cas `enrollName()` (4 cas — CREATED, SAME_NAME, RENAMED, NAME_TAKEN).
 *  - Cas erreur (`DB_ERROR` via Workstation::create exception).
 *  - `logByodEnrollment()` (audit-only, pas de DB Workstation).
 *  - `assignRoom()` (succès + invalid_room_id).
 *  - `attachGroup()` / `detachGroup()` (succès + invalid_group_id).
 *
 * `AdMachineManager` est **mocké** (parité 16.7 — pas de samba-tool réel).
 */
class WorkstationEnrollmentServiceTest extends TestCase
{
    private WorkstationEnrollmentService $service;
    private AdMachineManager $adManager;

    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        config()->set('sambaedu.legacy_ldap.suffix', '');

        $this->adManager = Mockery::mock(AdMachineManager::class);
        $this->service = new WorkstationEnrollmentService(
            $this->adManager,
            new IpxeHostnameSanitizer(),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_enrolls_new_workstation_with_status_created(): void
    {
        $this->adManager->shouldReceive('check')->once()->with('pc-new-1')->andReturn(true);
        $this->adManager->shouldReceive('registerHardware')->once()->with('pc-new-1', Mockery::any())->andReturn(true);

        $result = $this->service->enrollName(
            rawName: 'pc-new-1',
            mac: 'aa:bb:cc:dd:ee:01',
            uuid: '11111111-1111-1111-1111-111111111111',
        );

        self::assertSame(EnrollNameStatus::Created, $result->status);
        self::assertSame('pc-new-1', $result->sanitizedName);
        self::assertNotNull($result->workstation);
        self::assertTrue($result->adResult);

        self::assertDatabaseHas('workstations', [
            'name' => 'pc-new-1',
            'uuid' => '11111111-1111-1111-1111-111111111111',
        ]);
    }

    #[Test]
    public function it_returns_same_name_when_uuid_already_owns_name(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-existing-1',
            'uuid' => '22222222-2222-2222-2222-222222222222',
            'mac' => 'aa:bb:cc:dd:ee:02',
            'status' => 'active',
        ]);

        // Pas d'appel AD attendu (idempotent).
        $this->adManager->shouldReceive('check')->never();
        $this->adManager->shouldReceive('registerHardware')->never();
        $this->adManager->shouldReceive('renameComputer')->never();

        $result = $this->service->enrollName(
            rawName: 'pc-existing-1',
            mac: 'aa:bb:cc:dd:ee:02',
            uuid: '22222222-2222-2222-2222-222222222222',
        );

        self::assertSame(EnrollNameStatus::SameName, $result->status);
        self::assertSame($ws->id, $result->workstation?->id);
    }

    #[Test]
    public function it_renames_workstation_when_uuid_known_and_new_name_unique(): void
    {
        Workstation::create([
            'name' => 'old-name',
            'uuid' => '33333333-3333-3333-3333-333333333333',
            'mac' => 'aa:bb:cc:dd:ee:03',
            'status' => 'active',
        ]);

        // Story 4.9 : le rename AD est désormais piloté par l'observer
        // + WorkstationAdSyncJob (async). Plus d'appel direct à
        // renameComputer/registerHardware depuis ce service.
        $this->adManager->shouldReceive('renameComputer')->never();
        $this->adManager->shouldReceive('registerHardware')->never();

        $result = $this->service->enrollName(
            rawName: 'new-name',
            mac: 'aa:bb:cc:dd:ee:03',
            uuid: '33333333-3333-3333-3333-333333333333',
        );

        self::assertSame(EnrollNameStatus::Renamed, $result->status);
        self::assertSame('new-name', $result->sanitizedName);
        self::assertTrue($result->adResult);

        self::assertDatabaseHas('workstations', [
            'uuid' => '33333333-3333-3333-3333-333333333333',
            'name' => 'new-name',
        ]);
    }

    #[Test]
    public function it_returns_name_taken_when_another_workstation_owns_name(): void
    {
        Workstation::create([
            'name' => 'taken-name',
            'uuid' => '55555555-5555-5555-5555-555555555555',
            'mac' => 'aa:bb:cc:dd:ee:05',
            'status' => 'active',
        ]);

        $this->adManager->shouldReceive('check')->never();
        $this->adManager->shouldReceive('registerHardware')->never();
        $this->adManager->shouldReceive('renameComputer')->never();

        $result = $this->service->enrollName(
            rawName: 'taken-name',
            mac: 'aa:bb:cc:dd:ee:99',
            uuid: '66666666-6666-6666-6666-666666666666',
        );

        self::assertSame(EnrollNameStatus::NameTaken, $result->status);
        self::assertNull($result->workstation);
        self::assertDatabaseMissing('workstations', [
            'uuid' => '66666666-6666-6666-6666-666666666666',
        ]);
    }

    #[Test]
    public function it_returns_db_error_when_hostname_contains_injection(): void
    {
        $this->adManager->shouldReceive('check')->never();
        $this->adManager->shouldReceive('registerHardware')->never();

        $result = $this->service->enrollName(
            rawName: "pc-001; rm -rf /",
            mac: 'aa:bb:cc:dd:ee:bb',
            uuid: '77777777-7777-7777-7777-777777777777',
        );

        // Le sanitize ne corrige pas l'injection (espaces, `;`) → la validation
        // regex échoue → DbError + reasonLabel 'nom invalide'.
        self::assertSame(EnrollNameStatus::DbError, $result->status);
        self::assertSame('nom invalide', $result->reasonLabel);

        // RIEN ne doit avoir été créé en DB.
        self::assertDatabaseMissing('workstations', [
            'uuid' => '77777777-7777-7777-7777-777777777777',
        ]);
    }

    #[Test]
    public function it_logs_byod_enrollment_without_creating_workstation(): void
    {
        $countBefore = Workstation::count();
        $this->adManager->shouldReceive('check')->never();
        $this->adManager->shouldReceive('registerHardware')->never();

        $this->service->logByodEnrollment(
            rawName: 'student-laptop',
            mac: 'aa:bb:cc:dd:ee:88',
            uuid: '88888888-8888-8888-8888-888888888888',
            ip: '192.168.1.42',
        );

        // Pas de création Workstation (BYOD = audit-only).
        self::assertSame($countBefore, Workstation::count());

        // Une row MachineBootLog créée avec action=ipxe_enroll_byod.
        $logRow = MachineBootLog::query()
            ->where('action', 'ipxe_enroll_byod')
            ->where('machine_name', 'byod:student-laptop')
            ->first();
        self::assertNotNull($logRow);
        self::assertNull($logRow->workstation_id);
    }

    #[Test]
    public function it_assigns_workstation_to_physical_room(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-room-1',
            'uuid' => '99999999-9999-9999-9999-999999999999',
            'mac' => 'aa:bb:cc:dd:ee:90',
            'status' => 'active',
        ]);
        $room = WorkstationGroup::create([
            'name' => 'salle-101',
            'is_physical' => true,
            'is_active' => true,
        ]);

        $ok = $this->service->assignRoom($ws, (int) $room->id);

        self::assertTrue($ok);
        self::assertDatabaseHas('workstations', [
            'id' => $ws->id,
            'physical_room_id' => $room->id,
        ]);
    }

    #[Test]
    public function it_rejects_assign_room_with_invalid_id(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-room-2',
            'uuid' => 'aaaa9999-9999-9999-9999-aaaaaaaaaaaa',
            'mac' => 'aa:bb:cc:dd:ee:91',
            'status' => 'active',
        ]);

        $ok = $this->service->assignRoom($ws, 99999);

        self::assertFalse($ok);
        self::assertDatabaseHas('workstations', [
            'id' => $ws->id,
            'physical_room_id' => null,
        ]);
    }

    #[Test]
    public function it_rejects_assign_room_when_group_is_logical_not_physical(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-room-3',
            'uuid' => 'bbbb9999-9999-9999-9999-bbbbbbbbbbbb',
            'mac' => 'aa:bb:cc:dd:ee:92',
            'status' => 'active',
        ]);
        $logical = WorkstationGroup::create([
            'name' => 'parc-logique',
            'is_physical' => false,
            'is_active' => true,
        ]);

        $ok = $this->service->assignRoom($ws, (int) $logical->id);

        self::assertFalse($ok);
    }

    #[Test]
    public function it_attaches_workstation_to_logical_parc(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-parc-1',
            'uuid' => 'cccc9999-9999-9999-9999-cccccccccccc',
            'mac' => 'aa:bb:cc:dd:ee:93',
            'status' => 'active',
        ]);
        $group = WorkstationGroup::create([
            'name' => 'parc-lab',
            'is_physical' => false,
            'is_active' => true,
        ]);

        $ok = $this->service->attachGroup($ws, (int) $group->id);

        self::assertTrue($ok);
        self::assertDatabaseHas('workstation_group_workstation', [
            'workstation_id' => $ws->id,
            'workstation_group_id' => $group->id,
        ]);
    }

    #[Test]
    public function it_rejects_attach_group_when_group_is_physical(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-parc-2',
            'uuid' => 'dddd9999-9999-9999-9999-dddddddddddd',
            'mac' => 'aa:bb:cc:dd:ee:94',
            'status' => 'active',
        ]);
        $physical = WorkstationGroup::create([
            'name' => 'salle-physique',
            'is_physical' => true,
            'is_active' => true,
        ]);

        $ok = $this->service->attachGroup($ws, (int) $physical->id);

        self::assertFalse($ok);
        self::assertDatabaseMissing('workstation_group_workstation', [
            'workstation_id' => $ws->id,
            'workstation_group_id' => $physical->id,
        ]);
    }

    #[Test]
    public function it_detaches_workstation_from_logical_parc(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-parc-3',
            'uuid' => 'eeee9999-9999-9999-9999-eeeeeeeeeeee',
            'mac' => 'aa:bb:cc:dd:ee:95',
            'status' => 'active',
        ]);
        $group = WorkstationGroup::create([
            'name' => 'parc-detach',
            'is_physical' => false,
            'is_active' => true,
        ]);
        $ws->groups()->attach($group->id);

        $ok = $this->service->detachGroup($ws, (int) $group->id);

        self::assertTrue($ok);
        self::assertDatabaseMissing('workstation_group_workstation', [
            'workstation_id' => $ws->id,
            'workstation_group_id' => $group->id,
        ]);
    }

    #[Test]
    public function it_returns_false_on_detach_with_invalid_group_id(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-parc-4',
            'uuid' => 'ffff9999-9999-9999-9999-ffffffffffff',
            'mac' => 'aa:bb:cc:dd:ee:96',
            'status' => 'active',
        ]);

        $ok = $this->service->detachGroup($ws, 88888);
        self::assertFalse($ok);
    }
}
