<?php

namespace Database\Seeders;

use App\Models\Depot;
use App\Models\DepotApplication;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class DepotApplicationSeeder extends Seeder
{
    private const SOURCES = [
        [
            'name'       => 'SambaEdu Stable',
            'url'        => 'http://deb.sambaedu.org/wpkg/xml/packages.xml',
            'xml_url'    => 'http://deb.sambaedu.org/wpkg/xml/packages.xml',
            'branch'     => 'stable',
            'is_primary' => true,
            'is_active'  => true,
        ],
        [
            'name'       => 'SambaEdu Dev',
            'url'        => 'http://deb.sambaedu.org/wpkg/xml/packages_dev.xml',
            'xml_url'    => 'http://deb.sambaedu.org/wpkg/xml/packages_dev.xml',
            'branch'     => 'dev',
            'is_primary' => false,
            'is_active'  => true,
        ],
    ];

    public function run(): void
    {
        DB::statement('TRUNCATE depot_applications RESTART IDENTITY CASCADE');
        $this->command->info('Table depot_applications vidée.');

        foreach (self::SOURCES as $source) {
            $depot = Depot::updateOrCreate(
                ['name' => $source['name']],
                [
                    'url'        => $source['url'],
                    'is_primary' => $source['is_primary'],
                    'is_active'  => $source['is_active'],
                ]
            );

            $this->command->info("Fetching {$source['xml_url']}...");

            $response = Http::timeout(30)->get($source['xml_url']);

            if (!$response->successful()) {
                $this->command->warn("Impossible de récupérer {$source['xml_url']} (HTTP {$response->status()})");
                continue;
            }

            $xml = simplexml_load_string($response->body());

            if ($xml === false) {
                $this->command->warn("XML invalide pour {$source['xml_url']}");
                continue;
            }

            $packages = $xml->branch->package ?? [];
            $count    = 0;
            $now      = now();
            $rows     = [];

            foreach ($packages as $pkg) {
                $rows[] = [
                    'depot_id'        => $depot->id,
                    'app_id'          => (string) $pkg['id'],
                    'name'            => (string) $pkg['name'],
                    'version'         => (string) ($pkg['revision'] ?? ''),
                    'category'        => (string) ($pkg['category'] ?? ''),
                    'compatibility'   => (string) ($pkg['compatibilite'] ?? ''),
                    'branch'          => $source['branch'],
                    'xml_url'         => (string) ($pkg['url'] ?? ''),
                    'xml_sha'         => (string) ($pkg['hash'] ?? ''),
                    'log_url'         => (string) ($pkg['log'] ?? ''),
                    'last_checked_at' => !empty((string) $pkg['date']) ? (string) $pkg['date'] : $now,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
                $count++;
            }

            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('depot_applications')->insert($chunk);
            }

            $this->command->info("{$count} applications importées pour « {$source['name']} ».");
        }
    }
}
