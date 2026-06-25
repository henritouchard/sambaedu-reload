<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\LdapConfig;
use Illuminate\Support\Facades\App;

/**
 * Helper centralisé pour la construction des DN LDAP
 *
 * C'est la classe chargée de construire les DN complets.
 * Elle utilise les RDN bruts depuis LdapConfig et les assemble.
 *
 * Utilisation dans les modèles :
 * - `LdapDnHelper::peopleDn()` pour obtenir le DN des personnes
 * - `LdapDnHelper::groupsDn()` pour obtenir le DN des groupes
 * - etc.
 *
 * ```
 */
class LdapDnHelper
{
    private SambaEduConfig $config;

    /** @var string Préfixe d'établissement (ex: "OU=0950000x,") ou chaîne vide */
    private string $establishmentPrefix;

    public function __construct(SambaEduConfig $config)
    {
        $this->config = $config;

        // Initialiser le préfixe d'établissement une seule fois au montage
        $etabCode = $this->config->getCurrentEstablishmentCode();

        // Si pas d'établissement ou code invalide (pas format UAI), pas de préfixe
        if (empty($etabCode) || $etabCode === '0' || !preg_match('/^[0-9]{7}[a-zA-Z]$/', $etabCode)) {
            $this->establishmentPrefix = '';
        } else {
            $this->establishmentPrefix = 'OU=' . $etabCode . ',';
        }
    }

    /**
     * Retourne le préfixe d'établissement
     * Utilisé pour préfixer les DN comme le fait le legacy
     */
    private function getEstablishmentPrefix(): string
    {
        return $this->establishmentPrefix;
    }

    /**
     * Retourne la configuration LDAP
     */
    private function ldap(): LdapConfig
    {
        return $this->config->ldap();
    }

    /**
     * Retourne le DN de base
     */
    public function base(): string
    {
        return $this->ldap()->baseDn;
    }

    /**
     * Retourne le DN de base des personnes (utilisateurs)
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement (domaine entier)
     *                     Si false (défaut), inclut le préfixe OU=<etab> pour limiter à l'établissement
     *                     Exemple: false => "OU=0950000x,ou=Utilisateurs,dc=..."
     *                              true  => "ou=Utilisateurs,dc=..."
     */
    public function people(bool $global = false): string
    {
        $prefix = $global ? '' : $this->getEstablishmentPrefix();
        return $prefix . $this->ldap()->peopleRdn . ',' . $this->ldap()->baseDn;
    }

    /**
     * Retourne le DN de base des groupes
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement (domaine entier)
     *                     Si false (défaut), inclut le préfixe OU=<etab> pour limiter à l'établissement
     *                     Utilisé en mode global pour les groupes principaux (Profs, Eleves, etc.)
     */
    public function groups(bool $global = false): string
    {
        $prefix = $global ? '' : $this->getEstablishmentPrefix();
        return $prefix . $this->ldap()->groupsRdn . ',' . $this->ldap()->baseDn;
    }

    /**
     * Retourne le DN de base des ordinateurs
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement (domaine entier)
     *                     Si false (défaut), inclut le préfixe OU=<etab> pour limiter à l'établissement
     */
    public function computers(bool $global = false): string
    {
        $prefix = $global ? '' : $this->getEstablishmentPrefix();
        return $prefix . $this->ldap()->computersRdn . ',' . $this->ldap()->baseDn;
    }

    /**
     * Retourne le DN de base des parcs
     * 
     * Les parcs vivent sous OU=Parcs (conteneur racine à part, PAS sous ou=Groups).
     * En fédération, l'établissement est imbriqué SOUS ou=Parcs, comme pour les
     * ordinateurs : OU=<etab>,ou=Parcs,dc=... (le préfixe précède le RDN, cf. computers()).
     *
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement (domaine entier)
     *                     Si false (défaut), inclut le préfixe OU=<etab> pour limiter à l'établissement
     */
    public function parcs(bool $global = false): string
    {
        $prefix = $global ? '' : $this->getEstablishmentPrefix();
        return $prefix . $this->ldap()->parcsRdn . ',' . $this->ldap()->baseDn;
    }

    /**
     * Retourne le DN de base des classes
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement (domaine entier)
     *                     Si false (défaut), inclut le préfixe OU=<etab> pour limiter à l'établissement
     */
    public function classes(bool $global = false): string
    {
        $prefix = $global ? '' : $this->getEstablishmentPrefix();
        return $this->ldap()->classesRdn . ',' . $prefix . $this->ldap()->groupsRdn . ',' . $this->ldap()->baseDn;
    }

    /**
     * Retourne le DN de base des équipes
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement (domaine entier)
     *                     Si false (défaut), inclut le préfixe OU=<etab> pour limiter à l'établissement
     */
    public function equipes(bool $global = false): string
    {
        $prefix = $global ? '' : $this->getEstablishmentPrefix();
        return $this->ldap()->equipesRdn . ',' . $prefix . $this->ldap()->groupsRdn . ',' . $this->ldap()->baseDn;
    }

    /**
     * Retourne le DN de base des matières
     * Note: Les matières sont directement sous la base DN, pas dans Groups
     */
    public function matieres(): string
    {
        return $this->ldap()->matieresRdn . ',' . $this->ldap()->baseDn;
    }

    /**
     * Retourne le DN de base des cours
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement (domaine entier)
     *                     Si false (défaut), inclut le préfixe OU=<etab> pour limiter à l'établissement
     */
    public function cours(bool $global = false): string
    {
        $prefix = $global ? '' : $this->getEstablishmentPrefix();
        return $this->ldap()->coursRdn . ',' . $prefix . $this->ldap()->groupsRdn . ',' . $this->ldap()->baseDn;
    }

