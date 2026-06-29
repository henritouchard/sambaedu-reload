<?php

declare(strict_types=1);

namespace App\Services\ControlHub\Resolution;

use App\Enums\ControlHubContractTarget;
use App\Enums\ControlHubEnforcementState;
use App\Enums\ControlHubLinkState;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\Application;
use App\Models\ControlHubContract;
use App\Models\WorkstationGroup;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;

/**
 * Story 28.3 — Source des candidats AMONT (controlHub) pour le `StateCompiler`.
 *
 * Lit le **contrat actif** (singleton « ≤ 1 actif » garanti par 28.2 :
 * `link_state = active`) et expose ses items prêts à devenir des
 * {@see StateCandidate} étiquetés `StateMaille::Upstream`, groupés par couple
 * (type de provider, portée). Le {@see UpstreamAwareProvider} interroge cette
 * source et adjoint les candidats amont aux candidats locaux de chaque provider.
 *
 * **Discipline D2** : cette source n'arbitre RIEN. Elle émet des candidats BRUTS
 * étiquetés `Upstream` ; la précédence amont > local vit dans
 * `StateCompiler::specificity()` SEUL (la maille `Upstream` y est plus spécifique
 * que toute maille locale). [Source: StateCompiler PHPDoc l. 18-26]
 *
 * **NFR3 — court-circuit (CRITIQUE)** : la résolution du contrat est **mémoïsée**
 * (résolue UNE fois, réutilisée par tous les providers d'une compilation). En
 * production le conteneur est par-requête ⇒ la mémoïsation == par-compilation
 * (≤ 1 requête « contrat actif ? »). S'il n'y a **aucun** contrat actif, la table
 * `items` n'est JAMAIS requêtée (exactement 1 requête, qui renvoie null), aucun
 * candidat n'est émis, et le décorateur est un **pass-through strict** : le
 * compilé reste **byte-identique** au standalone (mêmes items, même ordre, même
 * hash). [Source: prd-contrat-manage-se5.md#NFR3]
 *
 * **Déterminisme (NFR4 / ETag 23.5)** : les items sont ordonnés par `id` stable
 * (`sourceId` = id de l'item contrat), JAMAIS par l'ordre SQL. L'injection est
 * donc stable entre deux compilations identiques. [Source: StateCompiler PHPDoc]
 *
 * **Ciblage par label (Story 30.4 — couture 28.3 refermée)** : les items
 * `target_type = label` ne sont **plus** ignorés. Ils sont chargés au même titre
 * que les items `instance` (filtre `target_type = instance` LEVÉ) et pré-groupés
 * par `target_label` dans {@see self::$groupedByLabel}. À l'appel
 * {@see self::candidatesFor()}, ils sont injectés UNIQUEMENT pour un poste qui
 * **porte** ce label, c.-à-d. membre d'un {@see WorkstationGroup} dont
 * `controlhub_label` égale (par nom, pas de FK — cf. 30.2) le `target_label` de
 * l'item. La résolution des labels portés par le poste passe par
 * {@see self::labelsCarriedBy()} (lecture des `WorkstationGroup` directs via
 * `TargetContext::workstationGroupIds()`), **mémoïsée par poste** (anti-N+1 sur
 * les ~10 providers décorés — NFR4).
 *
 * **NFR3 — court-circuit label (CRITIQUE)** : si le contrat actif ne contient
 * **aucun** item `label` ({@see self::$groupedByLabel} vide), la résolution des
 * labels portés n'est JAMAIS déclenchée (aucune requête `workstation_groups`) ⇒
 * le compilé reste **byte-identique** au comportement 28.3 (AC #5). La résolution
 * label n'a de sens que si au moins un item du contrat la cible.
 *
 * **Règle verrou/permissif SANS spécificité inter-parcs (FR12)** : la maille d'un
 * candidat label dérive **uniquement** de l'`enforcement_state` de l'item (comme
 * pour `instance`) — `locked → Upstream` (rang -1), `permissive →
 * UpstreamPermissive` (rang 6). Elle ne dépend JAMAIS du type de parc (physique/
 * logique) qui porte le label, ni d'un ordre entre labels. Deux items `locked`
 * portés via deux parcs sont donc deux candidats de **même** rang ⇒
 * `StateCompiler::resolveExclusiveWinner` ne les départage PAS par parc mais par
 * le tiebreak intra-maille (`updated_at` desc / `sourceId` desc) — aucune
 * spécificité inter-parcs n'est réintroduite (D2 ne fuit pas).
 *
 * **Collision insoluble (frontière 30.4 ↔ 30.5)** : deux items `locked`
 * contradictoires sur la **même** `exclusiveKey()` portés par le même poste via
 * deux labels produisent deux candidats `Upstream` de même rang. 30.4 NE résout
 * PAS ce conflit par un choix métier (ce serait arbitraire/silencieux) : elle
 * réutilise le signal existant `agent.state.conflict` (émis par
 * `resolveExclusiveWinner` pour tout « tied-at-top ») pour l'OBSERVER, et le
 * tiebreak déterministe évite de servir un état vide (NFR4). La **prévention
 * prédictive** (avertir le refnum à l'assignation d'un label / liaison d'un parc,
 * AVANT que la contradiction n'atteigne le poste) relève de la **Story 30.5**
 * (FR13). Aucune branche de résolution ad hoc n'est ajoutée ici.
 *
 * ⚠️ **Warning sur valeurs CONCORDANTES (sémantique préexistante exposée par 30.4)** :
 * deux candidats `Upstream` rang -1 de **même** clé déclenchent
 * `agent.state.conflict` **même si leurs valeurs sont identiques** —
 * `resolveExclusiveWinner` détecte le « tied-at-top » sur le rang de maille, il NE
 * compare PAS les payloads. Les deux cas réalistes : (a) deux items `label`
 * distincts (via deux parcs portés) imposant la même clé ; (b) — le plus probable
 * — un item `instance` ET un item `label` imposant la même clé sur un même poste.
 * Symétrique au rang 6 (deux `permissive` plancher sans local). 30.4 se contente
 * d'EXPOSER ce comportement au runtime (les items `label` étant désormais émis) ;
 * sa PRÉVENTION (avertir le refnum à l'assignation) ET l'éventuel **adoucissement
 * du warning sur valeurs concordantes** relèvent de la **Story 30.5**. Le moteur
 * (`resolveExclusiveWinner`) n'est PAS modifié (AC7a) et les payloads ne sont PAS
 * dédupliqués en exclusive ici.
 *
 * **Bornage de scope (28.3 / 30.4)** :
 *  - **Cible** : les items `target_type = instance` ET `target_type = label` sont
 *    injectés ; les `label` uniquement aux postes portant le label (cf. ci-dessus).
 *  - **Enforcement** : `locked` ET `permissive` sont injectés mais à des mailles
 *    DIVERGENTES (Story 29.3) ; `absent` est **exclu** (l'autorité déclare ne pas
 *    imposer cette clé — il ne prime sur rien — AC #6).
 *
 *    ✅ RELAXATION PERMISSIVE LIVRÉE (Story 29.3 — couture Epic 29 fermée) : un
 *    item `locked` est injecté à la maille `StateMaille::Upstream` (rang -1,
 *    INBATTABLE — l'amont gagne toujours, FR3) ; un item `permissive` est injecté
 *    à la maille `StateMaille::UpstreamPermissive` (rang 6, le MOINS spécifique de
 *    toute la chaîne — un PLANCHER que toute maille locale surcharge, FR4). La
 *    maille dérive DIRECTEMENT de l'`enforcement_state` de l'item (source de
 *    vérité unique — pas de recalcul via `UpstreamLockResolver`). La précédence
 *    elle-même reste arbitrée par `StateCompiler::specificity()` SEUL (D2).
 *
 * **Cache** : aucun cache applicatif (Redis/file). La mémoïsation `$resolved`/
 * `$grouped` EST néanmoins un cache à **durée de vie du conteneur** : sûr tant que
 * le conteneur est **par-requête** (PHP-FPM, pool www-admin = cas prod actuel ⇒
 * mémoïsation == par-compilation). ⚠️ CAVEAT long-running : sous `laravel/octane`
 * ou un worker de queue (conteneur réutilisé entre requêtes), cette source
 * servirait un contrat **périmé** indéfiniment — il faudrait alors brancher un
 * listener sur `App\Events\ControlHubContractChanged` (28.2) pour invalider.
 * Aujourd'hui le seul déclencheur de résolution est `StateController` (HTTP,
 * par-requête) ⇒ risque pratique nul ; l'event reste SANS listener (cohérent NFR3,
 * Task 4). Si l'usage migre vers un worker long-running, brancher l'invalidation.
 *
 * ⚠️ GARDE-FOU R3 : aucun « central ». Vocabulaire « amont » / `Upstream` /
 * `ControlHub*`. [Source: prd-contrat-manage-se5.md#R3]
 */
