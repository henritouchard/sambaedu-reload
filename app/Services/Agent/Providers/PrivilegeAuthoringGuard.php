<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

/**
 * Story 35.6 (AC3) — garde-fou d'AUTHORING des projections `windows/privilege` :
 * refuse à la SOURCE tout droit que l'agent refuserait (défense en profondeur —
 * le serveur peut avoir tort, mais ne doit JAMAIS produire un catalogue capable
 * de verrouiller une machine).
 *
 * **Pourquoi SeDeny*-only est la règle CENTRALE (piège #3, D3).** Le handler
 * agent possède la liste de titulaires du privilège EN ENTIER (conteneur sans
 * store, D4) : il RÉVOQUE tout titulaire hors état désiré. C'est SÛR uniquement
 * parce que les privilèges `SeDeny*` sont VIDES PAR DÉFAUT sous Windows (aucun
 * titulaire légitime préexistant à écraser). La même convergence sur un droit
 * *grant* (`SeInteractiveLogonRight`, `SeRemoteInteractiveLogonRight`, …)
 * révoquerait le droit de session à TOUT LE MONDE → machine verrouillée,
 * injoignable. L'allowlist fermée {@see self::ALLOWED_PRIVILEGES} rend cette
 * variante catastrophique INEXPRIMABLE — elle est l'AUTORITÉ du vocabulaire,
 * dupliquée en miroir côté agent (`privilegeAllowlist`,
 * agent/shared/handler_privilege.go).
 *
 * **Où.** L'authoring est catalogue-first (migrations/seeds — aucune UI de
 * création n'existe encore) : ce service PUR (projections en entrée, violations
 * NOMMÉES en sortie, zéro requête/écriture) est exécuté par un invariant de
 * test sur les données réellement seedées
 * ({@see \Tests\Feature\Migrations\CapabilityPrivilegeSeedTest}) ET au runtime
 * par l'observer Eloquent {@see \App\Observers\CapabilityProjectionObserver}
 * (dispatch par mécanisme, événement `saving`). Conçu pour être RÉUTILISÉ TEL
 * QUEL par un futur formulaire (mêmes messages FR, mêmes constantes publiques).
 *
 * **Ce qu'il refuse** :
 *   1. un `privilege` HORS de {@see self::ALLOWED_PRIVILEGES} — un droit *grant*
 *      ou un SeDeny inconnu (message explicitant le risque de verrouillage) ;
 *   2. un `privilege` vide ou absent ;
 *   3. un jeton d'audience inconnu (hors {@see AudienceTokens::TOKENS}) dans
 *      `accounts` ;
 *   4. un compte littéral à LARGE PORTÉE ({@see BROAD_PRINCIPALS} ou SID
 *      well-known : `Everyone`/`Authenticated Users`/`Domain Users`/`Users`/
 *      `Administrators`/`SYSTEM`/`Interactive`…) — une SeDeny* LÉGITIME (donc
 *      hors du filet allowlist) pointée sur un principal trop large VERROUILLE
 *      le poste (ex. `SeDenyInteractiveLogonRight` sur `Domain Users` = plus
 *      aucune session console possible). L'allowlist SeDeny*-only ne couvre PAS
 *      ce vecteur — c'est le pendant « portée » du garde-fou « type » (miroir
 *      SID côté agent, résolution LSA) ;
 *   5. une capacité portant une projection `privilege` (mécanisme de REFUS par
 *      nature) SANS `warning` non vide (l'implication doit être confirmée).
 *
 * **Ce qu'il N'INTERDIT PAS** : une liste `accounts` VIDE est LÉGITIME — c'est
 * le `off` réel (l'agent VIDE le privilège, RDP rétabli au logon suivant).
 *
 * **Pas de ciblage par utilisateur (piège #11).** Le mécanisme `privilege` est
 * de portée MACHINE : « qui est refusé » = la liste `accounts` DANS le payload,
 * « quels postes » = les assignations parc/salle/poste/broadcast. Un override
 * UserGroup/User est structurellement SANS EFFET (le service SYSTEM fetch sans
 * `?user`) — pas un garde-fou runtime, un fait de compilation.
 *
 * **Différence avec {@see FsAclAuthoringGuard} / {@see FirewallAuthoringGuard}** :
 * service SÉPARÉ (même patron) ; il valide l'ALLOWLIST de droits (pas des
 * chemins protégés ni des plages IP) — l'objet dangereux ici est le NOM du
 * privilège lui-même.
 */
