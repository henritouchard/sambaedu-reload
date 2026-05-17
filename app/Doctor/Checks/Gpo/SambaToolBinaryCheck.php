<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Gpo;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;

final class SambaToolBinaryCheck implements EnvironmentCheck
{
    public function tag(): string
    {
        return 'gpo';
    }

    public function name(): string
    {
        return 'samba-tool binary';
    }

    public function run(): CheckResult
    {
        $binPath = (string) config('sambaedu.gpo.bin_path', '/usr/bin/samba-tool');

        if (! is_executable($binPath)) {
            return CheckResult::error(
                sprintf('Binaire introuvable ou non exécutable : %s', $binPath),
                'Installer samba-tool (paquet `samba-common-bin`) ou ajuster config(\'sambaedu.gpo.bin_path\').',
            );
        }

        return CheckResult::ok(sprintf('Trouvé : %s', $binPath));
    }
}
