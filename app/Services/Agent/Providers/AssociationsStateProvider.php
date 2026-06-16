<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\FileAssociation;
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
 * Story 27.3bis — provider `associations` (associations de fichiers/protocoles
 * par défaut).
 *
 * UN type `associations` (contrat §7, identifiant figé
 * `FileAssociation::TYPE_ASSOCIATIONS`), UNE table catalogue `file_associations`,
 * UN seul provider serveur (`scope()=Session`) : l'association vit sous `HKCU`
 * (UserChoice de l'utilisateur connecté), appliquée par le COMPAGNON au logon —
 * pas de pendant machine (contrairement à `registry` qui a deux ruches).
 *
 * **Lecture Postgres PURE** (NFR7, critère Keycloak) : le provider lit le
 * catalogue `file_associations` × le pivot polymorphe `file_association_assignables`
 * (WorkstationGroup + Workstation + UserGroup + User), restreint aux ids déjà
 * résolus du {@see TargetContext}. JAMAIS l'AD / LdapRecord / APCu / `samba-tool`.
 * ⚠️ NE réutilise PAS la dépendance APCu/WPKG de `AssociationsResolver` (16.3c) :
 * le canal desired-state lit Postgres et lui seul.
 *
 * **Catalogue → items CONCRETS (invariant central).** Chaque association du
 * catalogue SE COMPILE en un payload `{identifier, progid, type}` concret. Le
 * `key`/`id` du catalogue ne fuite JAMAIS au payload — c'est CE qui garde l'option
 * « clés brutes » (v2) gratuite. **Aucun hash ni SID au payload** : le hash
 * UserChoice est calculé 100 % côté agent (piège n° 2).
 *
 * **Sémantique `exclusive` PAR IDENTIFIANT** (décision n° 4,
 * {@see KeyedExclusiveProvider}) : une extension/un protocole = UN programme par
 * défaut ; la maille la plus spécifique gagne POUR CET identifiant, les
 * identifiants distincts s'accumulent. Le provider rend des candidats BRUTS par
 * maille (discipline D2) : aucune précédence/tri/dédup ici — la sélection vit
 * dans le `StateCompiler` SEUL (qui consulte `exclusiveKey()`).
 *
 * Une association non assignée à aucune maille du poste **n'émet aucun item**
 * (contrat §8 : type/clé absent = non géré ; « désactiver » = cesser de gérer,
 * jamais un reset OFF — piège n° 5).
 */
final class AssociationsStateProvider implements KeyedExclusiveProvider, StateProvider
{
    public function type(): string
    {
        return FileAssociation::TYPE_ASSOCIATIONS;
    }

    public function semantics(): ResourceSemantics
    {
        return ResourceSemantics::Exclusive;
    }

    public function scope(): StateScope
    {
        // HKCU (UserChoice) appliqué par le compagnon au logon — D-Henri n°6.
        return StateScope::Session;
    }

    /**
     * Identité d'une association exclusive : l'`identifier` (extension/protocole).
     * Insensible à la casse (Windows l'est sur extensions/protocoles) → normalisé
     * en minuscules pour la STABILITÉ de la sélection. Déterministe (ETag 23.5).
     *
     * @param  array<string,mixed>  $payload
     */
    public function exclusiveKey(array $payload): string
    {
        return strtolower((string) ($payload['identifier'] ?? ''));
    }

    /**
     * Un candidat PAR (association active × assignation applicable au contexte).
     * Lecture pivot par maille ; chaque association COMPILÉE en item concret.
     * Candidats BRUTS (D2) — la précédence par identifiant est au compilateur.
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        $wgIds = $ctx->workstationGroupIds();

        $rows = FileAssociation::query()
            ->where('file_associations.is_active', true)
            ->join('file_association_assignables', 'file_associations.id', '=', 'file_association_assignables.file_association_id')
            ->where(function ($q) use ($ctx, $wgIds): void {
                if ($wgIds !== []) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('file_association_assignables.assignable_type', WorkstationGroup::class)
                        ->whereIn('file_association_assignables.assignable_id', $wgIds));
                }

                $q->orWhere(fn ($qq) => $qq
                    ->where('file_association_assignables.assignable_type', Workstation::class)
                    ->where('file_association_assignables.assignable_id', $ctx->workstation->id));

                if ($ctx->userGroupIds !== []) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('file_association_assignables.assignable_type', UserGroup::class)
                        ->whereIn('file_association_assignables.assignable_id', $ctx->userGroupIds));
                }

                if ($ctx->user !== null) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('file_association_assignables.assignable_type', User::class)
                        ->where('file_association_assignables.assignable_id', $ctx->user->id));
                }
            })
            ->get([
                'file_associations.id',
                'file_associations.identifier',
                'file_associations.assoc_type',
                'file_associations.progid',
                'file_associations.updated_at',
                'file_association_assignables.assignable_type',
                'file_association_assignables.assignable_id',
            ]);

        return $rows->map(fn (FileAssociation $row): StateCandidate => new StateCandidate(
            maille: $this->mailleFor($row, $ctx),
            payload: $this->payloadFor($row),
            updatedAt: $row->updated_at,
            sourceId: (int) $row->id,
        ));
    }

    /**
     * Compile une association de catalogue en item CONCRET `{identifier, progid,
     * type}` — JAMAIS d'`id`/`key` de catalogue, JAMAIS de hash/SID (invariant
     * central + piège n° 2). `type` ∈ `file`|`protocol` (cf. `assoc_type`).
     *
     * @return array<string,mixed>
     */
    private function payloadFor(FileAssociation $row): array
    {
        return [
            'identifier' => (string) $row->identifier,
            'progid' => (string) $row->progid,
            'type' => (string) $row->assoc_type,
        ];
    }

    /**
     * Étiquetage assignable → maille (D2 = compilateur applique la précédence).
     * La distinction physique/logique d'un WorkstationGroup se fait via les
     * listes du contexte (la requête a déjà restreint aux groupes du poste) —
     * étiquetage, pas précédence.
     */
    private function mailleFor(FileAssociation $row, TargetContext $ctx): StateMaille
    {
        return match ($row->assignable_type) {
            WorkstationGroup::class => in_array((int) $row->assignable_id, $ctx->physicalGroupIds, true)
                ? StateMaille::PhysicalGroup
                : StateMaille::LogicalGroup,
            Workstation::class => StateMaille::Workstation,
            UserGroup::class => StateMaille::UserGroup,
            User::class => StateMaille::User,
            // Inatteignable via itemsFor() (le WHERE ne ramène que ces types) —
            // garde-fou explicite (iso registry/shortcuts providers).
            default => throw new \LogicException(
                "assignable_type inattendu pour file_association #{$row->id} : {$row->assignable_type}",
            ),
        };
    }
}
