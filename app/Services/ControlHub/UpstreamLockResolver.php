<?php

declare(strict_types=1);

namespace App\Services\ControlHub;

use App\Enums\ControlHubContractTarget;
use App\Enums\ControlHubEnforcementState;
use App\Enums\ControlHubLinkState;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\ControlHubContract;
use Illuminate\Support\Collection;

/**
 * Story 29.2 — Résolution du VERROU d'écriture imposé par le contrat amont.
 *
 * Pendant côté ÉCRITURE de {@see \App\Services\ControlHub\Resolution\UpstreamContractSource}
 * (qui, lui, sert la résolution du compilé desired-state). Ce service répond à
 * UNE seule question : « tel item / telle capacité est-il VERROUILLÉ par le
 * contrat amont actif, donc non éditable localement ? ». Le compilé (28.3) fait
 * DÉJÀ gagner l'item amont `locked` ; 29.2 transforme ce « défait en silence » en
 * REFUS explicite à l'écriture (Gate + service + message).
 *
 * **Périmètre du verrou en 29.2 (strict)** : un item est verrouillant ssi
 *   - `type = registry` (exclusive-par-clé — sémantique de verrou nette ;
 *      les types aggregate type `shortcuts` sont HORS verrou 29.2, couture) ;
 *   - `enforcement_state = locked` UNIQUEMENT (`permissive` est surchargeable —
 *      Story 29.3 / FR4 — et `absent` n'impose rien : NE PAS les bloquer) ;
 *   - `target_type = instance` (le ciblage par `label` est différé Epic 30 :
 *      ignoré proprement, ni résolution ni plantage).
 * C'est un SOUS-ENSEMBLE strict de ce que lit `UpstreamContractSource` (qui
 * retient locked ET permissive). Le verrou est INSTANCE-WIDE : il se résout par
 * PRÉSENCE d'un item `locked` au contrat actif, indépendamment de l'utilisateur
 * et du parc (PAS une délégation par-salle — ne pas confondre avec la 29.1).
 *
 * **NFR3 — court-circuit (CRITIQUE)** : la résolution est MÉMOÏSÉE (singleton
 * par-requête, voir AgentServiceProvider). S'il n'y a AUCUN contrat actif, la
 * table `items` n'est JAMAIS requêtée (exactement 1 requête « contrat actif ? »
 * qui renvoie null) et toutes les méthodes répondent « jamais verrouillé » : le
 * comportement d'écriture des surfaces capacité reste BYTE-IDENTIQUE au standalone
 * 27.12. [Source: prd-contrat-manage-se5.md#NFR3 ; UpstreamContractSource L.30-37]
 *
 * **Identité de clé alignée à l'octet** : la clé d'un item `registry` est
 * `hive|path|name[|type]` ; un candidat registre local a la clé d'exclusivité
 * `strtolower(hive|path|name)` ({@see \App\Services\Agent\Providers\AbstractCapabilityStateProvider::exclusiveKey()}).
 * C'est la MÊME identité des deux côtés (c'est par elle que l'amont gagne au
 * compilé). Le verrou DOIT matcher exactement cette clé, sinon il « ne mord pas »
 * (faux négatif) ou mord trop large (faux positif). On réutilise donc l'algèbre
 * `RegistryUpstreamAdapter` (décomposition `hive|path|name`) et la normalisation
 * `strtolower` du provider — aucune 3ᵉ normalisation inventée.
 *
 * **`overrides_locked` (27.12) ≠ verrou amont (29.2)** : `overrides_locked` est un
 * gel LOCAL (l'admin SE5 gèle une capacité pour ses propres parcs). Ce service ne
 * lit JAMAIS ce flag ; les deux refus coexistent sur des axes distincts.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central ». Vocabulaire « amont » / `Upstream` /
 * `ControlHub*`. [Source: prd-contrat-manage-se5.md#R3]
 */
final class UpstreamLockResolver
{
    private bool $resolved = false;

    /**
     * Set des clés registre verrouillées amont, indexé par `exclusiveKey`
     * normalisée (`strtolower(hive|path|name)`). Vide si aucun contrat actif
     * (court-circuit NFR3) ou aucun item `locked`/`instance`/`registry`.
     *
     * @var array<string, true>
     */
    private array $lockedRegistryKeys = [];

    /**
     * Set des clés registre verrouillées amont (lecture mémoïsée).
     *
     * @return array<string, true>
     */
    public function lockedRegistryKeys(): array
    {
        $this->ensureResolved();

        return $this->lockedRegistryKeys;
    }

    /**
     * Primitive générique extensible (Epic 33) : la clé `$key` du type `$type`
     * est-elle verrouillée amont ? Seul le type `registry` (exclusive-par-clé)
     * est câblé en 29.2 ; tout autre type renvoie `false` (couture documentée,
     * jamais d'exception).
     */
    public function isLocked(string $type, string $key): bool
    {
        if ($type !== CapabilityProjection::MECHANISM_REGISTRY) {
            return false;
        }

        $this->ensureResolved();

        if ($this->lockedRegistryKeys === []) {
            return false;
        }

        return isset($this->lockedRegistryKeys[$this->normalizeItemKey($key)]);
    }

