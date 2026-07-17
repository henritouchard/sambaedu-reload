<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mode de la « politique de gestion des fichiers » d'un établissement / parc
 * (décision Henri 2026-07-17). Détermine COMMENT les utilisateurs accèdent à
 * leurs fichiers, et en particulier si l'agent monte les lecteurs réseau SMB.
 *
 *  - `Partages`         : modèle historique — lecteurs réseau (home K:, classes
 *                         H:, partages gérés) montés par l'agent
 *                         ({@see \App\Services\Agent\Providers\DrivesStateProvider}).
 *  - `NextcloudDesktop` : pas de lecteur réseau ; les fichiers sont synchronisés
 *                         localement par le client Nextcloud Desktop (Windows).
 *                         Le déploiement du client est un chantier ultérieur —
 *                         ici la politique coupe seulement les lecteurs.
 *  - `AutreWeb`         : ni lecteur réseau ni client de synchro ; tout passe par
 *                         une interface web (Nextcloud web dans notre cas).
 *
 * Défaut d'instance = `Partages` (comportement historique préservé). La valeur
 * effective se résout global (défaut) surchargé par parc — {@see \App\Services\FilePolicyService}.
 */
enum FilePolicyMode: string
{
    case Partages = 'partages';
    case NextcloudDesktop = 'nextcloud_desktop';
    case AutreWeb = 'autre_web';

    public function label(): string
    {
        return match ($this) {
            self::Partages => 'Partages réseau (lecteurs K:, H:, partages)',
            self::NextcloudDesktop => 'Nextcloud Desktop (synchronisation locale)',
            self::AutreWeb => 'Web uniquement (Nextcloud web / autre)',
        };
    }

    /**
     * L'agent doit-il monter les lecteurs réseau (K:/H:/partages) dans ce mode ?
     * Seul le mode `Partages` émet des lecteurs — les deux autres coupent TOUT
     * montage (aucun dépôt de fichier sur un lecteur/le bureau).
     */
    public function drivesEnabled(): bool
    {
        return $this === self::Partages;
    }
}
