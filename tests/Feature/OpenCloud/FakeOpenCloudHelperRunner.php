<?php

declare(strict_types=1);

namespace Tests\Feature\OpenCloud;

use App\Services\OpenCloud\Deployment\OpenCloudHelperRunner;

/**
 * LE SEAM PRIVILÉGIÉ, REMPLACÉ PAR UN OBSERVATEUR.
 *
 * Aucun conteneur n'est exécuté dans la suite de tests : ce qui se vérifie ici,
 * c'est ce que SE5 **demande** au root — la séquence exacte des verbes, la forme
 * des arguments, et par où le secret transite. C'est le patron déjà en service
 * pour le système d'extensions, repris tel quel.
 *
 * Il rend les mêmes formes que le script réel : des lignes `clé=valeur` sur la
 * sortie standard, et un code de sortie non nul avec une cause sur la sortie
 * d'erreur.
 */
final class FakeOpenCloudHelperRunner implements OpenCloudHelperRunner
{
    /** @var list<array{verb:string,args:list<string>,stdin:?string}> */
    public array $calls = [];

    /** @var array<string, array{stdout:list<string>,stderr:list<string>,exitCode:int}> */
    private array $stubs = [];

    /** @param list<string> $stdout */
    public function stub(string $verb, array $stdout): void
    {
        $this->stubs[$verb] = ['stdout' => $stdout, 'stderr' => [], 'exitCode' => 0];
    }

    public function fail(string $verb, string $reason): void
    {
        $this->stubs[$verb] = ['stdout' => [], 'stderr' => $reason === '' ? [] : [$reason], 'exitCode' => 2];
    }

    /** {@inheritdoc} */
    public function run(array $args, ?string $stdin = null): array
    {
        $verb = (string) ($args[0] ?? '');
        $this->calls[] = ['verb' => $verb, 'args' => array_values($args), 'stdin' => $stdin];

        return $this->stubs[$verb] ?? ['stdout' => [], 'stderr' => [], 'exitCode' => 0];
    }
}
