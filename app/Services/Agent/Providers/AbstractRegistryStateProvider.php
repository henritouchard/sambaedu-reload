<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Models\RegistrySetting;
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
 * Story 27.3 — base COMMUNE des deux providers `registry` (D-Q2).
 *
 * UN type `registry` (contrat §7, identifiant figé `RegistrySetting::TYPE_REGISTRY`),
 * UNE table catalogue `registry_settings`, MAIS deux providers serveur :
 *   - {@see RegistryMachineStateProvider} : ruche HKLM → `scope()=Machine` ;
 *   - {@see RegistryUserStateProvider}    : ruche HKCU → `scope()=Session`.
 * Un `StateProvider` déclare UNE portée et le compilateur route tous ses items
 * vers ce seul casier — donc une ruche par provider. Côté agent, c'est UN SEUL
 * handler Go `registry` (HKLM par le service SYSTEM, HKCU par le compagnon) : la
 * séparation est purement serveur.
 *
 * **Lecture Postgres PURE** (NFR7, critère Keycloak) : le provider lit le
 * catalogue `registry_settings` × le pivot polymorphe `registry_setting_assignables`
 * (WorkstationGroup + Workstation + UserGroup + User), restreint aux ids déjà
 * résolus du {@see TargetContext}. JAMAIS l'AD / LdapRecord / APCu /
 * `samba-tool` (ciblage = relations Postgres uniquement).
 *
 * **Catalogue → items CONCRETS (invariant central).** Chaque réglage du
 * catalogue SE COMPILE en un payload `{hive, path, name, type, value}` concret.
 * Le `key`/`id` du catalogue ne fuite JAMAIS au payload — c'est CE qui garde
 * l'option « éditeur de clés brutes » (v2) gratuite : une 2ᵉ source d'autoring
 * produira les MÊMES items → zéro changement d'agent/contrat/provider.
 *
 * **Sémantique `exclusive` PAR IDENTITÉ DE CLÉ** (décision n° 4,
 * {@see KeyedExclusiveProvider}) : une clé de registre = UNE valeur ; la maille
 * la plus spécifique gagne pour CETTE clé `{hive, path, name}`, les clés
 * distinctes s'accumulent. (`aggregate` est impossible : une clé ne peut pas
 * porter plusieurs valeurs.) Le provider rend des candidats BRUTS par maille
 * (discipline D2) : aucune précédence/tri/dédup ici — la sélection vit dans le
 * `StateCompiler` SEUL (qui consulte `exclusiveKey()`).
 *
 * **Story 27.3ter — VALEUR PAR DÉFAUT DIFFUSÉE (Broadcast) + OVERRIDE par parc.**
 * Le modèle 27.3 (« géré uniquement si assigné, sinon non géré ») est ABANDONNÉ.
 * Le provider émet désormais DEUX sources de candidats BRUTS :
 *   1. **Broadcast (rang 5)** — un candidat par réglage ACTIF de la ruche,
 *      `payload.value` = défaut catalogue (`registry_settings.value`), `maille`
 *      = {@see StateMaille::Broadcast}, `sourceId` = id du réglage. ⚠️ Ce
 *      candidat NE passe PAS par {@see mailleFor()} (pas d'assignable). Chaque
 *      clé active est donc gérée à sa valeur par défaut sur TOUTE la flotte (D1).
 *   2. **Par maille** — un candidat par assignation applicable au contexte
 *      (pivot × `TargetContext`), `payload.value` = OVERRIDE du pivot
 *      (`registry_setting_assignables.value`) **avec repli sur le défaut catalogue
 *      si null** (D2 ; override inerte si NULL).
 * La précédence existante (`logique > physique > broadcast`) fait que l'override
 * par maille bat le défaut Broadcast pour cette clé — STATECOMPILER INCHANGÉ.
 * « Retirer » un override = supprimer la ligne de pivot = le poste re-converge
 * vers le défaut Broadcast au cycle suivant (D3 ; PAS « cesser de gérer »).
 */
abstract class AbstractRegistryStateProvider implements KeyedExclusiveProvider, StateProvider
{
    public function type(): string
    {
        return RegistrySetting::TYPE_REGISTRY;
    }

    public function semantics(): ResourceSemantics
    {
        return ResourceSemantics::Exclusive;
    }

    /**
     * Ruche gérée par CE provider (HKLM | HKCU) — filtre du catalogue.
     */
    abstract protected function hive(): string;

    /**
     * Identité d'une clé de registre exclusive : `{hive, path, name}`. Insensible
     * à la casse (Windows l'est sur les clés/valeurs) → normalisée en minuscules
     * pour la STABILITÉ de la sélection (deux mailles assignant la même clé à des
     * casses différentes restent en concurrence). Déterministe (ETag 23.5).
     */
    public function exclusiveKey(array $payload): string
    {
        $hive = strtolower((string) ($payload['hive'] ?? ''));
        $path = strtolower((string) ($payload['path'] ?? ''));
        $name = strtolower((string) ($payload['name'] ?? ''));

        return $hive.'|'.$path.'|'.$name;
    }

    /**
     * Candidats BRUTS (D2) du provider — DEUX sources (Story 27.3ter) :
     *   (1) un candidat **Broadcast** par réglage ACTIF de la ruche (défaut
     *       catalogue, émis à TOUTE la flotte) ;
     *   (2) un candidat par maille par **assignation applicable** au contexte
     *       (override `pivot.value` avec repli sur le défaut catalogue si null).
     * Chaque réglage COMPILÉ en item concret. La précédence par clé est au
     * compilateur — le provider ne trie/filtre/dédup RIEN.
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        // ── Source 1 : DÉFAUT diffusé (Broadcast) ─────────────────────────────
        // Tous les réglages actifs de la ruche → candidat Broadcast au défaut
        // catalogue. Ne passe PAS par mailleFor() (pas d'assignable). C'est le
        // changement de comportement central de 27.3ter (D1) : une clé active
        // est gérée à sa valeur par défaut même SANS aucune assignation.
        $defaults = RegistrySetting::query()
            ->where('registry_settings.is_active', true)
            ->where('registry_settings.hive', $this->hive())
            ->get([
                'registry_settings.id',
                'registry_settings.hive',
                'registry_settings.path',
                'registry_settings.name',
                'registry_settings.type',
                'registry_settings.value',
                'registry_settings.updated_at',
            ]);

        /** @var Collection<int, StateCandidate> $candidates */
        $candidates = $defaults->map(fn (RegistrySetting $row): StateCandidate => new StateCandidate(
            maille: StateMaille::Broadcast,
            payload: $this->payloadFor($row),
            updatedAt: $row->updated_at,
            sourceId: (int) $row->id,
        ));

        // ── Source 2 : OVERRIDES par maille (pivot × contexte) ────────────────
        // Mêmes restrictions de ciblage qu'en 27.3, mais le payload.value devient
        // l'override du pivot (repli sur le défaut catalogue si null).
        $wgIds = $ctx->workstationGroupIds();

        $overrides = RegistrySetting::query()
            ->where('registry_settings.is_active', true)
            ->where('registry_settings.hive', $this->hive())
            ->join('registry_setting_assignables', 'registry_settings.id', '=', 'registry_setting_assignables.registry_setting_id')
            ->where(function ($q) use ($ctx, $wgIds): void {
                if ($wgIds !== []) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('registry_setting_assignables.assignable_type', WorkstationGroup::class)
                        ->whereIn('registry_setting_assignables.assignable_id', $wgIds));
                }

                $q->orWhere(fn ($qq) => $qq
                    ->where('registry_setting_assignables.assignable_type', Workstation::class)
                    ->where('registry_setting_assignables.assignable_id', $ctx->workstation->id));

                if ($ctx->userGroupIds !== []) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('registry_setting_assignables.assignable_type', UserGroup::class)
                        ->whereIn('registry_setting_assignables.assignable_id', $ctx->userGroupIds));
                }

                if ($ctx->user !== null) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('registry_setting_assignables.assignable_type', User::class)
                        ->where('registry_setting_assignables.assignable_id', $ctx->user->id));
                }
            })
            ->get([
                'registry_settings.id',
                'registry_settings.hive',
                'registry_settings.path',
                'registry_settings.name',
                'registry_settings.type',
                'registry_settings.value',
                'registry_settings.updated_at',
                'registry_setting_assignables.assignable_type',
                'registry_setting_assignables.assignable_id',
                // OVERRIDE de valeur du parc (null = repli sur le défaut catalogue).
                'registry_setting_assignables.value as override_value',
            ]);

        foreach ($overrides as $row) {
            $candidates->push(new StateCandidate(
                maille: $this->mailleFor($row, $ctx),
                // override_value sélectionné en alias : null => repli défaut.
                payload: $this->payloadFor($row, $row->override_value),
                updatedAt: $row->updated_at,
                sourceId: (int) $row->id,
            ));
        }

        return $candidates->values();
    }

    /**
     * Compile un réglage de catalogue en item CONCRET `{hive, path, name, type,
     * value}` — JAMAIS d'`id`/`key` de catalogue (invariant central). EXACTEMENT
     * 5 clés. La valeur typée du contrat (zéro float §4.1) : DWORD/QWORD en
     * entier, MULTI_SZ en liste, SZ/EXPAND_SZ en chaîne (la colonne `value` est
     * stockée en texte ; cf. migration pour la sérialisation).
     *
     * Story 27.3ter : `$override` (nullable) porte l'override de parc. `null` ⇒
     * repli sur le défaut catalogue (`$row->value`). JAMAIS de clé de catalogue
     * au payload.
     *
     * @return array<string,mixed>
     */
    private function payloadFor(RegistrySetting $row, ?string $override = null): array
    {
        $raw = $override ?? (string) $row->value;

        return [
            'hive' => (string) $row->hive,
            'path' => (string) $row->path,
            'name' => (string) $row->name,
            'type' => (string) $row->type,
            'value' => $this->typedValue((string) $row->type, $raw),
        ];
    }

    /**
     * Convertit la valeur stockée en texte vers le type JSON du contrat (zéro
     * float). DWORD/QWORD → entier ; MULTI_SZ → liste de chaînes (JSON array
     * décodé, fallback liste vide) ; SZ/EXPAND_SZ et inconnus → chaîne brute.
     */
    private function typedValue(string $type, string $raw): mixed
    {
        return match (strtoupper($type)) {
            // SE5 tourne en PHP 64 bits (requis) : (int) couvre [−2^63, 2^63−1],
            // donc REG_DWORD (uint32) ET REG_QWORD (uint64 < 2^63) sans troncature.
            'REG_DWORD', 'REG_QWORD' => (int) $raw,
            'REG_MULTI_SZ' => $this->decodeMultiSz($raw),
            default => $raw,
        };
    }

    /**
     * REG_MULTI_SZ stocké en JSON array de chaînes. Décodage défensif : tout ce
     * qui n'est pas une liste JSON valide → liste vide (jamais un float, jamais
     * une exception au render).
     *
     * @return list<string>
     */
    private function decodeMultiSz(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_map(static fn ($v): string => (string) $v, $decoded));
    }

    /**
     * Étiquetage assignable → maille (D2 = compilateur applique la précédence).
     * La distinction physique/logique d'un WorkstationGroup se fait via les
     * listes du contexte (la requête a déjà restreint aux groupes du poste) —
     * étiquetage, pas précédence.
     */
    private function mailleFor(RegistrySetting $row, TargetContext $ctx): StateMaille
    {
        return match ($row->assignable_type) {
            // Un WG a un `is_physical` booléen NON null → `physicalGroupIds` et
            // `logicalGroupIds` du contexte sont DISJOINTS (TargetContext::for).
            // Si (théorique, non garanti par contrainte) un id figurait dans les
            // deux, PhysicalGroup gagnerait — donc, depuis D-Q3, la maille la
            // MOINS spécifique. Cas non réalisable en pratique.
            WorkstationGroup::class => in_array((int) $row->assignable_id, $ctx->physicalGroupIds, true)
                ? StateMaille::PhysicalGroup
                : StateMaille::LogicalGroup,
            Workstation::class => StateMaille::Workstation,
            UserGroup::class => StateMaille::UserGroup,
            User::class => StateMaille::User,
            // Inatteignable via itemsFor() (le WHERE ne ramène que ces types) —
            // garde-fou explicite (iso ShortcutsStateProvider).
            default => throw new \LogicException(
                "assignable_type inattendu pour registry_setting #{$row->id} : {$row->assignable_type}",
            ),
        };
    }
}
