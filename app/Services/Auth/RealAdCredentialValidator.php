<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Config\SambaEduConfig;
use App\Contracts\Ad\AdCredentialValidator;
use Illuminate\Support\Facades\Log;

/**
 * Implémentation RÉELLE de {@see AdCredentialValidator} (Story 21.2).
 *
 * Extraction 1:1 de l'ancien `AuthenticationService::attemptBind()` (canal B —
 * `ldap_connect()` + `@ldap_bind()` brut, parité legacy `user_valid_passwd()`).
 * Bindée PAR DÉFAUT dans tous les environnements ; le comportement d'auth réel
 * de dev/prod/testing est donc STRICTEMENT INCHANGÉ (AC5).
 *
 * Le mot de passe n'est JAMAIS loggué (seul le DN l'est, en cas d'échec).
 */
class RealAdCredentialValidator implements AdCredentialValidator
{
    public function __construct(
        private readonly SambaEduConfig $sambaEduConfig,
    ) {
    }

    public function attemptBind(string $userDn, string $password): bool
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
     * Construit l'URL LDAP pour la connexion.
     * Extraction 1:1 de l'ancien `AuthenticationService::buildLdapUrl()`.
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
}
