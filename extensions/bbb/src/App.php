<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb;

use SambaEdu\ExtBbb\Admin\ServersController;
use SambaEdu\ExtBbb\Bbb\BbbApiClient;
use SambaEdu\ExtBbb\Bbb\LiveBbbApiClient;
use SambaEdu\ExtBbb\Bbb\ServerSelector;
use SambaEdu\ExtBbb\Guest\GuestController;
use SambaEdu\ExtBbb\Http\NativeSessionStore;
use SambaEdu\ExtBbb\Http\Request;
use SambaEdu\ExtBbb\Http\Response;
use SambaEdu\ExtBbb\Http\Router;
use SambaEdu\ExtBbb\Http\SessionStore;
use SambaEdu\ExtBbb\Oidc\Client;
use SambaEdu\ExtBbb\Oidc\Credentials;
use SambaEdu\ExtBbb\Oidc\CurlHttpClient;
use SambaEdu\ExtBbb\Oidc\ErrorCodes;
use SambaEdu\ExtBbb\Oidc\IdTokenVerifier;
use SambaEdu\ExtBbb\Oidc\JsonHttpClient;
use SambaEdu\ExtBbb\Oidc\OidcException;
use SambaEdu\ExtBbb\Oidc\ProviderMetadata;
use SambaEdu\ExtBbb\Oidc\SqliteReplayGuard;
use SambaEdu\ExtBbb\Rooms\RecordingsController;
use SambaEdu\ExtBbb\Rooms\RoomsController;
use Throwable;

/**
 * Story 57.1 — **LE POINT DE COMPOSITION DE L'EXTENSION.**
 *
 * Tout ce que l'extension sait de SE5 entre par deux portes, et deux seulement :
 * les sept variables d'environnement ({@see Env}), et HTTP. Pas de conteneur, pas
 * de découverte automatique : les dépendances se câblent ici, à la main, ce qui
 * a l'avantage de rendre la liste des collaborateurs LISIBLE — et remplaçable en
 * test.
 *
 * **Ouvertures paresseuses, et c'est structurel** : ni la base, ni le client
 * OIDC, ni le client BBB ne sont construits au démarrage. `GET /` est la sonde
 * de santé (contrat §8) : elle doit répondre même si la base est corrompue et
 * même si le fournisseur est éteint.
 */
final class App
{
    private ?Store $store = null;

    private ?Client $oidc = null;

    public function __construct(
        private readonly Env $env,
        private readonly View $view,
        private readonly SessionStore $sessionStore,
        private readonly BbbApiClient $api,
        private readonly JsonHttpClient $http,
    ) {
        Url::configure($this->env->basePath);
    }

    /** Câblage de production. */
    public static function bootstrap(?Env $env = null): self
    {
        $env ??= Env::capture();

        return new self(
            env: $env,
            view: new View(dirname(__DIR__) . '/views'),
            // L'issuer porte le schéma : c'est lui qui décide du flag `Secure`
            // du cookie d'état — review 57.1 #1.
            sessionStore: new NativeSessionStore($env->issuer),
            api: new LiveBbbApiClient(),
            http: new CurlHttpClient(),
        );
    }

    public function handle(Request $request): Response
    {
        return $this->router()->dispatch($request);
    }

    // =====================================================================
    // Routes — chemins NUS : le proxy a retiré `/ext/bbb`
    // =====================================================================

