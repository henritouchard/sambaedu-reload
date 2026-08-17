<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Nextcloud;

use App\Config\LdapConfig;
use App\Services\Nextcloud\NextcloudLdapSyncSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La traduction de NOTRE configuration d'annuaire dans le vocabulaire de
 * l'instance — pure, donc entièrement observable sans réseau ni base.
 */
class NextcloudLdapSyncSettingsTest extends TestCase
{
    private static function ldap(
        string $url = 'ldaps://localdev.fr',
        int $port = 636,
        string $baseDn = 'dc=localdev,dc=fr',
        string $adminName = 'Administrator',
        string $adminPassword = 'secret-du-compte-de-lecture',
        string $domain = 'localdev.fr',
        string $peopleRdn = 'ou=Utilisateurs',
    ): LdapConfig {
        return new LdapConfig(
            url: $url,
            port: $port,
            baseDn: $baseDn,
            adminName: $adminName,
            adminPassword: $adminPassword,
            domain: $domain,
            sambaDomain: 'localdev',
            peopleRdn: $peopleRdn,
            groupsRdn: 'ou=Groups',
            computersRdn: 'ou=computers',
            parcsRdn: 'ou=Parcs',
            classesRdn: 'ou=classes',
            equipesRdn: 'ou=equipes',
            matieresRdn: 'ou=matieres',
            coursRdn: 'ou=cours',
            projetsRdn: 'ou=projets',
            otherGroupsRdn: 'ou=autres',
            delegationsRdn: 'ou=delegations',
            equipementsRdn: 'ou=Materiels',
            rightsRdn: 'ou=Rights',
            trashRdn: 'ou=Trash',
            etablissementsRdn: 'ou=Etablissements',
            adminRdn: 'ou=Admin',
        );
    }

    /**
     * LA CLÉ QUI FERME LE PIÈGE DU COMPTE FANTÔME. Sans elle, l'instance choisit
     * l'identifiant interne, le crochet de création SE5 envoie le login, et une
     * personne se retrouve avec deux comptes.
     */
    #[Test]
    public function the_internal_identifier_is_pinned_to_the_login_attribute(): void
    {
        $settings = NextcloudLdapSyncSettings::for(self::ldap());

        self::assertSame('sAMAccountName', $settings->keys['ldapExpertUsernameAttr']);
        self::assertSame(
            '(&(objectclass=user)(sAMAccountName=%uid))',
            $settings->keys['ldapLoginFilter'],
            'le login saisi doit être comparé au même attribut que celui qui nomme le compte',
        );
    }

    /**
     * Le périmètre est le conteneur des personnes, pas la racine : sur la racine,
     * le compte d'administration de l'annuaire est l'homonyme du compte admin de
     * l'instance, et l'instance suffixe l'identifiant pour les distinguer.
     */
    #[Test]
    public function the_search_scope_is_the_people_container(): void
    {
        $settings = NextcloudLdapSyncSettings::for(self::ldap());

        self::assertSame('dc=localdev,dc=fr', $settings->keys['ldapBase']);
        self::assertSame('ou=Utilisateurs,dc=localdev,dc=fr', $settings->keys['ldapBaseUsers']);
    }

    /** Sans conteneur dédié, restreindre sur rien vaut mieux que sur une branche absente. */
    #[Test]
    public function an_empty_people_container_falls_back_to_the_base(): void
    {
        $settings = NextcloudLdapSyncSettings::for(self::ldap(peopleRdn: ''));

        self::assertSame('dc=localdev,dc=fr', $settings->keys['ldapBaseUsers']);
    }

    /**
     * La règle de carte SYMÉTRIQUE : « pas de synchro de groupes » doit ÉCRIRE une
     * valeur, sans quoi une instance réglée à la main contredit en silence ce que
     * la commande annonce.
     */
    #[Test]
    public function the_group_settings_are_written_empty_and_not_omitted(): void
    {
        $settings = NextcloudLdapSyncSettings::for(self::ldap());

        foreach (NextcloudLdapSyncSettings::GROUP_KEYS as $key) {
            self::assertArrayHasKey($key, $settings->keys, $key.' doit être écrite');
            self::assertSame('', $settings->keys[$key], $key.' doit être remise à vide');
        }
    }

    /**
     * Accepter un certificat invérifiable est un GESTE, jamais un défaut : le
     * chemin legacy le faisait en dur dans le code, ce qui rendait la faiblesse
     * invisible.
     */
    #[Test]
    public function trusting_an_unverifiable_certificate_is_never_the_default(): void
    {
        self::assertSame('0', NextcloudLdapSyncSettings::for(self::ldap())->keys['turnOffCertCheck']);
        self::assertSame('1', NextcloudLdapSyncSettings::for(self::ldap(), true)->keys['turnOffCertCheck']);
    }

    /** Le compte de lecture est désigné par son UPN, dérivable sans connaître son conteneur. */
    #[Test]
    public function the_reading_account_is_designated_by_its_principal_name(): void
    {
        self::assertSame(
            'Administrator@localdev.fr',
            NextcloudLdapSyncSettings::for(self::ldap())->keys['ldapAgentName'],
        );
    }

