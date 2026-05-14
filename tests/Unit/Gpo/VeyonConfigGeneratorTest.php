<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Config\LdapConfig;
use App\Config\SambaEduConfig;
use App\Dto\AppCustomization\AppContext;
use App\Gpo\Services\VeyonConfigGenerator;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `VeyonConfigGenerator` — Story 16.3b AC5.4.
 */
class VeyonConfigGeneratorTest extends TestCase
{
    private string $pubKeyPath;
    private string $privKeyPath;
    private string $templatePath;

    protected function setUp(): void
    {
        parent::setUp();

        // Génère une paire RSA 2048 dans un tmp local pour les tests OAEP.
        $tmpDir = sys_get_temp_dir() . '/veyon-test-' . uniqid('', true);
        @mkdir($tmpDir, 0o755, true);

        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privPem);
        $pubDetails = openssl_pkey_get_details($res);

        $this->privKeyPath = $tmpDir . '/priv.pem';
        $this->pubKeyPath = $tmpDir . '/pub.pem';
        file_put_contents($this->privKeyPath, $privPem);
        file_put_contents($this->pubKeyPath, $pubDetails['key']);

        $this->templatePath = base_path('tests/Fixtures/Gpo/veyon-template.json');

        config()->set('sambaedu.gpo.veyon.template_path', $this->templatePath);
        config()->set('sambaedu.gpo.veyon.local_path', '/nonexistent/local.json');
        config()->set('sambaedu.gpo.veyon.pubkey_path', $this->pubKeyPath);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        @unlink($this->pubKeyPath);
        @unlink($this->privKeyPath);
        parent::tearDown();
    }

    private function makeLdapConfig(string $baseDn): LdapConfig
    {
        return new LdapConfig(
            url: 'ldaps://test.local',
            port: 636,
            baseDn: $baseDn,
            adminName: 'admin',
            adminPassword: 'pwd',
            domain: 'example.local',
            sambaDomain: 'EXAMPLE',
            peopleRdn: 'ou=Utilisateurs',
            groupsRdn: 'ou=Groupes',
            computersRdn: 'ou=Computers',
            parcsRdn: 'ou=Parcs',
            classesRdn: 'ou=Classes',
            equipesRdn: 'ou=Equipes',
            matieresRdn: 'ou=Matieres',
            coursRdn: 'ou=Cours',
            projetsRdn: 'ou=Projets',
            otherGroupsRdn: 'ou=AutresGroupes',
            delegationsRdn: 'ou=Delegations',
            equipementsRdn: 'ou=Equipements',
            rightsRdn: 'ou=Droits',
            trashRdn: 'ou=Trash',
            etablissementsRdn: 'ou=Etablissements',
            adminRdn: 'cn=Administrator',
            etabServerIp: '127.0.0.1',
            strictLocalAd: false,
        );
    }

    private function makeConfig(array $kv): SambaEduConfig
    {
        /** @var SambaEduConfig&\Mockery\MockInterface $mock */
        $mock = Mockery::mock(SambaEduConfig::class);
        $mock->shouldReceive('get')
            ->andReturnUsing(function (string $key, mixed $default = null) use ($kv): mixed {
                return $kv[$key] ?? $default;
            });
        $mock->shouldReceive('has')
            ->andReturnUsing(fn(string $key) => array_key_exists($key, $kv));
        $mock->shouldReceive('all')->andReturn($kv);

        $mock->shouldReceive('ldap')->andReturn($this->makeLdapConfig($kv['ldap_base_dn'] ?? 'dc=example,dc=local'));

        return $mock;
    }

    private function makeContext(string $salle = 'salle1'): AppContext
    {
        return AppContext::fromApcuArray([
            'user' => ['cn' => 'jdoe'],
            'machine' => ['cn' => 'post01'],
            'salle' => $salle,
            'list_u' => [],
            'os' => 'linux',
            'time' => time(),
        ]);
    }

    #[Test]
    public function it_builds_ldap_section_with_all_required_keys(): void
    {
        $generator = new VeyonConfigGenerator($this->makeConfig([
            'suffix' => '',
            'people_rdn' => 'ou=Utilisateurs',
            'groups_rdn' => 'ou=Groups',
            'parcs_rdn' => 'ou=Parcs',
            'computers_rdn' => 'ou=computers',
            'ldap_base_dn' => 'dc=example,dc=local',
            'se4ad_name' => 'se4ad',
            'domain' => 'example.local',
        ]));

        $json = $generator->generate($this->makeContext(), 'mypwd');

        $this->assertArrayHasKey('LDAP', $json);
        foreach ([
            'BaseDN', 'BindDN', 'BindPassword', 'ServerHost', 'ServerPort',
            'ConnectionSecurity', 'TLSVerifyMode', 'UseBindCredentials',
            'UserTree', 'GroupTree', 'ComputerTree', 'ComputerGroupTree',
            'ComputerGroupsFilter', 'ComputersFilter', 'UserGroupsFilter',
        ] as $key) {
            $this->assertArrayHasKey($key, $json['LDAP'], "Missing LDAP.$key");
        }
        $this->assertSame(389, $json['LDAP']['ServerPort']);
        $this->assertSame(1, $json['LDAP']['ConnectionSecurity']);
        $this->assertSame(1, $json['LDAP']['TLSVerifyMode']);
        $this->assertSame('CN=read.user,ou=Utilisateurs,dc=example,dc=local', $json['LDAP']['BindDN']);
    }

    #[Test]
    public function it_encrypts_bind_password_with_openssl_pkcs1_oaep_padding(): void
    {
        $generator = new VeyonConfigGenerator($this->makeConfig([
            'suffix' => '',
            'people_rdn' => 'ou=Utilisateurs',
            'groups_rdn' => 'ou=Groups',
            'parcs_rdn' => 'ou=Parcs',
            'computers_rdn' => 'ou=computers',
            'ldap_base_dn' => 'dc=example,dc=local',
        ]));

        $clearPwd = 'SuperSecret#42';
        $json = $generator->generate($this->makeContext(), $clearPwd);

        $hex = $json['LDAP']['BindPassword'];
        $this->assertNotSame('', $hex, 'BindPassword non vide');
        // Hex strict
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $hex);

        // Déchiffrer avec la clé privée pour vérifier le clear-text.
        $cipher = hex2bin($hex);
        $priv = file_get_contents($this->privKeyPath);
        $decrypted = '';
        $ok = openssl_private_decrypt($cipher, $decrypted, $priv, OPENSSL_PKCS1_OAEP_PADDING);
        $this->assertTrue($ok, 'openssl_private_decrypt OK');
        $this->assertSame($clearPwd, $decrypted, 'clear-text reproductible iso-legacy');
    }

    #[Test]
    public function it_applies_cleandn_in_all_dn_attributes(): void
    {
        // Note legacy : `cleandn` est appliqué sur BaseDN, ComputerGroupTree,
        // ComputerTree, UserTree, GroupTree, AuthorizedUserGroups. BindDN
        // utilise les valeurs brutes (parité legacy `gpo/veyon_out.php:80`).
        $this->assertSame('OU=foo,DC=bar,DC=local', VeyonConfigGenerator::cleandn('ou=foo,dc=bar,dc=local'));
        $this->assertSame('CN=Admins,OU=Groups,DC=x', VeyonConfigGenerator::cleandn('cn=Admins,ou=Groups,dc=x'));
        $this->assertSame('OU=Already,DC=upper', VeyonConfigGenerator::cleandn('OU=Already,DC=upper'));

        $generator = new VeyonConfigGenerator($this->makeConfig([
            'suffix' => '',
            'people_rdn' => 'ou=Utilisateurs',
            'groups_rdn' => 'ou=Groups',
            'parcs_rdn' => 'ou=Parcs',
            'computers_rdn' => 'ou=computers',
            'ldap_base_dn' => 'dc=example,dc=local',
        ]));
        $json = $generator->generate($this->makeContext(), 'p');

        $this->assertSame('DC=example,DC=local', $json['LDAP']['BaseDN']);
        $this->assertSame('OU=Parcs', $json['LDAP']['ComputerGroupTree']);
        $this->assertSame('OU=computers', $json['LDAP']['ComputerTree']);
        $this->assertSame('OU=Utilisateurs', $json['LDAP']['UserTree']);
        $this->assertSame('OU=Groups', $json['LDAP']['GroupTree']);
    }

    #[Test]
    public function it_merges_local_json_override_via_array_replace_recursive(): void
    {
        $localPath = sys_get_temp_dir() . '/veyon-local-' . uniqid('', true) . '.json';
        file_put_contents($localPath, json_encode([
            'Service' => ['AutostartService' => false, 'CustomLocal' => 42],
        ]));
        config()->set('sambaedu.gpo.veyon.local_path', $localPath);

        $generator = new VeyonConfigGenerator($this->makeConfig([
            'suffix' => '',
            'people_rdn' => 'ou=Utilisateurs',
            'groups_rdn' => 'ou=Groups',
            'parcs_rdn' => 'ou=Parcs',
            'computers_rdn' => 'ou=computers',
            'ldap_base_dn' => 'dc=example,dc=local',
        ]));
        $json = $generator->generate($this->makeContext(), 'p');

        $this->assertFalse($json['Service']['AutostartService'], 'override local appliqué');
        $this->assertSame(42, $json['Service']['CustomLocal']);
        // Le template gardé pour clés non-écrasées
        $this->assertArrayHasKey('AccessControl', $json);

        @unlink($localPath);
    }

    #[Test]
    public function it_includes_authorized_user_groups_admins_profs_administratifs(): void
    {
        $generator = new VeyonConfigGenerator($this->makeConfig([
            'suffix' => '',
            'people_rdn' => 'ou=Utilisateurs',
            'groups_rdn' => 'ou=Groups,dc=example,dc=local',
            'parcs_rdn' => 'ou=Parcs',
            'computers_rdn' => 'ou=computers',
            'ldap_base_dn' => 'dc=example,dc=local',
        ]));
        $json = $generator->generate($this->makeContext(), 'p');

        $this->assertArrayHasKey('AccessControl', $json);
        $auth = $json['AccessControl']['AuthorizedUserGroups'];
        $this->assertSame('CN=Admins,OU=Groups,DC=example,DC=local', $auth[0]);
        $this->assertSame('CN=Profs,OU=Groups,DC=example,DC=local', $auth[1]);
        $this->assertSame('CN=Administratifs,OU=Groups,DC=example,DC=local', $auth[2]);
    }

    #[Test]
    public function it_includes_openent_in_predefined_websites_when_openent_uri_set(): void
    {
        $generator = new VeyonConfigGenerator($this->makeConfig([
            'suffix' => '',
            'people_rdn' => 'ou=Utilisateurs',
            'groups_rdn' => 'ou=Groups',
            'parcs_rdn' => 'ou=Parcs',
            'computers_rdn' => 'ou=computers',
            'ldap_base_dn' => 'dc=example,dc=local',
            'openent_uri' => 'https://ent.example.org',
        ]));
        $json = $generator->generate($this->makeContext(), 'p');

        $this->assertArrayHasKey('DesktopServices', $json);
        $entry = $json['DesktopServices']['PredefinedWebsites']['JsonStoreArray'][0];
        $this->assertSame('ENT', $entry['Name']);
        $this->assertSame('https://ent.example.org', $entry['Path']);
    }

    #[Test]
    public function it_omits_predefined_websites_when_openent_uri_missing(): void
    {
        $generator = new VeyonConfigGenerator($this->makeConfig([
            'suffix' => '',
            'people_rdn' => 'ou=Utilisateurs',
            'groups_rdn' => 'ou=Groups',
            'parcs_rdn' => 'ou=Parcs',
            'computers_rdn' => 'ou=computers',
            'ldap_base_dn' => 'dc=example,dc=local',
        ]));
        $json = $generator->generate($this->makeContext(), 'p');

        // Pas d'erreur, pas de section DesktopServices ajoutée par défaut
        // (le template ne contient pas DesktopServices).
        $this->assertArrayNotHasKey('DesktopServices', $json);
    }

    #[Test]
    public function it_uses_fallback_parc_filter_when_salle_not_found(): void
    {
        $generator = new VeyonConfigGenerator($this->makeConfig([
            'suffix' => '',
            'people_rdn' => 'ou=Utilisateurs',
            'groups_rdn' => 'ou=Groups',
            'parcs_rdn' => 'ou=Parcs',
            'computers_rdn' => 'ou=computers',
            'ldap_base_dn' => 'dc=example,dc=local',
        ]));
        // search_ad shim retourne [] côté tests → fallback `(cn=$salle)`
        $json = $generator->generate($this->makeContext('salle1'), 'p');
        $this->assertSame('(cn=salle1)', $json['LDAP']['ComputerGroupsFilter']);
    }
}
