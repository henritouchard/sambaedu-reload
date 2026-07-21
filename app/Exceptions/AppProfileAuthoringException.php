<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Story 36.5 (AC1) — levée quand une projection `windows/app_profile` viole le
 * garde-fou d'authoring {@see \App\Services\Agent\Providers\AppProfileAuthoringGuard}
 * au moment de la persistance (observer Eloquent sur
 * {@see \App\Models\CapabilityProjection}).
 *
 * Rend la décision « nom de profil neuf hors radical sambaedu » (piège n°1)
 * RÉELLE au runtime serveur : un catalogue qui collisionnerait avec le
 * nettoyage `legacy_cleanup` (38.3) ne peut plus être enregistré. Le message
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
            "Projection app_profile refusée par le garde-fou d'authoring (Story 36.5) :\n- "
            .implode("\n- ", $violations)
        );
    }
}
