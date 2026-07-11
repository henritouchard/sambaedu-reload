<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Capability;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\DB;

/**
 * Story 43.3 — cadence de propagation PILOTÉE par contexte (FR-A4).
 *
 * **Point d'extension unique (D1)** consommé par
 * {@see StateCompiler::compile()} : `ttlSeconds()` retourne le TTL COURT
 * (`config('agent.ttl_sensitive_seconds')`, défaut 90 s) si le contexte est en
 * « bascule sensible », le TTL global sinon (`config('agent.ttl_seconds')`,
 * défaut historique 3600 s — INCHANGÉ, D5).
 *
 * **Critère V1 de la bascule sensible (D1-D3)** : le contexte matche ssi il
 * existe AU MOINS une ligne `capability_assignments` telle que :
 *   - la capacité (`capabilities.key`) figure dans
 *     `config('agent.ttl_sensitive_capabilities')` (défaut `['restrict_run']`) ;
 *   - `value` est NON null (D2 — une ligne `value = null` est un repli sur le
 *     défaut diffusé, cf. commentaire de colonne de la migration
 *     `2026_06_18_100200_create_capability_assignments_table`, PAS une
 *     bascule) ;
 *   - `(assignable_type, assignable_id)` matche une maille du contexte (D3,
 *     MIROIR EXACT de
 *     {@see \App\Services\Agent\Providers\AbstractCapabilityStateProvider::resolveOverrides()}) :
 *     poste, chaîne physique ÉTENDUE aux ancêtres (`TargetContext::$physicalGroupDepths`)
 *     ∪ parcs logiques directs, groupes user, user.
 *
 * Défaut config `['restrict_run']` + capacité pas encore seedée (41.2 non
 * livrée à ce jour) ⇒ `Capability::whereIn('key', …)` ne résout AUCUN id ⇒
 * early-return `false` SANS la requête `capability_assignments` ⇒
 * comportement AUJOURD'HUI strictement inchangé. Le branchement de la
 * bascule examen (41.3) se fait donc à ZÉRO code ici : poser un
 * `capability_assignments.value` non-null pour `restrict_run` sur le parc
 * physique de la salle suffit.
 *
 * ⚠️ Piège n°4 — NE JAMAIS ajouter `internet_access` à
 * `agent.ttl_sensitive_capabilities` : l'exemption enseignante (FR-E4, epic
 * 41) est un assignment PERMANENT `internet_access=on` sur le groupe logique
 * du poste prof — un slug permanent dans la liste donnerait un TTL court À
 * VIE (poll 90 s en continu, à tort). La liste ne doit contenir QUE des
 * capacités dont les assignments sont TRANSITOIRES par construction (posés au
 * flag, purgés au déflag).
 *
 * ⚠️ Piège n°5 — déterminisme (exigence ETag, docblock `StateCompiler`) : ce
 * résolveur ne lit QUE des données PERSISTÉES (assignments + config), JAMAIS
 * d'horloge ni d'aléa — pas de notion de « récemment modifié ».
 *
 * PAS de cache du verdict : deux requêtes par compilation (pluck key→id +
 * `EXISTS`), y compris sur le chemin 304 — bon marché (tables minuscules,
 * indexées) ; un cache APCu serait faux multi-process et violerait
 * `project_apcu_cache_no_lock` ; une mémoïsation statique key→id survivrait
 * aux requêtes d'un worker FPM (staleness après reseed des capacités).
 *
 * PAS `final` (délibérément, iso `CupsPrinterService`) : les tests Unit
 * `StateCompilerTest` stubbent ce résolveur via `createMock()` (piège n°7 —
 * aucune requête SQL en Unit, la couverture du critère SQL vit en Feature,
 * `AgentTtlResolverTest`).
 */
class AgentTtlResolver
{
    public function ttlSeconds(TargetContext $ctx): int
    {
        if ($this->hasSensitiveSwitch($ctx)) {
            return max(60, (int) config('agent.ttl_sensitive_seconds'));
        }

        // Plancher et fallback iso (ex-)StateCompiler.php:74 — le défaut de
        // config() ne couvre pas une clé PRÉSENTE mais null (env vide).
        return max(1, (int) (config('agent.ttl_seconds') ?? 3600));
    }

    /**
     * Pluck key→id puis requête `EXISTS` (pas de fetch de lignes),
     * early-return sans requête `EXISTS` si la liste config est vide ou si
     * aucune capacité des clés listées n'existe (cas nominal tant que 41.2
     * n'est pas livrée).
     */
    private function hasSensitiveSwitch(TargetContext $ctx): bool
    {
        $keys = config('agent.ttl_sensitive_capabilities', []);
        if (! is_array($keys) || $keys === []) {
            return false;
        }

        $capabilityIds = Capability::query()->whereIn('key', $keys)->pluck('id')->all();
        if ($capabilityIds === []) {
            return false;
        }

        // Mailles D3 : chaîne physique ÉTENDUE aux ancêtres ∪ parcs logiques
        // directs — miroir exact de AbstractCapabilityStateProvider::resolveOverrides().
        $wgIds = array_values(array_unique(array_merge(
            array_map('intval', array_keys($ctx->physicalGroupDepths)),
            $ctx->logicalGroupIds,
        )));

        return DB::table('capability_assignments')
            ->whereIn('capability_id', $capabilityIds)
            ->whereNotNull('value')
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
            ->exists();
    }
}
