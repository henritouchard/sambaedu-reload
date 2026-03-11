<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Exempter seulement les routes legacy de la vérification CSRF
        'action_serv.php*',
        'admin*',
        'annu*',
        'annu2*',
        'api*',
        'api2*',
        'auth.php*',
        'barre.php*',
        'bbb*',
        'blank.php*',
        'cas*',
        'central*',
        'cloud*',
        'conf_*.php*',
        'config*',
        'contact.php*',
        'dhcp*',
        'display*',
        'dossier_echange*',
        'google*',
        'gpo*',
        'index.php*',
        'individuel.php*',
        'infos*',
        'ipxe*',
        'logout.php*',
        'logs.php*',
        'majtest.php*',
        'menu.php*',
        'metrics*',
        'oauth2*',
        'parcs*',
        'parcs2*',
        'partages*',
        'printers*',
        'sso*',
        'stats*',
        'test.php*',
        'tests*',
        'user*',
        'visio*',
        'wait.php*',
        'wpkg*',
    ];
}
