<?php

namespace App\Services\ControlHub;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\Shortcut;
use App\Services\ControlHub\Data\SyncManifestResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service de convergence déclarative pour le Sync Manifest.
 *
 * Reçoit un manifeste décrivant l'état souhaité et converge l'instance en 3 passes :
 * - Pass 1 : Upsert des entités sans relations
 * - Pass 2 : Résolution des relations
 * - Pass 3 : Nettoyage des entités ControlHub absentes du manifeste
 */
class SyncManifestService
{
    /**
     * Applique le manifeste et retourne le rapport de convergence.
     *
     * @param array<string, mixed> $payload
     * @param string $manifestVersion
     * @return SyncManifestResult
     */
    public function apply(array $payload, string $manifestVersion): SyncManifestResult
    {
        $result = new SyncManifestResult($manifestVersion);

        $shortcutsData = $payload['shortcuts'] ?? [];
        $appProfilesData = $payload['app_profiles'] ?? [];

        Log::info('SyncManifestService: Starting sync', [
            'manifest_version' => $manifestVersion,
            'shortcuts_count' => count($shortcutsData),
            'app_profiles_count' => count($appProfilesData),
        ]);

        DB::transaction(function () use ($shortcutsData, $appProfilesData, $result) {
            // ── Pass 1 : Upsert entités sans relations ──
            $this->pass1Applications($appProfilesData, $result);
            $this->pass1Shortcuts($shortcutsData, $result);
            $this->pass1AppProfiles($appProfilesData, $result);

            // ── Pass 2 : Résolution des relations ──
            $this->pass2AppProfilesToApplications($appProfilesData, $result);

            // ── Pass 3 : Nettoyage ──
            $this->pass3Cleanup($shortcutsData, $appProfilesData, $result);
        });

        Log::info('SyncManifestService: Sync completed', [
            'manifest_version' => $manifestVersion,
            'result' => $result->toArray(),
        ]);

        return $result;
    }

    // ═══════════════════════════════════════════════════════════════
    // Pass 1 : Upsert entités
    // ═══════════════════════════════════════════════════════════════

