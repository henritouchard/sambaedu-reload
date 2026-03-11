<?php

/**
 * Contrôleur pour la gestion de l'authentification
 */

namespace App\Http\Controllers;

use App\Constants\Errors\AuthenticationErrors;
use App\Services\AuthenticationService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class AuthController extends Controller
{
    private AuthenticationService $authService;

    public function __construct(AuthenticationService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Afficher la page de connexion
     */
    public function showLogin(Request $request)
    {
        // Vérifier si l'utilisateur est déjà connecté
        if ($this->authService->isAlreadyAuthenticated()) {
            return redirect()->route('app.dashboard');
        }

        // Vérifier si l'authentification ENT est activée et disponible
        if ($this->authService->isEntAuthAvailable()) {
            Log::info('Redirection vers authentification ENT OAuth2');
            return $this->redirectToEntOAuth2();
        }

        // Vérifier si l'authentification CAS est configurée
        if ($this->authService->isCasAuthAvailable()) {
            Log::info('Redirection vers authentification CAS');
            return $this->redirectToCas();
        }

        // Tentative d'auto-login basé sur l'IP
        $autolog = false;
        $login = null;

        try {
            $autoLoginResult = $this->authService->attemptAutoLogin($request->ip());

            if ($autoLoginResult) {
                $login = $autoLoginResult['login'];
                $autolog = true;

                Log::info('Auto-login configuré', [
                    'machine' => $autoLoginResult['machine'],
                    'user' => $login,
                    'ip' => $request->ip()
                ]);
            }
        } catch (Exception $e) {
            Log::error('Erreur lors de l\'auto-login', [
                'error' => $e->getMessage(),
                'ip' => $request->ip()
            ]);
        }

        // Vérifier aussi les paramètres d'autolog en URL (fallback)
        if (!$autolog && $request->has('autolog') && $request->get('autolog') === '1') {
            $autologUser = $request->get('user');
            if ($autologUser) {
                $autolog = true;
                $login = $autologUser;
            }
        }

        return view('auth.login', [
            'autolog' => $autolog,
            'login' => $login,
            'error' => null
        ]);
    }

    /**
     * Traiter la tentative de connexion
     */
    public function authenticate(Request $request)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required|string'
        ]);

        try {
            $result = $this->authService->authenticate(
                $request->input('login'),
                $request->input('password'),
                $request->ip()
            );

            if ($result['success']) {
                Log::info('Connexion réussie', [
                    'login' => $request->input('login'),
                    'ip' => $request->ip(),
                    'user_data' => $result['user'] ?? null
                ]);

                ToastMagic::success('Connexion', 'Bienvenue ' . $request->input('login') . ' ! Connexion réussie.');
                return redirect()->intended(route('app.dashboard'));
            } else {
                // Gérer le cas spécial du changement de mot de passe requis
                if (isset($result['code']) && $result['code'] === AuthenticationErrors::ERROR_AUTHENTICATION_PASSWORD_CHANGE_REQUIRED) {
                    Log::info('Changement de mot de passe requis', [
                        'login' => $request->input('login'),
                        'ip' => $request->ip()
                    ]);

                    // Vérifier si c'est un environnement "ent"
                    $config = $this->authService->getConfig();
                    if (isset($config['ent']) && $config['ent'] === true) {
                        // Pour les environnements ENT, accepter la connexion même avec mot de passe non modifié
                        ToastMagic::info('Connexion', 'Connexion réussie (environnement ENT).');
                        return redirect()->intended(route('app.dashboard'));
                    } else {
                        // Pour les autres environnements, créer un token et rediriger vers le changement de mot de passe
                        $token = $this->authService->createPasswordChangeToken($request->input('login'));
                        ToastMagic::warning('Changement de mot de passe', 'Vous devez changer votre mot de passe avant de continuer.');
                        return redirect()->route('auth.change-password', ['token' => $token]);
                    }
                }

                Log::warning('Échec de connexion', [
                    'login' => $request->input('login'),
                    'ip' => $request->ip(),
                    'error' => $result['error'] ?? 'Erreur inconnue',
                    'code' => $result['code'] ?? null
                ]);

                return back()
                    ->withInput($request->only('login'))
                    ->withErrors(['auth' => $result['error'] ?? 'Identifiants invalides']);
            }
        } catch (Exception $e) {
            Log::error('Erreur lors de la connexion', [
                'login' => $request->input('login'),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return back()
                ->withInput($request->only('login'))
                ->withErrors(['auth' => 'Une erreur est survenue lors de la connexion. Veuillez réessayer.']);
        }
    }

    /**
     * Rediriger vers l'authentification ENT OAuth2
     */
    private function redirectToEntOAuth2()
    {
        try {
            Log::info('Redirection vers ENT OAuth2');
            return redirect('/oauth2/login.php');
        } catch (Exception $e) {
            Log::error('Erreur lors de la redirection ENT', [
                'error' => $e->getMessage()
            ]);
            ToastMagic::error('Erreur', 'Impossible de se connecter à l\'ENT. Veuillez réessayer.');
            return back();
        }
    }

    /**
     * Rediriger vers l'authentification CAS
     */
    private function redirectToCas()
    {
        try {
            Log::info('Redirection vers CAS');
            return redirect('/cas/cas.php');
        } catch (Exception $e) {
            Log::error('Erreur lors de la redirection CAS', [
                'error' => $e->getMessage()
            ]);
            ToastMagic::error('Erreur', 'Impossible de se connecter via CAS. Veuillez réessayer.');
            return back();
        }
    }

    /**
     * Callback pour l'authentification ENT (si nécessaire)
     */
    public function entCallback(Request $request)
    {
        Log::info('Callback ENT reçu', [
            'params' => $request->all()
        ]);

        // Rediriger vers l'URL demandée initialement ou le dashboard
        return redirect()->intended(route('app.dashboard'));
    }

    /**
     * Déconnecter l'utilisateur
     */
    public function logout()
    {
        try {
            $this->authService->logout();

            Log::info('Déconnexion réussie');
            ToastMagic::success('Déconnexion', 'Vous avez été déconnecté avec succès.');

            return redirect()->route('auth.login');
        } catch (Exception $e) {
            Log::error('Erreur lors de la déconnexion', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            ToastMagic::error('Déconnexion', 'Une erreur est survenue lors de la déconnexion.');
            return redirect()->route('auth.login');
        }
    }
}
