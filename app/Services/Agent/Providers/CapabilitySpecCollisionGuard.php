<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Models\CapabilityProjection;

/**
 * Story 35.2 (AC3) — garde-fou d'AUTHORING des `spec` registre : refuse les
 * configurations que le compilateur ne peut PAS arbitrer au poste.
 *
 * **Pourquoi.** `registry` et `registry_list` ont des `exclusiveKey()`
 * INCOMPARABLES (`{hive|path|name}` vs `{hive|path}`) : une clé-conteneur
 * ciblée à la fois par un item scalaire ET un conteneur de liste produirait une
 * collision SILENCIEUSE au poste (deux propriétaires de la même clé, l'agent
 * list supprimant potentiellement la valeur du scalaire si son nom est
 * numérique). On refuse donc EN AMONT, à l'authoring.
 *
 * **Où.** L'authoring est catalogue-first (migrations/seeds — aucune UI de
 * création de spec n'existe) : ce service PUR (données en entrée, violations en
 * sortie, zéro requête/écriture) est exécuté par un invariant de
 * `CapabilitiesSchemaAndSeedTest` sur les données réellement seedées, et reste
 * réutilisable par un futur geste UI d'édition de spec.
 *
 * **Ce qu'il valide** (projections `windows` des mécanismes registry +
 * registry_list) :
 *   1. COLLISION scalaire↔conteneur : un `{hive|path}` normalisé (minuscules)
 *      de clé `registry` ÉGAL au `{hive|path}` d'un conteneur `registry_list`
 *      — quel que soit le `name` du scalaire (il vivrait DANS la clé possédée
 *      par l'agent). Un flag dans la clé PARENTE (`…\Explorer` name
 *      `DisallowRun`) vs le conteneur ENFANT (`…\Explorer\DisallowRun`) n'est
 *      PAS une collision (paths distincts — cas nominal `blocked_executables`).
 *   2. `entry_type` des conteneurs ∈ REG_SZ | REG_EXPAND_SZ (contrat §7.6).
 *   3. `values` bien formées : littéral = liste de scalaires ; map
 *      valeur-capacité = chaque entrée liste de scalaires (jamais `$ensure`
 *      ni forme assoc — non supportés en registry_list).
 */
