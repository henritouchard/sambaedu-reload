<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

/**
 * Story 61.1 — CE QU'IL EST ADVENU D'UN MONTAGE, par élément.
 *
 * Quatre issues, et surtout pas un booléen global : « le provisionnement a
 * réussi » ne dit pas si le second passage a recréé un doublon, et un rapport qui
 * ne distingue pas « créé » de « déjà conforme » rend l'idempotence invérifiable
 * depuis l'exploitation.
 *
 * `Simule` existe parce que la simulation ne doit pas mentir : afficher « créé »
 * dans un `--dry-run` ferait croire à une écriture qui n'a pas eu lieu.
 */
enum NextcloudMountAction: string
{
    case Cree = 'cree';
    case Conforme = 'conforme';
    case MisAJour = 'mis_a_jour';
    case Echec = 'echec';
    case Simule = 'simule';

    public function label(): string
    {
        return match ($this) {
            self::Cree => 'créé',
            self::Conforme => 'déjà conforme',
            self::MisAJour => 'mis à jour',
            self::Echec => 'échec',
            self::Simule => 'serait créé ou mis à jour',
        };
    }

    public function isFailure(): bool
    {
        return $this === self::Echec;
    }
}
