<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\FolderAccessRule;
use App\Models\WorkstationGroup;
use App\Services\Agent\Contracts\KeyedExclusiveProvider;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Story 36.4 (D1) — Provider `fs_acl` **BI-ALIMENTÉ** : capacités (36.1) ET
 * règles d'accès aux dossiers (36.4), dans UN SEUL provider compilé.
 *
 * **Pourquoi UN seul provider (piège #1 — condition structurelle de l'AC).**
 * `StateCompiler::compileProvider()` appelle `itemsFor()` puis `selectExclusive()`
 * provider PAR provider, sans arbitrage croisé. Enregistrer un second provider
 * `fs_acl` à côté de {@see FsAclCapabilityProvider} produirait DEUX items de même
 * identité `{path|trustee|ace_type}` au state pour une collision règle↔capacité
 * (le dédup du handler Go trancherait par ordre trié — aveugle à la maille). La
 * condition de l'AC d'epic « collision arbitrée par le compilateur (maille/récence)
 * » est donc que les deux flux de candidats passent par UNE SEULE sélection
 * exclusive → COMPOSITION : ce provider enveloppe {@see FsAclCapabilityProvider}
 * (`final`) et unionne ses candidats aux candidats-règles. `StateCompiler` reste
 * INTOUCHÉ (garde-fou D2 d'epic).
 *
 * **Délégation d'identité.** `type()`/`semantics()`/`scope()` et surtout
 * `exclusiveKey()` sont DÉLÉGUÉS au provider capacités — l'identité
 * `{path|trustee|ace_type}` est définie à UN endroit. Deux candidats (règle ET
 * capacité) de même identité sont donc en concurrence (la maille la plus
 * spécifique/la plus récente gagne, `selectExclusive()` EXISTANT) ; les identités
 * distinctes coexistent (cumul, doctrine 36.1 piège #2).
 *
 * **Byte-identité golden (piège #5).** Sans AUCUNE règle en base,
 * `ruleCandidates()` renvoie vide ⇒ `concat([])` rend EXACTEMENT les candidats
 * capacités, même ordre ⇒ `FROZEN_STATE_HASH` INCHANGÉ.
 *
 * **Portée MACHINE (piège #7).** Le service SYSTEM fetch SANS `?user`
 * (`TargetContext::for($ws, null)`) et les règles doivent SORTIR : on n'utilise
 * QUE la chaîne parc du contexte (`physicalGroupDepths` étendue aux ancêtres ∪
 * `logicalGroupIds`) — JAMAIS l'inverse (early-return sur user null de
 * `DrivesStateProvider`). Un pivot User/UserGroup est impossible par construction
 * (types validés — {@see FolderAccessRule::ALLOWED_ASSIGNABLE_TYPES}).
 *
 * **Postgres pur (NFR7, critère Keycloak).** Zéro AD/LdapRecord/APCu : lecture du
 * pivot restreinte aux ids parc du contexte, trustee dérivé par jointure
 * `user_groups` (résolution SID côté POSTE, LSA).
 */
final class FolderAccessRulesStateProvider implements KeyedExclusiveProvider, StateProvider
{
    /**
     * Offset de `sourceId` des candidats-règles pour rester INJECTIF dans le pool
     * composé avec les candidats capacités (`sourceId = capability.id`, petits) —
     * `resolveExclusiveWinner()` départage en DERNIER recours par `sourceId` desc
     * (piège #6, iso discipline `DrivesStateProvider` « 2 + pivot_id »). La récence
     * réelle (`updatedAt` non null des deux côtés) tranche AVANT ce tiebreak dans
     * tous les cas réalistes.
     */
    public const RULE_SOURCE_ID_OFFSET = 1_000_000;

    public function __construct(
        private readonly FsAclCapabilityProvider $capabilities,
    ) {}

    public function type(): string
    {
        return $this->capabilities->type();
    }

    public function semantics(): ResourceSemantics
    {
        return $this->capabilities->semantics();
    }

    public function scope(): StateScope
    {
        return $this->capabilities->scope();
    }

    /**
     * Identité DÉLÉGUÉE au provider capacités : `{path|trustee|ace_type}` définie
     * à UN endroit (36.1). C'est la condition de l'arbitrage règle↔capacité.
     */
    public function exclusiveKey(array $payload): string
    {
        return $this->capabilities->exclusiveKey($payload);
    }

    /**
     * `candidats_capacités ∪ candidats_règles` (bruts, sans arbitrage — D2). Sans
     * règle en base, byte-identique aux candidats capacités (piège #5).
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        return $this->capabilities->itemsFor($ctx)->concat($this->ruleCandidates($ctx));
    }

    /**
     * Candidats des règles applicables au contexte — un candidat par (règle ×
     * assignation parc matchante), étiqueté de sa maille. Lecture Postgres PURE :
     * pivot restreint à la chaîne parc du contexte (`physicalGroupDepths` étendue
     * aux ancêtres — pour l'héritage salle-enfant→bâtiment-parent, piège #8 — ∪
     * `logicalGroupIds`).
     *
     * @return Collection<int, StateCandidate>
     */
    private function ruleCandidates(TargetContext $ctx): Collection
    {
        // Chaîne physique (salle directe + ancêtres) ∪ parcs logiques directs —
        // même ensemble que la résolution d'overrides des capacités (héritage
        // physique cohérent avec le flux avec lequel les règles sont arbitrées).
        $wgIds = array_values(array_unique(array_merge(
            array_map('intval', array_keys($ctx->physicalGroupDepths)),
            $ctx->logicalGroupIds,
        )));

        if ($wgIds === []) {
            return new Collection();
        }

        $rows = DB::table('folder_access_rule_assignables as fra')
            ->join('folder_access_rules as r', 'r.id', '=', 'fra.folder_access_rule_id')
            ->leftJoin('user_groups as ug', 'ug.id', '=', 'r.user_group_id')
            ->where('fra.assignable_type', WorkstationGroup::class)
            ->whereIn('fra.assignable_id', $wgIds)
            ->orderBy('r.id')
            ->orderBy('fra.id')
            ->get([
                'r.id as rule_id',
                'r.path as path',
                'r.ace_type as ace_type',
                'r.rights as rights',
                'r.applies_to as applies_to',
                'r.is_active as is_active',
                'r.updated_at as rule_updated_at',
                'ug.ad_dn as ug_ad_dn',
                'ug.name as ug_name',
                'fra.id as pivot_id',
                'fra.assignable_id as wg_id',
                'fra.updated_at as pivot_updated_at',
            ]);

        $candidates = new Collection();

        foreach ($rows as $row) {
            $trustee = FolderAccessRule::deriveTrustee(
                $row->ug_ad_dn !== null ? (string) $row->ug_ad_dn : null,
                (string) ($row->ug_name ?? ''),
            );
            if (trim($trustee) === '') {
                // Groupe orphelin / sans nom : rien à émettre (défensif — jamais
                // de payload à trustee vide). L'agent tomberait en erreur d'item.
                continue;
            }

            $wgId = (int) $row->wg_id;
            $isPhysical = array_key_exists($wgId, $ctx->physicalGroupDepths);

            $candidates->push(new StateCandidate(
                maille: $isPhysical ? StateMaille::PhysicalGroup : StateMaille::LogicalGroup,
                payload: [
                    'path' => (string) $row->path,
                    'trustee' => $trustee,
                    'ace_type' => (string) $row->ace_type,
                    'rights' => (string) $row->rights,
                    'applies_to' => (string) $row->applies_to,
                    // Off réel (D3) : règle inactive ⇒ retrait honnête `absent`.
                    'ensure' => ((bool) $row->is_active) ? 'present' : 'absent',
                ],
                // Récence D10 : max(rule.updated_at, pivot.updated_at) — modifier la
                // règle OU son assignation la « rafraîchit » face à un override de
                // capacité de même identité.
                updatedAt: $this->maxDate($row->rule_updated_at, $row->pivot_updated_at),
                sourceId: self::RULE_SOURCE_ID_OFFSET + (int) $row->pivot_id,
                // Profondeur physique (hérédité) : renseignée pour la maille
                // `physical_group` (salle directe → ancêtres), `null` en logique.
                depth: $isPhysical ? ($ctx->physicalGroupDepths[$wgId] ?? null) : null,
            ));
        }

        return $candidates;
    }

    /**
     * `max(a, b)` de deux dates brutes (nullable) — la plus récente, ou `null` si
     * les deux sont nulles. Déterministe (valeurs DB stables entre compilations).
     */
    private function maxDate(mixed $a, mixed $b): ?\DateTimeInterface
    {
        $da = $a !== null ? Carbon::parse((string) $a) : null;
        $db = $b !== null ? Carbon::parse((string) $b) : null;

        if ($da === null) {
            return $db;
        }
        if ($db === null) {
            return $da;
        }

        return $da->greaterThanOrEqualTo($db) ? $da : $db;
    }
}
