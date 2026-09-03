<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Capability;
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
 * ⚠️ Depuis 29.8 ce gate ne reflète QUE le verrou amont (plus aucun droit) : un
 * `@can('modify-capability', $cap)` en Blade ne masquerait donc PAS selon le droit
 * de l'acteur — le contrôle de droit doit être porté par la surface appelante
 * (`guardCustomize` scopé / `server.admin` global), cf. note de `modify()`.
 *
 * **Le verrou n'est PAS une délégation par-salle** : il se résout par PRÉSENCE
 * d'un item verrouillant au contrat amont actif ({@see UpstreamLockResolver}),
 * indépendamment de l'utilisateur — ne PAS réimporter
 * `PermissionService::canOnWorkstationGroup` (29.1). Sa PORTÉE dépend du canal :
 * un item `registry`/`instance` verrouille toute l'instance ; un item
 * `capabilities` désigne sa cible par un LABEL et ne verrouille alors que les
 * parcs qui le portent. D'où le second argument `$label` — le label du parc
 * édité, ou `null` pour une surface d'instance (défaut diffusé), qui ne voit que
 * les verrous de portée instance.
 *
 * **Distinct du gel local `overrides_locked` (27.12)** : ce dernier gèle l'AJOUT
 * d'overrides par parc pour les besoins de l'admin SE5 ; le verrou amont gèle
 * l'édition pour le refnum sous autorité controlHub. Axes différents, refus
 * coexistants.
 *
 * **Story 29.8 — ce gate ne porte PLUS de plancher de droit.** `modify-capability`
 * est DUAL-PURPOSE : il sert l'override PAR-PARC (`capabilities-tab`, droit SCOPÉ
 * `customize-workstationGroup` — 29.6) ET le défaut diffusé GLOBAL d'instance
 * (`registry-tab`, droit GLOBAL `server.admin`). Comme les deux surfaces n'ont pas
 * la même exigence de droit, le droit est désormais filtré PAR SURFACE EN AMONT
 * (`guardCustomize`/`guardAdmin` abortent 403 avant d'atteindre ce gate) et ce gate
 * ne conserve QUE le verrou amont. Avant 29.8 il imposait en plus un plancher de
 * droit GLOBAL `app.customize` qui rebloquait le délégué positif-seul que le guard
 * scopé venait d'autoriser (P1 review 29.6) — plancher retiré comme redondant et
 * nuisible. Sans contrat amont actif, le resolver court-circuite (NFR3) : la
 * décision = « pas verrouillé », BYTE-IDENTIQUE à standalone.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central ». Vocabulaire « amont » / `Upstream` /
 * `ControlHub*`. [Source: prd-contrat-manage-se5.md#R3]
 */
class CapabilityPolicy
{
    use RegistersGates;

    protected static array $gates = [
        'modify-capability' => 'modify',
    ];

    public function __construct(private readonly UpstreamLockResolver $lockResolver) {}

    /**
     * Cette capacité est-elle MODIFIABLE (override de parc OU défaut diffusé) ?
     * `true` ssi aucune capacité résolue OU la capacité n'est PAS verrouillée par
     * le contrat amont. Le verrou amont est désormais le SEUL motif de refus.
     *
     * Story 29.8 — `$user` est INUTILISÉ : le contrôle de droit est porté par
     * chaque surface EN AMONT de ce gate (`customize-workstationGroup` scopé pour
     * l'override par-parc / `server.admin` global pour le défaut diffusé). Le
     * paramètre reste dans la signature car Laravel passe l'utilisateur en premier
     * argument d'une méthode de policy (contrat Gate).
     *
     * Conséquence : l'INVITÉ (`$user === null`, cas guest-policy Laravel) n'est plus
     * refusé ICI — sur une capacité non verrouillée, ce gate renvoie `true`. C'est
     * voulu (la policy ne juge plus l'identité) ; la surface appelante DOIT garder
     * l'authentification en amont (`guardCustomize` fait `auth()->check()`,
     * `guardAdmin` passe par `Gate::allows('server.admin')` qui refuse l'invité).
     *
     * `locked` amont → refus ; `permissive`/`absent`/standalone/severed → autorisé.
     *
     * `$label` est le label amont du parc édité (`workstation_groups.controlhub_label`).
     * Absent, seuls les verrous de portée instance sont vus : c'est le cas des
     * surfaces globales (défaut diffusé), qu'un verrou ciblant un label ne concerne
     * pas.
     */
    public function modify(?Authenticatable $user, ?Capability $capability = null, ?string $label = null): bool
    {
        if ($capability === null) {
            return true;
        }

        return ! $this->lockResolver->isCapabilityLocked($capability, $label);
    }
}
