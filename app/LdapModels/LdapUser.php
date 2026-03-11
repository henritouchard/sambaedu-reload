<?php

namespace App\LdapModels;

use LdapRecord\Models\ActiveDirectory\User as BaseUser;

/**
 * Modèle LdapRecord pour les utilisateurs SambaEdu
 * 
 * @internal Ne pas utiliser directement - Utiliser le type App\Types\User
 *           via la méthode toBusinessObject() ou un Repository
 * 
 * Ce modèle ne contient QUE la logique liée à LdapRecord/LDAP.
 * Toute la logique métier est dans App\Types\User
 * Le but est d'obfusquer la complexité de ldap en utilisant un modèle orienté 
 * métier avec des méthodes et variables aussi claires que possible
 */
class LdapUser extends BaseUser
{
    /**
     * Les attributs à retourner dans les résultats LDAP
     */
    protected array $columns = [
        'cn',                    // login
        'displayname',           // Prenom Nom
        'sn',                    // Nom
        'givenname',             // Prenom
        'mail',                  // Mail
        'telephonenumber',       // Numéro téléphone
        'description',
        'physicaldeliveryofficename', // Date de naissance, Sexe (F/M) hash
        'title',                 // Numéro unique id ENT (OpenENT), externalId
        'employeenumber',        // Identifiants unique SIECLE et/ou GPEI, ASM... (séparés par des ,)
        'initials',              // pseudo
        'useraccountcontrol',    // État du compte (actif = 512, désactivé = 514)
        'memberof',              // Groupes
        'userprincipalname',     // Pseudo-adresse mail correspondant au login ENT
        'objectguid',            // Identifiant unique à décoder avec to_guid()
        'pwdlastset',           // 0 => doit changer de mdp
        'accountexpires',        // Date d'expiration du compte en temps windows
        'homedirectory',
        'profilepath',
        'scriptpath',
        'whencreated',
        'whenchanged',
        'lastlogon',
    ];

    // ============================================
    // CONFIGURATION LDAPRECORD
    // ============================================

    /**
     * Le DN de base pour ce type d'objet
     */
    public static function baseDn(): string
    {
        return \App\Config\LdapDnHelper::peopleDn();
    }

    // ============================================
    // MÉTHODES DE RECHERCHE LDAP
    // ============================================

    /**
     * Recherche un utilisateur par son login (cn)
     * 
     * Utilise LdapRecord avec la connexion centralisée (IP directe configurée dans LdapConfig)
     */
    public static function findByLogin(string $login): ?static
    {
        // Chercher dans le baseDn du modèle (OU=People)
        return static::where('cn', '=', $login)->first();
    }

    /**
     * Recherche un utilisateur par son employeenumber
     */
    public static function findByEmployeeNumber(string $employeenumber): ?static
    {
        return static::where('employeenumber', '=', $employeenumber)->first();
    }

    // ============================================
    // ACCESSEURS LDAP SIMPLES (pour usage interne avant conversion DTO)
    // ============================================

    /**
     * Récupère le login (cn) - Utile avant conversion en DTO
     */
    public function getLogin(): string
    {
        $cn = $this->getAttribute('cn', '');
        return is_array($cn) ? ($cn[0] ?? '') : (string) $cn;
    }

    // ============================================
    // CONVERSION VERS DTO
    // ============================================

    /**
     * Conversion vers DataObject métier
     * 
     * C'est LA méthode à utiliser pour obtenir un objet User utilisable
     */
    public function toBusinessObject(): \App\Types\User
    {
        $attributes = $this->attributes;

        // Helper pour extraire la première valeur d'un attribut LDAP
        $getValue = function ($key, $default = null) use ($attributes) {
            if (!isset($attributes[$key])) {
                return $default;
            }
            $value = $attributes[$key];
            if (is_array($value) && count($value) > 0) {
                return $value[0] ?? $default;
            }
            return $value ?? $default;
        };

        // Helper pour extraire un tableau d'attributs LDAP
        $getArray = function ($key) use ($attributes) {
            if (!isset($attributes[$key])) {
                return [];
            }
            $value = $attributes[$key];
            if (is_array($value)) {
                if (isset($value['count'])) {
                    unset($value['count']);
                }
                return array_values($value);
            }
            return $value ? [$value] : [];
        };

        // Extraire memberOf pour calculer groups et rights
        $memberOf = $getArray('memberof');

        $rightsBranch = $this->getRightsBranch();

        // Extraire les noms de groupes simples depuis les DN (exclure les droits)
        $groups = [];
        foreach ($memberOf as $dn) {
            // Exclure les droits si rightsBranch est défini
            if (!empty($rightsBranch) && preg_match("/" . preg_quote($rightsBranch, '/') . "/i", $dn)) {
                continue;
            }
            if (preg_match('/^CN=([^,]+),/', $dn, $matches)) {
                $groups[] = $matches[1];
            }
        }

        // Extraire les droits depuis les groupes de droits
        // Les droits sont les memberOf dont le DN contient la branche rights
        $rights = [];
        foreach ($memberOf as $dn) {
            $isRight = !empty($rightsBranch)
                ? preg_match("/" . preg_quote($rightsBranch, '/') . "/i", $dn)
                : str_contains(strtolower($dn), 'ou=rights');

            if ($isRight && preg_match('/^CN=([^,]+),/', $dn, $matches)) {
                $rights[] = $matches[1];
            }
        }

        // Récupérer le code et le nom de l'établissement
        $etabCode = $this->extractEtablissement();
        $etabName = $this->extractEtablissementName($etabCode);

        return new \App\Types\User(
            login: $getValue('cn', 'unknown'),
            fullname: $getValue('displayname', ''),
            firstname: $getValue('givenname'),
            lastname: $getValue('sn'),
            email: $getValue('mail'),
            phone: $getValue('telephonenumber'),
            description: $getValue('description'),
            etabCode: $etabCode,
            etabName: $etabName,
            homeDirectory: $getValue('homedirectory'),
            profilePath: $getValue('profilepath'),
            connectionScriptPath: $getValue('scriptpath'),
            isActive: ((int) $getValue('useraccountcontrol', 512)) === 512,
            pwdLastSet: $getValue('pwdlastset'),
            lastLogon: $getValue('lastlogon'),
            createdAt: $getValue('whencreated'),
            updatedAt: $getValue('whenchanged'),
            memberOf: $memberOf,
            groups: $groups,
            rights: $rights,
            dn: $this->dn ?? '',
            role: $this->extractRole($memberOf),
            isActiveUser: !$this->checkIsInTrash(),
            isTrash: $this->checkIsInTrash(),
        );
    }

