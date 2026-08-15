<?php

declare(strict_types=1);

namespace App\Services\Filesystem;

use App\Enums\ActiveCloud;
use App\Enums\FileBackendName;
use App\Exceptions\Filesystem\FileLocationException;

/**
 * Story 63.1 — OÙ VIT L'ESPACE PERSO, OÙ VIT L'ESPACE PARTAGÉ, ET C'EST TOUT.
 *
 * Objet de valeur PUR (aucune I/O, aucune application démarrée pour le
 * tester) qui porte les trois réglages du cadrage §4 : l'autorité de l'espace
 * perso, l'autorité de l'espace partagé, et le cloud actif de l'instance.
 *
 * **La garde est STRUCTURELLE, pas applicative.** Le constructeur est privé :
 * {@see self::make()} est l'UNIQUE porte d'entrée, et elle rejoue à chaque
 * appel les trois refus ci-dessous. Il n'existe ni setter, ni
 * `withEspacePerso()`, ni constructeur public qui accepterait un tableau brut
 * — un dev qui en ajouterait un rouvrirait exactement le trou que cette
 * story ferme (garde-fou §8 du cadrage : l'état « autorité incohérente »
 * doit être IRREPRÉSENTABLE, pas seulement interdit par un `if` qu'on peut
 * oublier d'appeler).
 *
 * **Trois refus, et rien de plus** :
 *  1. {@see FileBackendName::Preview} n'est jamais un emplacement : il
 *     n'écrit aucun droit.
 *  2. Une autorité cloud (`Nextcloud`/`OpenCloud`) qui désigne un cloud
 *     différent du cloud actif de l'instance.
 *  3. *(conséquence de 2)* Une autorité cloud alors que le cloud actif est
 *     `Aucun` — aucun produit ne peut être l'autorité d'un cloud inexistant.
 *
 * `espace_perso = posix` avec `cloud.actif = nextcloud` est ACCEPTÉ : le
 * cloud peut être configuré pendant que l'espace perso reste sur le serveur
 * — c'est le cas courant, et c'est précisément ce que l'ancien
 * `FilePolicyMode` (reverté) ne savait pas exprimer (cadrage §3).
 */
final readonly class FileLocations
{
    /**
     * Le vocabulaire réellement ACCEPTABLE comme emplacement : celui de
     * {@see FileBackendName} MOINS l'aperçu, qui n'écrit aucun droit et ne peut
     * donc être l'autorité d'aucun fichier réel.
     *
     * C'est cette liste — jamais le vocabulaire complet — que les refus
     * annoncent : proposer une valeur qui sera refusée au tour suivant ferait
     * tourner l'exploitant en rond. Un test épingle qu'elle vaut exactement
     * `FileBackendName::cases()` privé de l'aperçu, pour qu'elle ne puisse pas
     * dériver si le vocabulaire s'élargit.
     *
     * @var list<FileBackendName>
     */
    public const ACCEPTABLE_AUTHORITIES = [
        FileBackendName::Posix,
        FileBackendName::Nextcloud,
        FileBackendName::OpenCloud,
    ];

    private function __construct(
        public FileBackendName $espacePerso,
        public FileBackendName $espacePartage,
        public ActiveCloud $cloudActif,
    ) {}

    /**
     * La SEULE fabrique. Rejoue les trois gardes de cohérence avant de
     * renvoyer un objet garanti valide.
     *
     * @throws FileLocationException
     */
    public static function make(
        FileBackendName $espacePerso,
        FileBackendName $espacePartage,
        ActiveCloud $cloudActif,
    ): self {
        self::assertIsAcceptableLocation('l\'espace perso', $espacePerso, $cloudActif);
        self::assertIsAcceptableLocation('l\'espace partagé', $espacePartage, $cloudActif);

        return new self($espacePerso, $espacePartage, $cloudActif);
    }

    private static function assertIsAcceptableLocation(
        string $objet,
        FileBackendName $authority,
        ActiveCloud $cloudActif,
    ): void {
        if ($authority === FileBackendName::Preview) {
            throw FileLocationException::previewIsNotALocation($objet);
        }

        if ($authority === FileBackendName::Posix) {
            return;
        }

        // Ici, $authority est forcément Nextcloud ou OpenCloud (les 4 cases de
        // FileBackendName sont couvertes : Preview et Posix sont sorties
        // au-dessus). L'autorité doit désigner EXACTEMENT le cloud actif.
        $expectedActiveCloud = $authority === FileBackendName::Nextcloud
            ? ActiveCloud::Nextcloud
            : ActiveCloud::OpenCloud;

        if ($cloudActif !== $expectedActiveCloud) {
            throw FileLocationException::authorityIsNotTheActiveCloud($objet, $authority, $cloudActif);
        }
    }

    /**
     * L'espace perso est-il servi par le serveur de fichiers (SMB) ? C'est la
     * couture que 63.2 consommera pour décider d'émettre un lecteur réseau —
     * aucune lettre, aucun chemin réseau, aucun chemin n'apparaît ici,
     * seulement ce booléen dérivé.
     */
    public function espacePersoSurSmb(): bool
    {
        return $this->espacePerso === FileBackendName::Posix;
    }

    /** Même lecture dérivée pour l'espace partagé — la couture du second lecteur historique. */
    public function espacePartageSurSmb(): bool
    {
        return $this->espacePartage === FileBackendName::Posix;
    }

    /**
     * Les littéraux du vocabulaire acceptable — la forme que consomment les
     * messages de refus.
     *
     * @return list<string>
     */
    public static function acceptableAuthorityValues(): array
    {
        return array_map(
            static fn (FileBackendName $backend): string => $backend->value,
            self::ACCEPTABLE_AUTHORITIES,
        );
    }

    /**
     * Le payload persisté par {@see FileLocationService::set()} — les trois
     * clés littérales du cadrage §4, mot pour mot.
     *
     * @return array{'espace_perso.autorite': string, 'espace_partage.autorite': string, 'cloud.actif': string}
     */
    public function toArray(): array
    {
        return [
            'espace_perso.autorite' => $this->espacePerso->value,
            'espace_partage.autorite' => $this->espacePartage->value,
            'cloud.actif' => $this->cloudActif->value,
        ];
    }
}
