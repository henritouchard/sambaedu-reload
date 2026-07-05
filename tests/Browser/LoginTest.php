<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Parcours e2e du login local (form `login`/`password`).
 *
 * Périmètre HOST ISOLÉ : l'auth réelle passe par un bind LDAP/AD (voir
 * {@see \App\Services\AuthenticationService::validatePassword()}) qui n'est
 * pas joignable hors VM. On couvre donc ici :
 *   1. le RENDU de la page (valide tout le stack Dusk : Chrome, serve, Vite,
 *      routing, Blade) ;
 *   2. le CHEMIN NÉGATIF (identifiants invalides → alerte d'erreur), qui ne
 *      nécessite aucun backend d'auth (le bind échoue vite : LDAP_HOST=127.0.0.1).
 *
 * Le happy-path (form → dashboard) exigera un seam de test gaté APP_ENV=dusk
 * dans le service d'auth — décision à valider avant de l'ajouter.
 */
class LoginTest extends DuskTestCase
{
    private const LOGIN_URL = '/authentication/login';

    public function test_login_page_renders(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit(self::LOGIN_URL)
                ->assertPresent('form[action$="/authentication/login"]')
                ->assertPresent('input#login')
                ->assertPresent('input#password')
                ->assertSee("Nom d'utilisateur")
                ->assertSee('Mot de passe')
                ->assertSee('Se connecter');
        });
    }

    public function test_invalid_credentials_show_error(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit(self::LOGIN_URL)
                ->type('login', 'utilisateur-inexistant')
                ->type('password', 'mauvais-mot-de-passe')
                ->press('Se connecter')
                ->waitForLocation(self::LOGIN_URL)
                ->assertPathIs(self::LOGIN_URL)
                ->assertPresent('.alert-error');
        });
    }

    /**
     * Happy-path : le couple de test (seam APP_ENV=dusk) authentifie et mène au
     * dashboard, servi par le DuskAuthGuard (sans LDAP).
     */
    public function test_valid_credentials_reach_dashboard(): void
    {
        $login = env('DUSK_TEST_LOGIN', 'dusk-admin');
        $password = env('DUSK_TEST_PASSWORD', 'dusk-secret-2026');

        $this->browse(function (Browser $browser) use ($login, $password) {
            $browser->visit(self::LOGIN_URL)
                ->type('login', $login)
                ->type('password', $password)
                ->press('Se connecter')
                ->waitForLocation('/app/dashboard')
                ->assertPathIs('/app/dashboard')
                ->assertDontSee('Se connecter');
        });
    }
}
