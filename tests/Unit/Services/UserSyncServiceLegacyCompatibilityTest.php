<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Constants\Ldap\MainGroups;
use App\LdapModels\LdapUser;
use App\Services\UserSyncService;
use App\Types\User as AdUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserSyncServiceLegacyCompatibilityTest extends TestCase
{
    private UserSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new UserSyncService();
        $this->createUsersTable();
        $this->createSyncCursorsTable();
    }

    /** @test */
    public function it_maps_ldap_user_to_typed_ad_user_like_legacy_rules(): void
    {
        $ldapUser = $this->mockLdapUser(
            login: 'jdupont',
            dn: 'CN=jdupont,OU=People,DC=example,DC=local',
            memberOf: [
                'CN=Classe_3emeA,OU=Classes,OU=Groups,DC=example,DC=local',
                'CN=RefNum,OU=Rights,OU=Groups,DC=example,DC=local',
                'CN=DROIT_X,OU=Droits,OU=Groups,DC=example,DC=local',
            ],
            attributes: [
                'displayname' => 'Jean Dupont',
                'givenname' => 'Jean',
                'sn' => 'Dupont',
                'mail' => 'jdupont@example.test',
            ]
        );

        /** @var AdUser $result */
        $result = $this->invokePrivate($this->service, 'ldapUserToAdData', [$ldapUser, MainGroups::PROFS]);

        $this->assertSame('jdupont', $result->login);
        $this->assertSame('Jean Dupont', $result->fullname);
        $this->assertSame('prof', $result->role);
        $this->assertSame(['Classe_3emeA', 'RefNum', 'DROIT_X'], $result->groups);
        $this->assertSame(['RefNum', 'DROIT_X'], $result->rights);
    }

    /** @test */
    public function it_detects_establishment_membership_by_tree_or_memberof_like_legacy(): void
    {
        $establishmentDn = 'OU=0751234A,OU=Etablissements,DC=example,DC=local';

        $treeUser = $this->mockLdapUser(
            login: 'tree.user',
            dn: 'CN=tree.user,OU=People,OU=0751234A,OU=Etablissements,DC=example,DC=local',
            memberOf: []
        );

        $memberOfUser = $this->mockLdapUser(
            login: 'memberof.user',
            dn: 'CN=memberof.user,OU=People,DC=example,DC=local',
            memberOf: [$establishmentDn]
        );

        $externalUser = $this->mockLdapUser(
            login: 'external.user',
            dn: 'CN=external.user,OU=People,DC=example,DC=local',
            memberOf: ['CN=Other,OU=Elsewhere,DC=example,DC=local']
        );

        $this->assertSame('tree', $this->invokePrivate($this->service, 'getEstablishmentMatchType', [$treeUser, $establishmentDn]));
        $this->assertSame('memberOf', $this->invokePrivate($this->service, 'getEstablishmentMatchType', [$memberOfUser, $establishmentDn]));
        $this->assertNull($this->invokePrivate($this->service, 'getEstablishmentMatchType', [$externalUser, $establishmentDn]));
    }

    /** @test */
    public function it_upserts_sql_user_from_typed_ad_user(): void
    {
        $adUser = new AdUser(
            login: 'mleclerc',
            fullname: 'Marie Leclerc',
            firstname: 'Marie',
            lastname: 'Leclerc',
            email: 'mleclerc@example.test',
            dn: 'CN=mleclerc,OU=People,DC=example,DC=local',
            groups: ['Classe_5emeB', 'Equipe_5emeB'],
            role: 'eleve',
        );

        $created = $this->invokePrivate($this->service, 'upsertUser', [$adUser]);
        $this->assertSame('created', $created);

        $row = \App\Models\User::query()->where('login', 'mleclerc')->firstOrFail();
        $this->assertSame('Marie Leclerc', $row->fullname);

        $updatedUser = new AdUser(
            login: 'mleclerc',
            fullname: 'Marie L.',
            firstname: 'Marie',
            lastname: 'L',
            email: 'marie.l@example.test',
            dn: 'CN=mleclerc,OU=People,DC=example,DC=local',
            groups: ['Classe_5emeB'],
            role: 'eleve',
        );

        $updated = $this->invokePrivate($this->service, 'upsertUser', [$updatedUser]);
        $this->assertSame('updated', $updated);

        $row->refresh();
        $this->assertSame('Marie L.', $row->fullname);
    }

    /** @test */
    public function it_persists_and_reads_delta_cursor_whenchanged(): void
    {
        $this->invokePrivate($this->service, 'saveDeltaCursor', ['20260224103045.0Z']);

        $cursor = $this->invokePrivate($this->service, 'getDeltaCursor', []);
        $this->assertSame('20260224103045.0Z', $cursor);

        $this->service->resetDeltaCursor();

        $cursorAfterReset = $this->invokePrivate($this->service, 'getDeltaCursor', []);
        $this->assertNull($cursorAfterReset);
    }

    private function createUsersTable(): void
    {
        DB::statement('DROP TABLE IF EXISTS users CASCADE');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('login')->unique();
            $table->string('password')->nullable();
            $table->string('fullname')->nullable();
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('email')->nullable();
            $table->text('dn')->nullable();
            $table->string('ad_guid')->nullable();
            $table->string('role')->default('autre');
            $table->string('school_code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('ad_right_profiles')->nullable();
            $table->unsignedInteger('ad_rights_bitmask')->default(0);
            $table->timestamp('ad_synced_at')->nullable();
            $table->timestamps();
        });
    }

    private function createSyncCursorsTable(): void
    {
        Schema::dropIfExists('sync_cursors');

        Schema::create('sync_cursors', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('cursor_value');
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * @param array<int,string> $memberOf
     * @param array<string,string|null> $attributes
     */
    private function mockLdapUser(string $login, string $dn, array $memberOf, array $attributes = []): LdapUser
    {
        $ldapUser = $this->getMockBuilder(LdapUser::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getLogin', 'getDn', 'getAttribute', 'getFirstAttribute'])
            ->getMock();

        $ldapUser->method('getLogin')->willReturn($login);
        $ldapUser->method('getDn')->willReturn($dn);
        $ldapUser->method('getAttribute')->willReturnCallback(
            static fn(string $attribute) => $attribute === 'memberof' ? $memberOf : null
        );
        $ldapUser->method('getFirstAttribute')->willReturnCallback(
            static fn(string $attribute) => $attributes[$attribute] ?? null
        );

        return $ldapUser;
    }

    /**
     * @param array<int,mixed> $arguments
     */
    private function invokePrivate(object $instance, string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($instance, $arguments);
    }
}