final class PrivilegeAuthoringGuard
{
    /**
     * Les 5 droits de logon `SeDeny*` — enum FERMÉ (D3, contrat §7.9). SEULS
     * noms admis au champ `privilege` d'une projection `windows/privilege`.
     * Tout droit *grant* est REFUSÉ (risque de verrouillage machine, cf.
     * docblock de classe). Constante PUBLIQUE : autorité du vocabulaire,
     * miroir Go `privilegeAllowlist` (agent/shared/handler_privilege.go).
     *
     * @var list<string>
     */
    public const ALLOWED_PRIVILEGES = [
        'SeDenyInteractiveLogonRight',
        'SeDenyNetworkLogonRight',
        'SeDenyBatchLogonRight',
        'SeDenyServiceLogonRight',
        'SeDenyRemoteInteractiveLogonRight',
    ];

    /**
     * Principals à LARGE PORTÉE refusés dans `accounts` (forme normalisée :
     * minuscules, préfixe domaine `NT AUTHORITY\`/`BUILTIN\`/`DOMAIN\` retiré).
     * Une SeDeny* posée sur l'un d'eux verrouille le poste (personne ne peut
     * plus ouvrir de session du type refusé). Couvre FR et EN (poste localisé).
     * Le serveur ne résout pas les SID (NFR7) : il couvre les cas NOMMABLES,
     * l'agent refuse par SID well-known après résolution LSA (denylist miroir).
     *
     * @var list<string>
     */
    public const BROAD_PRINCIPALS = [
        'everyone',
        'tout le monde',
        'authenticated users',
        'utilisateurs authentifiés',
        'utilisateurs authentifies',
        'domain users',
        'utilisateurs du domaine',
        'users',
        'utilisateurs',
        'administrators',
        'administrateurs',
        'system',
        'local system',
        'interactive',
        'ouverture de session interactive',
        'network',
        'réseau',
        'reseau',
    ];

    /**
     * SID well-known à large portée refusés dans `accounts` (le contrat porte
     * des NOMS, mais un formulaire pourrait poster un SID). Formes exactes +
     * RID de groupes de domaine à large portée (Domain Users `-513`,
     * Domain Admins `-512`, Domain Computers `-515`).
     *
     * @var list<string>
     */
    public const BROAD_SIDS = [
        'S-1-1-0',      // Everyone
        'S-1-5-11',     // Authenticated Users
        'S-1-5-4',      // Interactive
        'S-1-5-2',      // Network
        'S-1-5-13',     // Terminal Server User
        'S-1-5-14',     // Remote Interactive Logon
        'S-1-5-18',     // Local System
        'S-1-5-32-544', // Administrators
        'S-1-5-32-545', // Users
    ];

    /**
     * Valide un ensemble de projections d'authoring `privilege`.
     *
     * @param  list<array{capability:string, warning:?string, spec:mixed}>  $projections
     *         une entrée par projection windows/privilege : `capability` = key
     *         lisible (messages), `warning` = message d'implications de la
     *         capacité (règle « privilege ⇒ warning non vide »), `spec` =
     *         `{privilege, accounts}` décodé.
     * @return list<string> violations lisibles (vide = authoring valide)
     */
    public function violations(array $projections): array
    {
        $violations = [];

        foreach ($projections as $projection) {
            $capability = (string) ($projection['capability'] ?? '?');
            $warning = $projection['warning'] ?? null;
            $spec = is_array($projection['spec'] ?? null) ? $projection['spec'] : [];

            // ── 1/2. Privilège : présent, non vide, DANS l'allowlist SeDeny* ──
            $privilege = trim((string) ($spec['privilege'] ?? ''));
            if ($privilege === '') {
                $violations[] = sprintf(
                    "privilege [%s] : champ `privilege` vide ou absent (attendu : un des 5 droits SeDeny* — %s).",
                    $capability,
                    implode(', ', self::ALLOWED_PRIVILEGES),
                );
            } elseif (! $this->isAllowedPrivilege($privilege)) {
                $violations[] = sprintf(
                    "privilege [%s] : droit '%s' hors de l'allowlist SeDeny* (admis : %s). Tout droit *grant* est "
                    .'REFUSÉ : une convergence exclusive « possède la liste entière » sur un grant révoquerait le '
                    .'droit de session à tout le monde — machine VERROUILLÉE, injoignable (piège #3).',
                    $capability,
                    $privilege,
                    implode(', ', self::ALLOWED_PRIVILEGES),
                );
            }

            // ── 3. Comptes : jeton d'audience inconnu REFUSÉ ──────────────────
            foreach ($this->accountValues($spec['accounts'] ?? null) as $account) {
                $account = (string) $account;
                if (trim($account) === '') {
                    $violations[] = sprintf("privilege [%s] : compte vide dans `accounts`.", $capability);

                    continue;
                }
                if (AudienceTokens::isToken($account)) {
                    // Jeton d'audience : doit être dans l'enum fermé. Un jeton
                    // résout un groupe MÉTIER — jamais large : pas de contrôle
                    // BROAD_PRINCIPALS à faire (miroir fs_acl).
                    if (! array_key_exists(strtolower($account), AudienceTokens::TOKENS)) {
                        $violations[] = sprintf(
                            "privilege [%s] : jeton d'audience '%s' inconnu (admis : %s).",
                            $capability,
                            $account,
                            implode(', ', array_keys(AudienceTokens::TOKENS)),
                        );
                    }

                    continue;
                }

                // Compte littéral à LARGE PORTÉE REFUSÉ : une SeDeny* légitime
                // dessus verrouille le poste (le type est déjà borné par
                // l'allowlist ; ici on borne la PORTÉE).
                if ($this->isBroadPrincipal($account)) {
                    $violations[] = sprintf(
                        "privilege [%s] : compte '%s' à trop large portée pour un refus de logon — poserait la SeDeny* "
                        .'sur tous les utilisateurs (poste VERROUILLÉ). Cibler un groupe métier (jeton @eleves/@profs/'
                        .'@personnels ou un groupe nommé), jamais Everyone/Authenticated Users/Domain Users/Users/'
                        .'Administrators/SYSTEM/Interactive.',
                        $capability,
                        $account,
                    );
                }
            }

            // ── 4. Mécanisme de REFUS par nature ⇒ warning non vide (AC3) ────
            // Une liste `accounts` vide reste LÉGITIME (= off, privilège vidé) :
            // le guard vérifie la cohérence privilège/warning, pas la non-vacuité.
            if (trim((string) ($warning ?? '')) === '') {
                $violations[] = sprintf(
                    "privilege [%s] : projection `privilege` sans `warning` non vide — l'implication (refus de "
                    .'logon, effet au logon suivant) doit être confirmée.',
                    $capability,
                );
            }
        }

        return $violations;
    }

