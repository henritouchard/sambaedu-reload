<?php

declare(strict_types=1);

namespace App\Exceptions\Filesystem;

use RuntimeException;

/**
 * Story 34.2 (piège #3 de 34.1, finding M1) — levée lorsqu'attribuer une lettre
 * EXPLICITE à un répertoire réseau INTRODUIRAIT une collision : un AUTRE
 * répertoire DISTINCT vise déjà la MÊME lettre pour une audience qui se recouvre
 * (au moins une maille commune — user / groupe / parc — résolvant les deux). Le
 * type `drives` étant `aggregate`, deux payloads `{letter, unc, label}` DIFFÉRENTS
 * réclamant la même lettre produisent un comportement indéfini côté agent : c'est
 * une erreur d'authoring que 34.1 a délibérément déléguée à la validation
 * prédictive 34.2. L'opération est REFUSÉE avant écriture/provision.
 *
 * Le message est en français et **affichable** tel quel en toast (via
 * {@see \App\Components\Traits\WithToasts}) — il nomme la lettre et les deux
 * répertoires en conflit. Patron : {@see \App\Exceptions\ControlHub\UpstreamLockCollisionException}.
 */
final class NetworkShareLetterCollisionException extends RuntimeException
{
    /**
     * @param  list<array{letter:string,shareName:string,otherName:string,sharedCount:int}>  $collisions
     */
    public static function fromCollisions(array $collisions): self
    {
        $lines = array_map(static function (array $c): string {
            $count = (int) ($c['sharedCount'] ?? 0);
            $audience = $count > 1 ? "{$count} cibles communes" : 'une cible commune';

            return sprintf(
                'la lettre « %s » est déjà attribuée au répertoire « %s » pour %s avec « %s ».',
                $c['letter'],
                $c['otherName'],
                $audience,
                $c['shareName'],
            );
        }, $collisions);

        return new self(
            'Conflit de lettre de lecteur : '
            .implode(' ', $lines)
            .' Choisissez une autre lettre ou laissez le champ vide (attribution automatique).'
        );
    }
}
