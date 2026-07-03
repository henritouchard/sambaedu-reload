<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\Agent\Contracts\KeyedExclusiveProvider;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Support\Collection;

/**
 * Story 27.12 — base COMMUNE des deux providers `registry` CAPABILITY-FIRST (D1/D2).
 *
 * Rewrite « capability-first » du registre (décision 2026-06-17) : la table
 * centrale d'authoring devient {@see Capability} (intention métier OS-agnostique),
 * le registre devient UNE PROJECTION ({@see CapabilityProjection}, mécanisme
 * `registry`). Ce provider EXPANSE une capacité → items de contrat CONCRETS
 * `{hive, path, name, type, value}` exactement comme l'ancien
 * l'ancien `AbstractRegistryStateProvider` (qu'il SUPERSEDE) — `StateCompiler`, contrat et
 * agent restent INCHANGÉS (D3).
 *
 * UN type `registry` (contrat §7, figé NFR12), UNE table d'authoring `capabilities`
 * × ses projections, MAIS deux providers serveur (D-Q2 27.3, conservé) :
 *   - {@see RegistryMachineCapabilityProvider} : ruche HKLM → `scope()=Machine` ;
 *   - {@see RegistryUserCapabilityProvider}    : ruche HKCU → `scope()=Session`.
 * Un `StateProvider` déclare UNE portée → un casier ; donc une ruche par provider.
 * Côté agent, c'est UN SEUL handler Go `registry` : la séparation est purement
 * serveur.
 *
 * **Lecture Postgres PURE** (NFR7, critère Keycloak) : le provider lit
 * `capabilities` actives (OS windows, projection registry) × le pivot polymorphe
 * `capability_assignments` (WorkstationGroup + Workstation + UserGroup + User),
 * restreint aux ids déjà résolus du {@see TargetContext}. JAMAIS l'AD /
 * LdapRecord / APCu / `samba-tool` (ciblage = relations Postgres uniquement).
 *
 * **Invariant central (piège n°1).** Ni `id`/`key` de capacité, ni de projection,
 * ne fuit au payload — l'item registry reste CONCRET : `{hive, path, name, type,
 * value}` (5 clés) pour une ÉCRITURE, `{hive, path, name, ensure: "absent"}`
 * (4 clés, Story 35.1) pour une SUPPRESSION. C'est CE qui garde « éditeur de
 * clés brutes » (v2) gratuit ET garantit que l'agent ne change pas.
 *
 * **Trois régimes par clé de `spec`** (Story 35.1) :
 *   1. **écrire** — valeur résolue scalaire/liste → item 5 clés (le provider
 *      n'émet JAMAIS `ensure: "present"` explicite : byte-identité des payloads
 *      existants, contrat additif D1) ;
 *   2. **supprimer** — marqueur réservé {@see self::SPEC_ENSURE}
 *      (`'off' => ['$ensure' => 'absent']` dans une map valeur-capacité) →
 *      item 4 clés `ensure: "absent"` (l'agent supprime la valeur nommée) ;
 *   3. **ne pas gérer** — sentinelle UNMANAGED (clé de map ABSENTE pour la
 *      valeur effective) → rien n'est émis.
 *
 * **Sémantique `exclusive` PAR IDENTITÉ DE CLÉ** ({@see KeyedExclusiveProvider}) :
 * une clé de registre = UNE valeur ; la maille la plus spécifique gagne pour CETTE
 * clé `{hive, path, name}`, les clés distinctes s'accumulent. Le provider rend des
 * candidats BRUTS par maille (discipline D2) : aucune précédence/tri/dédup ici — la
 * sélection vit dans le `StateCompiler` SEUL (qui consulte `exclusiveKey()`).
 *
 * **Broadcast (défaut diffusé) + OVERRIDE par maille** (D4) — remonté de 27.3ter au
 * niveau CAPACITÉ. Le provider émet DEUX sources de candidats BRUTS :
 *   1. **Broadcast** — pour chaque capacité applicable, valeur effective =
 *      `default_value` ; la `spec` est expansée filtrée par la ruche du provider →
 *      1 candidat Broadcast par clé ÉMISE (`sourceId` = capability.id) ;
 *   2. **Par maille** — pour chaque assignation applicable au contexte, valeur
 *      effective = `assignment.value ?? default_value` ; mêmes clés → candidats à
 *      la maille de l'assignable.
 * La précédence existante (`logique > physique > broadcast`) fait que l'override
 * par maille bat le défaut Broadcast pour cette clé — STATECOMPILER INCHANGÉ.
 * « Retirer » un override = supprimer la ligne d'assignation = le poste re-converge
 * vers le défaut Broadcast au cycle suivant (PAS « cesser de gérer »).
 *
 * **Bundle = une capacité → PLUSIEURS candidats** (piège n°3). Une capacité dont
 * la projection a N clés produit N candidats (un par clé), tous au même `sourceId`
 * (= `capability.id`). Deux capacités définissant la même clé → collision arbitrée
 * par la récence au compilateur (cas réel, testé).
 */
