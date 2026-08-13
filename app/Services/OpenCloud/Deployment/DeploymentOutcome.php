<?php

declare(strict_types=1);

namespace App\Services\OpenCloud\Deployment;

/**
 * Les TROIS issues d'un déploiement, et le code de retour de chacune.
 *
 * « Déjà conforme » n'est pas un échec et n'est pas non plus un déploiement :
 * c'est l'issue NORMALE d'un rejeu, et l'exploitant doit pouvoir la distinguer
 * sans lire une phrase. C'est la même leçon que le vocabulaire de résultat du
 * plan de fichiers — un code de retour qui agrège « fait » et « rien à faire »
 * rend une commande de convergence illisible en supervision.
 */
enum DeploymentOutcome: string
{
    /** L'instance a été montée, ou son état a changé pour atteindre la cible. */
    case Deployed = 'deployed';

    /** Rien à faire : l'instance était déjà dans l'état voulu. */
    case Conforming = 'conforming';

    /** Refus NOMMÉ : validation, port occupé, sonde en échec, seam indisponible. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Deployed => 'déployé',
            self::Conforming => 'déjà conforme',
            self::Failed => 'échec',
        };
    }

    /** Code de sortie de la commande d'administration. */
    public function exitCode(): int
    {
        return match ($this) {
            self::Deployed => 0,
            self::Conforming => 0,
            self::Failed => 2,
        };
    }

    public function isFailure(): bool
    {
        return $this === self::Failed;
    }
}
