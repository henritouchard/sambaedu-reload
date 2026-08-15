<?php

declare(strict_types=1);

namespace App\Exceptions\Filesystem;

use App\Enums\ActiveCloud;
use App\Enums\FileBackendName;
use App\Exceptions\OpenCloud\OpenCloudConfigurationException;
use App\Services\Filesystem\Backend\FileBackendSelection;
use App\Services\Filesystem\FileLocations;
use App\Services\Filesystem\FileLocationService;
use RuntimeException;

/**
 * Story 63.1 — LE REFUS QUI NOMME, QUAND UN EMPLACEMENT NE PEUT PAS ÊTRE
 * REPRÉSENTÉ.
 *
 * Patron {@see UnknownFileBackendException} et
 * {@see OpenCloudConfigurationException} : chaque
 * fabrique nomme l'OBJET concerné (« l'espace perso », « l'espace partagé »,
 * « le cloud actif »), la VALEUR lue et le VOCABULAIRE ou l'incohérence en
 * cause — jamais un repli silencieux sur un défaut. Levée aussi bien par
 * {@see FileLocations::make()} (garde à l'écriture)
 * que par {@see FileLocationService::current()}
 * (même garde, rejouée à la lecture) : une ligne de `system_settings` se
 * modifie en SQL, en tinker ou par un import de configuration, et la garde ne
 * doit pas vivre que dans le seul chemin d'écriture applicatif.
 */
final class FileLocationException extends RuntimeException
{
    /**
     * L'aperçu ({@see FileBackendName::Preview}) est désigné comme
     * emplacement — il n'écrit aucun droit, il ne peut donc être l'autorité
     * d'aucun fichier réel. Symétrique de
     * {@see FileBackendSelection::refusalFor()}.
     */
    public static function previewIsNotALocation(string $objet): self
    {
        return new self(sprintf(
            '%s ne peut pas être confié au backend d\'aperçu : l\'aperçu n\'écrit aucun droit, il ne '
            .'peut donc pas être l\'autorité d\'un emplacement réel. Choisissez « Serveur de fichiers '
            .'(POSIX/SMB) » ou le cloud actif dans Administration › Fichiers.',
            ucfirst($objet),
        ));
    }

    /**
     * Un emplacement désigne un backend cloud qui n'est PAS le cloud actif de
     * l'instance — garde n° 2 du cadrage, rejouée côté service (iso AC3 de
     * 61.4).
     */
    public static function authorityIsNotTheActiveCloud(
        string $objet,
        FileBackendName $authority,
        ActiveCloud $activeCloud,
    ): self {
        return new self(sprintf(
            '%s désigne « %s », mais le cloud actif de l\'instance est « %s » : un emplacement ne peut '
            .'jamais pointer vers un cloud qui n\'est pas le cloud actif. Changez le cloud actif ou cet '
            .'emplacement dans Administration › Fichiers.',
            ucfirst($objet),
            $authority->value,
            $activeCloud->label(),
        ));
    }

    /**
     * La valeur lue pour un emplacement n'appartient pas au vocabulaire fermé
     * {@see FileBackendName}. Jamais un repli : l'appelant doit corriger la
     * donnée.
     *
     * Le vocabulaire annoncé est celui qui est réellement ACCEPTABLE comme
     * emplacement ({@see FileLocations::ACCEPTABLE_AUTHORITIES}), donc SANS
     * l'aperçu : annoncer `preview` orienterait vers une valeur que
     * {@see FileLocations::make()} refuserait au tour suivant.
     */
    public static function unknownAuthority(string $objet, mixed $raw): self
    {
        return new self(sprintf(
            '%s porte une valeur d\'autorité inconnue « %s » (vocabulaire attendu : %s) — aucun repli sur '
            .'un défaut : corrigez la donnée dans Administration › Fichiers.',
            ucfirst($objet),
            is_scalar($raw) ? (string) $raw : gettype($raw),
            implode('|', FileLocations::acceptableAuthorityValues()),
        ));
    }

    /**
     * La valeur lue pour le cloud actif n'appartient pas au vocabulaire fermé
     * {@see ActiveCloud}. Jamais un repli.
     */
    public static function unknownActiveCloud(mixed $raw): self
    {
        return new self(sprintf(
            'Le cloud actif porte une valeur inconnue « %s » (vocabulaire attendu : %s) — aucun repli sur '
            .'un défaut : corrigez la donnée dans Administration › Fichiers.',
            is_scalar($raw) ? (string) $raw : gettype($raw),
            implode('|', ActiveCloud::values()),
        ));
    }

    /**
     * Il manque l'une des trois valeurs dans le payload persisté. Ce payload
     * n'a qu'un seul écrivain ({@see FileLocationService::set()}),
     * qui écrit toujours les trois ensemble : un payload amputé est une
     * corruption ou un bricolage manuel, jamais un état à tolérer — le
     * tolérer inventerait une décision que personne n'a prise.
     */
    public static function missingValue(string $objet): self
    {
        return new self(sprintf(
            '%s est absent du réglage des emplacements : les trois valeurs (espace perso, espace '
            .'partagé, cloud actif) doivent toujours être présentes ensemble.',
            ucfirst($objet),
        ));
    }

    /**
     * La ligne persistée EXISTE mais ne porte pas un objet à trois clés — une
     * chaîne, un nombre, un booléen. Ce n'est pas une absence de décision :
     * c'est une décision ILLISIBLE, et la distinction est le cœur de la garde
     * n° 3 du cadrage.
     *
     * Retomber ici sur les défauts inventerait « espace perso sur le serveur de
     * fichiers, aucun cloud » alors que personne ne l'a décidé, et masquerait
     * la corruption au lieu de la nommer. Seule l'ABSENCE de ligne rend les
     * défauts.
     */
    public static function malformedPayload(mixed $raw): self
    {
        return new self(sprintf(
            'Le réglage des emplacements existe mais n\'est pas un objet à trois clés : la valeur lue est '
            .'de type %s. Aucun repli sur un défaut — une ligne illisible n\'est pas une absence de '
            .'décision. Réparez-la avec « php artisan files:adopt-locations --force », ou corrigez-la '
            .'dans Administration › Fichiers.',
            get_debug_type($raw),
        ));
    }
}
