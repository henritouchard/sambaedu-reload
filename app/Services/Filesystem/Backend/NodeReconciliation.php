<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend;

use App\Enums\FileBackendOutcome;
use App\Exceptions\Filesystem\InvalidBackendReportException;
use App\Services\Filesystem\Plan\GroupNameNormalizer;

/**
 * Story 60.3 — ce qu'un backend a fait (ou pas) SUR UN NŒUD.
 *
 * Trois champs, et pas un de plus. En particulier : **aucun code natif, aucun
 * statut de transport**. Les trois sémantiques mesurées pour « c'était déjà fait »
 * sont normalisées par l'adaptateur en {@see FileBackendOutcome::Conforme} ; les
 * laisser remonter obligerait chaque appelant au-dessus de la ligne de contrat à
 * connaître le dialecte de chaque backend, ce qui est exactement ce que la ligne
 * existe pour éviter. Une règle d'architecture le tient
 * ({@see \Tests\Architecture\PlanNamespaceIsolationTest}).
 *
 * **`detail` est obligatoire quand l'état l'exige, et c'est vérifié ICI.** Un
 * échec sans cause, un « non supporté » sans dire QUOI, un « non piloté » sans
 * dire ce qui manque : trois silences, et le silence est le mode de rupture que le
 * sondage a mesuré. Une convention se viole sans bruit ; un constructeur qui
 * refuse de construire, non.
 *
 * `detail` est aussi le SEUL texte libre d'un rapport — c'est donc par lui que la
 * garde de neutralité fait entrer ses marqueurs
 * ({@see \Tests\Unit\Services\Filesystem\Backend\BackendReportNeutralityTest}).
 */
final class NodeReconciliation
{
    /** Chemin du nœud, tel qu'il figure dans le plan (racine comprise). */
    public readonly string $path;

    public readonly FileBackendOutcome $outcome;

    /** Cause ou raison. Obligatoire pour `echec|non_exprimable|non_implemente`. */
    public readonly ?string $detail;

    public function __construct(string $path, FileBackendOutcome $outcome, ?string $detail = null)
    {
        if (! GroupNameNormalizer::isSafeNodePath($path)) {
            throw InvalidBackendReportException::make(sprintf(
                'chemin de nœud non sûr « %s » dans un rapport de backend : un rapport parle des nœuds '
                . 'du plan, en chemins relatifs (ou du jeton racine « %s »).',
                $path,
                GroupNameNormalizer::ROOT_NODE_PATH,
            ));
        }

        $trimmed = $detail === null ? null : trim($detail);

        if ($outcome->requiresDetail() && ($trimmed === null || $trimmed === '')) {
            throw InvalidBackendReportException::make(sprintf(
                'l\'état « %s » du nœud « %s » exige un detail non vide : un %s sans raison nommée est '
                . 'un silence, et c\'est précisément ce que ce rapport existe pour rendre impossible.',
                $outcome->value,
                $path,
                $outcome->isDecline() ? 'déclin' : 'échec',
            ));
        }

        $this->path = $path;
        $this->outcome = $outcome;
        $this->detail = ($trimmed === '') ? null : $trimmed;
    }

    /** Fabrique de commodité — « déjà dans l'état voulu ». */
    public static function conforme(string $path, ?string $detail = null): self
    {
        return new self($path, FileBackendOutcome::Conforme, $detail);
    }

    /** Fabrique de commodité — « écart corrigé par ce passage ». */
    public static function applique(string $path, ?string $detail = null): self
    {
        return new self($path, FileBackendOutcome::Applique, $detail);
    }

    public static function echec(string $path, string $detail): self
    {
        return new self($path, FileBackendOutcome::Echec, $detail);
    }

    /** Le MODÈLE du backend n'a pas le concept — permanent. */
    public static function nonExprimable(string $path, string $detail): self
    {
        return new self($path, FileBackendOutcome::NonExprimable, $detail);
    }

    /** Le mécanisme existe, SE5 ne le pilote pas — temporaire. */
    public static function nonImplemente(string $path, string $detail): self
    {
        return new self($path, FileBackendOutcome::NonImplemente, $detail);
    }

    /** Ce backend n'exécute rien, par conception. */
    public static function nonExecute(string $path, ?string $detail = null): self
    {
        return new self($path, FileBackendOutcome::NonExecute, $detail);
    }

    /** @return array{path:string,outcome:string,detail:string|null} */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'outcome' => $this->outcome->value,
            'detail' => $this->detail,
        ];
    }
}
