<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\FilePolicyMode;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\TargetContext;
use App\Services\FilePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Résolution de la politique de gestion des fichiers : défaut global
 * (`SystemSetting`) surchargé par parc (colonnes `workstation_groups`), avec
 * précédence logique > physique > global.
 */
class FilePolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    #[Test]
    public function defaults_to_partages_when_nothing_persisted(): void
    {
        self::assertSame(FilePolicyMode::Partages, FilePolicyService::globalMode());
        self::assertSame(FilePolicyMode::Partages->value, FilePolicyService::globalConfig()['mode']);
    }

    #[Test]
    public function persists_and_reads_back_the_global_config(): void
    {
        FilePolicyService::setGlobal(FilePolicyMode::AutreWeb, '  https://cloud.etab.fr  ', 'https://cloud.etab.fr');

        $config = FilePolicyService::globalConfig();
        self::assertSame(FilePolicyMode::AutreWeb->value, $config['mode']);
        // URL trimée à l'écriture.
        self::assertSame('https://cloud.etab.fr', $config['nextcloud']['server_url']);
        self::assertSame(FilePolicyMode::AutreWeb, FilePolicyService::globalMode());
    }

    #[Test]
    public function tolerates_a_partial_or_bogus_stored_payload(): void
    {
        // Mode invalide → repli Partages ; nextcloud absent → chaînes vides.
        SystemSetting::set(FilePolicyService::SETTING_KEY, ['mode' => 'bogus']);

        self::assertSame(FilePolicyMode::Partages, FilePolicyService::globalMode());
        self::assertSame('', FilePolicyService::globalConfig()['nextcloud']['server_url']);
    }

    #[Test]
    public function effective_mode_falls_back_to_global_without_override(): void
    {
        FilePolicyService::setGlobal(FilePolicyMode::NextcloudDesktop);
        $ctx = $this->ctxWithGroups([]);

        self::assertSame(FilePolicyMode::NextcloudDesktop, FilePolicyService::effectiveMode($ctx));
    }

    #[Test]
    public function logical_override_wins_over_physical_and_global(): void
    {
        FilePolicyService::setGlobal(FilePolicyMode::Partages);
        $physical = WorkstationGroup::factory()->physical()->create([
            'files_policy_mode' => FilePolicyMode::Partages,
        ]);
        $logical = WorkstationGroup::factory()->logical()->create([
            'files_policy_mode' => FilePolicyMode::AutreWeb,
        ]);

        $ctx = $this->ctxWithGroups([$physical->id, $logical->id]);

        self::assertSame(FilePolicyMode::AutreWeb, FilePolicyService::effectiveMode($ctx));
    }

    /** @param  list<int>  $groupIds */
    private function ctxWithGroups(array $groupIds): TargetContext
    {
        $ws = Workstation::factory()->create();
        if ($groupIds !== []) {
            $ws->groups()->attach($groupIds);
        }
        $user = User::factory()->create();

        return TargetContext::for($ws, $user);
    }
}
