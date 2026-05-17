<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Gpo;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use Illuminate\Support\Facades\Process;

final class DcReachableCheck implements EnvironmentCheck
{
    public function tag(): string
    {
        return 'gpo';
    }

    public function name(): string
    {
        return 'DC AD reachable';
    }

    public function run(): CheckResult
    {
        $dcIp = (string) config('sambaedu.se4ad_ip', '');
        if ($dcIp === '') {
            return CheckResult::error(
                'config(\'sambaedu.se4ad_ip\') vide.',
                'Définir SE4AD_IP=<ip-du-DC> dans .env.',
            );
        }

        $result = Process::timeout(5)->run(['ping', '-c', '1', '-W', '2', $dcIp]);
        if (! $result->successful()) {
            return CheckResult::error(
                sprintf('Aucune réponse de %s (ping timeout 2s).', $dcIp),
                'Vérifier le réseau, le routage, et que le DC AD est démarré.',
            );
        }

        return CheckResult::ok(sprintf('ping OK vers %s', $dcIp));
    }
}
