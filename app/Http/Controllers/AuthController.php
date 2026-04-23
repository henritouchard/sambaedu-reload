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
use phpCAS;
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
                    if ($this->sambaEduConfig->get('ent') === true) {
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
     * Redirige vers le serveur CAS via une URL Laravel (pas d'exit()).
     *
     * checkAuthentication() retourne false sans exit() si l'utilisateur n'est pas
     * encore authentifié — on redirige manuellement vers le serveur CAS.
     * La validation du ticket se fait dans casCallback() sur une route dédiée.
     */
    private function redirectToCas()
    {
        try {
            $this->initCasClient();
            $this->applyCasServerValidation();

            if (phpCAS::checkAuthentication()) {
                return $this->handleCasAuthenticated();
            }

            return redirect(phpCAS::getServerLoginURL());
        } catch (Exception $e) {
            Log::error('Erreur lors de l\'authentification CAS', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            ToastMagic::error('Erreur', 'Impossible de se connecter via CAS. Veuillez réessayer.');
            return redirect()->route('auth.login');
        }
    }

    /**
     * Callback CAS — valide le ticket retourné par le serveur CAS.
     *
     * Route dédiée (auth.cas.callback) : le paramètre ?ticket= est destiné à cette URL.
     * forceAuthentication() valide le ticket et retourne sans exit() car le ticket est présent.
     */
    public function casCallback()
    {
        try {
            $this->initCasClient();
            $this->applyCasServerValidation();
            phpCAS::forceAuthentication();

            return $this->handleCasAuthenticated();
        } catch (Exception $e) {
            Log::error('Erreur lors du callback CAS', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            ToastMagic::error('Erreur', 'Impossible de valider l\'authentification CAS. Veuillez réessayer.');
            return redirect()->route('auth.login');
        }
    }

    /**
     * Traite le retour CAS après validation du ticket.
     *
     * En CAS 3.0, récupère les attributs du serveur (dont cn) via getAttributes().
     * En CAS 2.0, cn est défini sur le login local faute d'attributs disponibles.
     */
    private function handleCasAuthenticated(): \Illuminate\Http\RedirectResponse
    {
        $casLogin = phpCAS::getUser();

        if (empty($casLogin)) {
            Log::warning('CAS : getUser() a retourné une valeur vide');
            ToastMagic::error('Erreur', 'Connexion impossible. Veuillez réessayer.');
            return redirect()->route('auth.login');
        }

        $user = $this->userRepository->findByLogin($casLogin);
        if (!$user) {
            Log::warning('CAS : utilisateur introuvable localement', [
                'cas_login' => $casLogin,
            ]);
            ToastMagic::error('Erreur', 'Connexion impossible. Veuillez réessayer.');
            return redirect()->route('auth.login');
        }

        // En CAS 3.0, les attributs sont disponibles via getAttributes()
        $casVersion = $this->sambaEduConfig->get('cas_version') ?: CAS_VERSION_2_0;
        $cn = $user->login;
        if ($casVersion === CAS_VERSION_3_0) {
            $attributes = phpCAS::getAttributes();
            $cn = $attributes['cn'] ?? $attributes['displayName'] ?? $user->login;
        }

        $this->authService->createEntSession(
            ['cn' => $cn, 'login' => $casLogin],
            null
        );
        $_SESSION['cas_auth_method'] = 'cas';

        Log::info('Connexion CAS réussie', [
            'login' => $user->login,
            'cas_login' => $casLogin,
            'cas_version' => $casVersion,
        ]);

        ToastMagic::success('Connexion', 'Bienvenue ' . $user->login . ' ! Connexion CAS réussie.');
        return redirect()->intended(route('app.dashboard'));
    }

    /**
     * Initialise le client phpCAS avec la configuration SambaEdu.
     *
     * Utilise une route de callback dédiée (auth.cas.callback) comme serviceUrl,
     * ce qui isole la validation du ticket du flow de login standard.
     * La version CAS est configurable via la clé cas_version (défaut : CAS 2.0).
     */
    private function initCasClient(): void
    {
        $casUrl  = $this->sambaEduConfig->get('cas_url');
        $casPortRaw = $this->sambaEduConfig->get('cas_port');
        $casPort = ($casPortRaw !== null && $casPortRaw !== '') ? (int) $casPortRaw : 443;
        $casBase = $this->sambaEduConfig->get('cas_base') ?: '';
        $casVersion = $this->sambaEduConfig->get('cas_version') ?: CAS_VERSION_2_0;
        $serviceUrl = route('auth.cas.callback');

        if (empty(trim($casUrl ?? ''))) {
            throw new \RuntimeException('Configuration CAS incomplète : cas_url requis.');
        }

        if (!phpCAS::isInitialized()) {
            phpCAS::client($casVersion, $casUrl, $casPort, $casBase, $serviceUrl, true);
        }
    }

    /**
     * Désactive la validation du certificat TLS en environnement non-production.
     * En production, le CA cert doit être configuré via phpCAS::setCasServerCACert().
     */
    private function applyCasServerValidation(): void
    {
        if (!app()->isProduction()) {
            phpCAS::setNoCasServerValidation();
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
            // Lire le flag AVANT de détruire la session
            $wasCasAuth = $this->authService->isEntAuthenticated()
                && (($_SESSION['cas_auth_method'] ?? '') === 'cas');

            $this->authService->logout();

            if ($wasCasAuth) {
                try {
                    $this->initCasClient();
                    $this->applyCasServerValidation();
                    phpCAS::logoutWithRedirectService(route('auth.login'));
                    // phpCAS::logoutWithRedirectService fait exit() — on n'arrive pas ici
                } catch (Exception $e) {
                    Log::warning('Logout CAS échoué, fallback local', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

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
