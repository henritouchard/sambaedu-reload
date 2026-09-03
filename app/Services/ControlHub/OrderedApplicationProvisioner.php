<?php

declare(strict_types=1);

namespace App\Services\ControlHub;

use App\Enums\ApplicationStatus;
use App\Enums\ControlHubEnforcementState;
use App\Jobs\InstallOrderedApplicationJob;
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
 *    {@see AppStoreService::materializeFromSource()}, SANS
 *    `Depot`/`DepotApplication`/`DepotSyncService` (jamais appelés par ce mécanisme) ;
 *  - MET EN FILE la POSE SERVEUR ({@see InstallOrderedApplicationJob}) de toute app
 *    ordonnée qui n'est pas déjà installée — matérialisée à l'instant comme
 *    préexistante.
 *
 * **Matérialiser ne suffit pas.** Le catalogue projeté au poste ne contient que les
 * applications INSTALLÉES sur le serveur ({@see Application::scopeInstalled()}). Une
 * app ordonnée laissée en `Available` n'a donc pas de recette dans `packages.xml` :
 * l'agent la réclame à WPKG, qui ne trouve aucun `<package id="…">` et n'installe
 * rien. Rien n'échoue nulle part — le poste rapporte simplement l'app manquante à
 * chaque passage. C'est ce trou que ferme la pose serveur.
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
                // Déjà en inventaire (matérialisée antérieurement ou app locale) : la
                // ligne n'est pas touchée (AC3), mais la POSE SERVEUR reste due — c'est
                // elle, et non la ligne d'inventaire, qui fait entrer la recette dans le
                // catalogue projeté au poste.
                $existing = Application::query()->where('app_id', $appId)->first();
                if ($existing !== null) {
                    $result->alreadyPresent++;
                    $this->dispatchServerInstall($existing, $result);

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

                $this->dispatchServerInstall($application, $result);
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

    /**
     * Met en file la pose SERVEUR d'une application ordonnée.
     *
     * Matérialiser la ligne d'inventaire ne suffit pas : le catalogue servi au poste
     * ne projette que les applications INSTALLÉES sur le serveur
     * ({@see \App\Models\Application::scopeInstalled()}). Une app ordonnée restée en
     * `Available` n'a donc pas de `<package id="…">` dans `packages.xml` — WPKG ne
     * trouve rien à installer et l'agent la rapporte manquante à chaque passage, sans
     * qu'aucune étape n'ait signalé d'échec.
     *
     * Sans `xml_url` il n'y a pas de recette à tirer : on compte et on trace plutôt
     * que de lancer un job condamné à passer l'application en `Error` — une app locale
     * ordonnée par le contrat ne doit pas être abîmée par un ordre qu'on ne sait pas
     * servir.
     */
    private function dispatchServerInstall(
        Application $application,
        OrderedApplicationProvisioningResult $result,
    ): void {
        if ($application->status === ApplicationStatus::Installed) {
            return;
        }

        if ($application->xml_url === null || $application->xml_url === '') {
            $result->installSkipped++;
            Log::warning('agent.applications.install_skipped', [
                'app_id' => $application->app_id,
                'reason' => 'no_xml_url',
            ]);

            return;
        }

        // La mise en file ne doit JAMAIS faire échouer la matérialisation : sur une
        // connexion `sync` le job s'exécute ici même, et un dépôt distant injoignable
        // remonterait alors comme un échec de provisionnement — l'ordre serait compté
        // perdu alors que la ligne d'inventaire, elle, est bien posée.
        try {
            InstallOrderedApplicationJob::dispatch($application->id);
            $result->installDispatched++;

            Log::info('agent.applications.install_dispatched', [
                'app_id' => $application->app_id,
                'application_id' => $application->id,
            ]);
        } catch (Throwable $e) {
            Log::error('agent.applications.install_dispatch_failed', [
                'app_id' => $application->app_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
