<?php

declare(strict_types=1);

namespace App\Services\ControlHub\Resolution;

use App\Enums\ControlHubContractTarget;
use App\Enums\ControlHubEnforcementState;
use App\Enums\ControlHubLinkState;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\ControlHubContract;
use App\Services\Agent\StateCandidate;

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
 * **Bornage de scope (28.3)** :
 *  - **Cible** : seuls les items `target_type = instance` sont injectés. Les
 *    items `target_type = label` sont **différés Epic 30** (mapping label →
 *    `WorkstationGroup`) — ignorés proprement ici (ni résolution, ni plantage).
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
     * Candidats amont mémoïsés, groupés par clé « providerType|scope ».
     *
     * @var array<string, list<StateCandidate>>
     */
    private array $grouped = [];

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
     * @return list<StateCandidate>
     */
    public function candidatesFor(string $providerType, StateScope $scope): array
    {
        $this->ensureResolved();

        return $this->grouped[$this->groupKey($providerType, $scope)] ?? [];
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
            // Bornage 28.3 : cible instance uniquement (label → Epic 30).
            ->where('target_type', ControlHubContractTarget::Instance->value)
            // AC #6 : locked + permissive priment ; absent exclu (n'impose rien).
            ->whereIn('enforcement_state', [
                ControlHubEnforcementState::Locked->value,
                ControlHubEnforcementState::Permissive->value,
            ])
            // Déterminisme : ordre stable par id (jamais l'ordre du plan SQL).
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            $adapter = $this->adapters[$item->type] ?? null;
            if ($adapter === null) {
                // Type amont sans adaptateur enregistré : ignoré proprement
                // (couture Epic 33 — types non encore démontrés).
                continue;
            }

            // Story 29.3 — maille divergente selon l'enforcement de l'ITEM (source
            // de vérité unique) : `locked` → `Upstream` (rang -1, inbattable, FR3) ;
            // `permissive` → `UpstreamPermissive` (rang 6, plancher battable, FR4).
            // Les deux états ont été retenus par le `whereIn` ci-dessus ; `absent`
            // n'arrive jamais ici (exclu en amont).
            $maille = $item->enforcement_state === ControlHubEnforcementState::Permissive
                ? StateMaille::UpstreamPermissive
                : StateMaille::Upstream;

            $this->grouped[$this->groupKey($adapter->providerType(), $adapter->scopeFor($item))][] = new StateCandidate(
                maille: $maille,
                payload: $adapter->toPayload($item),
                updatedAt: $item->updated_at,
                sourceId: (int) $item->id,
            );
        }
    }

    private function groupKey(string $providerType, StateScope $scope): string
    {
        return $providerType.'|'.$scope->value;
    }
}