    /**
     * La capacité est-elle verrouillée amont ? `true` ssi AU MOINS UNE clé de ses
     * projections `registry` (`spec.keys[]`) appartient au set verrouillé. Toutes
     * les ruches sont considérées (HKLM ∪ HKCU) : un verrou sur n'importe quelle
     * clé de la capacité la rend non éditable.
     *
     * Court-circuit NFR3 : si le set verrouillé est vide (aucun contrat actif),
     * renvoie `false` SANS expanser les projections.
     *
     * Eager-load `projections` (filtre `mechanism = registry`) en amont pour
     * éviter le N+1 quand cette méthode est appelée en boucle (rendu UI).
     */
    public function isCapabilityLocked(Capability $capability): bool
    {
        $this->ensureResolved();

        if ($this->lockedRegistryKeys === []) {
            return false;
        }

        foreach ($this->registryProjections($capability) as $projection) {
            foreach ($this->specKeys($projection) as $key) {
                $exclusive = $this->exclusiveKey(
                    (string) ($key['hive'] ?? ''),
                    (string) ($key['path'] ?? ''),
                    (string) ($key['name'] ?? ''),
                );

                if (isset($this->lockedRegistryKeys[$exclusive])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Résout le contrat actif UNE fois (mémoïsé). Court-circuit NFR3 : sans
     * contrat actif, on ne touche JAMAIS la table `items` (≤ 1 requête).
     */
    private function ensureResolved(): void
    {
        if ($this->resolved) {
            return;
        }
        $this->resolved = true;

        $contract = ControlHubContract::query()
            ->where('link_state', ControlHubLinkState::Active->value)
            ->first();

        if ($contract === null) {
            return; // court-circuit : zéro clé, zéro requête items (NFR3).
        }

        $items = $contract->items()
            // Verrou 29.2 = registry exclusive-par-clé UNIQUEMENT.
            ->where('type', CapabilityProjection::MECHANISM_REGISTRY)
            // `locked` SEUL refuse : `permissive` (29.3/FR4) et `absent` restent
            // éditables — NE PAS sur-bloquer.
            ->where('enforcement_state', ControlHubEnforcementState::Locked->value)
            // Cible instance uniquement (label → Epic 30, ignoré proprement).
            ->where('target_type', ControlHubContractTarget::Instance->value)
            ->get(['key']);

        foreach ($items as $item) {
            $this->lockedRegistryKeys[$this->normalizeItemKey((string) $item->key)] = true;
        }
    }

    /**
     * Projections `registry` d'une capacité, sans N+1 : réutilise la relation déjà
     * chargée si présente (et la filtre par mécanisme), sinon une requête ciblée.
     *
     * @return iterable<CapabilityProjection>
     */
    private function registryProjections(Capability $capability): iterable
    {
        if ($capability->relationLoaded('projections')) {
            /** @var Collection<int, CapabilityProjection> $loaded */
            $loaded = $capability->projections;

            return $loaded->filter(
                static fn (CapabilityProjection $p): bool => $p->mechanism === CapabilityProjection::MECHANISM_REGISTRY,
            );
        }

        return $capability->projections()
            ->where('mechanism', CapabilityProjection::MECHANISM_REGISTRY)
            ->get();
    }

    /**
     * Clés concrètes d'une projection registry (`spec.keys[]`), défensif.
     *
     * @return list<array<string,mixed>>
     */
    private function specKeys(CapabilityProjection $projection): array
    {
        $spec = $projection->spec;
        $keys = is_array($spec) && isset($spec['keys']) && is_array($spec['keys'])
            ? $spec['keys']
            : [];

        return array_values(array_filter($keys, 'is_array'));
    }

    /**
     * Normalise une clé d'item amont `hive|path|name[|type]` en `exclusiveKey`
     * (`strtolower(hive|path|name)`), iso `RegistryUpstreamAdapter::parts()` (3
     * premiers segments) + `AbstractCapabilityStateProvider::exclusiveKey()`.
     */
    private function normalizeItemKey(string $key): string
    {
        $segments = explode('|', $key);

        return $this->exclusiveKey(
            $segments[0] ?? '',
            $segments[1] ?? '',
            $segments[2] ?? '',
        );
    }

    /**
     * Identité de clé registre EXCLUSIVE, alignée à l'octet sur
     * {@see \App\Services\Agent\Providers\AbstractCapabilityStateProvider::exclusiveKey()} :
     * `strtolower(hive).'|'.strtolower(path).'|'.strtolower(name)`.
     */
    private function exclusiveKey(string $hive, string $path, string $name): string
    {
        return strtolower($hive).'|'.strtolower($path).'|'.strtolower($name);
    }
}