    /**
     * Extrait et upsert toutes les applications depuis les app_profiles.
     *
     * @param array<int, array<string, mixed>> $appProfilesData
     */
    private function pass1Applications(array $appProfilesData, SyncManifestResult $result): void
    {
        foreach ($appProfilesData as $profileData) {
            $applications = $profileData['applications'] ?? [];
            
            foreach ($applications as $appData) {
                $controlhubId = $appData['controlhub_id'] ?? null;
                $appId = $appData['app_id'] ?? null;

                if (!$appId) {
                    $result->addWarning('Application sans app_id ignorée');
                    continue;
                }

                // Recherche par controlhub_id si présent, sinon par app_id
                $existing = null;
                if ($controlhubId) {
                    $existing = Application::where('controlhub_id', $controlhubId)->first();
                }
                if (!$existing) {
                    $existing = Application::where('app_id', $appId)->first();
                }

                $attributes = [
                    'app_id' => $appId,
                    'name' => $appData['name'] ?? $existing?->name ?? $appId,
                    'version' => $appData['version'] ?? $existing?->version,
                    'category' => $appData['category'] ?? $existing?->category,
                    'compatibility' => $appData['compatibility'] ?? $existing?->compatibility,
                    'branch' => $appData['branch'] ?? $existing?->branch,
                    'xml' => $appData['xml'] ?? $existing?->xml,
                    'xml_url' => $appData['xml_url'] ?? $existing?->xml_url,
                    'xml_sha' => $appData['xml_sha'] ?? $existing?->xml_sha,
                    'log_url' => $appData['log_url'] ?? $existing?->log_url,
                ];

                // Ajouter controlhub_id et controlhub_version si présents
                if ($controlhubId) {
                    $attributes['controlhub_id'] = $controlhubId;
                    $attributes['controlhub_version'] = $appData['controlhub_version'] ?? null;
                    $attributes['managed_by_control_hub'] = true;
                }

                if ($existing) {
                    $existing->update($attributes);
                    $result->applicationsStats['updated']++;
                } else {
                    // depot_id est nullable maintenant, on peut créer sans dépôt
                    Application::create($attributes);
                    $result->applicationsStats['created']++;
                }
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $shortcutsData
     */
    private function pass1Shortcuts(array $shortcutsData, SyncManifestResult $result): void
    {
        foreach ($shortcutsData as $data) {
            $controlhubId = $data['controlhub_id'] ?? null;
            if (!$controlhubId) {
                $result->addWarning('Shortcut sans controlhub_id ignoré');
                continue;
            }

            $existing = Shortcut::where('controlhub_id', $controlhubId)->first();

            $attributes = [
                'controlhub_id' => $controlhubId,
                'controlhub_version' => $data['controlhub_version'] ?? null,
                'name' => $data['name'] ?? $existing?->name ?? 'unnamed',
                'owner' => $data['owner'] ?? $existing?->owner ?? '',
                'place' => $data['place'] ?? $existing?->place ?? 'desktop',
                'is_global' => true,
                'windows_link' => $data['windows']['link'] ?? $data['windows_link'] ?? $existing?->windows_link ?? '',
                'windows_args' => $data['windows']['args'] ?? $data['windows_args'] ?? $existing?->windows_args ?? '',
                'windows_path' => $data['windows']['path'] ?? $data['windows_path'] ?? $existing?->windows_path ?? '',
                'linux_link' => $data['linux']['link'] ?? $data['linux_link'] ?? $existing?->linux_link ?? '',
                'linux_args' => $data['linux']['args'] ?? $data['linux_args'] ?? $existing?->linux_args ?? '',
                'linux_path' => $data['linux']['path'] ?? $data['linux_path'] ?? $existing?->linux_path ?? '',
                'linux_startupwmclass' => $data['linux']['startupwmclass'] ?? $data['linux_startupwmclass'] ?? $existing?->linux_startupwmclass ?? '',
            ];

            if ($existing) {
                $existing->update($attributes);
                $result->shortcutsStats['updated']++;
            } else {
                $attributes['key'] = uniqid();
                Shortcut::create($attributes);
                $result->shortcutsStats['created']++;
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $appProfilesData
     */
    private function pass1AppProfiles(array $appProfilesData, SyncManifestResult $result): void
    {
        foreach ($appProfilesData as $data) {
            $controlhubId = $data['controlhub_id'] ?? null;
            if (!$controlhubId) {
                $result->addWarning('AppProfile sans controlhub_id ignoré');
                continue;
            }

            $existing = AppProfile::where('controlhub_id', $controlhubId)->first();

            $attributes = [
                'controlhub_id' => $controlhubId,
                'controlhub_version' => $data['controlhub_version'] ?? null,
                'name' => $data['name'] ?? $existing?->name ?? 'unnamed',
                'description' => $data['description'] ?? $existing?->description,
            ];

            if ($existing) {
                $existing->update($attributes);
                $result->appProfilesStats['updated']++;
            } else {
                AppProfile::create($attributes);
                $result->appProfilesStats['created']++;
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Pass 2 : Résolution des relations
    // ═══════════════════════════════════════════════════════════════

    /**
     * Sync app_profiles ↔ applications (résolution soft par app_id).
     *
     * @param array<int, array<string, mixed>> $appProfilesData
     */
    private function pass2AppProfilesToApplications(array $appProfilesData, SyncManifestResult $result): void
    {
        foreach ($appProfilesData as $data) {
            $controlhubId = $data['controlhub_id'] ?? null;
            if (!$controlhubId || !array_key_exists('applications', $data)) {
                continue;
            }

            $profile = AppProfile::where('controlhub_id', $controlhubId)->first();
            if (!$profile) {
                continue;
            }

            $applicationIds = [];
            foreach ($data['applications'] as $appRef) {
                $appId = $appRef['app_id'] ?? null;
                if (!$appId) {
                    continue;
                }
                $application = Application::where('app_id', $appId)->first();
                if ($application) {
                    $applicationIds[] = $application->id;
                    $result->appProfilesToApplications['resolved']++;
                } else {
                    $result->appProfilesToApplications['missing']++;
                    $result->addWarning("AppProfile '{$data['name']}': application '{$appId}' non trouvée localement (ignorée)");
                }
            }

            $profile->applications()->sync($applicationIds);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Pass 3 : Nettoyage
    // ═══════════════════════════════════════════════════════════════

    /**
     * Supprime les entités ControlHub absentes du manifeste.
     *
     * @param array<int, array<string, mixed>> $shortcutsData
     * @param array<int, array<string, mixed>> $appProfilesData
     */
    private function pass3Cleanup(
        array $shortcutsData,
        array $appProfilesData,
        SyncManifestResult $result
    ): void {
        $manifestShortcutIds = collect($shortcutsData)->pluck('controlhub_id')->filter()->all();
        $manifestProfileIds = collect($appProfilesData)->pluck('controlhub_id')->filter()->all();

        // Supprimer les shortcuts ControlHub absents du manifeste
        $orphanShortcuts = Shortcut::whereNotNull('controlhub_id')
            ->where('is_global', true)
            ->when(!empty($manifestShortcutIds), fn ($q) => $q->whereNotIn('controlhub_id', $manifestShortcutIds))
            ->get();

        foreach ($orphanShortcuts as $shortcut) {
            $shortcut->workstationGroups()->detach();
            $shortcut->delete();
            $result->cleanup['shortcuts_deleted']++;
            Log::info('SyncManifestService: Orphan shortcut deleted', [
                'controlhub_id' => $shortcut->controlhub_id,
                'name' => $shortcut->name,
            ]);
        }

        // Supprimer les app_profiles ControlHub absents du manifeste
        $orphanProfiles = AppProfile::whereNotNull('controlhub_id')
            ->when(!empty($manifestProfileIds), fn ($q) => $q->whereNotIn('controlhub_id', $manifestProfileIds))
            ->get();

        foreach ($orphanProfiles as $profile) {
            $profile->applications()->detach();
            $profile->workstationGroups()->detach();
            $profile->delete();
            $result->cleanup['app_profiles_deleted']++;
            Log::info('SyncManifestService: Orphan app_profile deleted', [
                'controlhub_id' => $profile->controlhub_id,
                'name' => $profile->name,
            ]);
        }
    }
}