    private function router(): Router
    {
        $router = new Router(fn (int $status, string $code): Response => $this->errorPage($code, $status));

        $router->add('GET', '/', $this->home(...));
        $router->add('GET', '/login', $this->login(...));
        $router->add('GET', '/oidc/callback', $this->callback(...));
        $router->add('GET', '/logout', $this->logout(...));
        $router->add('GET', '/admin/servers', $this->servers(...));
        $router->add('POST', '/admin/servers', $this->servers(...));

        // Story 57.2 — Les salons. **Tout ce qui appelle un serveur
        // BigBlueButton est en POST** : un GET serait préchargé au survol d'un
        // lien, et ouvrirait des meetings sans que personne ne l'ait demandé.
        $router->add('GET', '/rooms', $this->rooms(...));
        $router->add('POST', '/rooms', $this->rooms(...));
        $router->add('POST', '/rooms/start', $this->rooms(...));
        $router->add('POST', '/rooms/join', $this->rooms(...));
        $router->add('POST', '/rooms/delete', $this->rooms(...));

        // Story 57.3 — L'invitation externe : deux actes LOCAUX du créateur,
        // aucun appel sortant, mais mutants — donc en POST, avec jeton
        // anti-CSRF, comme le reste.
        $router->add('POST', '/rooms/guest/enable', $this->rooms(...));
        $router->add('POST', '/rooms/guest/revoke', $this->rooms(...));

        // ═══════════════════════════════════════════════════════════════════
        //  LA ROUTE PUBLIQUE — la SEULE de l'extension à ne pas exiger le SSO
        //
        //  Elle est servie par un contrôleur DISTINCT, qui ne reçoit pas de
        //  magasin d'état : c'est le typage qui garantit qu'aucun état par
        //  visiteur n'est ouvert sur ce parcours, avant comme après la
        //  vérification du mot de passe.
        // ═══════════════════════════════════════════════════════════════════
        $router->add('GET', '/visio', $this->visio(...));
        $router->add('POST', '/visio', $this->visio(...));

        // Story 57.3 — Les enregistrements. `GET /recordings` est le SEUL GET
        // de l'extension à faire des appels sortants : c'est une lecture, elle
        // ne mute rien, elle est bornée, et le verrou d'état est relâché avant.
        $router->add('GET', '/recordings', $this->recordings(...));
        $router->add('POST', '/recordings/delete', $this->recordings(...));

        return $router;
    }

    /**
     * `GET /` — **LA SONDE DE SANTÉ**.
     *
     * Aucun appel BBB, aucun appel OIDC, aucune ouverture de base. Toute
     * réponse HTTP prouve la joignabilité au superviseur de SE5 ; mais une page
     * qui pendrait sur un tiers lent serait, elle, un faux négatif — et un
     * serveur intégré mono-processus bloqué pour tout le monde.
     */
    private function home(Request $request): Response
    {
        $identity = Identity::fromSessionStore($this->sessionStore);

        return Response::html($this->view->page('home', [
            'provisioned' => $this->env->hasOidc(),
        ], 'Accueil', $this->env, $identity));
    }

    private function login(Request $request): Response
    {
        if (Identity::fromSessionStore($this->sessionStore) !== null) {
            return Response::redirect('/');
        }

        try {
            return Response::redirectTo($this->oidcClient()->authorizationUrl($this->sessionStore));
        } catch (OidcException $e) {
            return $this->refuse($e);
        } catch (Throwable $e) {
            error_log('[ext-bbb] démarrage de connexion impossible : ' . $e->getMessage());

            return $this->errorPage(ErrorCodes::DISCOVERY_UNAVAILABLE, 503);
        }
    }

    private function callback(Request $request): Response
    {
        try {
            $claims = $this->oidcClient()->completeAuthorization($request, $this->sessionStore);
            $identity = Identity::fromClaims($claims);
        } catch (OidcException $e) {
            // Une tentative refusée ne laisse aucun état derrière elle.
            $this->oidcClient()->forgetAuthorizationState($this->sessionStore);

            return $this->refuse($e);
        } catch (Throwable $e) {
            error_log('[ext-bbb] retour d\'autorisation illisible : ' . $e->getMessage());

            return $this->errorPage(ErrorCodes::TOKEN_EXCHANGE_FAILED, 502);
        }

        // Promotion d'anonyme à connecté : nouvel identifiant d'état, pour ne
        // pas laisser un identifiant choisi par un tiers devenir une identité.
        $this->sessionStore->regenerate();
        $identity->storeIn($this->sessionStore);

        return Response::redirect('/');
    }

    private function logout(Request $request): Response
    {
        Identity::clear($this->sessionStore);
        $this->sessionStore->destroy();

        // Pas de déconnexion SSO globale : hors contrat, et une extension n'a
        // pas à fermer la session SambaEdu de la personne.
        return Response::redirect('/');
    }

    private function servers(Request $request): Response
    {
        $controller = new ServersController($this->store(), $this->api, $this->view, $this->env);

        return $controller->handle($request, $this->sessionStore);
    }

