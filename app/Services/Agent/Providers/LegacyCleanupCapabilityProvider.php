<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\StateScope;
use App\Models\CapabilityProjection;
use Illuminate\Support\Facades\Log;

/**
 * Story 38.3 — provider `legacy_cleanup` CAPABILITY-FIRST, portée **Machine**
 * (le service SYSTEM est le SEUL acteur : HKLM, schtasks, `C:\Users\*` —
 * aucun volet compagnon session, D2 de la story).
 *
 * Quatrième mécanisme HORS-REGISTRE (jumeau structurel de
 * {@see FirewallCapabilityProvider} / {@see PrivilegeCapabilityProvider},
 * doctrine Epic 36 : « mécanisme = code payé une fois, capacité = donnée »).
 * Il EXPANSE la capacité de gating `legacy_hooks_cleanup` → AU PLUS UN item
 * de contrat CONCRET 1 clé `{mozilla}` (§7.10) — enum FERMÉ à la SEULE valeur
 * `vanilla` (décision Q5-a, Henri 2026-07-10 : suppression des paires
 * `profiles.ini`/`installs.ini` référençant `sambaedu.default`, AUCUN profil
 * forcé posé). Il SURCHARGE l'interpréteur `expand()` du provider abstrait
 * sans toucher `StateCompiler` (AC2) et réutilise `resolveKeyValue()`
 * (map/littéral) + `UNMANAGED` hérités ; lecture Postgres pure (NFR7).
 *
 * **Le serveur GATE, l'agent sait QUOI nettoyer (D3).** Le CATALOGUE des
 * artefacts legacy (blobs `applications-*`, tâches WPKG, scripts GPO locale,
 * helpers, autologon `se4install`, paires Mozilla) est versionné DANS l'agent
 * (`agent/shared/handler_legacy_cleanup.go`) : c'est de la connaissance legacy
 * FIGÉE (chemins Windows), pas du paramétrage métier. Le payload reste donc
 * minimal — la seule donnée contractuelle est le traitement Mozilla (Q5).
 *
 * **`exclusiveKey()` FIXE `legacy_cleanup` (1 identité pour tout le type).**
 * Il n'existe qu'UN nettoyage par poste : la maille la plus spécifique gagne
 * l'item ENTIER (un override parc remplace le défaut Broadcast, il ne s'y
 * ajoute pas). C'est le patron « défaut Broadcast + override parc »
 * (registre 27.3ter) appliqué à un mécanisme mono-item.
 *
 * **Pas de `off` (piège #7 de la story).** Le nettoyage est ONE-WAY (on ne
 * « restaure » pas des crochets legacy) : `unmanaged` (sentinelle, rien émis,
 * agent inactif) et `on` (item émis, scan+suppression level-triggered) sont
 * les deux seuls états. La règle « off écrit une vraie valeur »
 * (`project_capability_value_map_symmetric_rule`) s'applique aux maps registre
 * symétriques, pas ici — consigné aussi au seed.
 *
 * **Pas de ciblage par utilisateur.** `scope() = Machine` ⇒ le service SYSTEM
 * fetch sans `?user` (`userGroupIds = []`) : un override UserGroup/User d'une
 * capacité `legacy_cleanup` est SANS EFFET (iso privilege/firewall).
 *
 * **`hive()` non applicable** : `expand()` est surchargé intégralement —
 * `handlesHive()` n'est JAMAIS appelé (iso {@see PrivilegeCapabilityProvider}).
 */
final class LegacyCleanupCapabilityProvider extends AbstractCapabilityStateProvider
{
    /**
     * Seule valeur admise du payload `{mozilla}` en v1 (enum FERMÉ, §7.10) —
     * trace contractuelle de Q5-a, extensible si (b)/(c) revenait un jour.
     */
    public const MOZILLA_VANILLA = 'vanilla';

    public function scope(): StateScope
    {
        return StateScope::Machine;
    }

    protected function mechanism(): string
    {
        return CapabilityProjection::MECHANISM_LEGACY_CLEANUP;
    }

    /**
     * Non applicable au mécanisme `legacy_cleanup` — `expand()` est surchargé
     * intégralement, `handlesHive()` n'est JAMAIS appelé. Implémentée pour
     * satisfaire le contrat de la classe abstraite (registre-specific).
     */
    protected function hive(): string
    {
        return '';
    }

    /**
     * Identité FIXE : il n'existe qu'UN nettoyage legacy par poste — la maille
     * la plus spécifique gagne l'item ENTIER (jamais de cumul).
     */
    public function exclusiveKey(array $payload): string
    {
        return 'legacy_cleanup';
    }

    /**
     * Interpréteur de `spec` du mécanisme `legacy_cleanup`. La projection porte
     * `spec = { "mozilla": <littéral OU map valeur-capacité> }` (seed canonique :
     * `{"mozilla": {"on": "vanilla"}}`) :
     *   - `mozilla` est résolu par {@see resolveKeyValue()} : clé de map absente
     *     (`unmanaged`, sentinelle) ⇒ item NON émis — l'agent est INACTIF sur ce
     *     type (le handler n'est même pas invoqué, contrat §8) ;
     *   - la valeur résolue est bornée à l'enum FERMÉ `["vanilla"]` (§7.10) :
     *     hors domaine ⇒ item NON émis + warning (défensif, jamais d'exception
     *     au render — une spec corrompue ne doit pas casser la compilation) ;
     *   - forme inattendue (liste, objet, scalaire non-string) ⇒ non émis
     *     défensif.
     * Le payload résultant est CONCRET : EXACTEMENT 1 clé `{mozilla}`, jamais
     * d'id de capacité (invariant 27.12), zéro float (§4.1).
     *
     * @return list<array<string,mixed>> zéro ou un payload 1 clé
     */
    protected function expand(CapabilityProjection $projection, string $capabilityValue): array
    {
        $spec = $projection->spec;
        if (! is_array($spec)) {
            return [];
        }

        // Résolution map/littéral (D5 du modèle capacités) : `unmanaged` est
        // ABSENT de la map ⇒ sentinelle ⇒ rien émis (agent inactif).
        $resolved = $this->resolveKeyValue($spec['mozilla'] ?? null, $capabilityValue);
        if ($resolved === self::UNMANAGED) {
            return [];
        }
        if (! is_string($resolved)) {
            return []; // forme inattendue (liste/objet/null) ⇒ non émis défensif.
        }

        // Enum FERMÉ §7.10 : `vanilla` est la SEULE valeur v1 (Q5-a). Une spec
        // hors domaine est écartée SILENCIEUSEMENT à la compilation sinon —
        // on trace pour que l'anomalie soit observable.
        if ($resolved !== self::MOZILLA_VANILLA) {
            Log::warning('legacy_cleanup : projection écartée, valeur `mozilla` hors enum fermé ["vanilla"] (item non émis).', [
                'capability_id' => $projection->capability_id,
                'mozilla' => $resolved,
            ]);

            return [];
        }

        return [[
            'mozilla' => self::MOZILLA_VANILLA,
        ]];
    }
}
