<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Levée quand une projection `windows/app_profile` viole le
 * garde-fou d'authoring {@see \App\Services\Agent\Providers\AppProfileAuthoringGuard}
 * au moment de la persistance (observer Eloquent sur
 * {@see \App\Models\CapabilityProjection}).
 *
 * Rend la règle « nom de profil neuf hors radical sambaedu »
 * RÉELLE au runtime serveur : un catalogue qui collisionnerait avec le
 * nettoyage `legacy_cleanup` ne peut plus être enregistré. Le message
 * liste les violations en clair (FR).
 */
class AppProfileAuthoringException extends \RuntimeException
{
    /**
     * @param  list<string>  $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(
            "Projection app_profile refusée par le garde-fou d'authoring :\n- "
            .implode("\n- ", $violations)
        );
    }
}
