<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Levée quand une projection `windows/fs_acl`
 * viole le garde-fou d'authoring {@see \App\Services\Agent\Providers\FsAclAuthoringGuard}
 * au moment de la persistance (observer Eloquent sur {@see \App\Models\CapabilityProjection}).
 *
 * Rend les règles du garde-fou (« deny descendant sur racine protégée
 * interdit », etc.) RÉELLES au runtime serveur : une projection dangereuse ne peut plus être
 * enregistrée (protège aussi le futur formulaire). Le message liste les
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
            "Projection fs_acl refusée par le garde-fou d'authoring :\n- "
            .implode("\n- ", $violations)
        );
    }
}
