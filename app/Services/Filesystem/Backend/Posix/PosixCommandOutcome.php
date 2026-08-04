<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Posix;

/**
 * Story 60.4 — issue d'UN geste système, à l'intérieur du backend.
 *
 * **Ce n'est pas un rapport, et c'est pour ça qu'il porte un booléen.** La règle
 * « aucun booléen dans un rapport » vise ce qui traverse la ligne de contrat :
 * un verdict global y masquerait un nœud en échec. Ici on est SOUS la ligne, à
 * la granularité d'une commande, et c'est justement en gardant ces booléens-là
 * séparés que le backend peut effondrer N gestes en UN état de nœud selon la
 * convention de précédence — au lieu de les agréger au fur et à mesure.
 */
final class PosixCommandOutcome
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $output,
        public readonly string $error,
    ) {
    }
}
