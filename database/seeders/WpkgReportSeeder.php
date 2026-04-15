<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Workstation;
use App\Models\WorkstationApplicationStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WpkgReportSeeder extends Seeder
{
    private const ERROR_MESSAGES = [
        'Package not found in repository',
        'Installation failed: access denied',
        'Checksum mismatch, download corrupted',
        'Dependencies could not be resolved',
        'Installer returned exit code 1603',
        'Disk quota exceeded',
        'Timeout during download (60s)',
        'Registry key locked by another process',
        'Previous version could not be uninstalled',
        'MSI transform failed',
    ];

    /**
     * Seed workstation_application_status with realistic WPKG deployment data.
     * Only Windows workstations are targeted (WPKG is Windows-only).
     */
    public function run(): void
    {
        DB::statement('TRUNCATE workstation_application_status RESTART IDENTITY CASCADE');
        $this->command->info('Table workstation_application_status vidée.');

        $windowsWorkstations = Workstation::query()
            ->where('os', 'like', 'Windows%')
            ->get();

        $applications = Application::all();

        if ($windowsWorkstations->isEmpty()) {
            $this->command->warn('Aucun poste Windows trouvé — lancez WorkstationSeeder en premier.');
            return;
        }

        if ($applications->isEmpty()) {
            $this->command->warn('Aucune application trouvée — lancez DepotSeeder en premier.');
            return;
        }

        $this->command->info("Génération des rapports WPKG pour {$windowsWorkstations->count()} postes Windows et {$applications->count()} applications...");

        $inserted = 0;

        foreach ($windowsWorkstations as $workstation) {
            // Each workstation has a random subset of applications deployed (50–90%)
            $deployedApps = $applications->random(max(1, (int) round($applications->count() * (rand(50, 90) / 100))));

            foreach ($deployedApps as $app) {
                $roll = rand(1, 100);

                if ($roll <= 70) {
                    // 70% installed
                    $status  = 'installed';
                    $message = null;
                } elseif ($roll <= 85) {
                    // 15% not-installed (known to be missing, no attempt yet or removed)
                    $status  = 'not-installed';
                    $message = null;
                } elseif ($roll <= 94) {
                    // 9% error
                    $status  = 'error';
                    $message = self::ERROR_MESSAGES[array_rand(self::ERROR_MESSAGES)];
                } else {
                    // 6% in-progress (upgrading or downgrading)
                    $status  = rand(0, 1) ? 'upgrading' : 'downgrading';
                    $message = null;
                }

                WorkstationApplicationStatus::create([
                    'workstation_id'    => $workstation->id,
                    'application_id'    => $app->id,
                    'installed_version' => in_array($status, ['installed', 'upgrading', 'downgrading'])
                        ? $app->version
                        : null,
                    'status'          => $status,
                    'reboot_required' => $status === 'installed' && rand(0, 10) === 0,
                    'reported_at'     => now()->subMinutes(rand(0, 2880)),
                    'message'         => $message,
                ]);

                $inserted++;
            }
        }

        $this->command->info("{$inserted} entrées de rapport WPKG créées.");
    }
}
