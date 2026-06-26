<?php

declare(strict_types=1);

namespace App\Services\ControlHub\Resolution;

use App\Enums\StateScope;
use App\Models\CapabilityProjection;
use App\Models\ControlHubContractItem;
use Illuminate\Support\Facades\Log;

/**
 * Story 28.3 — Adaptateur AMONT pour le type `registry` (exclusive PAR IDENTITÉ
 * DE CLÉ — {@see \App\Services\Agent\Contracts\KeyedExclusiveProvider}).
 *
 * Convention de `key` (bridge minimal, déféré Epic 33 pour un schéma figé) :
 * `key = "hive|path|name"` ou `key = "hive|path|name|REG_TYPE"` (séparateur `|`,
 * EXACTEMENT la forme de la clé d'exclusivité du provider registry
 * `strtolower("$hive|$path|$name")`). Cela garantit qu'un item amont entre en
 * concurrence sur la MÊME clé qu'un candidat registry local. `value` = la valeur
 * de registre, coercée selon le `REG_TYPE` (DWORD/QWORD → int, MULTI_SZ →
 * `list<string>`, défaut → string ; zéro float §4.1), STRICTEMENT iso
 * `AbstractCapabilityStateProvider::typedValue()` (mêmes arms, même `coerceMultiSz`)
 * pour un payload de forme identique aux candidats locaux.
 *
 * Routage de portée : `hive=HKLM` → portée MACHINE (service SYSTEM) ;
 * `hive=HKCU` → portée SESSION (compagnon). Le `providerType()` `registry`
 * couvre les DEUX providers ; la portée discrimine lequel reçoit le candidat
 * (routage d'enveloppe, PAS une précédence de maille — D2 intact).
 *
 * ⚠️ GARDE-FOU R3 : aucun « central ». Vocabulaire « amont » / `Upstream`.
 */
final class RegistryUpstreamAdapter implements UpstreamPayloadAdapter
{
    public function upstreamType(): string
    {
        return CapabilityProjection::MECHANISM_REGISTRY; // 'registry'
    }

    public function providerType(): string
    {
        return CapabilityProjection::MECHANISM_REGISTRY; // 'registry'
    }

    public function scopeFor(ControlHubContractItem $item): StateScope
    {
        return strcasecmp($this->parts($item->key)['hive'], CapabilityProjection::HIVE_MACHINE) === 0
            ? StateScope::Machine
            : StateScope::Session;
    }

    /**
     * @return array<string,mixed> `{hive, path, name, type, value}` (5 clés
     *                             concrètes, iso payload registry local)
     */
    public function toPayload(ControlHubContractItem $item): array
    {
        // Clé malformée (< 3 segments hive|path|name) : tracée pour l'opérateur
        // (anomalie de configuration amont) puis défauts sûrs (jamais d'exception
        // — discipline « ignorer proprement »). Le candidat reste injecté mais ne
        // peut écraser aucune clé locale légitime (path/name vides ≠ vraie clé).
        if (substr_count($item->key, '|') < 2) {
            Log::channel('agent')->warning('[RegistryUpstreamAdapter] clé amont malformée (attendu hive|path|name[|type])', [
                'action_type' => 'controlhub.upstream.registry.malformed_key',
                'contract_item_id' => $item->id,
                'key' => $item->key,
            ]);
        }

        $parts = $this->parts($item->key);
        $type = $parts['type'];

        return [
            'hive' => $parts['hive'],
            'path' => $parts['path'],
            'name' => $parts['name'],
            'type' => $type,
            'value' => $this->typedValue($type, (string) ($item->value ?? '')),
        ];
    }

    /**
     * Décompose la clé amont `hive|path|name[|type]`. Les segments manquants
     * tombent sur des défauts sûrs (hive vide → routé session par défaut, type
     * `REG_SZ`). Stable et déterministe (sert l'ETag 23.5).
     *
     * @return array{hive:string, path:string, name:string, type:string}
     */
    private function parts(string $key): array
    {
        $segments = explode('|', $key);

        return [
            'hive' => $segments[0] ?? '',
            'path' => $segments[1] ?? '',
            'name' => $segments[2] ?? '',
            'type' => $segments[3] ?? 'REG_SZ',
        ];
    }

    /**
     * Coercition par type de registre (zéro float §4.1), iso
     * `AbstractCapabilityStateProvider::typedValue()` pour un payload de forme
     * identique aux candidats locaux.
     */
    private function typedValue(string $type, string $raw): mixed
    {
        return match (strtoupper($type)) {
            'REG_DWORD', 'REG_QWORD' => (int) $raw,
            'REG_MULTI_SZ' => $this->coerceMultiSz($raw),
            default => $raw,
        };
    }

    /**
     * Coerce une valeur MULTI_SZ en liste de chaînes (zéro float), iso
     * `AbstractCapabilityStateProvider::coerceMultiSz()` : `value` amont est une
     * chaîne JSON array (`["a","b"]`) → `list<string>` ; tout le reste → liste
     * vide (défensif, jamais d'exception au render).
     *
     * @return list<string>
     */
    private function coerceMultiSz(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_map(static fn ($v): string => (string) $v, $decoded));
    }
}
