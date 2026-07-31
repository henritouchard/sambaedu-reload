<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Story 56.3 — Refus de l'ORCHESTRATEUR de runs
 * ({@see \App\Services\Extensions\ExtensionOperationRunner}).
 *
 * ⚠️ Distinction avec {@see ExtensionInstallException} : celle-là est levée par
 * le MOTEUR (il ne peut pas exécuter l'opération) ; celle-ci l'est AVANT que
 * quoi que ce soit ne parte en file d'attente (il n'y a pas lieu de créer un
 * run). Aucune des deux ne doit jamais produire une 500 dans une page admin :
 * les composants Livewire les attrapent et les transforment en toast.
 *
 * Les messages sont écrits pour un ADMIN devant son navigateur : courts,
 * actionnables, sans jargon technique, et — comme partout dans le domaine des
 * extensions — sans URL de dépôt et sans secret.
 */
final class ExtensionOperationException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * Une opération est DÉJÀ en cours sur l'instance.
     *
     * Le verrou du moteur étant GLOBAL (décision 56.2 #2), la garde de
     * l'orchestrateur l'est aussi : ce n'est pas « cette extension est
     * occupée », c'est « le moteur est occupé ». Le message le dit tel quel,
     * pour que l'admin ne cherche pas ce qu'il aurait fait de travers.
     */
    public static function alreadyRunning(): self
    {
        return new self('Une opération d\'extension est déjà en cours. Attendez sa fin, puis réessayez.');
    }

    /** L'extension visée n'existe plus (écran périmé, prune concurrent). */
    public static function unknownExtension(): self
    {
        return new self('Extension introuvable — la page a été rechargée.');
    }

    /**
     * Le canal de fond n'existe QUE pour le type `app` : une `link` s'intègre
     * et se désintègre instantanément (54.2), il n'y a rien à installer.
     */
    public static function notAnApp(): self
    {
        return new self('Cette extension est un lien : elle n\'installe aucun composant système.');
    }

    /** Opération inconnue — ne peut venir que d'un appel forgé. */
    public static function unsupportedOperation(string $operation): self
    {
        return new self('Opération non reconnue : « '.$operation.' ».');
    }
}
