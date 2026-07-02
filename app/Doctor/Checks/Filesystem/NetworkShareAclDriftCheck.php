<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Filesystem;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use App\Models\NetworkShare;
use App\Services\Filesystem\NetworkShareService;
use Throwable;

/**
 * Epic 34 — détecte la DÉRIVE ACL des lecteurs réseau gérés : pour chaque
 * {@see NetworkShare}, compare l'état DÉSIRÉ (SQL autoritaire) à l'état EFFECTIF
 * sur disque ({@see NetworkShareService::computeDrift()}).
 *
 * Rend l'idempotence observable côté opérateur : c'est le garde-fou continu qui
 * complète la reconvergence 1-clic de l'UI. Read-only (seul `getfacl` est
 * appelé — aucun `setfacl`), conforme au contrat {@see EnvironmentCheck}.
 *
 * Sévérité : `warn` (non bloquant) s'il existe des lecteurs dérivés ou non
 * provisionnés — la remédiation (re-provisioning) est une action opérateur, pas
 * un pré-requis d'installation.
 */
final class NetworkShareAclDriftCheck implements EnvironmentCheck
{
    public function __construct(private readonly NetworkShareService $service)
    {
    }

    public function tag(): string
    {
        return 'filesystem';
    }

    public function name(): string
    {
        return 'Dérive ACL lecteurs réseau';
    }

    public function run(): CheckResult
    {
        $shares = NetworkShare::all();
        if ($shares->isEmpty()) {
            return CheckResult::ok('Aucun lecteur réseau géré — rien à vérifier.');
        }

        $drifted = [];
        $absent = [];
        $errored = [];

        foreach ($shares as $share) {
            try {
                $drift = $this->service->computeDrift($share);
            } catch (Throwable $e) {
                $errored[] = $share->directory_name . ' (' . $e->getMessage() . ')';
                continue;
            }

            match ($drift['status']) {
                'drifted' => $drifted[] = $share->directory_name,
                'absent' => $absent[] = $share->directory_name,
                'error' => $errored[] = $share->directory_name,
                default => null,
            };
        }

        if ($drifted === [] && $absent === [] && $errored === []) {
            return CheckResult::ok(sprintf('%d lecteur(s) réseau : ACL disque conformes au SQL.', $shares->count()));
        }

        $parts = [];
        if ($drifted !== []) {
            $parts[] = count($drifted) . ' dérivé(s) [' . implode(', ', $drifted) . ']';
        }
        if ($absent !== []) {
            $parts[] = count($absent) . ' non provisionné(s) [' . implode(', ', $absent) . ']';
        }
        if ($errored !== []) {
            $parts[] = count($errored) . ' illisible(s) [' . implode(', ', $errored) . ']';
        }

        return CheckResult::warn(
            'Lecteurs réseau non conformes : ' . implode(' ; ', $parts) . '.',
            'Re-provisionner les lecteurs concernés (bouton « Resynchroniser » sur la fiche du lecteur, '
            . 'ou re-enregistrer une assignation). Le re-provisioning est idempotent : il efface puis '
            . 're-applique les ACL canoniques dérivées du SQL.',
        );
    }
}
