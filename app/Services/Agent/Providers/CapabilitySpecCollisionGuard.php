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
 *   4. BORNÉ DES RUCHES par mécanisme (Story 35.3) : `registry` ∈
 *      {HKLM, HKCU, HKU} ; `registry_list` ∈ {HKLM, HKCU} — un conteneur
 *      `hive: HKU` est une violation NOMMÉE (le fan-out d'une réconciliation
 *      de clé-conteneur multiplierait la propriété de clé par N ruches sans
 *      consommateur connu — hors scope 35.3, extension future si besoin réel).
 *   5. HINT `spec.refresh` (Story 43.2, D1/D2) — pour les DEUX mécanismes, si
 *      la RACINE du `spec` porte la clé `refresh` : (a) la valeur DOIT être
 *      une string du vocabulaire fermé
 *      {@see CapabilityProjection::REFRESH_HINTS} (rejette
 *      non-string, valeurs hors vocabulaire — variantes de casse type
 *      `SHELL_NOTIFY`, valeurs 41.x anticipées type `logoff`) ; (b) — règle
 *      5b — la spec doit porter AU MOINS une clé/conteneur `hive: HKCU` : un
 *      hint sans clé de portée session est INERTE (jamais recopié par
 *      {@see AbstractCapabilityStateProvider::withRefreshHint()},
 *      gaté par portée Session/MachineUser) — une erreur d'authoring, refusée
 *      en amont plutôt que silence. `spec.refresh` ABSENT est un no-op (champ
 *      optionnel, AC1) : aucune des deux sous-règles ne s'applique.
 *   6. MARQUEUR `writer` (Story 35.7, D3) — attribut OPTIONNEL posé **PAR
 *      CLÉ** de `spec.keys[]` (contrairement à `refresh`, posé par
 *      projection) des mécanismes registry + registry_list. Borné : (a) la
 *      valeur DOIT être `'system'` ({@see CapabilityProjection::WRITER_SYSTEM},
 *      enum fermé — toute autre valeur est une violation nommée) ; (b) la clé
 *      porteuse DOIT être `hive: HKCU` — `writer` sur HKLM/HKU est une
 *      violation nommée : le service SYSTEM y est DÉJÀ l'exécutant, le
 *      marqueur n'y a aucun sens. Sémantique : l'item marqué est appliqué par
 *      le service SYSTEM dans `HKU\<SID>` de la session du contexte (jamais
 *      par le compagnon — trees `HKCU\…\Policies\*`, dont
 *      `CurrentVersion\Policies`, en LECTURE SEULE pour l'utilisateur
 *      standard sur poste joint au domaine : leçon runtime
 *      `blocked_executables`, « Accès refusé » du compagnon). **Exclusion
 *      mutuelle refresh/writer** (piège n°6) : `refresh` n'est émis QUE sur
 *      les items appliqués par le compagnon — la garde structurelle vit dans
 *      `withRefreshHint()` (jamais de `refresh` sur un payload marqué) et le
 *      retrofit `2026_07_13_100000` retire le hint des projections
 *      re-routées ; pas de règle de guard supplémentaire (le spec peut
 *      légitimement mêler clés marquées et clés compagnon sous un même hint).
 *
 * **Sémantique `HKU` (Story 35.3, contrat §7.1).** Une clé `hive: 'HKU'` est
 * émise par le provider MACHINE seul et appliquée par le service SYSTEM à
 * « toutes les ruches utilisateur du poste + `.DEFAULT` » (fan-out agent, drift
 * agrégé) — PAS de ciblage par utilisateur sur cette ruche (structurel : le
 * service fetch son state sans `?user` ; le ciblage fin reste au Session/HKCU).
 * **Discipline double-clé** : la double-clé HKU + HKCU sur le même `{path|name}`
 * (ex. numlock : `.DEFAULT`/ruches via SYSTEM + session courante via compagnon)
 * est un cas NOMINAL, pas une violation — MAIS une capacité portant une clé HKU
 * ne doit pas être ciblée par utilisateur/groupe d'utilisateurs, et ses maps
 * HKU/HKCU jumelles doivent être VALEUR-CONSISTANTES : un override user-maille
 * divergeant de la valeur machine ferait se battre compagnon et SYSTEM
 * (réécriture croisée à chaque cycle, drift perpétuel des deux côtés). Règle
 * d'authoring documentaire — pas de garde-fou runtime au-delà de cette doc.
 */
