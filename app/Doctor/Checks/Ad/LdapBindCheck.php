<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Ad;

use App\Config\SambaEduConfig;
use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use Throwable;

/**
 * Vérifie qu'un bind LDAP admin réussit contre l'AD configuré.
 *
 * Complète les checks `Gpo/DcReachableCheck` (ping IP) et
 * `Gpo/KerberosTicketCheck` (ccache) : ici on valide le canal applicatif
 * réellement utilisé par SE5 (ldap_bind simple avec les credentials admin
 * de `SambaEduConfig::ldap()`), timeout réseau court.
 *
 * **Choix assumé (review F4)** : ce check est auto-découvert par
 * `sambaedu:doctor` — la commande CLI fait donc un bind LDAP réel sortant.
 * Voulu : le doctor est un outil de diagnostic d'étab, pas un job CI ; le
 * timeout court borne le coût hors réseau.
 */
final class LdapBindCheck implements EnvironmentCheck
{
    /**
     * Timeout réseau du bind (secondes). Court : le check tourne dans la
     * requête Livewire `runChecks` — son timeout s'ADDITIONNE à ceux des
     * autres checks réseau (fix review F3 : borner le pire cas cumulé).
     */
    private const NETWORK_TIMEOUT = 3;

    public function __construct(
        private readonly SambaEduConfig $config,
    ) {}

    public function tag(): string
    {
        return 'ad';
    }

    public function name(): string
    {
        return 'Bind LDAP AD';
    }

    public function run(): CheckResult
    {
        if (! function_exists('ldap_connect')) {
            return CheckResult::warn(
                'extension PHP ldap absente.',
                'apt install php-ldap puis redémarrer PHP-FPM.',
            );
        }

        try {
            $ldap = $this->config->ldap();
        } catch (Throwable $e) {
            return CheckResult::error(
                sprintf('config LDAP illisible : %s', substr($e->getMessage(), 0, 160)),
                'Vérifier /etc/sambaedu/sambaedu.conf (clés ldap_*).',
            );
        }

        if ($ldap->url === '' || $ldap->adminName === '' || $ldap->adminPassword === '') {
            return CheckResult::error(
                'config LDAP incomplète (url, admin ou mot de passe vide).',
                'Renseigner les clés ldap_* dans /etc/sambaedu/sambaedu.conf.',
            );
        }

        $conn = @ldap_connect($ldap->url);
        if ($conn === false) {
            return CheckResult::error(
                sprintf('URL LDAP invalide : %s', $ldap->url),
                'Corriger ldap_url dans la config (ex: ldaps://dc.domaine.fr).',
            );
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, self::NETWORK_TIMEOUT);

        // `ldap_admin_name` est typiquement un samaccountname nu
        // (« Administrator ») : un simple bind AD exige un DN ou un UPN —
        // on suffixe @domain (pattern AdUserManager::validatePassword).
        // Passent tels quels : un DN (`cn=…`), un UPN (`…@…`) et la forme
        // NT4 `DOMAINE\user` (fix review F12 — acceptée par AD).
        $bindIdentity = $ldap->adminName;
        if (! str_contains($bindIdentity, '=')
            && ! str_contains($bindIdentity, '@')
            && ! str_contains($bindIdentity, '\\')
            && $ldap->domain !== '') {
            $bindIdentity .= '@' . $ldap->domain;
        }

        $bound = @ldap_bind($conn, $bindIdentity, $ldap->adminPassword);
        if (! $bound) {
            $error = ldap_error($conn);
            ldap_close($conn);

            return CheckResult::error(
                sprintf('bind échoué sur %s (identité %s) : %s', $ldap->url, $bindIdentity, $error),
                'Vérifier que le DC est joignable et que les credentials admin LDAP sont valides.',
            );
        }

        ldap_close($conn);

        return CheckResult::ok(sprintf('bind admin OK sur %s (base %s)', $ldap->url, $ldap->baseDn));
    }
}
