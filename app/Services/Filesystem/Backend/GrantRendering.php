<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend;

use App\Services\Filesystem\Plan\PlanGrant;

/**
 * Ce qu'une autorité d'écriture REND d'un octroi — demandé AVANT qu'elle ne
 * l'écrive, pour que l'écran de composition dise la vérité de celle qui exécutera.
 *
 * ---------------------------------------------------------------------------
 * **CE N'EST PAS UNE DÉCLARATION DE CAPACITÉS**, et la distinction porte tout le
 * reste. Une table déclarative est un second endroit où la vérité est écrite : un
 * backend peut la remplir de travers sans que rien ne le contredise. Ici, chaque
 * implémentation est tenue de répondre en APPELANT le code qui exécute — la même
 * traduction de verbes, sur les mêmes entrées. La réponse ne peut donc pas
 * diverger de l'écriture sans que l'écriture ait changé, et un test l'aligne
 * backend par backend sur les seize combinaisons de verbes.
 *
 * ---------------------------------------------------------------------------
 * **QUATRE CONSTATS QUI NE SE CONFONDENT PAS.**
 *
 *  - `$missing` — les verbes non rendus SUR CE NŒUD. Constat local.
 *  - `$inexpressible` — ceux que ce backend ne rendra JAMAIS, quel que soit le
 *    nœud, avec la raison en vocabulaire métier. C'est une limite permanente de
 *    son modèle : l'écran a le droit d'en empêcher la saisie.
 *  - `$demoted` — l'octroi SERAIT exact si le nœud portait le mécanisme qui
 *    l'approche, et ce nœud ne le porte pas. La perte appartient au nœud, pas au
 *    modèle : on saisit, et on déclare.
 *  - `$approximated` — l'octroi est rendu en entier, mais PAR un mécanisme qui ne
 *    fait qu'en approcher l'intention. Rien ne manque et quelque chose est tout de
 *    même à dire.
 *
 * `$differentiated` est d'un autre ordre : il ne dit pas une perte mais une FORME
 * d'exécution — dossiers et fichiers n'exigent pas le même niveau, l'autorité pose
 * donc l'octroi en deux temps.
 */
final class GrantRendering
{
    /**
     * @param  list<string>  $requested  ce que l'octroi DEMANDE
     * @param  list<string>  $rendered  ce que le backend REND ici
     * @param  list<string>  $missing  ce qu'il ne rend PAS ici, ordre canonique
     * @param  array<string, string>  $inexpressible  verbe => raison, limites PERMANENTES du modèle
     */
    private function __construct(
        public readonly array $requested,
        public readonly array $rendered,
        public readonly array $missing,
        public readonly array $inexpressible,
        public readonly bool $differentiated,
        public readonly bool $approximated,
        public readonly bool $demoted,
    ) {
    }

    /**
     * @param  list<string>  $requested
     * @param  list<string>  $rendered
     * @param  array<string, string>  $inexpressible
     */
    public static function of(
        array $requested,
        array $rendered,
        array $inexpressible = [],
        bool $differentiated = false,
        bool $approximated = false,
        bool $demoted = false,
    ): self {
        $canonical = static fn (array $verbs): array => array_values(array_filter(
            PlanGrant::VERBS,
            static fn (string $verb): bool => in_array($verb, $verbs, true),
        ));

        $rendered = $canonical($rendered);

        return new self(
            $canonical($requested),
            $rendered,
            array_values(array_diff($canonical($requested), $rendered)),
            $inexpressible,
            $differentiated,
            $approximated,
            $demoted,
        );
    }

    /**
     * Le backend rend l'octroi tel quel, sans perte ni mécanisme d'approche.
     *
     * @param  list<string>  $requested
     */
    public static function exact(array $requested): self
    {
        return self::of($requested, $requested);
    }

    /** Le backend rend-il EXACTEMENT ce que l'octroi demande ? */
    public function isExact(): bool
    {
        return $this->missing === [];
    }

    /** L'octroi ne produit RIEN : aucun de ses verbes n'est rendu. */
    public function isEmpty(): bool
    {
        return $this->rendered === [];
    }

    /** Ce verbe est-il une limite PERMANENTE du modèle de ce backend ? */
    public function forbids(string $verb): bool
    {
        return array_key_exists($verb, $this->inexpressible);
    }
}
