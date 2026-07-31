<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Story 56.2 — Refus de CONTRAT du moteur d'installation
 * ({@see \App\Services\Extensions\ExtensionInstallService}).
 *
 * ⚠️ Distinction à ne pas perdre de vue en review : cette exception porte les
 * refus qui surviennent AVANT qu'une extension précise soit identifiée (clé
 * inconnue, clé ambiguë, moteur déjà occupé) — il n'y a alors aucune ligne
 * `extensions` à laquelle attribuer une trace d'audit. Tous les refus qui
 * portent sur une extension RÉSOLUE (type, source, signature, borne de taille,
 * échec d'étape) ne lèvent PAS : ils sont journalisés en
 * `ExtensionAuditLog::ACTION_INSTALL_FAILED` et retournés à l'appelant sous
 * forme de tableau plat (NFR15) — un échec d'installation est un fait
 * observable, pas un bug de programmation.
 *
 * Le message est destiné à l'OPÉRATEUR : il dit ce qui a été refusé et ce qu'il
 * faut faire. Jamais l'URL d'un dépôt (elle peut porter un jeton), jamais un
 * secret.
 */
final class ExtensionInstallException extends RuntimeException
{
    /**
     * Story 56.3 — Catégorie STABLE du refus, distincte du message.
     *
     * Le message est écrit pour un opérateur devant un terminal : il est long,
     * il cite les sources en conflit, il donne la commande à taper. Ce n'est
     * pas ce qu'on persiste dans `extension_install_runs.error` (191
     * caractères, lu par une UI, soumis à la règle « jamais d'URL, jamais de
     * secret »). La catégorie, elle, est courte, close et traduisible —
     * {@see \App\Models\ExtensionInstallRun::errorLabel()} en fait une phrase.
     *
     * Ajout strictement ADDITIF : la construction reste privée, les messages
     * 56.2 sont inchangés.
     */
    public readonly string $category;

    private function __construct(string $message, string $category)
    {
        parent::__construct($message);

        $this->category = $category;
    }

    /** Aucune extension de cette clé au registre. */
    public static function unknownExtension(string $key): self
    {
        return new self(
            "Aucune extension « {$key} » au registre. Vérifiez la clé, ou synchronisez les sources "
            .'(`php artisan ext:sources:sync`).',
            \App\Models\ExtensionInstallRun::ERROR_UNKNOWN_EXTENSION,
        );
    }

    /**
     * Plusieurs sources publient cette clé (collision TOLÉRÉE au catalogue,
     * décision 56.1) : l'opérateur doit dire laquelle il installe. On refuse
     * plutôt que de choisir — installer le paquet d'une source non voulue est
     * exactement ce que la chaîne de confiance sert à empêcher.
     *
     * @param  list<string>  $sourceKeys
     */
    public static function ambiguousKey(string $key, array $sourceKeys): self
    {
        return new self(
            "L'extension « {$key} » est publiée par plusieurs sources (".implode(', ', $sourceKeys).'). '
            .'Précisez laquelle installer : --source=<clé de la source>.',
            \App\Models\ExtensionInstallRun::ERROR_AMBIGUOUS_KEY,
        );
    }

    /** La source demandée par `--source` ne publie pas cette clé. */
    public static function unknownSourceForKey(string $key, string $sourceKey): self
    {
        return new self(
            "La source « {$sourceKey} » ne publie aucune extension « {$key} ».",
            \App\Models\ExtensionInstallRun::ERROR_UNKNOWN_SOURCE,
        );
    }

    /**
     * Une installation (ou une désinstallation) est déjà en cours.
     *
     * Le verrou est GLOBAL et non par-clé (décision 56.2 #2) : les
     * installations sont des actes d'administration rares, et un verrou unique
     * rend l'allocation de port et l'unicité des clés triviales, sans course.
     */
    public static function engineBusy(): self
    {
        return new self(
            'Une opération d\'installation d\'extension est déjà en cours sur cette instance. '
            .'Réessayez dans un instant.',
            \App\Models\ExtensionInstallRun::ERROR_ENGINE_BUSY,
        );
    }

    /**
     * `ext:remove` sur une extension de type `link`.
     *
     * Le volet `link` de FR10 est DÉJÀ livré (Story 54.2, bouton
     * « Désinstaller » de la bibliothèque et de la fiche). Le dupliquer ici
     * créerait deux chemins d'audit pour le même acte (décision 56.2 #4).
     */
    public static function linkNotSupported(string $key): self
    {
        return new self(
            "« {$key} » est une extension de type « lien » : elle n'installe aucun composant système. "
            .'Désinstallez-la depuis la bibliothèque (/admin/extensions) — c\'est le cycle de la Story 54.2.',
            \App\Models\ExtensionInstallRun::ERROR_LINK_NOT_SUPPORTED,
        );
    }
}