final class UpstreamContractSource
{
    /** @var array<string, UpstreamPayloadAdapter> indexé par `upstreamType()` */
    private array $adapters = [];

    private bool $resolved = false;

    /**
     * Candidats amont `instance` mémoïsés, groupés par clé « providerType|scope ».
     *
     * @var array<string, list<StateCandidate>>
     */
    private array $grouped = [];

    /**
     * Candidats amont `label` mémoïsés, indexés par nom de label PUIS par clé
     * « providerType|scope ». Vide ⇒ court-circuit NFR3 (aucune résolution des
     * labels portés par le poste).
     *
     * @var array<string, array<string, list<StateCandidate>>>
     */
    private array $groupedByLabel = [];

    /**
     * Mémoïsation par poste des labels qu'il porte (anti-N+1 : `candidatesFor()`
     * est appelé une fois par provider décoré ⇒ ~10 fois par compilation). Clé =
     * `workstation->id`, valeur = liste triée des `controlhub_label` portés.
     *
     * **Durée de vie (CRITIQUE — long-running)** : ce cache d'appartenances de
     * parcs partage la durée de vie PAR-COMPILATION / PAR-REQUÊTE de `$grouped` /
     * `$resolved` (conteneur recréé à chaque requête PHP-FPM ⇒ risque pratique nul
     * aujourd'hui). Sous un worker long-running (Octane/queue), l'invalidation ne
     * suffit PAS de couvrir `App\Events\ControlHubContractChanged` (changement de
     * contrat) : elle DOIT aussi couvrir un **changement d'appartenance d'un poste
     * à un parc** (attach/detach `WorkstationGroup`), car les labels portés sont
     * dérivés de ces appartenances. Hors scope du runtime PHP-FPM actuel.
     *
     * @var array<int, list<string>>
     */
    private array $labelsCarriedByWorkstation = [];

