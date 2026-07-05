<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Story 35.6 (AC3) — levée quand une projection `windows/privilege` viole le
 * garde-fou d'authoring {@see \App\Services\Agent\Providers\PrivilegeAuthoringGuard}
 * au moment de la persistance (observer Eloquent sur
 * {@see \App\Models\CapabilityProjection}).
 *
 * Rend la décision SeDeny*-only RÉELLE au runtime serveur : une projection
 * portant un droit *grant* (risque de VERROUILLAGE machine — piège #3) ne peut
 * plus être enregistrée (protège aussi un futur formulaire). Le message liste
 * les violations en clair (FR). Jumeau de {@see FsAclAuthoringException} /
 * {@see FirewallAuthoringException}.
 */
class PrivilegeAuthoringException extends \RuntimeException
{
    /**
     * @param  list<string>  $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(
            "Projection privilege refusée par le garde-fou d'authoring (Story 35.6) :\n- "
            .implode("\n- ", $violations)
        );
    }
}
