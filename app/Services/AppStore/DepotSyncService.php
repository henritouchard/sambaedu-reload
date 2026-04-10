<?php

declare(strict_types=1);

namespace App\Services\AppStore;

use App\Models\Depot;
use App\Models\DepotApplication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service de synchronisation du catalogue distant vers depot_applications
 */
class DepotSyncService
{
    private int $syncTimeout;

    public function __construct()
    {
        $this->syncTimeout = (int) config('sambaedu.wpkg.sync_timeout', 30);
    }

    /**
     * Synchronise le catalogue d'applications depuis tous les depots actifs
     *
     * @return array{synced: int, new: int, updated: int, purged: int, errors: array}
     */
    public function syncAllDepots(): array
    {
        $stats = ['synced' => 0, 'new' => 0, 'updated' => 0, 'purged' => 0, 'errors' => []];

        $depots = Depot::active()->get();

        foreach ($depots as $depot) {
            try {
                $result = $this->syncDepot($depot);
                $stats['synced']++;
                $stats['new'] += $result['new'];
                $stats['updated'] += $result['updated'];
                $stats['purged'] += $result['purged'];
            } catch (\Exception $e) {
                $stats['errors'][] = "Depot '{$depot->name}': " . $e->getMessage();
                Log::error('[AppStore] Erreur sync depot', [
                    'depot_id' => $depot->id,
                    'depot_name' => $depot->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[AppStore] Synchronisation terminee', $stats);
        return $stats;
    }

    /**
     * Synchronise un depot specifique en recuperant son XML distant
     *
     * @return array{new: int, updated: int, purged: int}
     */
    public function syncDepot(Depot $depot): array
    {
        Log::info('[AppStore] Synchronisation du depot', ['depot' => $depot->name, 'url' => $depot->url]);

        $xmlUrl = str_ends_with($depot->url, '/packages.xml')
            ? $depot->url
            : rtrim($depot->url, '/') . '/packages.xml';
        $response = Http::timeout($this->syncTimeout)->get($xmlUrl);

        if (!$response->successful()) {
            throw new \RuntimeException("Impossible de recuperer {$xmlUrl} (HTTP {$response->status()})");
        }

        $xmlContent = $response->body();
        $newHash = hash('sha256', $xmlContent);

        // Verifier si le XML a change
        if ($depot->xml_hash === $newHash) {
            Log::debug('[AppStore] Depot inchange', ['depot' => $depot->name]);
            return ['new' => 0, 'updated' => 0, 'purged' => 0];
        }

        $stats = $this->parseAndUpsertApplications($depot, $xmlContent);

        // Mettre a jour le hash
        $depot->update(['xml_hash' => $newHash]);

        return $stats;
    }

    /**
     * Parse le XML du depot et met a jour la table depot_applications
     *
     * Les applications sont stockees dans depot_applications (catalogue distant).
     * Elles ne sont copiees dans applications que lors de l'installation.
     *
     * @return array{new: int, updated: int, purged: int}
     */
    private function parseAndUpsertApplications(Depot $depot, string $xmlContent): array
    {
        $stats = ['new' => 0, 'updated' => 0, 'purged' => 0];

        $xml = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $parsed = $xml->loadXML($xmlContent);
        libxml_use_internal_errors($prev);

        if (!$parsed) {
            throw new \RuntimeException('XML du depot invalide : parsing echoue');
        }

        // Parcourir les branches pour extraire la branche parente de chaque package
        $branches = $xml->getElementsByTagName('branch');
        $seenKeys = [];

        DB::beginTransaction();
        try {
            foreach ($branches as $branch) {
                $branchId = $branch->getAttribute('id') ?: 'stable';
                $packages = $branch->getElementsByTagName('package');

                foreach ($packages as $package) {
                    $appId = $package->getAttribute('id');
                    $name = $package->getAttribute('name');
                    $version = $package->getAttribute('revision') ?: $package->getAttribute('version');

                    if (empty($appId) || empty($name)) {
                        continue;
                    }

                    $data = [
                        'name' => $name,
                        'version' => $version ?: null,
                        'category' => $package->getAttribute('category') ?: null,
                        'compatibility' => $package->getAttribute('compatibilite') ?: null,
                        'branch' => $branchId,
                        'icon_url' => $this->extractIconUrl($depot, $package),
                        'xml_url' => $package->getAttribute('url') ?: null,
                        'xml_sha' => $package->getAttribute('hash') ?: null,
                        'log_url' => $package->getAttribute('log') ?: null,
                        'last_checked_at' => now(),
                    ];

                    // Cle unique : depot_id + app_id + branch
                    $existing = DepotApplication::where('depot_id', $depot->id)
                        ->where('app_id', $appId)
                        ->where('branch', $branchId)
                        ->first();

                    if ($existing) {
                        $existing->update($data);
                        $stats['updated']++;
                    } else {
                        DepotApplication::create(array_merge($data, [
                            'depot_id' => $depot->id,
                            'app_id' => $appId,
                        ]));
                        $stats['new']++;
                    }

                    $seenKeys[] = $appId . '|' . $branchId;
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        // Purge des applications obsoletes (apres le commit du bloc upsert)
        if (empty($seenKeys)) {
            Log::warning('[AppStore] Aucun package valide trouvé, purge ignorée', ['depot' => $depot->name]);
            return $stats;
        }

        $allForDepot = DepotApplication::where('depot_id', $depot->id)->get(['id', 'app_id', 'branch']);
        $toDelete = $allForDepot->filter(fn($da) => !in_array($da->app_id . '|' . $da->branch, $seenKeys));
        $purgedCount = $toDelete->count();
        if ($purgedCount > 0) {
            DepotApplication::whereIn('id', $toDelete->pluck('id'))->delete();
            Log::info('[AppStore] Purge depot_applications', ['depot' => $depot->name, 'purged' => $purgedCount]);
        }
        $stats['purged'] = $purgedCount;

        return $stats;
    }

    /**
     * Extrait l'URL de l'icone
     */
    private function extractIconUrl(Depot $depot, \DOMElement $package): ?string
    {
        $icon = $package->getAttribute('icon');
        if (!empty($icon)) {
            if (str_starts_with($icon, 'http')) {
                return $icon;
            }
            return rtrim($depot->url, '/') . '/' . $icon;
        }

        // Convention : icone dans le dossier de l'app
        $appId = $package->getAttribute('id');
        if (!empty($appId)) {
            return rtrim($depot->url, '/') . '/' . $appId . '/icon.png';
        }

        return null;
    }
}
