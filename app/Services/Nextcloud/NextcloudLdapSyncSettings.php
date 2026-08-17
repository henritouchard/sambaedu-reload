<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

use App\Config\LdapConfig;

/**
 * LA CONFIGURATION DE SYNCHRO D'ANNUAIRE DE L'INSTANCE, DÉRIVÉE DE LA NÔTRE.
 *
 * ---------------------------------------------------------------------------
 * **POURQUOI CETTE CLASSE EXISTE.** Les comptes Nextcloud du STOCK existant ne
 * peuvent pas venir de SE5 : {@see NextcloudUserProvisioner} n'invente jamais de
 * mot de passe, et le mot de passe n'est en main qu'à la création d'un compte SE5
 * ou à son changement. Pour tous les autres, un compte absent est *rapporté*,
 * jamais fabriqué — c'est écrit, c'est voulu, et ça laisse un seul chemin : que
 * l'instance lise l'annuaire elle-même.
 *
 * Ce chemin se réglait à la main, écran par écran, instance par instance. Il se
 * règle désormais par une commande, parce qu'une procédure à rejouer n'est pas un
 * mécanisme : elle diverge dès la deuxième instance.
 * ---------------------------------------------------------------------------
 *
 * **AUCUNE VALEUR N'EST INVENTÉE ICI.** Tout vient de `sambaedu.conf` via
 * {@see LdapConfig} — l'URL, le port, le DN de base, le compte de lecture, le RDN
 * des utilisateurs. Cette classe ne fait que traduire ce vocabulaire dans celui
 * de `user_ldap`, et cette traduction est PURE : aucun appel réseau, aucune
 * lecture d'état, donc entièrement observable par un test sur l'hôte.
 *
 * **CE QU'ELLE NE CONFIGURE PAS, ET C'EST LE POINT LE PLUS IMPORTANT : LES
 * GROUPES.** Un groupe visible dans Nextcloud est un groupe sur lequel n'importe
 * quel utilisateur peut accrocher un partage Nextcloud — donc un SECOND plan de
 * permissions sur une zone que Samba arbitre déjà. C'est exactement la ligne que
 * le garde-fou d'architecture de l'Epic 61 tient
 * ({@see \Tests\Architecture\NextcloudNamespaceTest}), et l'authentification n'en
 * a aucun besoin : le filtre de connexion suffit.
 *
 * Les clés de groupe sont donc écrites **VIDES, et non pas omises**. Une carte de
 * valeurs asymétrique — « on n'y touche pas quand c'est éteint » — laisserait une
 * instance déjà configurée à la main contredire en silence ce que la commande
 * annonce. Éteindre doit écrire une vraie valeur.
 */
