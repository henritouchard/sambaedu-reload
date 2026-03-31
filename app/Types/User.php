<?php

namespace App\Types;

use Livewire\Wireable;

/**
 * DTO (Data Transfer Object) typé pour un utilisateur Active Directory
 * 
 * Cette classe est l'unique représentation métier d'un utilisateur dans l'application.
 * Elle est créée depuis LdapUser::toBusinessObject() ou User::fromAdData().
 * 
 * Implémente Wireable pour être utilisable comme propriété Livewire.
 * Pour les APIs externes, utilisez UserResource pour une projection simplifiée.
 * 
 * @see \App\LdapModels\LdapUser::toBusinessObject() Conversion depuis LDAP
 * @see \App\Http\Resources\UserResource Projection API
 */
class User implements Wireable
{
    // ============================================
    // CONSTANTES POUR USERACCOUNTCONTROL
    // ============================================
    public const UAC_ACTIVE = 512;
    public const UAC_DISABLED = 514;

    public function __construct(
        public readonly string $login,                    // Login utilisateur
        public readonly string $fullname,              // Nom complet
        public readonly ?string $firstname = null,     // Prénom
        public readonly ?string $lastname = null,      // Nom de famille
        public readonly ?string $email = null,         // Email
        public readonly ?string $phone = null,         // Téléphone
        public readonly ?string $description = null,   // Description
        public readonly ?string $etabCode = null,      // Code établissement (UAI)
        public readonly ?string $etabName = null,      // Nom établissement
        public readonly ?string $homeDirectory = null, // Répertoire home
        public readonly ?string $profilePath = null,   // Chemin profil
        public readonly ?string $connectionScriptPath = null, // Script de connexion
        public readonly bool $isActive = true,         // Statut du compte (true = actif)
        public readonly ?string $pwdLastSet = null,    // Dernière modif mot de passe
        public readonly ?string $lastLogon = null,     // Dernière connexion
        public readonly ?string $createdAt = null,     // Date de création
        public readonly ?string $updatedAt = null,     // Date de modification
        public readonly array $memberOf = [],          // Groupes d'appartenance
        public readonly array $groups = [],           // Groupes simplifiés
        public readonly array $rights = [],           // Droits utilisateur
        public readonly ?string $dn = null,           // Distinguished Name
        public readonly string $role = 'autre',       // Rôle: eleves, profs, administratifs, autre
        public readonly bool $isActiveUser = true,    // Utilisateur actif (pas dans corbeille)
        public readonly bool $isTrash = false,        // Utilisateur dans la corbeille

        // ============================================
        // IDENTIFIANTS EXTERNES (données techniques)
        // Ces champs proviennent de systèmes externes (ENT, AAF, Siecle, etc.)
        // et peuvent ne pas être présents pour tous les utilisateurs
        // ============================================
        public readonly ?string $objectGuid = null,   // GUID Active Directory (format brut)
        public readonly ?string $objectGuidDisplay = null, // GUID AD formaté pour affichage
        public readonly ?string $idEnt = null,        // Identifiant ENT
        public readonly ?string $idAaf = null,        // Identifiant AAF (Annuaire Académique Fédérateur)
        public readonly ?string $idSiecle = null,     // Identifiant Siecle
        public readonly ?string $idGpei = null,       // Identifiant GPEI
        public readonly ?string $idNc = null,         // Identifiant NC
    ) {
    }

    // ============================================
    // MÉTHODES DE VÉRIFICATION DU STATUT COMPTE
    // ============================================

    /**
     * Vérifie si le compte est actif (useraccountcontrol = 512)
     */
    public function isActiveAccount(): bool
    {
        return $this->isActive;
    }

    /**
     * Vérifie si le compte est désactivé
     */
    public function isDisabled(): bool
    {
        return !$this->isActiveAccount();
    }

    /**
     * Vérifie si l'utilisateur doit changer son mot de passe
     * pwdlastset = 0 signifie que l'utilisateur doit changer son mot de passe
     */
    public function mustChangePassword(): bool
    {
        return $this->pwdLastSet === '0' || $this->pwdLastSet === 0;
    }

