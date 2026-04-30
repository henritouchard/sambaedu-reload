<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\RoamingProfileService;
use Illuminate\Http\Response;

/**
 * Controller — endpoint script de purge profils itinérants (story 1bis.18f).
 *
 * Sert le bash `del-roam.sh` consommé par les logon scripts Windows. La
 * génération est en pur PHP (pas d'embarquement legacy `del_roam.php`).
 *
 * L'authentification est portée par le middleware `AllowSe4FsScript` (port
 * natif du legacy `header_authorize_script`).
 */
class RoamingProfileController extends Controller
{
    public function delRoamScript(RoamingProfileService $service): Response
    {
        $script = $service->generatePurgeScript();

        return response($script, 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
