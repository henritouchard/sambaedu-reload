<?php

declare(strict_types=1);

namespace Tests\Feature\Observers;

use App\Jobs\AdSync\WorkstationAdSyncJob;
use App\Models\Workstation;
use App\Observers\WorkstationObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 4.9 — Tests d'observation (AC10).
 *
 * Vérifie que les hooks Eloquent created/updated/deleting du
 * {@see WorkstationObserver} dispatchent bien le bon
 * {@see WorkstationAdSyncJob} selon l'action.
 *
 * Le job lui-même n'est pas exécuté (Bus::fake) — c'est l'orchestration
 * observer → dispatch qui est testée ici.
 */
class WorkstationObserverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();

        // Le bootstrapper appelle Model::unsetEventDispatcher() pour éviter
        // que les observers tapent LDAP en environnement offline. Pour TES­TER
        // l'observer Workstation, on doit ré-attacher le dispatcher Laravel
        // et ré-enregistrer l'observer (équivalent à AppServiceProvider::boot
        // qui n'a aucun effet une fois le dispatcher unset).
        Model::setEventDispatcher(Event::getFacadeRoot());
        Workstation::observe(WorkstationObserver::class);

        // S'assurer que la sync est ON pour les tests (peut être laissée OFF
        // par un test précédent qui n'a pas restauré le flag).
        WorkstationObserver::$syncEnabled = true;

        // Bus::fake() AVANT toute opération Eloquent — sinon les
        // dispatch() de l'observer dans les setUp helpers tapent la queue
        // sync (LDAP réel indisponible en env test).
        Bus::fake([WorkstationAdSyncJob::class]);
    }

    protected function tearDown(): void
    {
        // Restaurer l'état mute pour ne pas polluer les tests suivants
        // de la suite (cohérent avec IpxeSchemaBootstrapper::bootstrap()).
        Model::unsetEventDispatcher();
        parent::tearDown();
    }

    private function makeWorkstation(string $name = 'PC-OBS-1'): Workstation
    {
        return Workstation::create([
            'name' => $name,
            'uuid' => 'aaaaaaaa-1111-2222-3333-' . substr(md5($name), 0, 12),
            'mac' => 'aa:bb:cc:dd:ee:01',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function creating_workstation_dispatches_create_job(): void
    {
        $ws = $this->makeWorkstation('PC-CREATE-1');

        Bus::assertDispatched(WorkstationAdSyncJob::class, function ($job) use ($ws) {
            return $job->action === WorkstationAdSyncJob::ACTION_CREATE
                && (int) $job->workstationId === (int) $ws->id;
        });
    }

    #[Test]
    public function renaming_workstation_dispatches_rename_job_with_old_and_new_names(): void
    {
        $ws = $this->makeWorkstation('PC-OLD-1');

        $ws->name = 'PC-NEW-1';
        $ws->save();

        Bus::assertDispatched(WorkstationAdSyncJob::class, function ($job) use ($ws) {
            return $job->action === WorkstationAdSyncJob::ACTION_RENAME
                && (int) $job->workstationId === (int) $ws->id
                && ($job->params['old_name'] ?? null) === 'PC-OLD-1'
                && ($job->params['new_name'] ?? null) === 'PC-NEW-1';
        });
    }

    #[Test]
    public function changing_status_dispatches_status_job(): void
    {
        $ws = $this->makeWorkstation('PC-STATUS-1');

        $ws->status = 'inactive';
        $ws->save();

        Bus::assertDispatched(WorkstationAdSyncJob::class, function ($job) use ($ws) {
            return $job->action === WorkstationAdSyncJob::ACTION_STATUS
                && (int) $job->workstationId === (int) $ws->id
                && ($job->params['status'] ?? null) === 'inactive';
        });
    }

    #[Test]
    public function changing_name_and_status_dispatches_single_update_job(): void
    {
        // Décision design #3 (review 4.9) : si name ET status changent dans
        // le même `save()`, l'observer doit dispatcher UN SEUL job `update`
        // (pas un rename + un status).
        $ws = $this->makeWorkstation('PC-FUSION-1');

        // Reset les dispatches précédents (le `created` a déjà émis 1 job
        // CREATE).
        Bus::fake([WorkstationAdSyncJob::class]);

        $ws->name = 'PC-FUSION-2';
        $ws->status = 'inactive';
        $ws->save();

        // Exactement 1 dispatch sur la classe (pour ce save).
        Bus::assertDispatched(WorkstationAdSyncJob::class, 1);

        Bus::assertDispatched(WorkstationAdSyncJob::class, function ($job) use ($ws) {
            return $job->action === WorkstationAdSyncJob::ACTION_UPDATE
                && (int) $job->workstationId === (int) $ws->id
                && ($job->params['old_name'] ?? null) === 'PC-FUSION-1'
                && ($job->params['new_name'] ?? null) === 'PC-FUSION-2'
                && ($job->params['status'] ?? null) === 'inactive';
        });

        // Pas de rename ni status indépendants dispatchés.
        Bus::assertNotDispatched(WorkstationAdSyncJob::class, function ($job) {
            return in_array($job->action, [
                WorkstationAdSyncJob::ACTION_RENAME,
                WorkstationAdSyncJob::ACTION_STATUS,
            ], true);
        });
    }

    #[Test]
    public function changing_only_unrelated_attribute_does_not_dispatch_rename_or_status(): void
    {
        $ws = $this->makeWorkstation('PC-UNRELATED-1');

        $ws->mac = 'ff:ee:dd:cc:bb:aa';
        $ws->save();

        Bus::assertNotDispatched(WorkstationAdSyncJob::class, function ($job) {
            return in_array($job->action, [
                WorkstationAdSyncJob::ACTION_RENAME,
                WorkstationAdSyncJob::ACTION_STATUS,
            ], true);
        });
    }

    #[Test]
    public function deleting_workstation_dispatches_delete_job_with_name_and_ad_guid(): void
    {
        $ws = $this->makeWorkstation('PC-DELETE-1');
        $ws->ad_guid = 'guid-abc-123';
        WorkstationObserver::withoutSync(fn () => $ws->save());

        $ws->delete();

        Bus::assertDispatched(WorkstationAdSyncJob::class, function ($job) {
            return $job->action === WorkstationAdSyncJob::ACTION_DELETE
                && $job->workstationId === 'PC-DELETE-1'
                && ($job->params['name'] ?? null) === 'PC-DELETE-1'
                && ($job->params['ad_guid'] ?? null) === 'guid-abc-123';
        });
    }

    #[Test]
    public function without_sync_helper_bypasses_observer(): void
    {
        $ws = WorkstationObserver::withoutSync(function () {
            return Workstation::create([
                'name' => 'PC-BYPASS-1',
                'uuid' => 'aaaaaaaa-9999-9999-9999-aaaaaaaaaaaa',
                'mac' => 'aa:bb:cc:dd:ee:99',
                'status' => 'active',
            ]);
        });

        self::assertNotNull($ws->id);
        Bus::assertNotDispatched(WorkstationAdSyncJob::class);
    }
}
