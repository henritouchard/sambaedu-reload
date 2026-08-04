<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend;

use App\Enums\FileBackendName;
use App\Exceptions\Filesystem\UnknownFileBackendException;
use App\Models\NetworkShare;
use App\Services\Filesystem\Backend\Posix\PosixFileBackend;
use Illuminate\Contracts\Container\Container;

/**
 * Story 60.3 — RÉSOLUTION d'un backend par son nom.
 *
 * Patron familier : on demande une autorité d'écriture par son nom, comme on
 * demande un disque de stockage par le sien — mais sans la bibliothèque de
 * fichiers derrière, qui n'abstrait pas les permissions et ne sert donc à rien ici.
 *
 * **La table des implémentations est du CODE, pas de la configuration** (D6). Un
 * fichier de configuration qui associerait un nom à une classe laisserait croire
 * qu'ajouter un backend est un réglage ; c'est un chantier d'adaptateur, avec ses
 * mesures et ses tests. Le vocabulaire est fermé par une enum, la table est fermée
 * par cette constante.
 *
 * **Story 60.4 — `posix` RÉPOND.** La story 60.3 laissait ce nom sans
 * implémentation : la valeur de colonne était légitime (tous les répertoires
 * existants sont servis par le serveur de fichiers historique), mais rien
 * n'exécutait derrière, et le demander levait une exception nommant la story à
 * venir. La descente de l'exécution sous la ligne de contrat est faite : la table
 * porte l'implémentation, et le test qui épinglait le refus est RETOURNÉ.
 *
 * Un nom sans implémentation reste un échec EXPLICITE, jamais un repli : c'est ce
 * qui attend les backends de l'Epic 61 tant qu'ils ne sont pas écrits.
 */
final class FileBackendRegistry
{
    /**
     * Table FERMÉE nom → classe d'implémentation.
     *
     * @var array<string, class-string<FileBackend>>
     */
    private const IMPLEMENTATIONS = [
        'posix' => PosixFileBackend::class,
        'preview' => PreviewBackend::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * L'implémentation enregistrée pour ce nom.
     *
     * @throws UnknownFileBackendException si aucune implémentation ne répond
     */
    public function get(FileBackendName $name): FileBackend
    {
        $class = self::IMPLEMENTATIONS[$name->value] ?? null;

        if ($class === null) {
            throw UnknownFileBackendException::notImplemented($name, $this->availableNames(), 'à venir');
        }

        /** @var FileBackend $backend */
        $backend = $this->container->make($class);

        return $backend;
    }

    /**
     * Le backend d'un partage, d'après sa colonne.
     *
     * La lecture de la colonne (et son échec explicite sur une valeur hors
     * vocabulaire) vit sur le modèle : c'est lui qui possède la donnée.
     *
     * @throws UnknownFileBackendException valeur illisible, ou nom sans implémentation
     */
    public function forShare(NetworkShare $share): FileBackend
    {
        return $this->get($share->backendName());
    }

    /** `true` si une implémentation répond à ce nom. */
    public function has(FileBackendName $name): bool
    {
        return array_key_exists($name->value, self::IMPLEMENTATIONS);
    }

    /**
     * Les noms qui ont effectivement une implémentation, dans l'ordre du
     * vocabulaire.
     *
     * @return list<FileBackendName>
     */
    public function availableNames(): array
    {
        return array_values(array_filter(
            FileBackendName::cases(),
            fn (FileBackendName $name): bool => $this->has($name),
        ));
    }
}
