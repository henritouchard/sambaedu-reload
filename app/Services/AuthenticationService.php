<?php

namespace App\Services;

use App\Config\SambaEduConfig;
use App\Constants\Errors\AuthenticationErrors;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class AuthenticationService
{
    private ?array $configCache = null;
    private UserRepository $userRepository;
    private SambaEduConfig $sambaEduConfig;

    public function __construct(UserRepository $userRepository, SambaEduConfig $sambaEduConfig)
    {
        $this->userRepository = $userRepository;
        $this->sambaEduConfig = $sambaEduConfig;
    }

    /**
     * Démarre la session PHP en validant le session ID du cookie.
     * Protège contre les session ID invalides (trop longs ou caractères interdits).
     */
    private function ensureSession(): void
    {
        if (!session_name()) {
            session_name("Sambaedu");
        }

        if (empty(session_id())) {
            $cookieName = session_name();
            if (isset($_COOKIE[$cookieName]) && !preg_match('/^[a-zA-Z0-9,-]{1,128}$/', $_COOKIE[$cookieName])) {
                unset($_COOKIE[$cookieName]);
                session_id(bin2hex(random_bytes(16)));
            }
            session_start(['cookie_lifetime' => 86400]);
        }
    }

    /**
     * Charge la configuration complète depuis les fichiers INI
     * Nécessaire UNIQUEMENT pour les fonctions legacy critiques suivantes :
     * - search_machine() : Recherche de machines par IP (auto-login) - utilise ldap.inc.php
     * - get_machine_status() : Statut des machines (gestion des sessions) - utilise logs.inc.php
     *
     * Ces fonctions nécessitent le format de config legacy complet avec bind LDAP.
     * TODO: Réécrire ces fonctions avec LdapRecord pour supprimer cette dépendance
     *
     * @return array Configuration complète au format legacy
     */
    private function getLegacyConfig(): array
    {
        // Pour les fonctions legacy, on doit charger la config complète avec get_config()
        // car elles nécessitent des données supplémentaires (bind LDAP, etc.)
        try {
            require_once 'config.inc.php';
            require_once 'ldap.inc.php';
            require_once 'logs.inc.php';

            return get_config();
        } catch (\Exception $e) {
            Log::error('Erreur lors du chargement de la configuration legacy', [
                'error' => $e->getMessage()
            ]);
            return $this->sambaEduConfig->all();
        }
    }

    /**
     * Authentifier un utilisateur
     */
    public function authenticate(string $login, string $password, string $ip): array
    {
        try {
            // Vérifier si l'utilisateur est un élève et si les élèves sont bloqués
            if ($this->isEleve($login) && !empty($this->sambaEduConfig->get('blocage_eleves'))) {
                Log::warning('Accès interdit aux élèves', ['login' => $login]);
                return [
                    'success' => false,
                    'error' => 'Accès interdit aux élèves',
                    'code' => 'ELEVES_BLOQUES'
                ];
            }

            // Vérifier l'authentification via LdapRecord
            $auth = $this->validatePassword($login, $password);

            if ($auth > 0) {
                // Authentification réussie
                $this->createSession($login);

                Log::info('Authentification réussie', [
                    'login' => $login,
                    'ip' => $ip
                ]);

                return [
                    'success' => true,
                    'login' => $login,
                    'code' => 'SUCCESS'
                ];
            } elseif ($auth == -1) {
                // Authentification réussie mais changement de mdp nécessaire.
                return [
                    'success' => false,
                    'error' => 'Changement de mot de passe obligatoire',
                    'code' => AuthenticationErrors::ERROR_AUTHENTICATION_PASSWORD_CHANGE_REQUIRED
                ];
            } else {
                // Échec de l'authentification
                Log::warning('Tentative d\'authentification échouée', [
                    'login' => $login,
                    'ip' => $ip,
                ]);

                return [
                    'success' => false,
                    'error' => 'Nom d\'utilisateur ou mot de passe incorrects',
                    'code' => 'INVALID_CREDENTIALS'
                ];
            }

        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'authentification', [
                'login' => $login,
                'ip' => $ip,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Erreur technique lors de l\'authentification',
                'code' => 'TECHNICAL_ERROR'
            ];
        }
    }

    /**
     * Vérifier si l'utilisateur est déjà authentifié
     */
    public function isAlreadyAuthenticated(): bool
    {
        $this->ensureSession();

        return !empty($_SESSION['login'] ?? null);
    }

    /**
     * Vérifier si l'authentification ENT est disponible
     */
    public function isEntAuthAvailable(): bool
    {
        return !empty($this->sambaEduConfig->get('ent_auth')) &&
            $this->sambaEduConfig->get('ent_auth') == 1 &&
            $this->getCacheValue('ent_status', false);
    }

    /**
     * Helper pour récupérer une valeur depuis le cache Laravel
     * Remplace l'ancien système APCu
     * 
     * @param string $key Clé de cache
     * @param mixed $default Valeur par défaut
     * @return mixed Valeur en cache ou valeur par défaut
     */
    private function getCacheValue(string $key, $default = false)
    {
        return Cache::get($key, $default);
    }

    /**
     * Vérifier si l'authentification CAS est disponible
     */
    public function isCasAuthAvailable(): bool
    {
        $casUrl = $this->sambaEduConfig->get('cas_url');
        return !empty($casUrl);
    }

    /**
     * Tentative d'auto-login basé sur l'IP
     */
    public function attemptAutoLogin(string $ip): ?array
    {
        try {
            require_once 'samba.inc.php';

            // Rechercher la machine par IP
            $machine = $this->searchMachine($ip, false);

            if (!empty($machine['cn'])) {
                $machineStatus = $this->getMachineStatus($machine['cn']);

                if (isset($machineStatus['user']) && count($machineStatus['user']) == 1) {
                    $login = $machineStatus['user'][0]['name'];

                    Log::info('Auto-login détecté', [
                        'machine' => $machine['cn'],
                        'user' => $login,
                        'ip' => $ip
                    ]);

                    return [
                        'login' => $login,
                        'machine' => $machine['cn']
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'auto-login', [
                'error' => $e->getMessage(),
                'ip' => $ip
            ]);
        }

        return null;
    }

    /**
     * Créer la session utilisateur
     */
    public function createSession(string $login): void
    {
        $this->ensureSession();

        $_SESSION['login'] = $login;
    }

    /**
     * Vérifier si l'utilisateur est un élève
     * TODO: La requête LDAP prend 720s sur ce serveur - désactivé temporairement
     */
    private function isEleve(string $login): bool
    {
        // Désactivé temporairement - requête LDAP trop lente (720s timeout)
        return false;
    }

    /**
     * Vérifie la validité du mot de passe d'un utilisateur
     * 
     * Utilise LdapRecord pour authentifier l'utilisateur via bind LDAP
     * 
     * @param string $login Login de l'utilisateur
     * @param string $password Mot de passe à vérifier
     * @return int 
     *   - 1 : Authentification réussie
     *   - -1 : Authentification réussie mais changement de mot de passe obligatoire (pwdlastset == 0)
     *   - -2 : Authentification échouée mais changement de mot de passe obligatoire (pwdlastset == 0)
     *   - 0 : Authentification échouée ou erreur
     */
    private function validatePassword(string $login, string $password): int
    {
        try {
            // Rechercher l'utilisateur via le repository
            // Le legacy utilise search_user($config, $login, "all", true) qui cherche dans toute la base DN
            $ldapUser = $this->userRepository->findLdapModelByLogin($login);

            if (!$ldapUser) {
                Log::warning("Utilisateur non trouvé pour l'authentification", [
                    'login' => $login,
                ]);
                return 0;
            }

            // Récupérer pwdlastset
            // LdapRecord peut retourner un objet Carbon pour les dates LDAP
            // Utiliser getFirstAttribute() pour obtenir la valeur brute avant conversion
            try {
                $pwdLastSetRaw = $ldapUser->getFirstAttribute('pwdlastset');

                // Si c'est null ou vide, considérer comme 0
                if ($pwdLastSetRaw === null || $pwdLastSetRaw === '') {
                    $pwdLastSet = 0;
                } else {
                    // Convertir en int directement depuis la valeur brute
                    $pwdLastSet = (int) $pwdLastSetRaw;
                }
            } catch (\Exception $e) {
                // En cas d'erreur, essayer avec getAttribute() et gérer Carbon
                $pwdLastSet = $ldapUser->getAttribute('pwdlastset');

                if ($pwdLastSet instanceof \Carbon\Carbon) {
                    // Si c'est un Carbon valide, ce n'est pas 0 (changement obligatoire)
                    // pwdlastset = 0 signifie "changement obligatoire au prochain login"
                    // Un Carbon valide signifie qu'une date est définie, donc ce n'est pas 0
                    $pwdLastSet = 1; // Pas 0, donc pas de changement obligatoire
                } elseif (is_array($pwdLastSet)) {
                    $pwdLastSet = $pwdLastSet[0] ?? 0;
                    if ($pwdLastSet instanceof \Carbon\Carbon) {
                        $pwdLastSet = 1; // Pas 0, donc pas de changement obligatoire
                    } else {
                        $pwdLastSet = (int) $pwdLastSet;
                    }
                } else {
                    $pwdLastSet = (int) $pwdLastSet;
                }
            }

            // Utiliser directement le DN pour le bind, comme le fait le legacy
            // Le legacy utilise toujours $user['dn'] pour le bind dans user_valid_passwd()
            $bindUsername = $ldapUser->getDn();

            // Cas spécial : changement de mot de passe obligatoire (pwdlastset == 0)
            if ($pwdLastSet == 0) {
                // Hack legacy : modifier temporairement pwdlastset à -1 pour permettre la vérification
                // sans déclencher le changement obligatoire pendant le bind
                try {
                    $ldapUser->setAttribute('pwdlastset', -1);
                    $ldapUser->save();

                    // Tenter l'authentification
                    $authenticated = $this->attemptBind($bindUsername, $password);

                    // Remettre pwdlastset à 0
                    $ldapUser->setAttribute('pwdlastset', 0);
                    $ldapUser->save();

                    if ($authenticated) {
                        return -1; // Authentification réussie mais changement obligatoire
                    } else {
                        return -2; // Authentification échouée mais changement obligatoire
                    }
                } catch (\Exception $e) {
                    // En cas d'erreur, essayer de remettre pwdlastset à 0
                    try {
                        $ldapUser->setAttribute('pwdlastset', 0);
                        $ldapUser->save();
                    } catch (\Exception $e2) {
                        Log::error("Erreur lors de la restauration de pwdlastset", [
                            'login' => $login,
                            'error' => $e2->getMessage()
                        ]);
                    }

                    Log::error("Erreur lors de l'authentification avec pwdlastset=0", [
                        'login' => $login,
                        'error' => $e->getMessage()
                    ]);
                    return 0;
                }
            } else {
                // Cas normal : authentification simple
                $authenticated = $this->attemptBind($bindUsername, $password);

                return $authenticated ? 1 : 0;
            }

        } catch (\Exception $e) {
            Log::error("Erreur lors de la validation du mot de passe", [
                'login' => $login,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Tente une authentification LDAP via bind avec les credentials de l'utilisateur
     * 
     * Utilise directement ldap_connect() et ldap_bind() comme le legacy
     * pour garantir la compatibilité exacte avec user_valid_passwd()
     * 
     * @param string $userDn DN de l'utilisateur
     * @param string $password Mot de passe
     * @return bool True si l'authentification réussit, false sinon
     */
    private function attemptBind(string $userDn, string $password): bool
    {
        try {
            // Construire l'URL LDAP comme le fait ad_url($config, "ldaps")
            $ldapUrl = $this->buildLdapUrl();

            // Utiliser directement ldap_connect() et ldap_bind() comme le legacy
            $ds = ldap_connect($ldapUrl);

            if (!$ds) {
                Log::error("Échec de la connexion LDAP", [
                    'ldapUrl' => $ldapUrl,
                ]);
                return false;
            }

            // Configurer les options comme le legacy
            ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ds, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($ds, LDAP_OPT_NETWORK_TIMEOUT, 60);

            // Tenter le bind avec le DN et le mot de passe
            // Utiliser @ pour supprimer les warnings comme le legacy
            $result = @ldap_bind($ds, $userDn, $password);

            if ($result) {
                ldap_close($ds);
                return true;
            } else {
                Log::warning("Échec du bind LDAP", [
                    'userDn' => $userDn,
                    'ldap_error' => ldap_error($ds),
                ]);
                ldap_close($ds);
                return false;
            }

        } catch (\Exception $e) {
            Log::error("Erreur lors du bind LDAP", [
                'userDn' => $userDn,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Construit l'URL LDAP pour la connexion
     * Utilise SambaEduConfig pour obtenir les paramètres
     * 
     * @return string URL LDAP (ex: "ldaps://server1:636 ldaps://server2:636")
     */
    private function buildLdapUrl(): string
    {
        $ldapConfig = $this->sambaEduConfig->ldap();
        $hosts = $ldapConfig->getHosts();
        $port = $ldapConfig->port;
        $useSsl = $ldapConfig->useSsl();

        if (empty($hosts)) {
            Log::error("Aucun hôte LDAP configuré");
            return '';
        }

        $url = '';
        $protocol = $useSsl ? 'ldaps' : 'ldap';

        foreach ($hosts as $host) {
            if (!empty($url)) {
                $url .= ' ';
            }
            $url .= $protocol . '://' . $host;
            // Pour ldaps, le port par défaut est 636, pour ldap c'est 389
            // On ajoute le port seulement s'il est différent du port par défaut
            $defaultPort = $useSsl ? 636 : 389;
            if ($port != $defaultPort) {
                $url .= ':' . $port;
            }
        }

        return $url;
    }

    /**
     * Rechercher une machine par IP
     * TODO: Réactiver quand les includes legacy seront correctement chargés
     */
    private function searchMachine(string $ip, bool $strict = false)
    {
        // Désactivé temporairement - fonction legacy non disponible
        return null;
        
        // if (function_exists('search_machine')) {
        //     // Ces fonctions legacy nécessitent la config complète avec bind LDAP
        //     $legacyConfig = $this->getLegacyConfig();
        //     return search_machine($legacyConfig, $ip, $strict);
        // } else {
        //     Log::error('Fonction search_machine non trouvée');
        //     return null;
        // }
    }

    /**
     * Obtenir le statut d'une machine
     */
    private function getMachineStatus(string $machine)
    {
        if (function_exists('get_machine_status')) {
            // Ces fonctions legacy nécessitent la config complète avec bind LDAP
            $legacyConfig = $this->getLegacyConfig();
            return get_machine_status($legacyConfig, $machine);
        } else {
            Log::error('Fonction get_machine_status non trouvée');
            return null;
        }
    }

    /**
     * Obtenir le login de l'utilisateur connecté
     */
    public function getCurrentUser(): ?string
    {
        $this->ensureSession();

        return $_SESSION['login'] ?? null;
    }

    /**
     * Obtenir la configuration SambaEdu
     * 
     * @deprecated Utiliser SambaEduConfig directement pour des valeurs spécifiques
     * Cette méthode est conservée pour compatibilité avec le code existant
     */
    public function getConfig(): array
    {
        // Charger la config complète legacy si nécessaire pour compatibilité
        return $this->getLegacyConfig();
    }

    /**
     * Créer un token temporaire pour le changement de mot de passe
     */
    public function createPasswordChangeToken(string $login): string
    {
        $token = bin2hex(random_bytes(32));
        $expiry = time() + (15 * 60); // 15 minutes

        // Stocker en session
        session([
            'password_change_token' => [
                'token' => $token,
                'login' => $login,
                'expires' => $expiry,
                'used' => false,
                'created_at' => time()
            ]
        ]);

        Log::info('Token de changement de mot de passe créé', [
            'login' => $login,
            'expires' => date('Y-m-d H:i:s', $expiry)
        ]);

        return $token;
    }

    /**
     * Valider un token de changement de mot de passe
     */
    public function validatePasswordChangeToken(string $token): ?array
    {
        $tokenData = session('password_change_token');

        if (
            !$tokenData ||
            $tokenData['token'] !== $token ||
            $tokenData['expires'] < time() ||
            $tokenData['used']
        ) {
            return null;
        }

        return $tokenData;
    }

    /**
     * Marquer un token comme utilisé
     */
    public function markTokenAsUsed(): void
    {
        session(['password_change_token.used' => true]);
        Log::info('Token de changement de mot de passe marqué comme utilisé');
    }

    /**
     * Créer une session ENT après authentification OAuth2
     */
    public function createEntSession(array $userInfo, $accessToken): void
    {
        $this->ensureSession();

        $_SESSION['login_ent'] = $userInfo['login'] ?? null;
        $_SESSION['login'] = $userInfo['cn'] ?? $userInfo['login'];
        $_SESSION['auth'] = "ent";
        $_SESSION['accesstoken'] = is_object($accessToken) ? $accessToken->getToken() : $accessToken;
        $_SESSION['hasPw'] = $userInfo['hasPw'] ?? null;
        $_SESSION['forceChangePassword'] = $userInfo['forceChangePassword'] ?? null;

        Log::info('Session ENT créée', [
            'login' => $_SESSION['login'],
            'login_ent' => $_SESSION['login_ent']
        ]);
    }

    /**
     * Vérifier si l'utilisateur est connecté via ENT
     */
    public function isEntAuthenticated(): bool
    {
        $this->ensureSession();

        return !empty($_SESSION['login']) &&
            !empty($_SESSION['auth']) &&
            $_SESSION['auth'] === 'ent';
    }

    /**
     * Obtenir les informations de session ENT
     */
    public function getEntSessionInfo(): ?array
    {
        if (!$this->isEntAuthenticated()) {
            return null;
        }

        return [
            'login' => $_SESSION['login'] ?? null,
            'login_ent' => $_SESSION['login_ent'] ?? null,
            'hasPw' => $_SESSION['hasPw'] ?? null,
            'forceChangePassword' => $_SESSION['forceChangePassword'] ?? null,
            'accesstoken' => $_SESSION['accesstoken'] ?? null
        ];
    }

    /**
     * Déconnecter l'utilisateur
     */
    public function logout(): void
    {
        $this->ensureSession();

        session_destroy();
        unset($_SESSION);
    }
}