abstract class AbstractCapabilityStateProvider implements KeyedExclusiveProvider, StateProvider
{
    /**
     * Marqueur d'authoring RÉSERVÉ dans une map valeur-capacité de `spec`
     * (Story 35.1) : `'off' => ['$ensure' => 'absent']` (forme JSON seed :
     * `{"$ensure": "absent"}`) fait émettre un item de SUPPRESSION 4 clés
     * `{hive, path, name, ensure: "absent"}` au lieu d'une écriture. PUBLIC :
     * réutilisé par les seeds/retrofits (35.1) et les stories 35.2 / 35.5.
     */
    public const SPEC_ENSURE = '$ensure';

    /** Valeur du marqueur {@see self::SPEC_ENSURE} : suppression de la valeur nommée. */
    public const ENSURE_ABSENT = 'absent';

    public function type(): string
    {
        return CapabilityProjection::MECHANISM_REGISTRY;
    }

    public function semantics(): ResourceSemantics
    {
        return ResourceSemantics::Exclusive;
    }

    /**
     * Ruche gérée par CE provider (HKLM | HKCU) — filtre des clés de la `spec`.
     */
    abstract protected function hive(): string;

    /**
     * Identité d'une clé de registre exclusive : `{hive, path, name}`. Insensible
     * à la casse (Windows l'est sur les clés/valeurs) → normalisée en minuscules
     * pour la STABILITÉ de la sélection. Déterministe (ETag 23.5). Iso 27.3.
     */
    public function exclusiveKey(array $payload): string
    {
        $hive = strtolower((string) ($payload['hive'] ?? ''));
        $path = strtolower((string) ($payload['path'] ?? ''));
        $name = strtolower((string) ($payload['name'] ?? ''));

        return $hive.'|'.$path.'|'.$name;
    }

    /**
     * Candidats BRUTS (D2) du provider — DEUX sources (D4) :
     *   (1) un lot de candidats **Broadcast** par capacité applicable (valeur
     *       effective = `default_value`), une clé de la `spec` filtrée par ruche
     *       → un candidat ;
     *   (2) un lot par maille par **assignation applicable** au contexte (valeur
     *       effective = `assignment.value ?? default_value`).
     * Chaque capacité EXPANSÉE en items concrets via l'interpréteur de `spec`
     * (D5). La précédence par clé est au compilateur — le provider ne
     * trie/filtre/dédup RIEN.
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        // Capacités actives qui ont une projection registry pour Windows.
        // Eager-load la projection registry uniquement (filtre os/mécanisme).
        $capabilities = Capability::query()
            ->where('capabilities.is_active', true)
            ->whereHas('projections', function ($q): void {
                $q->where('os', Capability::OS_WINDOWS)
                    ->where('mechanism', CapabilityProjection::MECHANISM_REGISTRY);
            })
            ->with(['projections' => function ($q): void {
                $q->where('os', Capability::OS_WINDOWS)
                    ->where('mechanism', CapabilityProjection::MECHANISM_REGISTRY);
            }])
            ->get();

        if ($capabilities->isEmpty()) {
            return new Collection();
        }

        // Overrides applicables au contexte, indexés par capability_id → maille +
        // valeur (lecture Postgres pure, restreinte aux ids résolus du contexte).
        $overrides = $this->resolveOverrides($ctx, $capabilities->pluck('id')->all());

        /** @var Collection<int, StateCandidate> $candidates */
        $candidates = new Collection();

