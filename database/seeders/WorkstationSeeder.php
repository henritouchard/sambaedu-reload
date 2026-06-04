<?php

namespace Database\Seeders;

use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkstationSeeder extends Seeder
{
    /**
     * Seed les tables workstations et workstation_groups avec des données de test
     */
    public function run(): void
    {
        // Désactiver la sync AD pendant le seeding
        WorkstationGroupObserver::disableSync();

        // Vider les tables (PostgreSQL)
        DB::statement('TRUNCATE workstation_group_workstation, workstations, workstation_groups RESTART IDENTITY CASCADE');
        $this->command->info('Tables workstations et workstation_groups vidées.');

        $osTypes = ['Windows 10', 'Windows 11', 'Linux Mint', 'Ubuntu 22.04', 'Windows 7'];
        $statuses = ['online', 'offline', 'unknown'];
        
        // Créer les salles (groupes physiques)
        $salles = [
            ['name' => 'info1', 'display_name' => 'Salle Informatique 1', 'description' => 'Salle principale'],
            ['name' => 'info2', 'display_name' => 'Salle Informatique 2', 'description' => 'Salle secondaire'],
            ['name' => 'info3', 'display_name' => 'Salle Informatique 3', 'description' => 'Salle de TP'],
            ['name' => 'cdi', 'display_name' => 'CDI', 'description' => 'Centre de Documentation'],
            ['name' => 'techno', 'display_name' => 'Salle Technologie', 'description' => 'Atelier technologie'],
            ['name' => 'physique', 'display_name' => 'Salle Physique', 'description' => 'Laboratoire physique'],
            ['name' => 'svt', 'display_name' => 'Salle SVT', 'description' => 'Laboratoire SVT'],
        ];

        $createdRooms = [];
        foreach ($salles as $salle) {
            $room = WorkstationGroup::create([
                'name' => $salle['name'],
                'display_name' => $salle['display_name'],
                'description' => $salle['description'],
                'is_physical' => true,
                'is_active' => true,
            ]);
            $createdRooms[$salle['name']] = $room;
        }
        $this->command->info(count($salles) . ' salles créées.');

        // Créer des groupes logiques
        $groupesLogiques = [
            ['name' => 'windows-all', 'display_name' => 'Tous les postes Windows', 'description' => 'Groupe logique Windows'],
            ['name' => 'linux-all', 'display_name' => 'Tous les postes Linux', 'description' => 'Groupe logique Linux'],
            ['name' => 'maintenance', 'display_name' => 'Postes en maintenance', 'description' => 'Postes nécessitant intervention'],
        ];

        $logicalGroups = [];
        foreach ($groupesLogiques as $groupe) {
            $logicalGroups[$groupe['name']] = WorkstationGroup::create([
                'name' => $groupe['name'],
                'display_name' => $groupe['display_name'],
                'description' => $groupe['description'],
                'is_physical' => false,
                'is_active' => true,
            ]);
        }
        $this->command->info(count($groupesLogiques) . ' groupes logiques créés.');

        // Créer des postes de test
        $windowsWorkstations = [];
        $linuxWorkstations = [];

        foreach ($createdRooms as $salleName => $room) {
            $nbPostes = rand(5, 10);
            
            for ($i = 1; $i <= $nbPostes; $i++) {
                $numPoste = str_pad($i, 2, '0', STR_PAD_LEFT);
                $name = "pc-{$salleName}-{$numPoste}";
                $os = $osTypes[array_rand($osTypes)];
                $status = $statuses[array_rand($statuses)];

                $workstation = Workstation::create([
                    'name' => $name,
                    'os' => $os,
                    'ip' => '192.168.' . rand(1, 10) . '.' . rand(10, 250),
                    'mac' => $this->generateMacAddress(),
                    'status' => $status,
                    'ad_guid' => Str::uuid()->toString(),
                    'last_report_at' => $status === 'online' ? now() : now()->subMinutes(rand(60, 10080)),
                ]);

                // Story 4.11 — l'appartenance « salle » vit dans le pivot global.
                $workstation->groups()->attach($room->id);

                // Collecter pour les groupes logiques
                if (str_contains($os, 'Windows')) {
                    $windowsWorkstations[] = $workstation->id;
                } else {
                    $linuxWorkstations[] = $workstation->id;
                }
            }
        }

        $totalWorkstations = Workstation::count();
        $this->command->info("{$totalWorkstations} postes créés.");

        // Associer les postes aux groupes logiques
        $logicalGroups['windows-all']->workstations()->attach($windowsWorkstations);
        $logicalGroups['linux-all']->workstations()->attach($linuxWorkstations);
        
        // Quelques postes en maintenance
        $maintenanceIds = array_slice($windowsWorkstations, 0, 3);
        $logicalGroups['maintenance']->workstations()->attach($maintenanceIds);

        $this->command->info('Associations groupes logiques créées.');

        // Réactiver la sync AD
        WorkstationGroupObserver::enableSync();
    }

    /**
     * Génère une adresse MAC aléatoire
     */
    private function generateMacAddress(): string
    {
        $mac = [];
        for ($i = 0; $i < 6; $i++) {
            $mac[] = str_pad(dechex(rand(0, 255)), 2, '0', STR_PAD_LEFT);
        }
        return strtoupper(implode(':', $mac));
    }
}
