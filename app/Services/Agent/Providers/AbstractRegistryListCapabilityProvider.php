<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Models\CapabilityProjection;

/**
 * Story 35.2 — base COMMUNE des deux providers `registry_list` (contrat §7.6) :
 * listes registre à sous-valeurs indexées `\1..\N` (ExtensionInstallForcelist,
 * DisallowRun).
 *
 * Réutilise TOUTE la mécanique capacité de {@see AbstractCapabilityStateProvider}
 * (Broadcast + overrides par maille, résolution map/littéral, lecture Postgres
 * pure NFR7) en ne changeant que le MÉCANISME filtré ({@see mechanism()}) et
 * l'interpréteur de `spec` ({@see expand()}). En bi-projection D5, chaque
 * provider ne voit que SA projection (`itemsFor()` filtre par mécanisme).
 *
 * **Payload EXACTEMENT 4 clés** `{hive, path, entry_type, values}` (invariant
 * central 27.12 : jamais d'id/key de capacité) :
 *   - `entry_type ∈ REG_SZ | REG_EXPAND_SZ` (borné par le contrat — les listes
 *     indexées Windows sont des chaînes), défaut de `spec` : REG_SZ ;
 *   - `values` = liste ORDONNÉE de chaînes (cast défensif, zéro float §4.1).
 *     L'ordre est porteur de sens (la canonicalisation ne trie pas les listes).
 *
 * **Trois régimes par conteneur de `spec`** (parallèle registry, avec une
 * différence VOLONTAIRE) :
 *   1. **imposer la liste** — `values` résolu en liste (littéral OU map
 *      valeur-capacité → liste) → conteneur émis ;
 *   2. **purger** — liste VIDE `[]` (le « off » honnête d'une liste : supprimer
 *      toutes les entrées numérotées) → conteneur émis avec `values: []` ;
 *   3. **ne pas gérer** — sentinelle UNMANAGED (clé de map absente) → rien.
 * Le marqueur `$ensure` de 35.1 n'existe PAS en `registry_list` : toute forme
 * assoc inattendue résolue (dont `{"$ensure": …}`) ⇒ conteneur NON émis
 * (défensif, jamais d'exception au render) — l'idiome de suppression EST la
 * liste vide (piège n°5 de la story).
 *
 * **Sémantique `exclusive` PAR CLÉ-CONTENEUR (D2)** :
 * `exclusiveKey() = {hive|path}` minuscules (2 segments, PAS de `name`) — la
 * maille la plus spécifique gagne la clé-conteneur ENTIÈRE via la précédence
 * EXISTANTE du StateCompiler (INTOUCHÉ) : jamais d'union de listes entre
 * mailles. Incomparable avec l'exclusiveKey 3 segments de `registry` : une
 * collision scalaire↔conteneur sur le même `{hive|path}` est REFUSÉE en amont
 * par le garde-fou d'authoring ({@see CapabilitySpecCollisionGuard}).
 */
abstract class AbstractRegistryListCapabilityProvider extends AbstractCapabilityStateProvider
{
    /**
     * Types d'entrée admis par le contrat §7.6 (listes Windows = chaînes).
     * PUBLIC : partagé avec le garde-fou d'authoring
     * ({@see CapabilitySpecCollisionGuard}) — une seule source du borné.
     *
     * @var list<string>
     */
    public const ALLOWED_ENTRY_TYPES = ['REG_SZ', 'REG_EXPAND_SZ'];

    protected function mechanism(): string
    {
        return CapabilityProjection::MECHANISM_REGISTRY_LIST;
    }

    /**
     * Identité d'une clé-conteneur exclusive : `{hive|path}` (2 segments,
     * jamais de `name` — l'agent possède la clé ENTIÈRE, D3). Insensible à la
     * casse (Windows l'est) → minuscules pour la stabilité de la sélection.
     */
    public function exclusiveKey(array $payload): string
    {
        $hive = strtolower((string) ($payload['hive'] ?? ''));
        $path = strtolower((string) ($payload['path'] ?? ''));

        return $hive.'|'.$path;
    }

    /**
     * Interpréteur de `spec` du mécanisme `registry_list`. La projection porte
     * `spec = { "keys": [ {hive, path, entry_type, values}, … ] }`. Pour CHAQUE
     * conteneur dont la ruche correspond à CE provider, résout `values` pour la
     * valeur effective de capacité via {@see resolveKeyValue()} (réutilisé) :
     * littéral liste = toujours émis ; map → liste (clé absente ⇒ UNMANAGED ⇒
     * rien) ; toute résolution NON-liste (scalaire, assoc dont `$ensure`) ⇒
     * conteneur non émis (défensif). `entry_type` hors contrat ⇒ non émis.
     *
     * @return list<array<string,mixed>> un payload 4 clés par conteneur émis
     */
    protected function expand(CapabilityProjection $projection, string $capabilityValue): array
    {
        $spec = $projection->spec;
        $keys = is_array($spec) && isset($spec['keys']) && is_array($spec['keys'])
            ? $spec['keys']
            : [];

        $payloads = [];

        foreach ($keys as $key) {
            if (! is_array($key)) {
                continue;
            }

            $hive = (string) ($key['hive'] ?? '');
            // Filtre par ruche du provider (iso registry) : HKLM → provider
            // machine, HKCU → provider session.
            if (strcasecmp($hive, $this->hive()) !== 0) {
                continue;
            }

            // `entry_type` borné (piège n°14) : REG_SZ | REG_EXPAND_SZ, défaut
            // REG_SZ. Hors contrat ⇒ conteneur non émis (défensif au render ;
            // le garde-fou d'authoring refuse déjà en amont).
            $entryType = strtoupper((string) ($key['entry_type'] ?? 'REG_SZ'));
            if (! in_array($entryType, self::ALLOWED_ENTRY_TYPES, true)) {
                continue;
            }

            // Résolution map/littéral (D5, réutilisée telle quelle).
            $resolved = $this->resolveKeyValue($key['values'] ?? null, $capabilityValue);
            if ($resolved === self::UNMANAGED) {
                continue; // clé de map absente : cesser de gérer ce conteneur.
            }

            // Une liste (vide ADMISE = purge) est la SEULE forme émissible.
            // Scalaire / assoc inattendue (dont `$ensure`, non supporté en
            // registry_list) ⇒ conteneur non émis, jamais d'exception.
            if (! is_array($resolved) || ! array_is_list($resolved)) {
                continue;
            }

            $payloads[] = [
                'hive' => $hive,
                'path' => (string) ($key['path'] ?? ''),
                'entry_type' => $entryType,
                // Cast défensif list<string> (zéro float §4.1, strings only).
                'values' => array_values(array_map(
                    static fn ($v): string => (string) $v,
                    $resolved,
                )),
            ];
        }

        return $payloads;
    }
}
