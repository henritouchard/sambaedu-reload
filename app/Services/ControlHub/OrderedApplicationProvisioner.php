<?php

declare(strict_types=1);

namespace App\Services\ControlHub;

use App\Enums\ControlHubEnforcementState;
use App\Models\Application;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractCatalogApp;
use App\Services\AppStore\AppStoreService;
use App\Services\ControlHub\Data\OrderedApplicationProvisioningResult;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 31.3 — Approvisionnement « désir d'état » des applications ORDONNÉES par le
 * contrat amont (controlHub), comblant le gap D4 de la Story 31.2 (FR6).
 *
 * Pour chaque ordre d'install amont (`controlhub_contract_items` `type='applications'`,
 * non-`absent`) dont l'`app_id` n'existe PAS encore en inventaire local (`applications`) :
 *  - résout l'entrée du catalogue amont (`controlhub_contract_catalog_apps{app_key}`) →
 *    référence de SOURCE par-app (`source_xml_url`/`source_xml_sha`, « Option B », D1) ;
 *  - MATÉRIALISE la ligne `Application` (status `Available`, recette WPKG) via
 *    {@see AppStoreService::materializeFromSource()} — SANS install serveur, SANS
 *    `Depot`/`DepotApplication`/`DepotSyncService` (jamais appelés par ce mécanisme).
 *
 * Après matérialisation, l'hydratation 27.5 trouve la ligne et l'ordre 31.2 est
 * PLEINEMENT honoré (plus de skip+warn).
 *
 * Patron CALQUÉ sur {@see ImposedWorkstationGroupReconciler} (30.3) : réconciliation
 * idempotente, re-jouable (commande jumelle), court-circuit NFR3 sans contrat actif.
 *
 * Invariants :
 *  - NFR3 — standalone : sans contrat amont actif, `provision()` est un no-op total ;
 *    `DepotSyncService` / AppStore restent strictement inchangés.
 *  - NFR4 — idempotence : `firstOrCreate` sur `app_id` ⇒ une `Application` locale
 *    préexistante n'est JAMAIS écrasée ; réconciliation rejouable sans doublon (AC3).
 *  - Résilience : une app en échec (source absente/vide, exception) n'interrompt NI
 *    l'ingestion NI les autres matérialisations (try/catch par app — AC6).
 *  - R3 — vocabulaire : terme prohibé proscrit ; « amont » / `ControlHub*` / `Upstream`.
 *    [Source: prd-contrat-manage-se5.md#R3]
 */
class OrderedApplicationProvisioner
{
    public function __construct(
        private readonly AppStoreService $appStoreService,
    ) {
    }

    /**
     * Matérialise les applications ordonnées par le contrat amont actif et absentes
     * de l'inventaire local.
     *
     * @return OrderedApplicationProvisioningResult Compteurs provisioned/skipped/failed + erreurs.
     */
    public function provision(): OrderedApplicationProvisioningResult
    {
        $result = new OrderedApplicationProvisioningResult();

        // NFR3 — Standalone : sans contrat amont actif, no-op total. Aucune autre table
        // n'est lue, AUCUNE synchro de dépôt déclenchée (Option B = DepotSyncService
        // jamais appelé de toute façon).
        $contract = ControlHubContract::active();
        if ($contract === null) {
            return $result;
        }

        // Les ORDRES d'install : items `type='applications'` non-`absent` (un `absent`
        // signifie « non imposé » → ce n'est pas un ordre, on ne matérialise pas). Cible
        // `instance` comme `label` : l'inventaire est instance-wide (la matérialisation
        // ne dépend pas du ciblage par poste — c'est 31.2 qui projette par cible).
        $orderedAppIds = $contract->items()
            ->where('type', Application::TYPE_APPLICATIONS)
            ->where('enforcement_state', '!=', ControlHubEnforcementState::Absent->value)
            ->pluck('key')
            ->unique()
            ->values();

        foreach ($orderedAppIds as $appId) {
            try {
                // Déjà en inventaire (matérialisée antérieurement ou app locale) → no-op (AC3).
                if (Application::query()->where('app_id', $appId)->exists()) {
                    $result->alreadyPresent++;

                    continue;
                }

                $catalogApp = $contract->catalogApps()
                    ->where('app_key', $appId)
                    ->first();

                // AC6 — ordre sans entrée catalogue OU sans source : laissé non matérialisé,
                // journalisé, SANS exception (31.2 retombe sur son skip+warn). Re-tentable.
                if ($catalogApp === null || $catalogApp->source_xml_url === null || $catalogApp->source_xml_url === '') {
                    $result->skipped++;
                    Log::warning('agent.applications.provision_skipped', [
                        'app_id' => $appId,
                        'reason' => $catalogApp === null
                            ? 'no_catalog_entry'
                            : 'no_source_xml_url',
                    ]);

                    continue;
                }

                $application = $this->appStoreService->materializeFromSource($appId, [
                    'name' => $catalogApp->display_name,
                    'xml_url' => $catalogApp->source_xml_url,
                    'xml_sha' => $catalogApp->source_xml_sha,
                ]);

                if ($application->wasRecentlyCreated) {
                    $result->provisioned++;
                    Log::info('agent.applications.provisioned', [
                        'app_id' => $appId,
                        'application_id' => $application->id,
                    ]);
                } else {
                    // Course rare : créée entre le exists() et le firstOrCreate ⇒ no-op (AC3).
                    $result->alreadyPresent++;
                }
            } catch (Throwable $e) {
                // Résilience (AC6) : on n'interrompt NI les autres apps NI l'ingestion.
                $result->failed++;
                $result->errors[] = "{$appId}: {$e->getMessage()}";
                Log::error('agent.applications.provision_failed', [
                    'app_id' => $appId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }
}