    /** LE SECRET N'EST PAS DANS LA CARTE AFFICHABLE — il n'entre que dans l'écriture. */
    #[Test]
    public function the_reading_secret_never_enters_the_displayable_map(): void
    {
        $settings = NextcloudLdapSyncSettings::for(self::ldap());

        self::assertArrayNotHasKey('ldapAgentPassword', $settings->keys);
        self::assertStringNotContainsString(
            'secret-du-compte-de-lecture',
            json_encode($settings->keys, JSON_THROW_ON_ERROR),
        );

        self::assertSame('secret-du-compte-de-lecture', $settings->keysForWriting()['ldapAgentPassword']);
    }

    /**
     * @return iterable<string, array{LdapConfig, string}>
     */
    public static function incompleteConfigurations(): iterable
    {
        yield 'sans URL' => [self::ldap(url: ''), 'ldap_url'];
        yield 'sans DN de base' => [self::ldap(baseDn: ''), 'ldap_base_dn'];
        yield 'sans compte de lecture' => [self::ldap(adminName: ''), 'ldap_admin_name'];
        yield 'sans mot de passe' => [self::ldap(adminPassword: ''), 'ldap_admin_passwd'];
        yield 'sans domaine' => [self::ldap(domain: ''), 'domain'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('incompleteConfigurations')]
    public function an_incomplete_configuration_names_what_is_missing(LdapConfig $ldap, string $expected): void
    {
        $missing = NextcloudLdapSyncSettings::missingFrom($ldap);

        self::assertNotSame([], $missing);
        self::assertStringContainsString($expected, implode(' | ', $missing));
    }

    #[Test]
    public function a_complete_configuration_misses_nothing(): void
    {
        self::assertSame([], NextcloudLdapSyncSettings::missingFrom(self::ldap()));
    }

    /**
     * L'instance rend le mot de passe MASQUÉ. Le prétendre comparé serait un
     * mensonge : la conformité doit donc se prononcer sans lui.
     */
    #[Test]
    public function conformity_is_decided_without_the_masked_secret(): void
    {
        $settings = NextcloudLdapSyncSettings::for(self::ldap());
        $remote = $settings->keys + ['ldapAgentPassword' => '***', 'ldapCacheTTL' => '600'];

        self::assertTrue($settings->matches($remote));
        self::assertSame([], $settings->divergences($remote));
    }

    #[Test]
    public function a_diverging_key_is_reported_with_both_sides(): void
    {
        $settings = NextcloudLdapSyncSettings::for(self::ldap());
        $remote = $settings->keys;
        $remote['ldapBaseUsers'] = 'dc=autre,dc=fr';

        self::assertFalse($settings->matches($remote));
        self::assertSame(
            [['cle' => 'ldapBaseUsers', 'actuel' => 'dc=autre,dc=fr', 'voulu' => 'ou=Utilisateurs,dc=localdev,dc=fr']],
            $settings->divergences($remote),
        );
    }

    /**
     * L'INSTANCE RÉÉCRIT CET ATTRIBUT EN MINUSCULES (mesuré). Sans comparaison
     * insensible à la casse, la commande annoncerait une divergence à chaque
     * exécution sur une instance qu'elle vient elle-même de régler.
     */
    #[Test]
    public function the_attribute_the_instance_lowercases_still_counts_as_conforming(): void
    {
        $settings = NextcloudLdapSyncSettings::for(self::ldap());
        $remote = $settings->keys;
        $remote['ldapExpertUsernameAttr'] = 'samaccountname';

        self::assertTrue($settings->matches($remote));
        self::assertSame([], $settings->divergences($remote));
    }

    /** Mais une AUTRE valeur reste une divergence : la tolérance porte sur la casse seule. */
    #[Test]
    public function the_tolerance_is_on_case_only_not_on_the_value(): void
    {
        $settings = NextcloudLdapSyncSettings::for(self::ldap());
        $remote = $settings->keys;
        $remote['ldapExpertUsernameAttr'] = 'uid';

        self::assertFalse($settings->matches($remote));
    }

    /** La tolérance ne DÉBORDE pas sur les autres clés. */
    #[Test]
    public function no_other_key_tolerates_a_case_difference(): void
    {
        $settings = NextcloudLdapSyncSettings::for(self::ldap());
        $remote = $settings->keys;
        $remote['ldapUserDisplayName'] = 'DISPLAYNAME';

        self::assertFalse($settings->matches($remote));
    }

    /** Une clé ABSENTE de l'instance est une divergence, pas une conformité. */
    #[Test]
    public function a_key_absent_from_the_instance_is_a_divergence(): void
    {
        $settings = NextcloudLdapSyncSettings::for(self::ldap());
        $remote = $settings->keys;
        unset($remote['ldapExpertUsernameAttr']);

        self::assertFalse($settings->matches($remote));
    }
}
