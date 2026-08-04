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
     * @param  list<ObservedGrant>  $grants
     */
    public function __construct(
        string $path,
        FileBackendObservation $status,
        array $grants = [],
        ?int $plafond = null,
        bool $plafondObserve = false,
        ?string $detail = null,
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

        $this->path = $path;
        $this->status = $status;
        $this->grants = array_values($grants);
        $this->plafond = $plafond;
        $this->plafondObserve = $plafondObserve;
        $this->detail = ($trimmed === '') ? null : $trimmed;
    }

    /** Fabrique — le nœud a été lu. */
    public static function observed(
        string $path,
        array $grants = [],
        ?int $plafond = null,
        bool $plafondObserve = false,
        ?string $detail = null,
    ): self {
        return new self($path, FileBackendObservation::Observe, $grants, $plafond, $plafondObserve, $detail);
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
        ];
    }
}
