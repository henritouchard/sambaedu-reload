<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Capability;
use App\Policies\Traits\ChecksPermissions;
use App\Policies\Traits\RegistersGates;
use App\Services\ControlHub\UpstreamLockResolver;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Story 29.2 — Policy d'écriture d'une capacité, gate `modify-capability`.
 *
 * Pose le VERROU AMONT au niveau Gate (defense-in-depth « pas seulement masquée » :
 * consommable en `@can('modify-capability', $capability)` côté Blade ET en
 * `Gate::authorize('modify-capability', $capability)` côté serveur). Réutilise le
 * patron d'ENREGISTREMENT de la 29.1 (`RegistersGates` + propriété `$gates`), PAS
 * sa logique de délégation.
 *
 * **Le verrou est INSTANCE-WIDE**, PAS une délégation par-salle : il se résout par
 * PRÉSENCE d'un item `locked`/`instance`/`registry` au contrat amont actif
 * ({@see UpstreamLockResolver}), indépendamment de l'utilisateur et du parc — ne
 * PAS réimporter `PermissionService::canOnWorkstationGroup` (29.1). Le scoping par
 * label viendra en Epic 30.
 *
 * **Distinct du gel local `overrides_locked` (27.12)** : ce dernier gèle l'AJOUT
 * d'overrides par parc pour les besoins de l'admin SE5 ; le verrou amont gèle
 * l'édition pour le refnum sous autorité controlHub. Axes différents, refus
 * coexistants.
 *
 * **Plancher de droit = `app.customize`** : iso le geste d'override par parc et le
 * défaut diffusé (les deux surfaces capacité éditées sous ce droit). Le `null`
 * (cas générique, sans capacité résolue) retombe sur le seul droit (pas de
 * capacité ⇒ aucun verrou applicable). Sans contrat amont actif, le resolver
 * court-circuite (NFR3) : la décision = `app.customize` seul, BYTE-IDENTIQUE à
 * 27.12.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central ». Vocabulaire « amont » / `Upstream` /
 * `ControlHub*`. [Source: prd-contrat-manage-se5.md#R3]
 */
class CapabilityPolicy
{
    use RegistersGates;
    use ChecksPermissions;

    protected static array $gates = [
        'modify-capability' => 'modify',
    ];

    public function __construct(private readonly UpstreamLockResolver $lockResolver) {}

    /**
     * L'utilisateur peut-il MODIFIER cette capacité (override de parc OU défaut
     * diffusé) ? `true` ssi il a `app.customize` ET (aucune capacité résolue OU
     * la capacité n'est PAS verrouillée par le contrat amont).
     *
     * `locked` amont → refus ; `permissive`/`absent`/standalone/severed → autorisé
     * (le resolver ne retient que `locked`/`instance`/`registry`).
     */
    public function modify(?Authenticatable $user, ?Capability $capability = null): bool
    {
        if (! $this->hasPermission($user, 'app.customize')) {
            return false;
        }

        if ($capability === null) {
            return true;
        }

        return ! $this->lockResolver->isCapabilityLocked($capability);
    }
}