    /**
     * Story 31.2 — ORDRES D'INSTALL amont (FR6) de cible `instance` : les `app_id`
     * (= clé d'un item `type='applications'`) que l'autorité amont impose à TOUTE
     * la flotte. Peuplé dans la MÊME passe que {@see self::$grouped} /
     * {@see self::$groupedByLabel} (zéro requête de plus). Lu par
     * {@see self::orderedApplicationAppIds()}. Vide ⇒ court-circuit NFR3 (avec
     * {@see self::$applicationOrdersByLabel}, aucune résolution des labels portés).
     *
     * @var list<string>
     */
    private array $applicationOrdersInstance = [];

    /**
     * Story 31.2 — ORDRES D'INSTALL amont de cible `label` : `app_id` indexés par
     * nom de label ciblé (`target_label`). Injectés UNIQUEMENT aux postes portant
     * le label (rattachement par NOM, iso 30.4). Vide (avec
     * {@see self::$applicationOrdersInstance}) ⇒ court-circuit NFR3.
     *
     * @var array<string, list<string>>
     */
    private array $applicationOrdersByLabel = [];

    /**
     * @param  iterable<UpstreamPayloadAdapter>  $adapters  bridge extensible :
     *                                            un adaptateur par type amont démontré
     */
    public function __construct(iterable $adapters)
    {
        foreach ($adapters as $adapter) {
            $this->adapters[$adapter->upstreamType()] = $adapter;
        }
    }