        foreach ($capabilities as $capability) {
            /** @var CapabilityProjection|null $projection */
            $projection = $capability->projections->first();
            if ($projection === null) {
                continue;
            }

            // ── Source 1 : DÉFAUT diffusé (Broadcast) ─────────────────────────
            // Valeur effective = default_value de la capacité. Ne passe PAS par
            // une maille d'assignable. Émis à TOUTE la flotte (D4).
            foreach ($this->expand($projection, (string) $capability->default_value) as $payload) {
                $candidates->push(new StateCandidate(
                    maille: StateMaille::Broadcast,
                    payload: $payload,
                    updatedAt: $capability->updated_at,
                    sourceId: (int) $capability->id,
                ));
            }

            // ── Source 2 : OVERRIDES par maille (pivot × contexte) ────────────
            // Valeur effective = assignment.value ?? default_value (D4). Une ligne
            // d'override par maille applicable → un lot de candidats à cette maille.
            foreach ($overrides[$capability->id] ?? [] as $override) {
                $effective = $override['value'] ?? (string) $capability->default_value;
                foreach ($this->expand($projection, (string) $effective) as $payload) {
                    $candidates->push(new StateCandidate(
                        maille: $override['maille'],
                        payload: $payload,
                        // Récence portée par l'assignation (override le plus récent
                        // gagne au sein d'une maille, iso compilateur).
                        updatedAt: $override['updated_at'] ?? $capability->updated_at,
                        sourceId: (int) $capability->id,
                        // Profondeur physique (hérédité) : le compilateur fait
                        // gagner l'enfant (profondeur faible) sur le parent au sein
                        // de la maille `physical_group`. `null` hors chaîne physique.
                        depth: $override['depth'],
                    ));
                }
            }
        }