    /**
     * Story 57.4 — le sélecteur de serveur est câblé ICI, à la main, comme le
     * reste : il prend le magasin et le client BBB, et rien d'autre. C'est ce
     * qui le rend exerçable sans réseau, et extractible tel quel le jour où le
     * kit de démarrage de l'Epic 58 le réclamera.
     */
    private function rooms(Request $request): Response
    {
        $store = $this->store();

        $controller = new RoomsController(
            $store,
            $this->api,
            $this->view,
            $this->env,
            new ServerSelector($store, $this->api),
        );

        return $controller->handle($request, $this->sessionStore);
    }

    /**
     * ⚠️ **Le magasin d'état n'est PAS passé ici, et c'est le point de la
     * story 57.3** : le parcours invité n'ouvre, ne lit et n'écrit aucun état
     * par visiteur. Ce n'est pas une discipline de rédaction, c'est la signature
     * du contrôleur.
     */
    private function visio(Request $request): Response
    {
        $controller = new GuestController($this->store(), $this->api, $this->view, $this->env);

        return $controller->handle($request);
    }

    private function recordings(Request $request): Response
    {
        $controller = new RecordingsController($this->store(), $this->api, $this->view, $this->env);

        return $controller->handle($request, $this->sessionStore);
    }

    // =====================================================================
    // Dépendances paresseuses
    // =====================================================================

    private function store(): Store
    {
        return $this->store ??= new Store($this->env->databasePath());
    }

    private function oidcClient(): Client
    {
        return $this->oidc ??= new Client(
            Credentials::fromEnv($this->env),
            new ProviderMetadata($this->http, $this->env->stateDirectory . '/oidc-metadata.json'),
            new IdTokenVerifier(new SqliteReplayGuard($this->store())),
            $this->http,
        );
    }

    // =====================================================================
    // Refus
    // =====================================================================

    private function refuse(OidcException $e): Response
    {
        error_log('[ext-bbb] connexion refusée : ' . $e->errorCode);

        return $this->errorPage($e->errorCode, self::statusFor($e->errorCode));
    }

    private static function statusFor(string $code): int
    {
        return match ($code) {
            ErrorCodes::NOT_PROVISIONED, ErrorCodes::DISCOVERY_UNAVAILABLE, ErrorCodes::JWKS_UNUSABLE => 503,
            ErrorCodes::TOKEN_EXCHANGE_FAILED, ErrorCodes::ID_TOKEN_MISSING => 502,
            default => 403,
        };
    }

    private function errorPage(string $code, int $status): Response
    {
        $canRetry = ! in_array($code, [ErrorCodes::NOT_PROVISIONED, ErrorCodes::ROLE_UNSUPPORTED], true);

        try {
            return Response::html(
                $this->view->page('error', [
                    'code' => $code,
                    'message' => self::humanMessage($code, $status),
                    'canRetry' => $canRetry,
                ], 'Erreur', $this->env, null),
                $status,
            );
        } catch (Throwable) {
            // Dernier filet : même sans vue, l'extension répond. Une réponse
            // HTTP, quelle qu'elle soit, vaut « joignable » pour la sonde.
            return new Response(
                'Extension Visioconférences — erreur (' . $code . ')',
                $status,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }
    }

    /**
     * Le message VU par la personne. Il ne nomme jamais le détail technique —
     * seul le code, affiché à part, sert la corrélation côté exploitant.
     */
    private static function humanMessage(string $code, int $status): string
    {
        return match ($code) {
            ErrorCodes::NOT_PROVISIONED => 'Cette extension n\'a pas encore été configurée par SambaEdu.',
            ErrorCodes::ROLE_UNSUPPORTED => 'Votre profil ne permet pas d\'utiliser cette extension.',
            ErrorCodes::DISCOVERY_UNAVAILABLE, ErrorCodes::JWKS_UNUSABLE => 'SambaEdu est momentanément injoignable.',
            ErrorCodes::TOKEN_EXCHANGE_FAILED, ErrorCodes::ID_TOKEN_MISSING => 'La connexion n\'a pas pu aboutir.',
            ErrorCodes::STATE_MISSING, ErrorCodes::STATE_MISMATCH => 'Votre demande de connexion a expiré.',
            'route.not_found' => 'Page introuvable.',
            'route.method_not_allowed' => 'Méthode non autorisée.',
            'internal.error' => 'Une erreur interne est survenue.',
            default => $status >= 500 ? 'Une erreur interne est survenue.' : 'Connexion refusée.',
        };
    }
}