    /**
     * Retourne le DN de base des projets
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement (domaine entier)
     *                     Si false (défaut), inclut le préfixe OU=<etab> pour limiter à l'établissement
     */
    public function projets(bool $global = false): string
    {
        $prefix = $global ? '' : $this->getEstablishmentPrefix();
        return $this->ldap()->projetsRdn . ',' . $prefix . $this->ldap()->groupsRdn . ',' . $this->ldap()->baseDn;
    }

    /**
     * Retourne le DN de base des autres groupes
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement (domaine entier)
     *                     Si false (défaut), inclut le préfixe OU=<etab> pour limiter à l'établissement
     */
    public function otherGroups(bool $global = false): string
    {
        $prefix = $global ? '' : $this->getEstablishmentPrefix();
        return $this->ldap()->otherGroupsRdn . ',' . $prefix . $this->ldap()->groupsRdn . ',' . $this->ldap()->baseDn;
    }

    /**
     * Retourne le DN de base des délégations
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement (domaine entier)
     *                     Si false (défaut), inclut le préfixe OU=<etab> pour limiter à l'établissement
     */
    public function delegations(bool $global = false): string
    {
        $prefix = $global ? '' : $this->getEstablishmentPrefix();
        return $this->ldap()->delegationsRdn . ',' . $prefix . $this->ldap()->groupsRdn . ',' . $this->ldap()->baseDn;
    }

    /**
     * Retourne le DN de base des équipements/matériels
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement (domaine entier)
     *                     Si false (défaut), inclut le préfixe OU=<etab> pour limiter à l'établissement
     */
    public function equipements(bool $global = false): string
    {
        $prefix = $global ? '' : $this->getEstablishmentPrefix();
        return $prefix . $this->ldap()->equipementsRdn . ',' . $this->ldap()->baseDn;
    }

    /**
     * Retourne le DN de base des droits
     */
    public function rights(): string
    {
        return $this->ldap()->rightsRdn . ',' . $this->ldap()->baseDn;
    }

    /**
     * Retourne le DN de base des établissements
     */
    public function etablissements(): string
    {
        return $this->ldap()->etablissementsRdn . ',' . $this->ldap()->baseDn;
    }

    /**
     * Retourne le DN de la corbeille
     */
    public function trash(): string
    {
        return $this->ldap()->trashRdn . ',' . $this->ldap()->baseDn;
    }

    /**
     * Retourne le DN des administrateurs
     */
    public function admin(): string
    {
        return $this->ldap()->adminRdn . ',' . $this->ldap()->baseDn;
    }

    // === Méthodes statiques pour utilisation directe dans les modèles ===

    /**
     * Accès statique au DN de base
     */
    public static function baseDn(): string
    {
        return App::make(self::class)->base();
    }

    /**
     * Accès statique au DN des personnes
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement
     */
    public static function peopleDn(bool $global = false): string
    {
        return App::make(self::class)->people($global);
    }

    /**
     * Accès statique au DN des groupes
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement
     */
    public static function groupsDn(bool $global = false): string
    {
        return App::make(self::class)->groups($global);
    }

    /**
     * Accès statique au DN des ordinateurs
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement
     */
    public static function computersDn(bool $global = false): string
    {
        return App::make(self::class)->computers($global);
    }

    /**
     * Accès statique au DN des parcs
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement
     */
    public static function parcsDn(bool $global = false): string
    {
        return App::make(self::class)->parcs($global);
    }

    /**
     * Accès statique au DN des classes
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement
     */
    public static function classesDn(bool $global = false): string
    {
        return App::make(self::class)->classes($global);
    }

    /**
     * Accès statique au DN des équipes
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement
     */
    public static function equipesDn(bool $global = false): string
    {
        return App::make(self::class)->equipes($global);
    }

    /**
     * Accès statique au DN des matières
     */
    public static function matieresDn(): string
    {
        return App::make(self::class)->matieres();
    }

    /**
     * Accès statique au DN des cours
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement
     */
    public static function coursDn(bool $global = false): string
    {
        return App::make(self::class)->cours($global);
    }

    /**
     * Accès statique au DN des projets
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement
     */
    public static function projetsDn(bool $global = false): string
    {
        return App::make(self::class)->projets($global);
    }

    /**
     * Accès statique au DN des autres groupes
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement
     */
    public static function otherGroupsDn(bool $global = false): string
    {
        return App::make(self::class)->otherGroups($global);
    }

    /**
     * Accès statique au DN des délégations
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement
     */
    public static function delegationsDn(bool $global = false): string
    {
        return App::make(self::class)->delegations($global);
    }

    /**
     * Accès statique au DN des équipements
     * 
     * @param bool $global Si true, retourne le DN sans préfixe d'établissement
     */
    public static function equipementsDn(bool $global = false): string
    {
        return App::make(self::class)->equipements($global);
    }

    /**
     * Accès statique au DN des droits
     */
    public static function rightsDn(): string
    {
        return App::make(self::class)->rights();
    }

    /**
     * Accès statique au DN des établissements
     */
    public static function etablissementsDn(): string
    {
        return App::make(self::class)->etablissements();
    }

    /**
     * Accès statique au DN de la corbeille
     */
    public static function trashDn(): string
    {
        return App::make(self::class)->trash();
    }

    /**
     * Accès statique au DN des administrateurs
     */
    public static function adminDn(): string
    {
        return App::make(self::class)->admin();
    }
}