    // ============================================
    // MÉTHODES PRIVÉES POUR toBusinessObject()
    // ============================================

    /**
     * Extraction de l'UAI depuis le DN
     * Retourne '0' si pas d'UAI trouvé (cas mono-établissement)
     */
    private function extractEtablissement(): string
    {
        $dn = $this->getDn() ?? '';

        // Extraire la partie avant les DC
        $rdn = preg_split('/,dc=/i', $dn);
        $rdnPart = $rdn[0] ?? '';

        // Chercher un UAI (7 chiffres + 1 lettre) dans le DN
        // Pattern: ou=UAI, cn=UAI, ou -UAI (pour les groupes avec suffixe)
        if (preg_match('/(ou=|cn=|-)([0-9]{7}[a-z]),/i', $rdnPart, $matches)) {
            return strtolower($matches[2]);
        }

        // Pas d'UAI trouvé = établissement par défaut (mono-établissement)
        return '0';
    }

    /**
     * Récupère le nom de l'établissement depuis son code UAI
     * Utilise le EstablishmentRepository
     */
    private function extractEtablissementName(?string $etabCode): ?string
    {
        if ($etabCode === null) {
            return null;
        }

        try {
            $repository = app(\App\Repositories\EstablishmentRepository::class);
            $name = $repository->getName($etabCode);

            // Si le repository retourne le code lui-même, c'est qu'il n'a pas trouvé le nom
            if ($name !== $etabCode) {
                return $name;
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Erreur récupération nom établissement', [
                'etabCode' => $etabCode,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Récupère la branche des droits depuis la configuration LDAP
     * Utilise le cache pour éviter de recharger la config à chaque appel
     * 
     * @return string|null La branche DN des droits (ex: "OU=rights,DC=domain,DC=local")
     */
    private function getRightsBranch(): ?string
    {
        static $rightsBranch = null;
        static $loaded = false;

        if (!$loaded) {
            $loaded = true;
            try {
                $configService = app(\App\Config\SambaEduConfig::class);
                $rightsBranch = $configService->ldap()->rightsDn();
            } catch (\Exception $e) {
                // Fallback si la config n'est pas disponible
                $rightsBranch = null;
            }
        }

        return $rightsBranch;
    }

    /**
     * Vérifie si l'utilisateur est dans la corbeille
     */
    private function checkIsInTrash(): bool
    {
        $dn = $this->getDn();
        $trashDn = config('sambaedu.ldap.trash_dn', 'Trash');
        return preg_match("/" . preg_quote($trashDn, '/') . "/i", $dn) === 1;
    }

    /**
     * Détermine le rôle depuis les attributs LDAP
     */
    private function extractRole(array $memberOf): string
    {
        $dn = $this->getDn() ?? '';

        // Vérifier élève
        foreach ($memberOf as $group) {
            if (preg_match('/Eleves/i', $group)) {
                return 'eleves';
            }
        }
        if (preg_match('/OU=Eleves/i', $dn)) {
            return 'eleves';
        }

        // Vérifier prof
        foreach ($memberOf as $group) {
            if (preg_match('/Profs/i', $group)) {
                return 'profs';
            }
        }
        if (preg_match('/OU=Profs/i', $dn)) {
            return 'profs';
        }

        // Vérifier administratif
        foreach ($memberOf as $group) {
            if (preg_match('/Administratifs/i', $group)) {
                return 'administratifs';
            }
        }
        if (preg_match('/OU=Administratifs/i', $dn)) {
            return 'administratifs';
        }

        return 'autre';
    }
}