        return $candidates->values();
    }

    /**
     * Interpréteur de `spec` (D5 — le cœur du modèle). La projection registry porte
     * `spec = { "keys": [ {hive, path, name, type, value}, … ] }`. Pour CHAQUE clé
     * dont la ruche correspond à CE provider, résout `value` pour la valeur
     * effective de capacité `$capabilityValue` :
     *   - **littéral** (scalaire OU liste = `array_is_list`) → toujours émis ;
     *   - **map** valeur-capacité → donnée (objet assoc, ex. `{"on":0,"off":1}`) →
     *     on cherche `$capabilityValue` ; **clé de map absente ⇒ clé NON émise**
     *     (= cesser de gérer cette clé pour cette valeur, piège n°5).
     * La valeur résolue peut porter le marqueur réservé {@see self::SPEC_ENSURE}
     * (`['$ensure' => 'absent']`, Story 35.1) → item de SUPPRESSION 4 clés
     * `{hive, path, name, ensure}` (ni `type` ni `value`) ; toute AUTRE forme
     * assoc inattendue ⇒ clé NON émise (défensif, jamais d'exception au render —
     * iso discipline UNMANAGED). Détection APRÈS `resolveKeyValue()` et AVANT
     * `typedValue()` (piège n°4 : la coercition écraserait le marqueur en 0/'').
     * Sinon, coercition finale par `type` (DWORD/QWORD→int, MULTI_SZ→liste de
     * chaînes, SZ/EXPAND_SZ→chaîne — zéro float §4.1). Le payload est CONCRET :
     * EXACTEMENT 5 clés pour une écriture, EXACTEMENT 4 pour une suppression
     * (invariant central).
     *
     * @return list<array<string,mixed>> un payload par clé émise
     */
    private function expand(CapabilityProjection $projection, string $capabilityValue): array
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
            // Filtre par ruche du provider : une clé HKLM n'est émise que par le
            // provider machine, une clé HKCU que par le provider session.
            if (strcasecmp($hive, $this->hive()) !== 0) {
                continue;
            }

            $type = (string) ($key['type'] ?? 'REG_SZ');

            // Résolution map/littéral (D5).
            $resolved = $this->resolveKeyValue($key['value'] ?? null, $capabilityValue);
            if ($resolved === self::UNMANAGED) {
                // Clé de map absente pour la valeur effective : cesser de gérer
                // cette clé (rien n'est émis).
                continue;
            }

            // Marqueur de SUPPRESSION (Story 35.1) — détecté APRÈS la résolution
            // et AVANT la coercition typedValue() (piège n°4). Une forme assoc
            // NON reconnue ⇒ clé non émise (défensif, pas d'exception au render).
            if (is_array($resolved) && ! array_is_list($resolved)) {
                if (($resolved[self::SPEC_ENSURE] ?? null) === self::ENSURE_ABSENT) {
                    // Item de suppression : EXACTEMENT 4 clés, ni type ni value.
                    $payloads[] = [
                        'hive' => $hive,
                        'path' => (string) ($key['path'] ?? ''),
                        'name' => (string) ($key['name'] ?? ''),
                        'ensure' => self::ENSURE_ABSENT,
                    ];
                }

                continue;
            }

            $payloads[] = [
                'hive' => $hive,
                'path' => (string) ($key['path'] ?? ''),
                'name' => (string) ($key['name'] ?? ''),
                'type' => $type,
                'value' => $this->typedValue($type, $resolved),
            ];
        }

        return $payloads;
    }

    /** Sentinelle « clé non émise » (distincte de toute valeur de registre réelle). */
    private const UNMANAGED = "\0__capability_unmanaged__\0";

    /**
     * Résout la `value` brute d'une clé de `spec` (D5) :
     *   - liste (`array_is_list`) ⇒ littéral MULTI_SZ → renvoyée telle quelle ;
     *   - scalaire ⇒ littéral → renvoyé tel quel ;
     *   - objet assoc ⇒ MAP valeur-capacité → donnée : on cherche
     *     `$capabilityValue` (clé string) ; absente ⇒ {@see self::UNMANAGED}.
     *
     * @param  mixed  $raw  la `value` de la clé telle qu'issue de la `spec`
     * @return mixed  valeur brute (avant coercition par type) ou self::UNMANAGED
     */
    private function resolveKeyValue(mixed $raw, string $capabilityValue): mixed
    {
        // Littéral liste (MULTI_SZ) — disambiguïsation map vs littéral via
        // array_is_list (piège n°5).
        if (is_array($raw)) {
            if (array_is_list($raw)) {
                return $raw; // littéral MULTI_SZ, toujours émis.
            }

            // Map valeur-capacité → donnée. Clés string (les valeurs de capacité
            // sont du texte : "on"/"off"/enum/scalaire).
            if (array_key_exists($capabilityValue, $raw)) {
                return $raw[$capabilityValue];
            }

            // Clé de map ABSENTE pour la valeur effective → cesser de gérer.
            return self::UNMANAGED;
        }

        // Scalaire littéral (toujours émis).
        return $raw;
    }

    /**
     * Convertit la valeur résolue vers le type JSON du contrat (zéro float §4.1).
     * DWORD/QWORD → entier ; MULTI_SZ → liste de chaînes ; SZ/EXPAND_SZ et
     * inconnus → chaîne. Accepte une valeur DÉJÀ typée (issue d'une map JSON :
     * `{"on": 0}` donne un int) ET une valeur texte (littéral de seed). Iso 27.3.
     *
     * @param  mixed  $raw  scalaire ou liste (jamais self::UNMANAGED ici)
     */
    private function typedValue(string $type, mixed $raw): mixed
    {
        return match (strtoupper($type)) {
            // SE5 tourne en PHP 64 bits (requis) : (int) couvre REG_DWORD (uint32)
            // ET REG_QWORD (uint64 < 2^63) sans troncature.
            'REG_DWORD', 'REG_QWORD' => is_array($raw) ? 0 : (int) $raw,
            'REG_MULTI_SZ' => $this->coerceMultiSz($raw),
            default => is_array($raw) ? '' : (string) $raw,
        };
    }

    /**
     * Coerce une valeur MULTI_SZ en liste de chaînes (zéro float). Accepte une
     * liste native (littéral de `spec`) ou une chaîne JSON array (compat valeur
     * texte). Tout le reste → liste vide (défensif, jamais d'exception au render).
     *
     * @return list<string>
     */
    private function coerceMultiSz(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_map(static fn ($v): string => (string) $v, $raw));
        }

        $decoded = json_decode((string) $raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_map(static fn ($v): string => (string) $v, $decoded));
    }

    /**
     * Overrides applicables au contexte, groupés par `capability_id` (lecture
     * Postgres pure restreinte aux ids résolus). Chaque entrée porte la maille
     * étiquetée (D2 = compilateur applique la précédence) + la valeur d'override
     * (null = repli sur le défaut) + l'updated_at du pivot (récence intra-maille)
     * + la profondeur physique (hérédité — `null` hors chaîne physique).
     *
     * **Hérédité physique (capacités uniquement).** Les WorkstationGroups ciblés
     * sont la chaîne physique ÉTENDUE aux ancêtres ({@see TargetContext::$physicalGroupDepths})
     * ∪ les parcs logiques DIRECTS. On élargit ICI, dans le provider de capacités,
     * et PAS via `workstationGroupIds()` (accesseur partagé par des providers qui
     * n'héritent pas — wallpaper/printers/shortcuts/associations/overlay/env).
     *
     * @param  list<int>  $capabilityIds
     * @return array<int, list<array{maille:StateMaille, value:?string, updated_at:\DateTimeInterface|null, depth:?int}>>
     */
    private function resolveOverrides(TargetContext $ctx, array $capabilityIds): array
    {
        if ($capabilityIds === []) {
            return [];
        }

        // Chaîne physique (salle directe + ancêtres) ∪ parcs logiques directs.
        $wgIds = array_values(array_unique(array_merge(
            array_map('intval', array_keys($ctx->physicalGroupDepths)),
            $ctx->logicalGroupIds,
        )));

        $rows = \Illuminate\Support\Facades\DB::table('capability_assignments')
            ->whereIn('capability_id', $capabilityIds)
            ->where(function ($q) use ($ctx, $wgIds): void {
                if ($wgIds !== []) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('assignable_type', WorkstationGroup::class)
                        ->whereIn('assignable_id', $wgIds));
                }

                $q->orWhere(fn ($qq) => $qq
                    ->where('assignable_type', Workstation::class)
                    ->where('assignable_id', $ctx->workstation->id));

                if ($ctx->userGroupIds !== []) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('assignable_type', UserGroup::class)
                        ->whereIn('assignable_id', $ctx->userGroupIds));
                }

                if ($ctx->user !== null) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('assignable_type', User::class)
                        ->where('assignable_id', $ctx->user->id));
                }
            })
            ->get([
                'capability_id',
                'assignable_type',
                'assignable_id',
                'value',
                'updated_at',
            ]);

        $out = [];
        foreach ($rows as $row) {
            $assignableType = (string) $row->assignable_type;
            $assignableId = (int) $row->assignable_id;
            $maille = $this->mailleFor($assignableType, $assignableId, $ctx);

            $out[(int) $row->capability_id][] = [
                'maille' => $maille,
                'value' => $row->value === null ? null : (string) $row->value,
                'updated_at' => $row->updated_at !== null
                    ? \Illuminate\Support\Carbon::parse($row->updated_at)
                    : null,
                // Profondeur physique : renseignée pour la maille `physical_group`
                // (chaîne salle directe → ancêtres), `null` partout ailleurs.
                'depth' => $maille === StateMaille::PhysicalGroup
                    ? ($ctx->physicalGroupDepths[$assignableId] ?? null)
                    : null,
            ];
        }

        return $out;
    }

    /**
     * Étiquetage assignable → maille (D2 = compilateur applique la précédence).
     * La distinction physique/logique d'un WorkstationGroup se fait via la chaîne
     * physique du contexte ({@see TargetContext::$physicalGroupDepths}, salle
     * directe + ancêtres) : un ancêtre n'est PAS dans `physicalGroupIds` (salles
     * directes) mais reste un groupe physique. Étiquetage, pas précédence. Iso 27.3.
     */
    private function mailleFor(string $assignableType, int $assignableId, TargetContext $ctx): StateMaille
    {
        return match ($assignableType) {
            WorkstationGroup::class => isset($ctx->physicalGroupDepths[$assignableId])
                ? StateMaille::PhysicalGroup
                : StateMaille::LogicalGroup,
            Workstation::class => StateMaille::Workstation,
            UserGroup::class => StateMaille::UserGroup,
            User::class => StateMaille::User,
            default => throw new \LogicException(
                "assignable_type inattendu pour capability_assignment : {$assignableType}",
            ),
        };
    }
}
