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
        // Story 20.1 — login fédéré : POST cross-site auto-soumis par l'IdP
        // externe (façon SAML POST binding). Pas de session SE5 préexistante,
        // donc pas de token CSRF possible. La preuve d'authenticité est le JWT
        // signé RS256 vérifié par le controller (anti-rejeu jti).
        'auth/federated/*',
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