    /**
     * Le nom est-il un des 5 SeDeny* ? Comparaison INSENSIBLE à la casse
     * (Windows l'est sur les noms de privilège) — le provider normalise
     * l'identité en minuscules, le guard accepte la même tolérance.
     */
    private function isAllowedPrivilege(string $privilege): bool
    {
        foreach (self::ALLOWED_PRIVILEGES as $allowed) {
            if (strcasecmp($privilege, $allowed) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un compte littéral est-il un principal à LARGE PORTÉE ? Couvre :
     *   1. un SID well-known exact ({@see BROAD_SIDS}) ou un RID de groupe de
     *      domaine à large portée (`…-513`/`-512`/`-515`, Domain Users/Admins/
     *      Computers) ;
     *   2. un nom bien connu ({@see BROAD_PRINCIPALS}), avec ou sans préfixe
     *      domaine (`NT AUTHORITY\Authenticated Users`, `SE4\Domain Users` →
     *      dernier segment), insensible à la casse.
     */
    private function isBroadPrincipal(string $account): bool
    {
        $trimmed = trim($account);

        // (1) SID : well-known exact OU RID de groupe de domaine large.
        if (preg_match('/^S-1-/i', $trimmed)) {
            foreach (self::BROAD_SIDS as $sid) {
                if (strcasecmp($trimmed, $sid) === 0) {
                    return true;
                }
            }

            return (bool) preg_match('/-(513|512|515)$/', $trimmed);
        }

        // (2) Nom bien connu (préfixe domaine retiré).
        $bare = $trimmed;
        $pos = strrpos($trimmed, '\\');
        if ($pos !== false) {
            $bare = substr($trimmed, $pos + 1);
        }

        return in_array(strtolower(trim($bare)), self::BROAD_PRINCIPALS, true);
    }

    /**
     * Comptes possibles d'un champ `accounts` de `spec` : liste littérale (ses
     * entrées) OU chaque entrée de chaque valeur d'une map valeur-capacité
     * (`{capValue: [comptes]}`, D8). Toute autre forme est ignorée (le provider
     * ne l'émettra pas — non émis défensif).
     *
     * @return list<mixed>
     */
    private function accountValues(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        if (array_is_list($raw)) {
            return array_values(array_filter($raw, 'is_scalar'));
        }

        // Map valeur-capacité : chaque valeur doit être une liste de comptes.
        $out = [];
        foreach ($raw as $list) {
            if (is_array($list) && array_is_list($list)) {
                foreach ($list as $account) {
                    if (is_scalar($account)) {
                        $out[] = $account;
                    }
                }
            }
        }

        return $out;
    }
}