final readonly class NextcloudLdapSyncSettings
{
    /**
     * L'IDENTIFIANT INTERNE NEXTCLOUD DOIT ÊTRE LE LOGIN SE5, et cette clé est
     * ce qui le garantit.
     *
     * Sans elle, `user_ldap` choisit l'identifiant interne lui-même (souvent un
     * UUID d'annuaire). Le crochet de création au fil de l'eau, lui, émet
     * `userid = login` ({@see NextcloudAdminClient::createUser()}) : deux comptes
     * apparaîtraient pour une seule personne, l'un local et l'autre synchronisé.
     * Le piège est nommé de longue date dans le docblock de
     * {@see NextcloudUserProvisioner} ; cette clé est ce qui le ferme.
     */
    public const USERNAME_ATTRIBUTE = 'sAMAccountName';

    /**
     * LES CLÉS QUE L'INSTANCE RÉÉCRIT EN MINUSCULES, et qu'on ne peut donc pas
     * comparer au caractère près.
     *
     * **Mesuré le 2026-08-17** : `sAMAccountName` posé revient `samaccountname`.
     * Sans cette liste, la comparaison ne converge JAMAIS — la commande annoncerait
     * une divergence à chaque exécution, sur une instance qu'elle vient elle-même
     * de régler, et « déjà conforme » ne se produirait pas une seule fois.
     *
     * On garde la casse canonique de l'annuaire à l'écriture plutôt que d'écrire en
     * minuscules pour arranger la comparaison : les noms d'attributs LDAP sont
     * insensibles à la casse, c'est donc bien la comparaison qui doit l'être, pas
     * l'écriture qui doit se déformer.
     *
     * @var list<string>
     */
    public const CASE_INSENSITIVE_KEYS = ['ldapExpertUsernameAttr'];

    /**
     * Les clés de GROUPE, écrites vides. Nommées à part pour qu'un test puisse
     * épingler leur mise à zéro : c'est une garantie, pas un oubli.
     *
     * @var list<string>
     */
    public const GROUP_KEYS = [
        'ldapBaseGroups',
        'ldapGroupFilter',
        'ldapGroupFilterObjectclass',
        'ldapGroupFilterGroups',
        'ldapGroupMemberAssocAttr',
    ];

    private function __construct(
        /** @var array<string, string> La carte de clés `user_ldap`, prête à poser. */
        public array $keys,
        /** Le secret, tenu à part : il ne doit apparaître dans aucun affichage. */
        private string $agentPassword,
    ) {
    }

    /**
     * Ce qui MANQUE dans notre propre configuration pour pouvoir régler
     * l'instance, en français, ou `[]` si tout est là.
     *
     * Appelé AVANT toute écriture : poser une configuration incomplète activerait
     * une synchro qui ne peut pas se lier, et l'instance dirait « configuration
     * active » sur une liaison morte.
     *
     * @return list<string>
     */
    public static function missingFrom(LdapConfig $ldap): array
    {
        $missing = [];

        if (trim($ldap->url) === '') {
            $missing[] = 'l\'URL du serveur LDAP (`ldap_url`)';
        }
        if (trim($ldap->baseDn) === '') {
            $missing[] = 'le DN de base de l\'annuaire (`ldap_base_dn`)';
        }
        if (trim($ldap->adminName) === '') {
            $missing[] = 'le compte de lecture de l\'annuaire (`ldap_admin_name`)';
        }
        if ($ldap->adminPassword === '') {
            $missing[] = 'le mot de passe du compte de lecture (`ldap_admin_passwd`)';
        }
        if (trim($ldap->domain) === '') {
            $missing[] = 'le domaine Active Directory (`domain`)';
        }

        return $missing;
    }

    /**
     * @param  bool  $trustSelfSignedCertificate  Accepter un certificat que rien ne
     *   peut vérifier. **Jamais un défaut** : le chemin legacy désactivait la
     *   vérification TLS en dur dans le code, ce qui rendait la faiblesse
     *   invisible à l'exploitant. Ici elle est un geste, nommé et affiché.
     */
    public static function for(LdapConfig $ldap, bool $trustSelfSignedCertificate = false): self
    {
        $baseDn = trim($ldap->baseDn);

        $keys = [
            // --- La liaison ---------------------------------------------------
            'ldapHost' => trim($ldap->url),
            'ldapPort' => (string) $ldap->port,

            // Le compte de lecture est désigné par son UPN (`compte@domaine`) et
            // non par son DN : l'AD accepte les deux, et l'UPN se dérive de la
            // configuration sans avoir à connaître le conteneur du compte —
            // lequel diffère d'un annuaire à l'autre.
            'ldapAgentName' => trim($ldap->adminName).'@'.trim($ldap->domain),

            // --- Où chercher les personnes ------------------------------------
            'ldapBase' => $baseDn,

            // LE PÉRIMÈTRE EST RESTREINT AU CONTENEUR DES UTILISATEURS SE5, et ce
            // n'est pas une optimisation. Sur la racine, la synchro embarque les
            // comptes de service et le compte d'administration de l'annuaire ;
            // celui-ci est l'HOMONYME du compte admin local de l'instance, et
            // `user_ldap` résout l'homonymie en suffixant l'identifiant interne
            // (mesuré : `admin` devenu `admin_2930`). Un compte fantôme de plus,
            // pour aucun usage.
            //
            // ⚠️ **CE N'EST PRÉVENTIF, PAS CURATIF.** Mesuré le 2026-08-17 :
            // resserrer le périmètre ne DÉ-RATTACHE pas les comptes qu'une
            // configuration plus large avait déjà rattachés — l'instance tient sa
            // propre table de correspondance, et ces comptes y demeurent en
            // « reliquats ». Sur une instance déjà synchronisée à la racine, il
            // faut les traiter côté instance ; cette carte n'y peut rien.
            'ldapBaseUsers' => self::usersBase($ldap),

            'ldapUserFilterObjectclass' => 'user',
            'ldapUserFilter' => '(&(objectclass=user)(objectcategory=person))',

            // --- Comment se connecte-t-on -------------------------------------
            // Le login saisi est comparé au `sAMAccountName`, parce que dans ce
            // produit le login SE5 EST le `sAMAccountName` : une seule identité,
            // du poste au cloud.
            'ldapLoginFilter' => '(&(objectclass=user)(sAMAccountName=%uid))',
            'ldapLoginFilterUsername' => '1',
            'ldapLoginFilterEmail' => '0',
            'ldapExpertUsernameAttr' => self::USERNAME_ATTRIBUTE,

            // --- Ce qu'on affiche ---------------------------------------------
            'ldapUserDisplayName' => 'displayName',
            'ldapEmailAttribute' => 'mail',

            // --- Le certificat -------------------------------------------------
            'ldapTLS' => '0',
            'turnOffCertCheck' => $trustSelfSignedCertificate ? '1' : '0',

            // --- Et c'est actif ------------------------------------------------
            'ldapConfigurationActive' => '1',
        ];

        // Les groupes, éteints EXPLICITEMENT (voir le docblock de la classe).
        foreach (self::GROUP_KEYS as $groupKey) {
            $keys[$groupKey] = '';
        }

        return new self($keys, $ldap->adminPassword);
    }

    /**
     * Le conteneur des utilisateurs : le RDN des personnes préfixé au DN de base.
     *
     * Un RDN vide rend la racine — l'annuaire n'a alors pas de conteneur dédié,
     * et restreindre sur rien vaut mieux que de restreindre sur une branche qui
     * n'existe pas.
     */
    public static function usersBase(LdapConfig $ldap): string
    {
        $baseDn = trim($ldap->baseDn);
        $peopleRdn = trim($ldap->peopleRdn, " \t\n\r\0\x0B,");

        return $peopleRdn === '' ? $baseDn : $peopleRdn.','.$baseDn;
    }

    /**
     * La carte COMPLÈTE, secret inclus, pour l'écriture — et elle seule.
     *
     * Le secret n'est pas dans {@see $keys} : ce tableau-là est affiché par la
     * commande, journalisé, comparé. Les séparer est ce qui rend impossible de
     * l'afficher par inadvertance.
     *
     * @return array<string, string>
     */
    public function keysForWriting(): array
    {
        return $this->keys + ['ldapAgentPassword' => $this->agentPassword];
    }

    /**
     * La configuration relue correspond-elle à celle qu'on poserait ?
     *
     * **Le mot de passe est HORS du critère** : l'instance le rend masqué
     * (`***`, mesuré), il est donc inobservable — le prétendre comparé serait un
     * mensonge de plus dans un tableau de comparaison.
     *
     * @param  array<string, mixed>  $remote
     */
    public function matches(array $remote): bool
    {
        return $this->divergences($remote) === [];
    }

    /**
     * Cette clé-là est-elle conforme ? Casse comprise, sauf pour celles que
     * l'instance normalise ({@see CASE_INSENSITIVE_KEYS}).
     */
    private static function keyMatches(string $key, string $current, string $wanted): bool
    {
        return in_array($key, self::CASE_INSENSITIVE_KEYS, true)
            ? strcasecmp($current, $wanted) === 0
            : $current === $wanted;
    }

    /**
     * Les écarts entre le relu et le voulu, pour un tableau de comparaison.
     *
     * @param  array<string, mixed>  $remote
     * @return list<array{cle: string, actuel: string, voulu: string}>
     */
    public function divergences(array $remote): array
    {
        $divergences = [];

        foreach ($this->keys as $key => $wanted) {
            $current = (string) ($remote[$key] ?? '');

            if (! self::keyMatches($key, $current, $wanted)) {
                $divergences[] = ['cle' => $key, 'actuel' => $current, 'voulu' => $wanted];
            }
        }

        return $divergences;
    }
}
