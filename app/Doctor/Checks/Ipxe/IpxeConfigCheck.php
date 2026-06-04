<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Ipxe;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;

/**
 * Vérifie les pré-requis de configuration du boot iPXE natif :
 *  1. au moins une racine d'assets OS (`ipxe.os_assets.roots`) existe sur
 *     le filesystem (sinon kernels/initrd 404 → reboot en boucle des postes) ;
 *  2. les variables AD nécessaires à la jointure pendant les installs
 *     (domain + se4install) sont renseignées — sans elles l'install Linux
 *     échoue à l'étape sambaedu-ad-dc et l'unattend Windows joint dans le
 *     vide.
 */
final class IpxeConfigCheck implements EnvironmentCheck
{
    public function tag(): string
    {
        return 'ipxe';
    }

    public function name(): string
    {
        return 'Config iPXE';
    }

    public function run(): CheckResult
    {
        /** @var array<int, string> $roots */
        $roots = (array) config('ipxe.actions.os_assets.roots', []);
        $existingRoot = null;
        foreach ($roots as $root) {
            if (is_dir((string) $root)) {
                $existingRoot = (string) $root;
                break;
            }
        }

        if ($existingRoot === null) {
            return CheckResult::error(
                sprintf('aucune racine d\'assets OS existante (%s).', implode(', ', $roots) ?: 'aucune configurée'),
                'Créer /var/sambaedu/unattended/install/os (ou ajuster IPXE_OS_ASSETS_ROOT) puis y déployer les sources via les scripts install-*-iso.sh.',
            );
        }

        $missing = [];
        if ((string) config('sambaedu.domain', '') === '') {
            $missing[] = 'SAMBAEDU_DOMAIN';
        }
        if ((string) config('sambaedu.se4install_name', '') === '') {
            $missing[] = 'SE4INSTALL_NAME';
        }

        if ($missing !== []) {
            return CheckResult::warn(
                sprintf('variables AD manquantes pour la jointure post-install : %s.', implode(', ', $missing)),
                'Renseigner ces variables dans .env — sans elles les installs joignent le domaine à vide.',
            );
        }

        // Fix review F5 : deux clés config désignent la racine OS
        // (`IPXE_OS_ASSETS_ROOT` servie aux postes vs
        // `IPXE_ISO_DEPLOYED_OS_BASE` lue par l'inventaire ISO/distros).
        // Si elles divergent, le diagnostic devient incohérent (« assets
        // OK » ici, « tout manquant » dans l'inventaire) — on le signale.
        $deployedBase = (string) config(
            'ipxe.iso_management.deployed_os_base_path',
            '/var/sambaedu/unattended/install/os',
        );
        if (rtrim($deployedBase, '/') !== rtrim($existingRoot, '/')) {
            return CheckResult::warn(
                sprintf(
                    'racines OS divergentes : assets servis depuis %s mais inventaire ISO/distros sur %s.',
                    $existingRoot,
                    $deployedBase,
                ),
                'Aligner IPXE_OS_ASSETS_ROOT et IPXE_ISO_DEPLOYED_OS_BASE dans .env (même répertoire).',
            );
        }

        return CheckResult::ok(sprintf('assets OS sous %s, vars AD de jointure présentes', $existingRoot));
    }
}