    /**
     * Candidats amont applicables à un provider donné, identifiés par son
     * `type()` ET sa portée `scope()`. La portée discrimine deux providers de
     * même type (ex. `registry` HKLM/machine vs HKCU/session). Liste vide si
     * aucun contrat actif (court-circuit NFR3) ou aucun item mappé pour ce couple.
     *
     * Story 30.4 — adjoint aux candidats `instance` les candidats `label` des
     * labels **portés** par le poste (`$ctx`). Court-circuit NFR3 : si le contrat
     * actif n'a aucun item `label`, les labels portés ne sont jamais résolus
     * (retour strictement identique à 28.3).
     *
     * @return list<StateCandidate>
     */
    public function candidatesFor(string $providerType, StateScope $scope, TargetContext $ctx): array
    {
        $this->ensureResolved();

        $key = $this->groupKey($providerType, $scope);
        $candidates = $this->grouped[$key] ?? [];

        // Court-circuit NFR3 : aucun item label dans le contrat actif (ou pas de
        // contrat) ⇒ on ne résout PAS les labels portés (zéro requête WG).
        if ($this->groupedByLabel === []) {
            return $candidates;
        }

        foreach ($this->labelsCarriedBy($ctx) as $label) {
            foreach ($this->groupedByLabel[$label][$key] ?? [] as $candidate) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * Story 30.5 — accesseur LECTURE SEULE des candidats `label` VERROUILLÉS
     * (maille {@see StateMaille::Upstream}), au service de la **prévention
     * prédictive** d'une collision verrou/verrou à l'assignation (FR13). Réutilise
     * STRICTEMENT le socle 30.4 ({@see self::$groupedByLabel}, déjà peuplé par
     * {@see self::ensureResolved()} via les MÊMES adaptateurs / `toPayload` /
     * `sourceId`) — aucune re-requête, aucun re-parsing.
     *
     * Filtre `StateMaille::Upstream` (locked SEULEMENT) : un candidat `permissive`
     * (maille `UpstreamPermissive`, rang 6) est un **plancher** surchargeable — il
     * ne peut JAMAIS entrer en collision insoluble (AC #3) et est donc exclu ici.
     * Les items `absent` n'ont jamais été indexés (exclus en amont).
     *
     * **Court-circuit NFR3** : si {@see self::$groupedByLabel} est vide (aucun item
     * `label`, ou aucun contrat actif), retour `[]` immédiat — cohérent avec le
     * court-circuit de {@see self::candidatesFor()}. L'appelant (détecteur 30.5)
     * traite `[]` comme « rien à valider » (zéro garde, hot-path d'assignation
     * intact).
     *
     * ⚠️ Cet accesseur n'ARBITRE RIEN : pas de précédence, pas de tiebreak — la
     * discipline D2 reste confinée au `StateCompiler`. Il EXPOSE des candidats
     * BRUTS que le détecteur 30.5 keye par `exclusiveKey()` (providers existants).
     *
     * @return array<string, array<string, list<StateCandidate>>> `label →
     *                       "providerType|scope" → candidats locked`
     */
    public function lockedLabelCandidates(): array
    {
        $this->ensureResolved();

        if ($this->groupedByLabel === []) {
            return []; // court-circuit NFR3 : rien à valider.
        }

        $locked = [];
        foreach ($this->groupedByLabel as $label => $byGroupKey) {
            foreach ($byGroupKey as $groupKey => $candidates) {
                foreach ($candidates as $candidate) {
                    // Locked uniquement : le permissif (UpstreamPermissive) ne
                    // collisionne jamais — c'est un plancher surchargeable (AC #3).
                    if ($candidate->maille === StateMaille::Upstream) {
                        $locked[$label][$groupKey][] = $candidate;
                    }
                }
            }
        }

        return $locked;
    }

    /**
     * Story 31.2 — accesseur LECTURE SEULE des ORDRES D'INSTALL amont (FR6) : les
     * `app_id` (= clé d'un item `type='applications'`) que l'autorité amont
     * ORDONNE d'installer sur le poste `$ctx` — cible `instance` (toute la flotte)
     * ∪ les `app_id` portés par un label que le poste porte (réutilisation STRICTE
     * de {@see self::labelsCarriedBy()}, socle 30.4). 3ᵉ jumeau de
     * {@see self::candidatesFor()} / {@see self::lockedLabelCandidates()} : même
     * discipline (mémoïsé via {@see self::ensureResolved()}, aucune écriture,
     * aucune re-requête). `key == applications.app_id` (jamais un id de pivot/scope).
     *
     * **Pont au niveau ENSEMBLE (D3)** : l'{@see \App\Services\Agent\Providers\ApplicationsStateProvider}
     * UNIONNE ces `app_id` à son ensemble cible AVANT hydratation → le payload
     * `{app_id, name}` est IDENTIQUE quelle que soit la source (résolu localement
     * OU ordonné amont) ⇒ la dédup aggregate du `StateCompiler` collapse un doublon
     * de source en UN item (idempotence d'état). On NE passe PAS par un
     * {@see UpstreamPayloadAdapter} : `toPayload()` est pur (pas d'accès DB) et ne
     * pourrait hydrater le `name` depuis l'`Application` locale → deux payloads
     * divergents → doublon. C'est pourquoi `applications` n'a (volontairement)
     * AUCUN adaptateur — il ne peuple jamais {@see self::$grouped}.
     *
     * **aggregate ⇒ pas d'enforcement à projeter (D1)** : seule la PRÉSENCE dans
     * l'ensemble compte. `locked` et `permissive` signifient tous deux « app
     * présente » (install) — aucune distinction, aucun rang `Upstream` ici (rien à
     * arbitrer : l'union EST le but, pas une précédence). Le RETRAIT existe DÉJÀ par
     * omission (WPKG synchronise `profiles.xml` par poste) — on ne pose aucun canal
     * `<remove>`/`absent` (l'`absent` est de toute façon exclu en amont par
     * {@see self::ensureResolved()}).
     *
     * **Court-circuit NFR3 (CRITIQUE)** : sans aucun ordre d'install (standalone,
     * ou contrat actif sans item `applications`), retour `[]` IMMÉDIAT —
     * {@see self::labelsCarriedBy()} (donc la requête `workstation_groups`) n'est
     * JAMAIS appelée. L'ensemble cible du provider reste byte-identique au 27.5.
     * Ce court-circuit est garanti POUR CET ACCESSEUR SEUL ; en production le
     * décorateur {@see UpstreamAwareProvider} (qui enrobe le même provider) peut
     * appeler {@see self::labelsCarriedBy()} pour d'AUTRES types de cible label
     * (items `registry`/`label` du contrat) — mais le résultat est MÉMOÏSÉ par
     * poste ({@see self::$labelsCarriedByWorkstation}), donc partagé avec cet
     * accesseur SANS requête supplémentaire (cet accesseur n'ajoute zéro requête).
     *
     * **Déterminisme NFR4** : union dédupliquée + triée `strcasecmp` (iso le tri du
     * provider) — ordre stable entre deux compilations identiques.
     *
     * ⚠️ GARDE-FOU R3 : aucun « central » ; vocabulaire « amont » / `Upstream`.
     * Caveat long-running identique aux autres accesseurs (mémoïsation
     * par-conteneur sûre PHP-FPM ; sous Octane brancher `ControlHubContractChanged`).
     *
     * @return list<string> `app_id` ordonnés, dédupliqués + triés (déterminisme)
     */
    public function orderedApplicationAppIds(TargetContext $ctx): array
    {
        $this->ensureResolved();

        // Court-circuit NFR3 : aucun ordre d'install (pas de contrat, ou contrat
        // sans item `applications`) ⇒ rien à unionner, et SURTOUT aucune résolution
        // des labels portés (zéro requête `workstation_groups`).
        if ($this->applicationOrdersInstance === [] && $this->applicationOrdersByLabel === []) {
            return [];
        }

        // Cible `instance` (toute la flotte) — toujours appliquée.
        $appIds = $this->applicationOrdersInstance;

        // Cible `label` — uniquement les ordres portés par un label du poste.
        foreach ($this->labelsCarriedBy($ctx) as $label) {
            foreach ($this->applicationOrdersByLabel[$label] ?? [] as $appId) {
                $appIds[] = $appId;
            }
        }

        // Dédup + tri déterministe (iso ApplicationsStateProvider) — NFR4.
        $appIds = array_values(array_unique($appIds));
        usort($appIds, static fn (string $a, string $b): int => strcasecmp($a, $b));

        return $appIds;
    }

    /**
     * Labels portés par le poste = `controlhub_label` (30.2) des `WorkstationGroup`
     * DIRECTS du poste (salles physiques + parcs logiques résolus une fois par
     * `TargetContext`). Mémoïsé par poste (anti-N+1). Liste triée + dédupliquée
     * (déterminisme NFR4 — indépendant du plan SQL).
     *
     * Le rattachement label↔parc se fait **par nom** (`target_label` sur l'item
     * amont == `controlhub_label` sur le `WorkstationGroup`), sans FK dure
     * (cohérent 28.1/30.2). N'est appelée QUE si le contrat porte au moins un item
     * label (cf. court-circuit de {@see self::candidatesFor()}).
     *
     * @return list<string>
     */
    private function labelsCarriedBy(TargetContext $ctx): array
    {
        $workstationId = $ctx->workstation->id;

        if (array_key_exists($workstationId, $this->labelsCarriedByWorkstation)) {
            return $this->labelsCarriedByWorkstation[$workstationId];
        }

        $groupIds = $ctx->workstationGroupIds();
        if ($groupIds === []) {
            return $this->labelsCarriedByWorkstation[$workstationId] = [];
        }

        $labels = WorkstationGroup::query()
            ->whereIn('id', $groupIds)
            ->whereNotNull('controlhub_label')
            // Garde-fou 30.4 : un label vide ne porte rien — on ne l'injecte JAMAIS
            // (symétrique de la garde côté item, cf. {@see self::ensureResolved()}).
            ->where('controlhub_label', '!=', '')
            ->pluck('controlhub_label')
            ->all();

        $labels = array_values(array_unique($labels));
        sort($labels);

        return $this->labelsCarriedByWorkstation[$workstationId] = $labels;
    }

    /**
     * Résout le contrat actif UNE fois (mémoïsé). Court-circuit NFR3 : sans
     * contrat actif, on ne touche jamais la table `items`.
     */
    private function ensureResolved(): void
    {
        if ($this->resolved) {
            return;
        }
        $this->resolved = true;

        $contract = ControlHubContract::query()
            ->where('link_state', ControlHubLinkState::Active->value)
            ->first();

        if ($contract === null) {
            return; // court-circuit : zéro candidat, zéro requête items (NFR3).
        }

        $items = $contract->items()
            // Story 30.4 : cible instance ET label (filtre `instance` LEVÉ — la
            // couture 28.3 est refermée ; les items label sont pré-groupés par nom).
            ->whereIn('target_type', [
                ControlHubContractTarget::Instance->value,
                ControlHubContractTarget::Label->value,
            ])
            // AC #6 : locked + permissive priment ; absent exclu (n'impose rien).
            ->whereIn('enforcement_state', [
                ControlHubEnforcementState::Locked->value,
                ControlHubEnforcementState::Permissive->value,
            ])
            // Déterminisme : ordre stable par id (jamais l'ordre du plan SQL).
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            // Story 31.2 — ORDRE D'INSTALL amont (type `applications`, FR6) : pont
            // au niveau ENSEMBLE (D3), PAS via adaptateur. On indexe l'`app_id`
            // (= $item->key) dans des structures dédiées lues par
            // {@see self::orderedApplicationAppIds()}, en RÉUTILISANT cette passe
            // items (zéro requête de plus). On `continue` ensuite : l'item
            // `applications` n'a (volontairement) aucun adaptateur ⇒ il ne peuple ni
            // `grouped` ni `groupedByLabel` (zéro double comptage côté
            // `candidatesFor()`). Les deux états retenus par le `whereIn`
            // (`locked`/`permissive`) signifient « app présente » : aggregate, aucune
            // valeur à relaxer/verrouiller (D1) ; `absent` n'arrive jamais ici.
            if ($item->type === Application::TYPE_APPLICATIONS) {
                if ($item->target_type === ControlHubContractTarget::Label) {
                    // Garde-fou SYMÉTRIQUE (iso le label de `grouped`, cf. plus bas et
                    // {@see self::labelsCarriedBy()}) : un `target_label` vide ne cible
                    // aucun parc identifiable — jamais indexé (sinon il s'appliquerait
                    // à tort à un parc à `controlhub_label` vide, anomalie 30.2).
                    if ($item->target_label === null || $item->target_label === '') {
                        continue;
                    }
                    $this->applicationOrdersByLabel[$item->target_label][] = (string) $item->key;
                } else {
                    // Cible `instance` (toute la flotte).
                    $this->applicationOrdersInstance[] = (string) $item->key;
                }

                continue;
            }

            $adapter = $this->adapters[$item->type] ?? null;
            if ($adapter === null) {
                // Type amont sans adaptateur enregistré : ignoré proprement
                // (couture Epic 33 — types non encore démontrés). Vaut pour
                // `instance` comme pour `label`.
                continue;
            }

            // Story 29.3 — maille divergente selon l'enforcement de l'ITEM (source
            // de vérité unique) : `locked` → `Upstream` (rang -1, inbattable, FR3) ;
            // `permissive` → `UpstreamPermissive` (rang 6, plancher battable, FR4).
            // La maille NE dépend JAMAIS de la cible (instance/label) ni du type de
            // parc portant le label (FR12 — pas de spécificité inter-parcs ; D2 ne
            // fuit pas). Les deux états ont été retenus par le `whereIn` ci-dessus ;
            // `absent` n'arrive jamais ici (exclu en amont).
            $maille = $item->enforcement_state === ControlHubEnforcementState::Permissive
                ? StateMaille::UpstreamPermissive
                : StateMaille::Upstream;

            $groupKey = $this->groupKey($adapter->providerType(), $adapter->scopeFor($item));
            $candidate = new StateCandidate(
                maille: $maille,
                payload: $adapter->toPayload($item),
                updatedAt: $item->updated_at,
                sourceId: (int) $item->id,
            );

            if ($item->target_type === ControlHubContractTarget::Label) {
                // Garde-fou 30.4 (defense-in-depth) : un item `label` dont le
                // `target_label` est vide ne cible aucun parc identifiable. On NE
                // l'indexe PAS — sinon il peuplerait `$groupedByLabel['']`, et un
                // poste membre d'un parc à `controlhub_label` vide (anomalie 30.2)
                // se le verrait injecter à tort. La résolution lit d'ailleurs
                // `controlhub_label != ''` (cf. {@see self::labelsCarriedBy()}) :
                // la garde est SYMÉTRIQUE des deux côtés du rattachement par nom.
                if ($item->target_label === null || $item->target_label === '') {
                    continue;
                }
                // Story 30.4 : injecté plus tard, mais SEULEMENT aux postes portant
                // ce label (`target_label` == `WorkstationGroup.controlhub_label`).
                $this->groupedByLabel[$item->target_label][$groupKey][] = $candidate;
            } else {
                // Comportement 28.3 strictement préservé.
                $this->grouped[$groupKey][] = $candidate;
            }
        }
    }

    private function groupKey(string $providerType, StateScope $scope): string
    {
        return $providerType.'|'.$scope->value;
    }
}
