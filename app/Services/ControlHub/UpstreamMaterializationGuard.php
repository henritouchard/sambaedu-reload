<?php

declare(strict_types=1);

namespace App\Services\ControlHub;

use App\Enums\ControlHubEnforcementState;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;

/**
 * Répond à une seule question, pour tout objet que le contrat amont a matérialisé
 * localement : l'administrateur a-t-il le droit d'y toucher ?
 *
 * La réponse suit l'enforcement de l'item d'origine — `locked` fige, `permissive`
 * laisse la main (l'écart remonte alors en `overridden` au rapport de conformité).
 * Sans cette lecture, un raccourci ou un fond imposé apparaîtrait dans l'interface
 * comme n'importe quel objet local, modifiable, et la prochaine réception écraserait
 * le geste de l'administrateur sans que rien ne l'ait prévenu.
 *
 * Pendant côté OBJETS MATÉRIALISÉS de {@see UpstreamLockResolver}, qui traite lui le
 * verrou des capacités par leur clé de registre. Les deux lisent le même contrat,
 * répondent à la même question, mais n'indexent pas la même chose : ici c'est le
 * couple (type d'item, clé d'item), là-bas une clé de registre normalisée.
 *
 * Sans contrat actif, tout est déverrouillé : le comportement autonome de SE5 ne
 * doit rien devoir au lien amont.
 */
class UpstreamMaterializationGuard
{
    private bool $resolved = false;

    /** @var array<string, true> clés « type|clé » des items verrouillés du contrat actif */
    private array $lockedKeys = [];

    /**
     * L'objet matérialisé par cet item de contrat est-il figé pour l'administrateur ?
     */
    public function isLocked(string $type, ?string $key): bool
    {
        if ($key === null || $key === '') {
            return false;
        }

        $this->ensureResolved();

        return isset($this->lockedKeys[$type.'|'.$key]);
    }

    /**
     * Charge en une passe les items verrouillés du contrat actif.
     *
     * Mémoïsé pour la durée du conteneur — par requête en PHP-FPM, donc par écran
     * d'administration. Sans contrat actif, la table des items n'est jamais lue.
     */
    private function ensureResolved(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->resolved = true;

        $contract = ControlHubContract::active();
        if ($contract === null) {
            return;
        }

        $items = ControlHubContractItem::query()
            ->where('controlhub_contract_id', $contract->id)
            ->where('enforcement_state', ControlHubEnforcementState::Locked->value)
            ->get(['type', 'key']);

        foreach ($items as $item) {
            $this->lockedKeys[$item->type.'|'.$item->key] = true;
        }
    }
}
