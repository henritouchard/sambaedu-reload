<?php

/**
 * Contrôleur pour la gestion de l'authentification
 */

namespace App\Http\Controllers;

use App\Config\SambaEduConfig;
use App\Constants\Errors\AuthenticationErrors;
use App\Repositories\UserRepository;
use App\Services\AuthenticationService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use League\OAuth2\Client\Provider\GenericProvider;
use Exception;

class AuthController extends Controller
{
    private AuthenticationService $authService;
    private SambaEduConfig $sambaEduConfig;
    private UserRepository $userRepository;

    public function __construct(
        AuthenticationService $authService,
        SambaEduConfig $sambaEduConfig,
        UserRepository $userRepository
    ) {
        $this->authService = $authService;
        $this->sambaEduConfig = $sambaEduConfig;
        $this->userRepository = $userRepository;
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
            $provider = $this->buildOAuth2Provider();
            $authorizationUrl = $provider->getAuthorizationUrl();

            session()->regenerate(true);
            session(['oauth2state' => $provider->getState()]);

            return redirect($authorizationUrl);
        } catch (Exception $e) {
            Log::error('Erreur lors de la redirection ENT OAuth2', [
                'error' => $e->getMessage()
            ]);
            ToastMagic::error('Erreur', 'Impossible de se connecter à l\'ENT. Veuillez réessayer.');
            return redirect()->route('auth.login');
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
     * Callback OAuth2 ENT — échange le code d'autorisation contre un token,
     * récupère les infos utilisateur, puis crée la session.
     */
    public function entCallback(Request $request)
    {
        if ($this->authService->isAlreadyAuthenticated()) {
            return redirect()->route('app.dashboard');
        }

        // Pas de code = pas de callback valide
        if (!$request->has('code')) {
            return redirect()->route('auth.login');
        }

        // Vérification CSRF via state
        $storedState = session('oauth2state');
        session()->forget('oauth2state');

        if (empty($request->get('state')) || !hash_equals($storedState ?? '', $request->get('state'))) {
            Log::warning('OAuth2 ENT : state invalide', [
                'received' => $request->get('state'),
            ]);
            ToastMagic::error('Erreur', 'Session OAuth2 invalide. Veuillez réessayer.');
            return redirect()->route('auth.login');
        }

        try {
            $provider = $this->buildOAuth2Provider();

            // Échanger le code contre un access token
            $accessToken = $provider->getAccessToken('authorization_code', [
                'code' => $request->get('code'),
            ]);

            // Récupérer les infos utilisateur depuis l'ENT
            $resourceOwner = $provider->getResourceOwner($accessToken);
            $userinfo = $resourceOwner->toArray();

            // Rechercher l'utilisateur local (par login ou par externalId dans l'attribut title)
            $login = $userinfo['login'] ?? '';
            $user = !empty($login) ? $this->userRepository->findByLogin($login) : null;

            if (!$user && !empty($userinfo['externalId'])) {
                $user = $this->userRepository->findByExternalId($userinfo['externalId']);
            }

            if (!$user) {
                Log::error('OAuth2 ENT : utilisateur introuvable localement', [
                    'login_ent' => $userinfo['login'] ?? null,
                    'externalId' => $userinfo['externalId'] ?? null,
                ]);
                ToastMagic::error('Erreur', 'Votre compte ENT n\'est associé à aucun compte local.');
                return redirect()->route('auth.login');
            }

            // Créer la session ENT via le service d'authentification
            // On force 'cn' avec le login local : le login ENT peut différer du login AD local
            // (incohérence connue entre userinfo ENT et l'annuaire AD)
            $this->authService->createEntSession(
                array_merge($userinfo, ['cn' => $user->login]),
                $accessToken
            );

            Log::info('Connexion ENT réussie', [
                'login' => $user->login,
                'login_ent' => $userinfo['login'] ?? null,
            ]);

            cache()->put('ent_status', true, 300);

            ToastMagic::success('Connexion', 'Bienvenue ' . $user->login . ' ! Connexion ENT réussie.');
            return redirect()->intended(route('app.dashboard'));

        } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
            Log::error('OAuth2 ENT : erreur provider', ['error' => $e->getMessage()]);

            // Quota overflow ou erreur provider → marquer ENT indisponible
            if (str_contains($e->getMessage(), 'quota_overflow') || str_contains($e->getMessage(), 'Invalid response received from Authorization Server')) {
                cache()->put('ent_status', false, 300);
                return redirect()->route('auth.login');
            }

            ToastMagic::error('Erreur', 'Erreur de connexion à l\'ENT. Veuillez réessayer.');
            return redirect()->route('auth.login');

        } catch (Exception $e) {
            Log::error('OAuth2 ENT : erreur inattendue', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            ToastMagic::error('Erreur', 'Erreur lors de la connexion ENT. Veuillez réessayer.');
            return redirect()->route('auth.login');
        }
    }

    /**
     * Construit le provider OAuth2 League avec la config SambaEdu.
     */
    private function buildOAuth2Provider(): GenericProvider
    {
        $clientId     = $this->sambaEduConfig->get('openent_oauth2_id', '');
        $clientSecret = $this->sambaEduConfig->get('openent_oauth2_pass', '');
        $entUri       = rtrim($this->sambaEduConfig->get('openent_uri', ''), '/');

        if (empty($clientId) || empty($clientSecret) || empty($entUri)) {
            throw new \RuntimeException('Configuration OAuth2 ENT incomplète : clientId, clientSecret et openent_uri sont requis.');
        }

        $options = [
            'clientId'                => $clientId,
            'clientSecret'            => $clientSecret,
            'redirectUri'             => route('auth.ent.callback'),
            'urlAuthorize'            => $entUri . '/' . ltrim($this->sambaEduConfig->get('openent_oauth2_auth', ''), '/'),
            'urlAccessToken'          => $entUri . '/' . ltrim($this->sambaEduConfig->get('openent_oauth2_token', ''), '/'),
            'urlResourceOwnerDetails' => $entUri . '/' . ltrim($this->sambaEduConfig->get('openent_oauth2_userinfo', ''), '/'),
            'scopes'                  => ['userinfo'],
        ];

        // Support proxy si configuré
        // TODO: verify=false hérité du legacy — décision archi en attente.
        //   Voir notes-constats-henri.md § "OAuth2 / Proxy — Question architecturale ouverte"
        $proxy = $this->sambaEduConfig->proxy();
        if ($proxy->isEnabled()) {
            $options['proxy'] = $proxy->getUrl();
            $options['verify'] = false;
        }

        return new GenericProvider($options);
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
