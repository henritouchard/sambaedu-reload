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
 * Stories 29.2 + 29.4 — Résolution du statut AMONT d'une capacité (verrou + permissif).
 *
 * Ce service est l'UNIQUE lecteur read-only du statut amont d'une capacité. Il
 * répond à deux questions :
 *   1. (29.2) Cette capacité est-elle VERROUILLÉE amont (item `locked`/`instance`/
 *      `registry`) → non éditable localement ? Le compilé (28.3) fait DÉJÀ gagner
 *      l'item `locked` ; 29.2 transforme ce « défait en silence » en REFUS explicite
 *      à l'écriture (Gate + service + message).
 *   2. (29.4) Cette capacité est-elle IMPOSÉE-PERMISSIVE amont (item `permissive`/
 *      `instance`/`registry`) → surchargeable, mais avec un statut visible dans
 *      l'UI (badge « Imposé permissif — surchargeable ») ? La surcharge en
 *      elle-même était déjà permise depuis 29.2 (le gate `modify-capability` ne
 *      refuse que `locked`). 29.4 EXPOSE ce statut en READ-ONLY pour la lisibilité
 *      FR8. Aucun changement de moteur, aucun nouveau Gate, aucune écriture.
 *
 * Pendant côté ÉCRITURE de {@see \App\Services\ControlHub\Resolution\UpstreamContractSource}
 * (qui, lui, sert la résolution du compilé desired-state).
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
 * qui renvoie null) et toutes les méthodes répondent « jamais verrouillé/permissif » :
 * le comportement d'écriture et d'affichage des surfaces capacité reste BYTE-IDENTIQUE
 * au standalone 27.12 (aucun badge amont, aucun masquage). [Source: prd-contrat-manage-se5.md#NFR3]
 *
 * **Bucketing locked+permissive en ≤ 1 requête `items`** (29.4) : `ensureResolved()`
 * utilise un `whereIn([Locked, Permissive])` UNIQUE puis bucketise chaque item dans
 * `lockedRegistryKeys` ou `permissiveRegistryKeys` selon son `enforcement_state`.
 * La requête `items` reste atomique ; le court-circuit NFR3 (contrat actif `null`
 * → return early, zéro requête `items`) est PRÉSERVÉ.
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
     * Set des clés registre VERROUILLÉES amont (29.2), indexé par `exclusiveKey`
     * normalisée (`strtolower(hive|path|name)`). Vide si aucun contrat actif
     * (court-circuit NFR3) ou aucun item `locked`/`instance`/`registry`.
     *
     * @var array<string, true>
     */
    private array $lockedRegistryKeys = [];

    /**
     * Set des clés registre PERMISSIVES amont (29.4 — read-only), indexé par
     * `exclusiveKey` normalisée. Vide si aucun contrat actif (court-circuit NFR3)
     * ou aucun item `permissive`/`instance`/`registry`. Bucketisé dans la MÊME
     * requête `items` que `lockedRegistryKeys` (≤ 1 requête `items` au total).
     *
     * @var array<string, true>
     */
    private array $permissiveRegistryKeys = [];

    /**
     * Story 29.4 — Indicateur mémoïsé : un contrat `active` existe-t-il ?
     * Positionné à `true` par `ensureResolved()` si et seulement si un contrat
     * `active` est trouvé. Reste `false` en standalone (court-circuit NFR3).
     * Aucune requête supplémentaire (réutilise la résolution de `ensureResolved()`).
     */
    private bool $activeContract = false;

    /**
     * Story 39.2 (review #4) — Catalogue mémoïsé des capacités portant AU MOINS une
     * projection `registry` (avec leurs projections `registry` eager-loaded). Chargé
     * paresseusement une seule fois par instance (le resolver est un singleton
     * par-requête), pour que `capabilitiesForRegistryKey()` — appelée une fois par
     * item `permissive`/`registry`/`instance` du rapport (jusqu'à `max:5000` items) —
     * filtre en mémoire au lieu de re-scanner le catalogue à CHAQUE appel.
     *
     * @var Collection<int, Capability>|null
     */
    private ?Collection $registryCapabilitiesCatalog = null;

    /**
     * Set des clés registre VERROUILLÉES amont (lecture mémoïsée).
     *
     * @return array<string, true>
     */
    public function lockedRegistryKeys(): array
    {
        $this->ensureResolved();

        return $this->lockedRegistryKeys;
    }

    /**
     * Story 29.4 — Un contrat amont `active` est-il présent ? Mémoïsé (singleton
     * par-requête, voir AgentServiceProvider). Aucune requête supplémentaire :
     * réutilise la résolution de `ensureResolved()` (1 requête `contracts` au plus).
     *
     * Permet de **gater l'affichage des badges** de statut amont dans les partials
     * UI : si aucun contrat n'est actif (standalone ou `severed`), AUCUN badge n'est
     * rendu — l'UI est byte-identique à 27.12/27.17 (NFR3).
     *
     * ⚠️ GARDE-FOU R3 : aucun mot « central ». [Source: prd-contrat-manage-se5.md#R3]
     */
    public function hasActiveContract(): bool
    {
        $this->ensureResolved();

        return $this->activeContract;
    }

    /**
     * Set des clés registre PERMISSIVES amont (lecture mémoïsée — 29.4, read-only).
     *
     * @return array<string, true>
     */
    public function permissiveRegistryKeys(): array
    {
        $this->ensureResolved();

        return $this->permissiveRegistryKeys;
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
     * Story 29.4 — La capacité est-elle imposée PERMISSIVE amont ? `true` ssi AU
     * MOINS UNE clé de ses projections `registry` appartient au set permissif
     * (item `permissive`/`instance`/`registry` du contrat actif).
     *
     * **Miroir exact de `isCapabilityLocked()`** : mêmes helpers, mêmes gardes N+1
     * et court-circuit NFR3. Un item `permissive` est un PLANCHER (rang le MOINS
     * spécifique, battu par toute maille locale — Broadcast inclus) : il rend la
     * capacité SURCHARGEABLE, jamais bloquée. Le badge UI dit donc la RELAXABILITÉ
     * (« votre override s'applique »), pas « la valeur amont sera servie en
     * baseline » (faux pour une capacité à défaut diffusé). [Source: 29-3 ANGLE
     * MORT ; project_permissive_floor_least_specific]
     *
     * ⚠️ Ne confondre PAS avec `overrides_locked` (27.12 — gel LOCAL, distinct).
     *
     * ⚠️ GARDE-FOU R3 : aucun mot « central ». [Source: prd-contrat-manage-se5.md#R3]
     */
    public function isCapabilityPermissive(Capability $capability): bool
    {
        $this->ensureResolved();

        if ($this->permissiveRegistryKeys === []) {
            return false;
        }

        foreach ($this->registryProjections($capability) as $projection) {
            foreach ($this->specKeys($projection) as $key) {
                $exclusive = $this->exclusiveKey(
                    (string) ($key['hive'] ?? ''),
                    (string) ($key['path'] ?? ''),
                    (string) ($key['name'] ?? ''),
                );

                if (isset($this->permissiveRegistryKeys[$exclusive])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Story 29.4 — Statut amont unifié d'une capacité : `'locked'` / `'permissive'`
     * / `'local'`. Précédence **verrouillé > permissif > local** (AC #4).
     *
     * Un seul appel par capacité au rendu (évite deux appels à `isCapabilityLocked`
     * + `isCapabilityPermissive` distincts et garantit la précédence en un seul
     * endroit). Le statut `'local'` = absence de toute contrainte amont, pas le gel
     * local `overrides_locked` (27.12) — ne pas confondre.
     *
     * ⚠️ GARDE-FOU R3 : aucun mot « central ». [Source: prd-contrat-manage-se5.md#R3]
     */
    public function capabilityUpstreamStatus(Capability $capability): string
    {
        // Précédence : verrouillé > permissif > local (AC #4).
        if ($this->isCapabilityLocked($capability)) {
            return 'locked';
        }

        if ($this->isCapabilityPermissive($capability)) {
            return 'permissive';
        }

        return 'local';
    }

    /**
     * Résout le contrat actif UNE fois (mémoïsé). Court-circuit NFR3 : sans
     * contrat actif, on ne touche JAMAIS la table `items` (≤ 1 requête).
     *
     * Story 29.4 — bucketing `locked`+`permissive` en une seule requête `items`
     * (`whereIn([Locked, Permissive])` + `get(['key', 'enforcement_state'])`).
     * Garantit ≤ 1 requête `items` au total ET préserve le court-circuit NFR3.
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

        // Story 29.4 — contrat actif trouvé : positionner le flag pour hasActiveContract().
        $this->activeContract = true;

        // Story 29.4 : bucketing `locked`+`permissive` en UNE requête items.
        // `absent` est exclu (n'impose rien). `target_type = label` est ignoré
        // proprement (Epic 30). Type `registry` uniquement (exclusive-par-clé).
        $items = $contract->items()
            ->where('type', CapabilityProjection::MECHANISM_REGISTRY)
            ->whereIn('enforcement_state', [
                ControlHubEnforcementState::Locked->value,
                ControlHubEnforcementState::Permissive->value,
            ])
            ->where('target_type', ControlHubContractTarget::Instance->value)
            ->get(['key', 'enforcement_state']);

        foreach ($items as $item) {
            $normalized = $this->normalizeItemKey((string) $item->key);
            // Normaliser l'enforcement_state qu'il soit casté en enum ou en string.
            $rawState = $item->enforcement_state instanceof ControlHubEnforcementState
                ? $item->enforcement_state->value
                : (string) $item->enforcement_state;

            if ($rawState === ControlHubEnforcementState::Locked->value) {
                $this->lockedRegistryKeys[$normalized] = true;
            } else {
                // Permissive (by the whereIn filter above — only Locked or Permissive).
                $this->permissiveRegistryKeys[$normalized] = true;
            }
        }
    }

    /**
     * Story 39.2 — Capacités dont AU MOINS UNE projection `registry` matche la clé
     * d'item amont `$key` (`hive|path|name[|type]`), par l'identité EXCLUSIVE
     * normalisée. Réutilise la MÊME normalisation que le verrou/permissif
     * (`normalizeItemKey()` + `exclusiveKey()`) — AUCUNE 3ᵉ normalisation inventée
     * (garde-fou du fichier). Lecture pure : indépendante de la résolution du
     * contrat actif (`ensureResolved()` non appelé), utilisable pour qualifier un
     * override local sur la clé d'un item de contrat lors de l'émission de
     * conformité.
     *
     * @return Collection<int, Capability>
     */
    public function capabilitiesForRegistryKey(string $key): Collection
    {
        $normalized = $this->normalizeItemKey($key);

        // Review 39.2 #4 — scan du catalogue mémoïsé (1× par instance) au lieu d'une
        // requête complète par appel. Le filtre par clé, lui, reste par appel.
        $this->registryCapabilitiesCatalog ??= Capability::query()
            ->whereHas('projections', static fn ($q) => $q->where('mechanism', CapabilityProjection::MECHANISM_REGISTRY))
            ->with(['projections' => static fn ($q) => $q->where('mechanism', CapabilityProjection::MECHANISM_REGISTRY)])
            ->get();

        return $this->registryCapabilitiesCatalog
            ->filter(function (Capability $capability) use ($normalized): bool {
                foreach ($this->registryProjections($capability) as $projection) {
                    foreach ($this->specKeys($projection) as $specKey) {
                        $exclusive = $this->exclusiveKey(
                            (string) ($specKey['hive'] ?? ''),
                            (string) ($specKey['path'] ?? ''),
                            (string) ($specKey['name'] ?? ''),
                        );

                        if ($exclusive === $normalized) {
                            return true;
                        }
                    }
                }

                return false;
            })
            ->values();
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
