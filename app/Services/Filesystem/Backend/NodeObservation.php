<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend;

use App\Enums\FileBackendObservation;
use App\Exceptions\Filesystem\InvalidBackendReportException;
use App\Services\Filesystem\Plan\GroupNameNormalizer;

/**
 * Story 60.3 — état RELU d'un nœud, tel que le backend le voit.
 *
 * **Le plafond a DEUX champs, et il en faut deux.** `plafond` porte la valeur
 * observée (`null` = aucun plafond posé) ; `plafondObserve` dit si le backend a
 * seulement REGARDÉ. Sans le second, `null` signifierait à la fois « il n'y a pas
 * de plafond » et « je n'ai pas regardé » — et sous une politique d'écart STRICTE,
 * ces deux phrases mènent à des conclusions opposées : la première autorise à
 * conclure à un écart, la seconde l'interdit. L'invariant
 * `plafond !== null ⇒ plafondObserve === true` est vérifié au constructeur : dire
 * une valeur sans avoir regardé n'est pas une observation.
 *
 * **Ce que `plafondObserve = false` veut dire, dans les DEUX cas** (correction
 * Henri, 2026-08-04) — et pourquoi l'observation ne suffit pas à trancher :
 *  - backend dont le plafond est **non implémenté** (POSIX aujourd'hui : le
 *    système de fichiers SAIT plafonner, nous ne le pilotons pas, la story est
 *    suspendue) : un plafond posé à la main est un ÉCART RÉEL qu'on choisit de ne
 *    pas observer pour l'instant. Dette visible et datée. Le jour où le pilotage
 *    arrive, il devient observable et c'est un écart ordinaire ;
 *  - backend dont le plafond est **non supporté** (modèle sans plafond de zone) :
 *    `plafondObserve = false` aussi, mais rien ne pourra JAMAIS exister à
 *    comparer — aucun écart n'est exprimable dans ce vocabulaire.
 *
 * Les deux se disent donc pareil ICI. **L'interprétation se lit sur la réponse de
 * `quota()`**, pas sur l'observation : `non_implemente` (temporaire) contre
 * `non_exprimable` (permanent). Écrire la nuance dans l'observation aurait
 * demandé à chaque backend de la redire à chaque nœud, alors qu'elle est une
 * propriété du backend, pas du nœud.
 *
 * **Les octrois n'accompagnent qu'une VRAIE observation.** Un nœud « absent » ou
 * « non observable » qui porterait des octrois affirmerait avoir lu ce qu'il n'a
 * pas lu — le constructeur le refuse.
 *
 * ---------------------------------------------------------------------------
 * **STORY 61.3 — LA CLÔTURE DEVIENT OBSERVABLE, ET C'EST STRICTEMENT ADDITIF.**
 *
 * Le docblock du comparateur d'état l'annonçait depuis la story 60.4 : « c'est un
 * backend à PROPAGATION qui devra matérialiser la clôture — et c'est là seulement
 * qu'elle deviendra comparable ». Ce moment est arrivé.
 *
 * `$closure` porte les sujets que l'état RELU referme effectivement sur ce nœud.
 * Trois valeurs, et la troisième est le cœur du dispositif :
 *
 *  | valeur    | signification                                                    |
 *  |-----------|------------------------------------------------------------------|
 *  | `null`    | ce backend ne dit RIEN de la clôture — aucune comparaison         |
 *  | `[]`      | il a regardé, et il ne referme personne ici                       |
 *  | `[…]`     | il a regardé, et voici sur qui la fermeture est effective          |
 *
 * **`null` n'est pas une liste vide, et l'écart entre les deux est tout le sujet.**
 * Le serveur de fichiers historique n'écrit rien pour la clôture (l'absence
 * d'entrée EST la fermeture) : il ne peut donc rien en observer, il rend `null`, et
 * la comparaison ne se prononce pas. Le backend d'aperçu n'observe rien du tout,
 * même chose. Faire de `null` un `[]` par défaut ferait déclarer à ces deux-là
 * qu'ils ne referment personne — donc un écart sur chaque nœud à clôture, pour
 * chaque partage existant. Le paramètre est optionnel et sa sérialisation
 * rétrocompatible : un rapport écrit avant cette story se relit à l'identique.
 *
 * Un sujet ne peut pas être à la fois dans `$grants` et dans `$closure` sur le même
 * nœud : « on lui a posé quelque chose » et « on a refermé sur lui » sont deux
 * observations différentes, et un backend qui rendrait les deux affirmerait deux
 * états d'un même sujet. Le constructeur le refuse.
 */
final class NodeObservation
{
    /** Chemin du nœud, tel qu'il figure dans le plan (racine comprise). */
    public readonly string $path;

    public readonly FileBackendObservation $status;

    /** @var list<ObservedGrant> triés par clé stable */
    public readonly array $grants;

    /** Plafond OBSERVÉ en octets, `null` = aucun plafond posé (ou non regardé). */
    public readonly ?int $plafond;

    /** Le backend a-t-il REGARDÉ l'état de plafond de ce nœud ? */
    public readonly bool $plafondObserve;

    /** Cause. Obligatoire pour `echec`. */
    public readonly ?string $detail;

    /**
     * Story 61.3 — les sujets que l'état relu REFERME sur ce nœud. `null` = ce
     * backend ne dit rien de la clôture (voir le docblock de classe).
     *
     * @var list<\App\Services\Filesystem\Plan\PlanSubject>|null
     */
    public readonly ?array $closure;