final class CapabilitySpecCollisionGuard
{
    /**
     * Ruches admises par mécanisme (Story 35.3) : `HKU` n'existe qu'en
     * `registry` (fan-out scalaire) — refusé en `registry_list`.
     *
     * Convention d'authoring : forme COURTE exclusive (HKLM/HKCU/HKU). Le
     * binaire tolère aussi les alias longs (HKEY_LOCAL_MACHINE, …) mais le
     * catalogue n'en écrit jamais — un import amont devra normaliser vers la
     * forme courte avant d'entrer ici (review 35.3 #3).
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_HIVES = [
        CapabilityProjection::MECHANISM_REGISTRY => ['HKLM', 'HKCU', 'HKU'],
        CapabilityProjection::MECHANISM_REGISTRY_LIST => ['HKLM', 'HKCU'],
    ];

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

        // ── 5. spec.refresh (Story 43.2) : vocabulaire fermé + non-inertie ──
        foreach ($projections as $projection) {
            $mechanism = $projection['mechanism'] ?? null;
            if (! in_array($mechanism, [
                CapabilityProjection::MECHANISM_REGISTRY,
                CapabilityProjection::MECHANISM_REGISTRY_LIST,
            ], true)) {
                continue;
            }

            $spec = $projection['spec'] ?? null;
            if (! is_array($spec) || ! array_key_exists('refresh', $spec)) {
                continue; // champ optionnel ABSENT : rien à valider (AC1).
            }

            $refresh = $spec['refresh'];
            if (! is_string($refresh) || ! in_array($refresh, CapabilityProjection::REFRESH_HINTS, true)) {
                $violations[] = sprintf(
                    '%s [%s] : spec.refresh %s hors vocabulaire fermé (%s).',
                    $mechanism,
                    (string) ($projection['capability'] ?? ''),
                    is_string($refresh) ? "'{$refresh}'" : '('.get_debug_type($refresh).')',
                    implode('|', CapabilityProjection::REFRESH_HINTS),
                );

                continue; // valeur invalide : la règle 5b (HKCU) n'ajoute rien.
            }

            // 5b — un hint sans AUCUNE clé/conteneur hive=HKCU serait INERTE
            // (withRefreshHint() ne recopie qu'en portée Session/MachineUser,
            // donc jamais sur un item HKLM/HKU) : refus AMONT plutôt que silence.
            if (! $this->specHasHkcuKey($this->specKeys($spec))) {
                $violations[] = sprintf(
                    "%s [%s] : spec.refresh '%s' posé mais AUCUNE clé hive=HKCU dans la spec "
                    .'— hint INERTE (jamais recopié au payload), authoring refusé.',
                    $mechanism,
                    (string) ($projection['capability'] ?? ''),
                    $refresh,
                );
            }
        }

        // ── 6. Marqueur `writer` (Story 35.7) : enum fermé + HKCU-only ──────
        foreach ($projections as $projection) {
            $mechanism = $projection['mechanism'] ?? null;
            if (! in_array($mechanism, [
                CapabilityProjection::MECHANISM_REGISTRY,
                CapabilityProjection::MECHANISM_REGISTRY_LIST,
            ], true)) {
                continue;
            }

            foreach ($this->specKeys($projection['spec'] ?? null) as $key) {
                if (! array_key_exists('writer', $key)) {
                    continue; // champ optionnel ABSENT : rien à valider.
                }

                $writer = $key['writer'];
                $identity = $this->identity($key);

                // 6a. Enum FERMÉ : 'system' est la seule valeur publiée.
                if ($writer !== CapabilityProjection::WRITER_SYSTEM) {
                    $violations[] = sprintf(
                        "%s [%s] clé '%s' : writer %s hors enum fermé ('%s' est la seule valeur publiée).",
                        $mechanism,
                        (string) ($projection['capability'] ?? ''),
                        $identity,
                        is_string($writer) ? "'{$writer}'" : '('.get_debug_type($writer).')',
                        CapabilityProjection::WRITER_SYSTEM,
                    );

                    continue; // valeur invalide : la règle 6b (HKCU) n'ajoute rien.
                }

                // 6b. HKCU UNIQUEMENT : sur HKLM/HKU le service SYSTEM est
                // DÉJÀ l'exécutant — le marqueur n'y a aucun sens.
                $hive = strtoupper(trim((string) ($key['hive'] ?? '')));
                if ($hive !== strtoupper(CapabilityProjection::HIVE_USER)) {
                    $violations[] = sprintf(
                        "%s [%s] clé '%s' : writer 'system' sur la ruche '%s' — le service SYSTEM "
                        .'y est déjà l\'exécutant, marqueur admis UNIQUEMENT sur une clé hive=HKCU '
                        .'(application par-session HKU\<SID>, Story 35.7).',
                        $mechanism,
                        (string) ($projection['capability'] ?? ''),
                        $identity,
                        $hive,
                    );
                }
            }
        }

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

                // 4. Borné des ruches (Story 35.3) : HKU refusé en registry_list
                // (violation NOMMÉE — fan-out de clé-conteneur hors scope) ;
                // toute autre ruche inconnue refusée aussi. Ruche vide déjà
                // couverte par la violation « hive et path sont requis ».
                $hive = strtoupper(trim((string) ($key['hive'] ?? '')));
                if ($hive !== '' && ! in_array($hive, self::ALLOWED_HIVES[CapabilityProjection::MECHANISM_REGISTRY_LIST], true)) {
                    $violations[] = $hive === strtoupper(CapabilityProjection::HIVE_USERS)
                        ? sprintf(
                            "registry_list [%s] conteneur '%s' : ruche HKU non admise en registry_list "
                            .'(hors scope 35.3 — le fan-out multi-ruches d\'une clé-conteneur n\'a pas de '
                            .'consommateur connu ; extension future si besoin réel). Ruches admises : HKLM|HKCU.',
                            $projection['capability'],
                            $identity,
                        )
                        : sprintf(
                            "registry_list [%s] conteneur '%s' : ruche '%s' hors borné (HKLM|HKCU).",
                            $projection['capability'],
                            $identity,
                            $hive,
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

                // 4. Borné des ruches (Story 35.3) : une clé scalaire `registry`
                // n'admet que HKLM | HKCU | HKU — toute autre valeur (typo
                // 'HKX', ruche vide, HKCR non routé…) ne serait émise par AUCUN
                // provider (silence d'authoring) : refus AMONT.
                $hive = strtoupper(trim((string) ($key['hive'] ?? '')));
                if (! in_array($hive, self::ALLOWED_HIVES[CapabilityProjection::MECHANISM_REGISTRY], true)) {
                    $violations[] = sprintf(
                        "registry [%s] clé '%s' (name '%s') : ruche '%s' hors borné (HKLM|HKCU|HKU) — "
                        .'aucun provider ne l\'émettrait (clé silencieusement morte), authoring refusé.',
                        $projection['capability'],
                        $identity,
                        (string) ($key['name'] ?? ''),
                        $hive,
                    );
                }

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
     * Story 43.2 (règle 5b) — au moins une clé/conteneur `hive: HKCU` parmi les
     * clés d'une spec (générique registry/registry_list : les deux formes
     * portent un champ `hive`).
     *
     * @param  list<array<string,mixed>>  $keys
     */
    private function specHasHkcuKey(array $keys): bool
    {
        foreach ($keys as $key) {
            if (strcasecmp((string) ($key['hive'] ?? ''), CapabilityProjection::HIVE_USER) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Problèmes de forme d'un champ `values` de conteneur.
     *
     * @return list<string>
     */
    private function malformedValues(mixed $values): array
    {
        if (! is_array($values)) {
            return ['values doit être une liste ou une map valeur-capacité → liste (obtenu : '.get_debug_type($values).')'];
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
