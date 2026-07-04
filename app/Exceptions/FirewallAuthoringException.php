<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Story 36.2 (AC3) — levée quand une projection `windows/firewall` viole le
 * garde-fou d'authoring {@see \App\Services\Agent\Providers\FirewallAuthoringGuard}
 * au moment de la persistance (observer Eloquent sur
 * {@see \App\Models\CapabilityProjection}).
 *
 * Rend la décision Q3 (« block couvrant le LAN/tout interdit », intersection
 * mathématique) RÉELLE au runtime serveur : une projection dangereuse ne peut
 * plus être enregistrée (protège aussi le futur formulaire 36.4). Le message
 * liste les violations en clair (FR). Jumeau de
 * {@see FsAclAuthoringException}.
 */
class FirewallAuthoringException extends \RuntimeException
{
    /**
     * @param  list<string>  $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(
            "Projection firewall refusée par le garde-fou d'authoring (Story 36.2) :\n- "
            .implode("\n- ", $violations)
        );
    }
}