    /**
     * @param  list<ObservedGrant>  $grants
     * @param  list<\App\Services\Filesystem\Plan\PlanSubject>|null  $closure
     */
    public function __construct(
        string $path,
        FileBackendObservation $status,
        array $grants = [],
        ?int $plafond = null,
        bool $plafondObserve = false,
        ?string $detail = null,
        ?array $closure = null,
    ) {
        if (! GroupNameNormalizer::isSafeNodePath($path)) {
            throw InvalidBackendReportException::make(sprintf(
                'chemin de nœud non sûr « %s » dans une observation : une observation parle des nœuds '
                . 'du plan, en chemins relatifs (ou du jeton racine « %s »).',
                $path,
                GroupNameNormalizer::ROOT_NODE_PATH,
            ));
        }

        $trimmed = $detail === null ? null : trim($detail);
        if ($status->requiresDetail() && ($trimmed === null || $trimmed === '')) {
            throw InvalidBackendReportException::make(sprintf(
                'l\'état d\'observation « %s » du nœud « %s » exige un detail non vide.',
                $status->value,
                $path,
            ));
        }

        if ($grants !== [] && ! $status->carriesGrants()) {
            throw InvalidBackendReportException::make(sprintf(
                'le nœud « %s » est rapporté « %s » mais porte des octrois observés : un nœud qu\'on n\'a '
                . 'pas lu ne peut pas dire ce qu\'il contient.',
                $path,
                $status->value,
            ));
        }

        if ($plafond !== null && ! $plafondObserve) {
            throw InvalidBackendReportException::make(sprintf(
                'le nœud « %s » rapporte un plafond observé sans déclarer l\'avoir regardé : '
                . 'affirmer une valeur qu\'on n\'a pas lue n\'est pas une observation.',
                $path,
            ));
        }

        if ($plafond !== null && $plafond <= 0) {
            throw InvalidBackendReportException::make(sprintf(
                'plafond observé non positif sur le nœud « %s » : l\'absence de plafond se dit « null », '
                . 'pas « zéro ».',
                $path,
            ));
        }

        foreach ($grants as $grant) {
            if (! $grant instanceof ObservedGrant) {
                throw InvalidBackendReportException::make(sprintf(
                    'octroi observé invalide sur le nœud « %s ».',
                    $path,
                ));
            }
        }

        usort($grants, static fn (ObservedGrant $a, ObservedGrant $b): int => strcmp($a->sortKey(), $b->sortKey()));

        if ($closure !== null) {
            if (! $status->carriesGrants()) {
                throw InvalidBackendReportException::make(sprintf(
                    'le nœud « %s » est rapporté « %s » mais dit sur qui il referme : un nœud qu\'on n\'a '
                    . 'pas lu ne peut rien affirmer de sa clôture.',
                    $path,
                    $status->value,
                ));
            }

            $granted = [];
            foreach ($grants as $grant) {
                $granted[$grant->subject->sortKey()] = true;
            }

            $seen = [];
            $kept = [];
            foreach ($closure as $subject) {
                if (! $subject instanceof \App\Services\Filesystem\Plan\PlanSubject) {
                    throw InvalidBackendReportException::make(sprintf(
                        'sujet de clôture invalide sur le nœud « %s ».',
                        $path,
                    ));
                }
                $key = $subject->sortKey();
                if (isset($granted[$key])) {
                    throw InvalidBackendReportException::make(sprintf(
                        'le nœud « %s » rapporte un même sujet à la fois comme octroi observé et comme '
                        . 'clôture observée : ce sont deux états différents, pas deux façons de dire le même.',
                        $path,
                    ));
                }
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $kept[$key] = $subject;
            }

            ksort($kept, SORT_STRING);
            $closure = array_values($kept);
        }

        $this->path = $path;
        $this->status = $status;
        $this->grants = array_values($grants);
        $this->plafond = $plafond;
        $this->plafondObserve = $plafondObserve;
        $this->detail = ($trimmed === '') ? null : $trimmed;
        $this->closure = $closure;
    }

    /** Fabrique — le nœud a été lu. */
    public static function observed(
        string $path,
        array $grants = [],
        ?int $plafond = null,
        bool $plafondObserve = false,
        ?string $detail = null,
        ?array $closure = null,
    ): self {
        return new self($path, FileBackendObservation::Observe, $grants, $plafond, $plafondObserve, $detail, $closure);
    }

    /** Fabrique — le nœud a été cherché et n'existe pas. */
    public static function absent(string $path, ?string $detail = null): self
    {
        return new self($path, FileBackendObservation::Absent, [], null, false, $detail);
    }

    /** Fabrique — le backend n'a pas regardé, ou ne sait pas regarder. */
    public static function nonObservable(string $path, ?string $detail = null): self
    {
        return new self($path, FileBackendObservation::NonObservable, [], null, false, $detail);
    }

    /** Fabrique — la relecture a échoué. */
    public static function echec(string $path, string $detail): self
    {
        return new self($path, FileBackendObservation::Echec, [], null, false, $detail);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'status' => $this->status->value,
            'grants' => array_map(static fn (ObservedGrant $g): array => $g->toArray(), $this->grants),
            'plafond' => $this->plafond,
            'plafond_observe' => $this->plafondObserve,
            'detail' => $this->detail,
            // ADDITIF et RÉTROCOMPATIBLE : la clé n'apparaît QUE si le backend a
            // quelque chose à dire. Un rapport sérialisé avant la story 61.3 se
            // relit donc à l'identique, et un backend qui ne l'observe pas
            // n'écrit pas une donnée qu'il n'a pas.
            ...($this->closure === null ? [] : [
                'closure' => array_map(
                    static fn (\App\Services\Filesystem\Plan\PlanSubject $s): array => $s->toArray(),
                    $this->closure,
                ),
            ]),
        ];
    }
}