    // ============================================
    // MÉTHODES DE VÉRIFICATION DU RÔLE
    // ============================================

    /**
     * Vérifie si l'utilisateur est administrateur
     * Un administrateur est membre d'un groupe contenant "administrateurs" ou "Domain Admins"
     */
    public function isAdmin(): bool
    {
        foreach ($this->memberOf as $group) {
            $groupLower = strtolower($group);
            if (
                str_contains($groupLower, 'cn=administrateurs') ||
                str_contains($groupLower, 'cn=domain admins') ||
                str_contains($groupLower, 'administrators')
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Vérifie si l'utilisateur est un élève
     * Un élève est membre d'un groupe contenant "Eleves" ou se trouve dans l'OU "Eleves"
     */
    public function isEleve(): bool
    {
        // Vérifier les groupes
        foreach ($this->memberOf as $group) {
            if (preg_match('/Eleves/i', $group)) {
                return true;
            }
        }
        // Vérifier le DN
        if ($this->dn && preg_match('/OU=Eleves/i', $this->dn)) {
            return true;
        }
        return false;
    }

    /**
     * Vérifie si l'utilisateur est un professeur
     * Un professeur est membre d'un groupe contenant "Profs" ou se trouve dans l'OU "Profs"
     */
    public function isProf(): bool
    {
        // Vérifier les groupes
        foreach ($this->memberOf as $group) {
            if (preg_match('/Profs/i', $group)) {
                return true;
            }
        }
        // Vérifier le DN
        if ($this->dn && preg_match('/OU=Profs/i', $this->dn)) {
            return true;
        }
        return false;
    }

    /**
     * Vérifie si l'utilisateur est administratif
     * Un utilisateur administratif est membre d'un groupe contenant "Administratifs"
     */
    public function isAdministratif(): bool
    {
        // Vérifier les groupes
        foreach ($this->memberOf as $group) {
            if (preg_match('/Administratifs/i', $group)) {
                return true;
            }
        }
        // Vérifier le DN
        if ($this->dn && preg_match('/OU=Administratifs/i', $this->dn)) {
            return true;
        }
        return false;
    }

    /**
     * Détermine le rôle de l'utilisateur depuis ses groupes
     * @return string 'eleves', 'profs', 'administratifs' ou 'autre'
     */
    public function determineRole(): string
    {
        if ($this->isEleve()) {
            return 'eleves';
        }
        if ($this->isProf()) {
            return 'profs';
        }
        if ($this->isAdministratif()) {
            return 'administratifs';
        }
        return 'autre';
    }

    /**
     * Vérifie si l'utilisateur est externe (rattaché à un autre établissement)
     */
    public function isExternal(): bool
    {
        if (empty(trim($this->etabCode ?? '')) || $this->etabCode === '0') {
            return false;
        }

        $currentCode = \App\Facades\SEConfig::getCurrentEstablishmentCode();

        if (empty($currentCode) || $currentCode === '0') {
            return false;
        }

        return strtolower($this->etabCode) !== strtolower($currentCode);
    }

    /**
     * Vérifie si l'utilisateur est dans la corbeille
     */
    public function isInTrash(): bool
    {
        return $this->isTrash;
    }

    /**
     * Vérifie si l'utilisateur est actif (pas dans la corbeille)
     */
    public function isActiveUser(): bool
    {
        return $this->isActiveUser;
    }

    /**
     * Récupère le login
     */
    public function getLogin(): string
    {
        return $this->login;
    }

    /**
     * Récupère le nom d'affichage (fullname ou cn)
     */
    public function getFullName(): string
    {
        return $this->fullname ?? "non défini";
    }

    /**
     * Vérifie si l'utilisateur appartient à un groupe
     */
    public function isMemberOf(string $group): bool
    {
        return in_array($group, $this->groups) ||
            array_filter($this->memberOf, fn($dn) => str_contains($dn, "CN=$group,"));
    }

    /**
     * Vérifie si l'utilisateur a un droit spécifique
     */
    public function hasRight(string $right): bool
    {
        return in_array($right, $this->rights);
    }

    /**
     * Extrait les noms de groupes simples depuis les DN (exclut les droits)
     * 
     * @param array $memberOf Liste des DN memberOf
     * @param string $rightsBranch Branche DN des droits (ex: "OU=rights,DC=domain,DC=local")
     */
    private static function extractGroups(array $memberOf, string $rightsBranch = ''): array
    {
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
        return $groups;
    }

    /**
     * Extrait les droits depuis les groupes de droits
     * Les droits sont les memberOf dont le DN contient la branche rights
     * 
     * @param array $memberOf Liste des DN memberOf
     * @param string $rightsBranch Branche DN des droits (ex: "OU=rights,DC=domain,DC=local")
     */
    private static function extractRights(array $memberOf, string $rightsBranch = ''): array
    {
        $rights = [];
        foreach ($memberOf as $dn) {
            // Utiliser la branche rights exacte si fournie, sinon fallback sur "rights"
            $isRight = !empty($rightsBranch)
                ? preg_match("/" . preg_quote($rightsBranch, '/') . "/i", $dn)
                : str_contains(strtolower($dn), 'ou=rights');

            if ($isRight && preg_match('/^CN=([^,]+),/', $dn, $matches)) {
                $rights[] = $matches[1];
            }
        }
        return $rights;
    }

    /**
     * Crée une instance depuis les données brutes d'Active Directory
     * 
     * @param array $adData Données brutes AD
     * @param string|null $rightsBranch Branche DN des droits (ex: "OU=rights,DC=domain,DC=local")
     *                                   Si null, utilise un fallback sur "OU=rights"
     */
    public static function fromAdData(array $adData, ?string $rightsBranch = null): self
    {
        $memberOf = $adData['memberof'] ?? [];
        $rightsBranchValue = $rightsBranch ?? '';

        return new self(
            login: $adData['cn'] ?? '',
            fullname: $adData['fullname'] ?? $adData['displayname'] ?? $adData['cn'] ?? '',
            firstname: $adData['givenname'] ?? null,
            lastname: $adData['sn'] ?? null,
            email: $adData['mail'] ?? null,
            phone: $adData['telephonenumber'] ?? null,
            description: $adData['description'] ?? null,
            etabCode: $adData['etab'] ?? null,
            etabName: $adData['etabName'] ?? null,
            homeDirectory: $adData['homedirectory'] ?? null,
            profilePath: $adData['profilepath'] ?? null,
            connectionScriptPath: $adData['scriptpath'] ?? null,
            isActive: ((int) ($adData['useraccountcontrol'] ?? 512)) === 512,
            pwdLastSet: $adData['pwdlastset'] ?? null,
            lastLogon: $adData['lastlogon'] ?? null,
            createdAt: $adData['whencreated'] ?? null,
            updatedAt: $adData['whenchanged'] ?? null,
            memberOf: $memberOf,
            groups: self::extractGroups($memberOf, $rightsBranchValue),
            rights: self::extractRights($memberOf, $rightsBranchValue),
            dn: $adData['dn'] ?? null,
            role: $adData['role'] ?? 'autre',
            isActiveUser: $adData['isActive'] ?? true,
            isTrash: $adData['isTrash'] ?? false,
            // Identifiants externes
            objectGuid: $adData['objectguid'] ?? null,
            objectGuidDisplay: $adData['objectguid_display'] ?? null,
            idEnt: $adData['id'] ?? null,
            idAaf: $adData['externalId'] ?? ($adData['externalid'] ?? null),
            idSiecle: $adData['Id Siecle'] ?? ($adData['idsiecle'] ?? null),
            idGpei: $adData['Id GPEI'] ?? ($adData['idgpei'] ?? null),
            idNc: $adData['Id NC'] ?? ($adData['idnc'] ?? null),
        );
    }

    /**
     * Convertit l'objet en tableau (pour Livewire)
     */
    public function toArray(): array
    {
        return [
            'login' => $this->login,
            'cn' => $this->login, // Alias pour compatibilité
            'fullname' => $this->fullname,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'email' => $this->email,
            'phone' => $this->phone,
            'description' => $this->description,
            'etabCode' => $this->etabCode,
            'etabName' => $this->etabName,
            'homeDirectory' => $this->homeDirectory,
            'profilePath' => $this->profilePath,
            'connectionScriptPath' => $this->connectionScriptPath,
            'isActive' => $this->isActive,
            'pwdLastSet' => $this->pwdLastSet,
            'lastLogon' => $this->lastLogon,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'memberOf' => $this->memberOf,
            'groups' => $this->groups,
            'rights' => $this->rights,
            'dn' => $this->dn,
            'role' => $this->role,
            'isActiveUser' => $this->isActiveUser,
            'isTrash' => $this->isTrash,

            // Identifiants externes
            'objectGuid' => $this->objectGuid,
            'objectGuidDisplay' => $this->objectGuidDisplay,
            'idEnt' => $this->idEnt,
            'idAaf' => $this->idAaf,
            'idSiecle' => $this->idSiecle,
            'idGpei' => $this->idGpei,
            'idNc' => $this->idNc,
        ];
    }

    /**
     * Sérialise l'objet pour Livewire (interface Wireable)
     */
    public function toLivewire(): array
    {
        return $this->toArray();
    }

    /**
     * Désérialise depuis Livewire (interface Wireable)
     */
    public static function fromLivewire($value): static
    {
        return new static(
            login: $value['login'] ?? '',
            fullname: $value['fullname'] ?? '',
            firstname: $value['firstname'] ?? null,
            lastname: $value['lastname'] ?? null,
            email: $value['email'] ?? null,
            phone: $value['phone'] ?? null,
            description: $value['description'] ?? null,
            etabCode: $value['etabCode'] ?? null,
            etabName: $value['etabName'] ?? null,
            homeDirectory: $value['homeDirectory'] ?? null,
            profilePath: $value['profilePath'] ?? null,
            connectionScriptPath: $value['connectionScriptPath'] ?? null,
            isActive: $value['isActive'] ?? true,
            pwdLastSet: $value['pwdLastSet'] ?? null,
            lastLogon: $value['lastLogon'] ?? null,
            createdAt: $value['createdAt'] ?? null,
            updatedAt: $value['updatedAt'] ?? null,
            memberOf: $value['memberOf'] ?? [],
            groups: $value['groups'] ?? [],
            rights: $value['rights'] ?? [],
            dn: $value['dn'] ?? null,
            role: $value['role'] ?? 'autre',
            isActiveUser: $value['isActiveUser'] ?? true,
            isTrash: $value['isTrash'] ?? false,
            // Identifiants externes
            objectGuid: $value['objectGuid'] ?? null,
            objectGuidDisplay: $value['objectGuidDisplay'] ?? null,
            idEnt: $value['idEnt'] ?? null,
            idAaf: $value['idAaf'] ?? null,
            idSiecle: $value['idSiecle'] ?? null,
            idGpei: $value['idGpei'] ?? null,
            idNc: $value['idNc'] ?? null,
        );
    }

    /**
     * Convertit vers le modèle LDAPRecord LdapUser
     * Utile pour les opérations d'écriture dans LDAP
     */
    public function toLdapModel(): \App\LdapModels\LdapUser
    {
        $ldapUser = new \App\LdapModels\LdapUser();

        // Mapper les propriétés du DTO vers les attributs LDAP
        $ldapUser->cn = $this->login;
        $ldapUser->displayname = $this->fullname;
        $ldapUser->givenname = $this->firstname;
        $ldapUser->sn = $this->lastname;
        $ldapUser->mail = $this->email;
        $ldapUser->telephonenumber = $this->phone;
        $ldapUser->description = $this->description;
        $ldapUser->homedirectory = $this->homeDirectory;
        $ldapUser->profilepath = $this->profilePath;
        $ldapUser->scriptpath = $this->connectionScriptPath;
        $ldapUser->useraccountcontrol = $this->isActive ? 512 : 514; // 512 = actif, 514 = désactivé
        $ldapUser->pwdlastset = $this->pwdLastSet;
        $ldapUser->lastlogon = $this->lastLogon;

        return $ldapUser;
    }
}