final class CapabilitySpecCollisionGuard
{
    /**
     * Valide un ensemble de projections d'authoring.
     *
     * @param  list<array{capability:string, mechanism:string, spec:mixed}>  $projections
     *         une entrée par projection windows : `capability` = key lisible
     *         (messages d'erreur), `mechanism` = registry|registry_list,
     *         `spec` = la spec décodée (`{"keys": […]}`).
     * @return list<string> violations lisibles (vide = authoring valide)
     */
    public function violations(array $projections): array
    {
        $violations = [];

        // ── Index des conteneurs registry_list par identité {hive|path} ─────
        /** @var array<string, list<string>> $containers identité → capacités */
        $containers = [];
        foreach ($projections as $projection) {
            if (($projection['mechanism'] ?? null) !== CapabilityProjection::MECHANISM_REGISTRY_LIST) {
                continue;
            }
            foreach ($this->specKeys($projection['spec'] ?? null) as $key) {
                $identity = $this->identity($key);
                $containers[$identity][] = (string) $projection['capability'];

                // 2bis. hive/path requis (review 35.2 #3) : un conteneur vide
                // passerait l'authoring puis serait rejeté {status: error}
                // silencieux par parseRegistryListSpec côté agent — refus AMONT.
                if (trim((string) ($key['hive'] ?? '')) === '' || trim((string) ($key['path'] ?? '')) === '') {
                    $violations[] = sprintf(
                        "registry_list [%s] conteneur '%s' : hive et path sont requis (conteneur vide rejeté par l'agent).",
                        $projection['capability'],
                        $identity,
                    );
                }

                // 2. entry_type borné (défaut REG_SZ admis).
                $entryType = strtoupper((string) ($key['entry_type'] ?? 'REG_SZ'));
                if (! in_array($entryType, AbstractRegistryListCapabilityProvider::ALLOWED_ENTRY_TYPES, true)) {
                    $violations[] = sprintf(
                        "registry_list [%s] conteneur '%s' : entry_type '%s' hors contrat (REG_SZ|REG_EXPAND_SZ).",
                        $projection['capability'],
                        $identity,
                        $entryType,
                    );
                }

                // 3. values bien formées (littéral liste OU map → listes).
                foreach ($this->malformedValues($key['values'] ?? null) as $problem) {
                    $violations[] = sprintf(
                        "registry_list [%s] conteneur '%s' : %s",
                        $projection['capability'],
                        $identity,
                        $problem,
                    );
                }
            }
        }

        // ── 1. Collision scalaire↔conteneur (égalité STRICTE d'identité) ────
        foreach ($projections as $projection) {
            if (($projection['mechanism'] ?? null) !== CapabilityProjection::MECHANISM_REGISTRY) {
                continue;
            }
            foreach ($this->specKeys($projection['spec'] ?? null) as $key) {
                $identity = $this->identity($key);
                foreach ($containers[$identity] ?? [] as $listCapability) {
                    $violations[] = sprintf(
                        "Collision registre : la clé-conteneur '%s' est possédée par le registry_list de [%s] "
                        ."ET ciblée par une clé scalaire registry de [%s] (name '%s') — "
                        .'le compilateur ne peut pas arbitrer (exclusiveKey incomparables), authoring refusé.',
                        $identity,
                        $listCapability,
                        $projection['capability'],
                        (string) ($key['name'] ?? ''),
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Clés/conteneurs d'une `spec` (défensif : spec inattendue = liste vide).
     *
     * @return list<array<string,mixed>>
     */
    private function specKeys(mixed $spec): array
    {
        if (! is_array($spec) || ! isset($spec['keys']) || ! is_array($spec['keys'])) {
            return [];
        }

        return array_values(array_filter($spec['keys'], 'is_array'));
    }

    /** Identité normalisée `{hive|path}` minuscules (iso exclusiveKey list). */
    private function identity(array $key): string
    {
        return strtolower((string) ($key['hive'] ?? '')).'|'.strtolower((string) ($key['path'] ?? ''));
    }

    /**
     * Problèmes de forme d'un champ `values` de conteneur.
     *
     * @return list<string>
     */
    private function malformedValues(mixed $values): array
    {
        if (! is_array($values)) {
            return ["values doit être une liste ou une map valeur-capacité → liste (obtenu : ".get_debug_type($values).')'];
        }

        // Littéral liste (vide admise = purge) : chaque entrée scalaire.
        if (array_is_list($values)) {
            return $this->nonScalarEntries($values, 'littéral');
        }

        // Map valeur-capacité → liste : chaque valeur doit être une LISTE de
        // scalaires ($ensure / assoc non supportés en registry_list).
        $problems = [];
        foreach ($values as $capabilityValue => $list) {
            if (! is_array($list) || ! array_is_list($list)) {
                $problems[] = sprintf(
                    "la valeur de map '%s' doit être une liste (le marqueur \$ensure n'existe pas en registry_list — le off d'une liste est [])",
                    (string) $capabilityValue,
                );

                continue;
            }
            $problems = array_merge($problems, $this->nonScalarEntries($list, "map '".(string) $capabilityValue."'"));
        }

        return $problems;
    }

    /**
     * @param  list<mixed>  $list
     * @return list<string>
     */
    private function nonScalarEntries(array $list, string $context): array
    {
        $problems = [];
        foreach ($list as $i => $entry) {
            if (! is_scalar($entry)) {
                $problems[] = sprintf(
                    'entrée %d (%s) non scalaire : les entrées de liste sont des chaînes (zéro float, zéro structure)',
                    $i,
                    $context,
                );
            }
        }

        return $problems;
    }
}
