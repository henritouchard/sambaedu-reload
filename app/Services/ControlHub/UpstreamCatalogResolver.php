<?php

declare(strict_types=1);

namespace App\Services\ControlHub;

use App\Models\ControlHubContract;

/**
 * Story 31.1 — Résolveur du CATALOGUE applicatif AMONT (source unique, mémoïsée).
 *
 * Le contrat amont reçu de controlHub porte un **catalogue applicatif faisant
 * autorité** ({@see \App\Models\ControlHubContractCatalogApp}, table
 * `controlhub_contract_catalog_apps`, livrée en 28.1). Ce service répond à UNE
 * question : « le canal d'install refnum est-il **borné** à ce catalogue, et si
 * oui, quelles apps sont autorisées ? ».
 *
 * **Correspondance de clé (confirmée par le code 28.1)** :
 * `controlhub_contract_catalog_apps.app_key` **==** `applications.app_id` (string).
 * Le bornage compare donc sur `Application.app_id`, JAMAIS sur l'`id` numérique
 * local (qui n'a pas de sens cross-instance — décision D2).
 *
 * **NFR3 — court-circuit (CRITIQUE)** : la résolution est MÉMOÏSÉE (singleton
 * par-requête, voir {@see \App\Providers\AgentServiceProvider}). S'il n'y a AUCUN
 * contrat actif, la table `controlhub_contract_catalog_apps` n'est JAMAIS
 * requêtée (exactement 1 requête « contrat actif ? » qui renvoie null via
 * {@see ControlHubContract::active()}) et toutes les méthodes répondent « jamais
 * borné » : la consultation et l'install restent BYTE-IDENTIQUES au standalone.
 * Même discipline que {@see \App\Services\ControlHub\Resolution\UpstreamContractSource::ensureResolved()}
 * et {@see \App\Services\ControlHub\UpstreamLockResolver::ensureResolved()}.
 *
 * **Décision D1 — catalogue vide = pas de bornage** : un contrat actif SANS
 * aucune `catalogApps` ne verrouille pas le refnum hors de toutes ses apps
 * (sémantique « l'autorité n'a pas (encore) défini de catalogue » ≠ « catalogue
 * autoritaire vide »). `isBounded()` exige donc un catalogue **non vide**.
 *
 * **Caveat long-running** : la mémoïsation par-conteneur est sûre en PHP-FPM (1
 * requête = 1 conteneur). Sous Octane / worker persistant, brancher l'invalidation
 * sur {@see \App\Events\ControlHubContractChanged} (même caveat documenté que
 * `UpstreamContractSource`).
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans ce service, ses messages ou ses
 *    commentaires. Vocabulaire imposé : « amont » / `Upstream` / `ControlHub*`.
 *    [Source: prd-contrat-manage-se5.md#R3]
 */
final class UpstreamCatalogResolver
{
    private bool $resolved = false;

    /**
     * `true` ssi un contrat amont `active` existe ET porte un catalogue non vide.
     * Reste `false` en standalone OU catalogue vide (court-circuit NFR3 / D1).
     */
    private bool $bounded = false;

    /**
     * Set des `app_id` autorisés (= `app_key` du contrat actif), indexé par
     * `app_id` pour un test d'appartenance O(1). Vide si `!bounded`.
     *
     * @var array<string, true>
     */
    private array $allowed = [];

    /**
     * Le canal d'install refnum est-il borné au catalogue amont ?
     *
     * `true` UNIQUEMENT si un contrat amont est `active` ET son catalogue est
     * **non vide** (≥ 1 `app_key`). `false` → aucun bornage (standalone OU
     * catalogue vide, cf. D1) : consultation/install strictement inchangées.
     */
    public function isBounded(): bool
    {
        $this->ensureResolved();

        return $this->bounded;
    }

    /**
     * Liste mémoïsée des `app_id` autorisés par le catalogue amont actif.
     * `[]` si `!isBounded()`.
     *
     * @return list<string>
     */
    public function allowedAppIds(): array
    {
        $this->ensureResolved();

        return array_keys($this->allowed);
    }

    /**
     * Le `app_id` donné est-il autorisé ? `true` si `!isBounded()` (pass-through
     * standalone / catalogue vide) OU si `$appId` figure dans le catalogue amont.
     */
    public function permits(string $appId): bool
    {
        $this->ensureResolved();

        if (! $this->bounded) {
            return true;
        }

        return isset($this->allowed[$appId]);
    }

    /**
     * Résout le contrat actif + son catalogue UNE fois (mémoïsé).
     *
     * Court-circuit NFR3 : sans contrat actif, on ne touche JAMAIS la table
     * `controlhub_contract_catalog_apps` (≤ 1 requête `controlhub_contracts`).
     * D1 : catalogue vide ⇒ pas de bornage (`bounded` reste `false`).
     */
    private function ensureResolved(): void
    {
        if ($this->resolved) {
            return;
        }
        $this->resolved = true;

        // Réutilise STRICTEMENT l'accesseur canonique du contrat actif (filtre
        // `link_state = active` — ne JAMAIS dupliquer ici). Standalone OU lien
        // rompu (`severed`) ⇒ null ⇒ court-circuit, zéro requête catalogue (NFR3).
        $contract = ControlHubContract::active();
        if ($contract === null) {
            return;
        }

        /** @var list<string> $appKeys */
        $appKeys = $contract->catalogApps()->pluck('app_key')->all();
        $appKeys = array_values(array_filter(
            array_map(static fn ($k): string => (string) $k, $appKeys),
            static fn (string $k): bool => $k !== '',
        ));

        // D1 : catalogue vide ⇒ pas de bornage (ne verrouille pas le refnum hors
        // de toutes ses apps).
        if ($appKeys === []) {
            return;
        }

        $this->bounded = true;
        foreach ($appKeys as $appKey) {
            $this->allowed[$appKey] = true;
        }
    }
}
