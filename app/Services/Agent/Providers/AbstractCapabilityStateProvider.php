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
 * ne fuit au payload — l'item registry reste `{hive, path, name, type, value}`
 * concrets. C'est CE qui garde « éditeur de clés brutes » (v2) gratuit ET garantit
 * que l'agent ne change pas.
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
     * Puis coercition finale par `type` (DWORD/QWORD→int, MULTI_SZ→liste de
     * chaînes, SZ/EXPAND_SZ→chaîne — zéro float §4.1). Le payload est CONCRET et
     * EXACTEMENT 5 clés (invariant central).
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
     * (null = repli sur le défaut) + l'updated_at du pivot (récence intra-maille).
     *
     * @param  list<int>  $capabilityIds
     * @return array<int, list<array{maille:StateMaille, value:?string, updated_at:\DateTimeInterface|null}>>
     */
    private function resolveOverrides(TargetContext $ctx, array $capabilityIds): array
    {
        if ($capabilityIds === []) {
            return [];
        }

        $wgIds = $ctx->workstationGroupIds();

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
            $out[(int) $row->capability_id][] = [
                'maille' => $this->mailleFor((string) $row->assignable_type, (int) $row->assignable_id, $ctx),
                'value' => $row->value === null ? null : (string) $row->value,
                'updated_at' => $row->updated_at !== null
                    ? \Illuminate\Support\Carbon::parse($row->updated_at)
                    : null,
            ];
        }

        return $out;
    }

    /**
     * Étiquetage assignable → maille (D2 = compilateur applique la précédence).
     * La distinction physique/logique d'un WorkstationGroup se fait via les listes
     * du contexte (la requête a déjà restreint aux groupes du poste) — étiquetage,
     * pas précédence. Iso 27.3.
     */
    private function mailleFor(string $assignableType, int $assignableId, TargetContext $ctx): StateMaille
    {
        return match ($assignableType) {
            WorkstationGroup::class => in_array($assignableId, $ctx->physicalGroupIds, true)
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
