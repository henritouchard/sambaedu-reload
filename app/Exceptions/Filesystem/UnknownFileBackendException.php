<?php

declare(strict_types=1);

namespace App\Exceptions\Filesystem;

use App\Enums\FileBackendName;
use RuntimeException;

/**
 * Story 60.3 — la résolution d'un backend a échoué, et elle le dit.
 *
 * **Fail-closed, toujours.** Il n'existe aucun repli sur un backend par défaut :
 * résoudre `posix` en `preview` « pour que ça passe » ferait croire à une
 * prévisualisation là où un administrateur attend une application réelle, et
 * l'inverse écrirait des permissions POSIX sur un partage hébergé ailleurs. Les
 * deux sont la même faute — un signal accepté qui n'atteint pas son destinataire.
 *
 * Deux causes, deux messages :
 *  - une valeur de colonne HORS VOCABULAIRE ({@see self::unknownValue()}) ;
 *  - un nom du vocabulaire dont l'implémentation n'est pas encore livrée
 *    ({@see self::notImplemented()}) — c'est le cas de `posix` dans cette story,
 *    et le message nomme la story qui le lèvera.
 */
final class UnknownFileBackendException extends RuntimeException
{
    /**
     * Le nom appartient au vocabulaire, mais aucune implémentation ne lui répond.
     *
     * @param  list<FileBackendName>  $available
     */
    public static function notImplemented(FileBackendName $name, array $available, string $story): self
    {
        return new self(sprintf(
            'aucune implémentation de backend de fichiers n\'est enregistrée pour « %s » '
            . '(disponibles : %s) — elle est livrée par la story %s.',
            $name->value,
            $available === []
                ? 'aucune'
                : implode(', ', array_map(static fn (FileBackendName $n): string => $n->value, $available)),
            $story,
        ));
    }

    /**
     * La valeur stockée n'appartient pas au vocabulaire fermé
     * {@see FileBackendName}. Jamais un repli : l'appelant doit corriger la donnée.
     */
    public static function unknownValue(mixed $raw): self
    {
        return new self(sprintf(
            'valeur de backend de fichiers inconnue « %s » (vocabulaire : %s) — '
            . 'aucun repli sur un défaut : un partage dont l\'autorité d\'écriture est illisible '
            . 'ne doit pas être provisionné au hasard.',
            is_scalar($raw) ? (string) $raw : gettype($raw),
            implode('|', FileBackendName::values()),
        ));
    }
}
