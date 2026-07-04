<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Story 36.1 (corr. review #2b) — levée quand une projection `windows/fs_acl`
 * viole le garde-fou d'authoring {@see \App\Services\Agent\Providers\FsAclAuthoringGuard}
 * au moment de la persistance (observer Eloquent sur {@see \App\Models\CapabilityProjection}).
 *
 * Rend la décision Q2 (« deny descendant sur racine protégée interdit », etc.)
 * RÉELLE au runtime serveur : une projection dangereuse ne peut plus être
 * enregistrée (protège aussi le futur formulaire 36.4). Le message liste les
 * violations en clair (FR).
 */
class FsAclAuthoringException extends \RuntimeException
{
    /**
     * @param  list<string>  $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(
            "Projection fs_acl refusée par le garde-fou d'authoring (Story 36.1) :\n- "
            .implode("\n- ", $violations)
        );
    }
}
