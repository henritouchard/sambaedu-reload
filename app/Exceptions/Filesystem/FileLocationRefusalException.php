<?php

declare(strict_types=1);

namespace App\Exceptions\Filesystem;

use App\Services\Filesystem\FileLocationChangeGuard;
use App\Services\Filesystem\FileLocationOptions;
use InvalidArgumentException;

/**
 * Story 63.3 (correction de revue) — LE REFUS MÉTIER DES DEUX GARDES
 * D'EMPLACEMENT, ET RIEN D'AUTRE.
 *
 * Les deux gardes ({@see FileLocationOptions::assertAvailable()} et
 * {@see FileLocationChangeGuard::assertChangeIsAllowed()}) levaient une
 * `InvalidArgumentException` nue. L'écran, lui, l'attrapait pour la présenter à
 * l'administrateur comme un refus métier — si bien qu'une
 * `InvalidArgumentException` VENUE D'AILLEURS (une conversion, une méthode de
 * collection, une dépendance) aurait été affichée en toast comme si les gardes
 * l'avaient prononcée, et le vrai défaut serait resté invisible.
 *
 * Ce type dit QUI refuse. Il **étend `InvalidArgumentException`** délibérément :
 * les appelants qui attrapaient le type large continuent de fonctionner à
 * l'identique — le resserrement est un gain de précision, jamais une rupture de
 * contrat pour un consommateur existant.
 *
 * Distinct de {@see FileLocationException}, qui dit qu'une VALEUR est illisible
 * ou incohérente (vocabulaire, payload amputé, combinaison irreprésentable) :
 * ici, la valeur est parfaitement lisible — c'est l'ÉTAT DE L'INSTANCE qui
 * refuse qu'on la pose.
 */
final class FileLocationRefusalException extends InvalidArgumentException
{
    /**
     * La position demandée n'est pas posable — le motif est celui, destiné à
     * l'administrateur, que rend {@see FileLocationOptions::refusalFor()}.
     */
    public static function positionIsNotAvailable(string $motif): self
    {
        return new self($motif);
    }

    /**
     * Le déplacement demandé est refusé parce que l'espace porte des données —
     * le motif est celui de {@see FileLocationChangeGuard::refusalFor()}, qui
     * nomme le chantier qui lèvera ce refus.
     */
    public static function spaceCarriesData(string $motif): self
    {
        return new self($motif);
    }
}
