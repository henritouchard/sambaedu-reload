<?php

declare(strict_types=1);

namespace Tests\Feature\Oidc;

use App\Auth\Oidc\Jwt\OidcIdTokenIssuer;
use App\Auth\Oidc\Support\OidcErrorCodes;
use App\Enums\SambaRole;
use App\Http\Middleware\Auth\AuditExternalAction;
use App\Http\Middleware\Auth\SambaEduAuth;
use App\Models\OidcAccessToken;
use App\Models\OidcClient;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use Database\Factories\OidcClientFactory;
use Database\Seeders\PermissionSeeder;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Oidc\Concerns\CapturesOidcLogs;
use Tests\Feature\Oidc\Concerns\UsesOidcTestKeys;
use Tests\TestCase;

/**
 * Story 55.2 — **AC1, AC2 et AC4** : le contrat de claims v1 dans l'id_token.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CE QUE CE FICHIER PROTÈGE
 *
 *  Le contrat de claims est PUBLIC et GELÉ (NFR11) : après la première
 *  extension intégrée, un claim ne peut plus être retiré ni renommé. Une clé
 *  émise par erreur est donc une **dette permanente** — et une fuite de PII.
 *
 *  D'où l'assertion centrale de ce fichier : la **liste EXACTE** des clés de
 *  claims, pour chaque combinaison de scopes. Un `assertArrayNotHasKey` ne
 *  couvre que ce à quoi on a pensé ; une liste exacte fait échouer le test
 *  pour un claim auquel personne n'avait pensé. C'est la seule forme
 *  d'assertion qui empêche une fuite de PII d'entrer sans être vue.
 *
 *  Les clés sont comparées TRIÉES : leur ORDRE dans le JWT n'est pas un
 *  contrat (aucun client OIDC n'en dépend), leur ENSEMBLE l'est.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Les tests assertent des VALEURS (le display_name attendu, le rôle attendu),
 * jamais la SOURCE du `sub` — patron 55.1 : l'arbitrage du sujet
 * ({@see \App\Auth\Oidc\Support\OidcSubjectResolver}) doit pouvoir basculer
 * sans réécrire cette suite.
 *
 * ⚠️ Bypass du guard `sambaedu.auth` : patron 55.1 (`OidcAuthorizationFlowTest`).
 */
class OidcIdTokenClaimsTest extends TestCase
{
    use CapturesOidcLogs;
    use RefreshDatabase;
    use UsesOidcTestKeys;

    private const REDIRECT_URI = 'https://ext.example.test/callback';

    private const VERIFIER = 'verifier-0123456789-0123456789-0123456789-abcdef';

    /** Les 7 clés standards de l'id_token (55.1), quand un `nonce` est demandé. */
    private const STANDARD_CLAIMS = ['aud', 'exp', 'iat', 'iss', 'jti', 'nonce', 'sub'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useOidcTestKeys();

        // Story 54.3 : `businessRoles()` consulte Spatie pour `super-admin`.
        (new PermissionSeeder())->run();

        // Créer un `user_groups` déclenche un job de sync AD (Observer) —
        // inatteignable sur l'hôte de test. Patron du projet
        // (`UsersListingScopedTest`) : on désactive la projection, pas le SQL.
        UserGroupObserver::disableSync();

        $this->withoutMiddleware([
            SambaEduAuth::class,
            AuditExternalAction::class,
        ]);
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();

        parent::tearDown();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function makeUser(string $role = 'prof', string $login = 'prof.dupont', ?string $fullname = 'Professeur Dupont'): User
    {
        return User::query()->create([
            'login' => $login,
            'fullname' => $fullname,
            'role' => $role,
            // Fiche projet : les cas nominaux sont des identités `ad` ; le cas
            // `federated` est traité une fois, à part.
            'source' => 'ad',
            'is_active' => true,
        ]);
    }

    /** @param array<string, string> $groups nom nu ⇒ type */
    private function attachGroups(User $user, array $groups): void
    {
        foreach ($groups as $name => $type) {
            // ⚠️ Post-fold 4.13 : NOM NU (`4B`), jamais `Classe_4B` — un
            // préfixe décrirait un état de la base qui n'existe plus.
            $group = UserGroup::query()->create(['name' => $name, 'type' => $type]);
            $user->userGroups()->attach($group->id);
        }
    }

    private function makeClient(): OidcClient
    {
        return OidcClient::factory()->withRedirectUris(self::REDIRECT_URI)->create();
    }

    private static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function obtainCode(OidcClient $client, User $user, string $scope): string
    {
        $response = $this->actingAs($user)->get('/oidc/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $client->client_id,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => $scope,
            'state' => 'state-xyz',
            'code_challenge' => self::challengeFor(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'nonce' => 'nonce-abc',
        ]));

        $response->assertStatus(302);
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        self::assertArrayHasKey('code', $query, 'le flux nominal doit émettre un code');

        return (string) $query['code'];
    }

    private function exchange(OidcClient $client, string $code)
    {
        return $this->post('/oidc/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => self::VERIFIER,
        ], [
            'Authorization' => 'Basic '.base64_encode($client->client_id.':'.OidcClientFactory::DEFAULT_SECRET),
        ]);
    }

    /**
     * Déroule le flux COMPLET et rend les claims DÉCODÉS ET VÉRIFIÉS avec la
     * clé publique — jamais un `json_decode` du payload : ce serait accepter un
     * jeton dont la signature n'a pas été contrôlée.
     *
     * @return array<string, mixed>
     */
    private function claimsFromFullFlow(User $user, string $scope, ?OidcClient $client = null): array
    {
        $client ??= $this->makeClient();
        $code = $this->obtainCode($client, $user, $scope);

        $body = $this->exchange($client, $code)->assertOk()->json();

        return (array) JWT::decode((string) $body['id_token'], new Key($this->testPublicKeyPem(), 'RS256'));
    }

    /**
     * Ensemble EXACT des clés de claims, trié — l'assertion qui empêche une
     * fuite silencieuse.
     *
     * @param  array<string, mixed>  $claims
     * @param  list<string>  $businessClaims
     */
    private function assertExactClaimKeys(array $claims, array $businessClaims): void
    {
        $expected = array_merge(self::STANDARD_CLAIMS, $businessClaims);
        sort($expected);

        $actual = array_keys($claims);
        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            'La liste EXACTE des clés de claims est le contrat public v1 (NFR5/NFR11). '
            .'Une clé en plus est une dette permanente ; une clé en moins casse les extensions.',
        );
    }

    // ── AC1 — le prof, claims complets et scope-gatés ─────────────────────

    #[Test]
    public function a_prof_gets_name_role_and_groups_and_nothing_else(): void
    {
        $user = $this->makeUser('prof');
        $this->attachGroups($user, ['4B' => 'classe', '3A' => 'classe', 'TechnoCollege' => 'equipe']);

        $claims = $this->claimsFromFullFlow($user, 'openid profile groups');

        self::assertSame('Professeur Dupont', $claims['name']);
        self::assertSame('prof', $claims['role'], 'le claim `role` est un SCALAIRE');
        self::assertSame(
            ['3A', '4B', 'TechnoCollege'],
            (array) $claims['groups'],
            'noms NUS, triés, dédupliqués — un ordre instable obligerait chaque client à trier',
        );

        $this->assertExactClaimKeys($claims, ['name', 'role', 'groups']);
    }

    #[Test]
    public function neither_name_nor_groups_nor_sub_ever_reach_the_oidc_journal(): void
    {
        // AC1 — `name` et `groups` sont de la PII au même titre que le `sub`,
        // que la doctrine 55.1 excluait déjà des logs.
        $user = $this->makeUser('prof', 'prof.durand', 'Sylvie Durand');
        $this->attachGroups($user, ['6C' => 'classe']);

        $this->captureLogs();

        $claims = $this->claimsFromFullFlow($user, 'openid profile groups');

        // Contrôle positif : le flux a bien produit la PII qu'on cherche.
        self::assertSame('Sylvie Durand', $claims['name']);
        self::assertNotEmpty($this->capturedLogs, 'le flux DOIT journaliser — sinon l\'absence ne prouve rien');

        $journal = $this->flattenedLogs();

        self::assertStringNotContainsString('Sylvie Durand', $journal, 'le `name` est de la PII');
        self::assertStringNotContainsString('6C', $journal, 'les `groups` sont de la PII');
        self::assertStringNotContainsString('prof.durand', $journal, 'le `sub` ne se journalise pas (doctrine 55.1)');
    }

    // ── AC2 — l'élève et la minimisation par scopes ───────────────────────

    #[Test]
    public function an_eleve_gets_their_role_and_their_class(): void
    {
        $user = $this->makeUser('eleve', 'eleve.martin', 'Léa Martin');
        $this->attachGroups($user, ['4B' => 'classe']);

        $claims = $this->claimsFromFullFlow($user, 'openid profile groups');

        self::assertSame('eleve', $claims['role']);
        self::assertSame(['4B'], (array) $claims['groups']);
        self::assertSame('Léa Martin', $claims['name']);

        $this->assertExactClaimKeys($claims, ['name', 'role', 'groups']);
    }

    #[Test]
    public function the_openid_scope_alone_produces_no_business_claim_at_all(): void
    {
        // NFR5 : un scope non demandé ne produit RIEN. C'est la minimisation,
        // et c'est vérifié par la liste exacte — pas par trois absences
        // choisies à la main.
        $user = $this->makeUser('prof');
        $this->attachGroups($user, ['4B' => 'classe']);

        $claims = $this->claimsFromFullFlow($user, 'openid');

        $this->assertExactClaimKeys($claims, []);
    }

    #[Test]
    public function the_profile_scope_without_groups_omits_the_groups_claim(): void
    {
        $user = $this->makeUser('prof');
        $this->attachGroups($user, ['4B' => 'classe']);

        $claims = $this->claimsFromFullFlow($user, 'openid profile');

        self::assertSame('Professeur Dupont', $claims['name']);
        self::assertSame('prof', $claims['role']);

        // L'utilisateur A des groupes : leur absence vient du scope, pas d'une
        // base vide.
        $this->assertExactClaimKeys($claims, ['name', 'role']);
    }

    #[Test]
    public function the_groups_scope_without_profile_omits_name_and_role(): void
    {
        $user = $this->makeUser('prof');
        $this->attachGroups($user, ['4B' => 'classe']);

        $claims = $this->claimsFromFullFlow($user, 'openid groups');

        self::assertSame(['4B'], (array) $claims['groups']);
        $this->assertExactClaimKeys($claims, ['groups']);
    }

    #[Test]
    public function no_claim_ever_carries_email_spatie_permissions_or_directory_attributes(): void
    {
        // Assertions NÉGATIVES explicites, adossées au contrôle POSITIF ci-dessus
        // (le flux produit bien `name`/`role`/`groups`) : sans lui, ces absences
        // pourraient n'être que le symptôme d'un flux cassé.
        $user = $this->makeUser('prof');
        $user->email = 'prof.dupont@college.test';
        $user->ad_guid = '11111111-2222-3333-4444-555555555555';
        $user->save();
        $user->assignRole(SambaRole::SuperAdmin->value);
        $this->attachGroups($user, ['4B' => 'classe']);

        $claims = $this->claimsFromFullFlow($user, 'openid profile groups');

        self::assertSame('Professeur Dupont', $claims['name'], 'contrôle positif');

        foreach (['email', 'given_name', 'family_name', 'picture', 'locale', 'updated_at',
            'ad_guid', 'dn', 'memberOf', 'permissions', 'roles', 'school_code'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $claims, 'claim hors contrat : '.$forbidden);
        }

        // Et aucune VALEUR de PII ne s'est glissée sous un autre nom.
        $serialized = json_encode($claims) ?: '';
        self::assertStringNotContainsString('@college.test', $serialized);
        self::assertStringNotContainsString('11111111-2222', $serialized);
        self::assertStringNotContainsString('super-admin', $serialized);
    }

    // ── Le rôle : scalaire, profil prioritaire, absent si non résolu ───────

    #[Test]
    public function a_prof_delegated_super_admin_stays_prof_for_the_extension(): void
    {
        // `businessRoles()` rend `['prof', 'admin']` ; le claim prend le
        // PREMIER — le profil métier prime sur la délégation d'administration.
        // C'est le bon comportement pour une extension pédagogique (BBB).
        $user = $this->makeUser('prof');
        $user->assignRole(SambaRole::SuperAdmin->value);

        self::assertSame(['prof', 'admin'], $user->businessRoles(), 'prérequis de la story');

        $claims = $this->claimsFromFullFlow($user, 'openid profile');

        self::assertSame('prof', $claims['role']);
        self::assertIsString($claims['role'], 'le type du claim est GELÉ : scalaire, jamais un tableau');
    }

    #[Test]
    public function a_pure_super_admin_gets_the_admin_role(): void
    {
        $user = $this->makeUser('autre', 'admin.local', 'Admin Local');
        $user->assignRole(SambaRole::SuperAdmin->value);

        $claims = $this->claimsFromFullFlow($user, 'openid profile');

        self::assertSame('admin', $claims['role']);
    }

    #[Test]
    public function a_user_whose_business_role_is_unresolvable_gets_no_role_claim(): void
    {
        // AC4 — `users.role = 'autre'` : jamais `"autre"` dans le claim, jamais
        // une valeur inventée. Le claim est ABSENT, et l'extension fail-closed
        // n'habilite pas.
        $user = $this->makeUser('autre', 'agent.polyvalent', 'Agent Polyvalent');

        $claims = $this->claimsFromFullFlow($user, 'openid profile');

        self::assertSame('Agent Polyvalent', $claims['name'], '`name` reste présent : seul `role` manque');
        $this->assertExactClaimKeys($claims, ['name']);
    }

    #[Test]
    public function a_federated_identity_without_delegation_gets_no_role_claim(): void
    {
        // AC4 / limite CONNUE et ASSUMÉE de `businessRoles()` (docblock 54.3) :
        // une identité fédérée sans rôle Spatie `super-admin` n'a pas de rôle
        // métier résoluble. Le raffinement appartient à 49.1/49.2, pas au SSO.
        $user = User::query()->create([
            'login' => 'ext:tech-42',
            'fullname' => 'Technicien controlHub',
            'role' => 'federated',
            'source' => 'federated',
            'is_active' => true,
        ]);

        $claims = $this->claimsFromFullFlow($user, 'openid profile');

        $this->assertExactClaimKeys($claims, ['name']);
    }

    // ── Les groupes : types, tri, déduplication, volumétrie ───────────────

    #[Test]
    public function only_classe_and_equipe_group_types_are_published(): void
    {
        $user = $this->makeUser('prof');
        $this->attachGroups($user, [
            '4B' => 'classe',
            'TechnoCollege' => 'equipe',
            'Profs' => 'role',
            'Direction' => 'function',
            'Club-Echecs' => 'custom',
        ]);

        $claims = $this->claimsFromFullFlow($user, 'openid profile groups');

        // Minimisation NFR5 : `role`/`function`/`custom` sont des artefacts
        // d'administration INTERNE — hors « groupes du contexte ».
        self::assertSame(['4B', 'TechnoCollege'], (array) $claims['groups']);
    }

    #[Test]
    public function the_groups_claim_is_an_empty_list_when_the_user_has_none(): void
    {
        // Le scope accordé émet la clé, même vide : « aucun groupe » est une
        // DONNÉE. L'omettre laisserait le client deviner entre « pas de
        // groupes » et « scope non accordé ».
        $user = $this->makeUser('prof');

        $claims = $this->claimsFromFullFlow($user, 'openid profile groups');

        self::assertSame([], (array) $claims['groups']);
        $this->assertExactClaimKeys($claims, ['name', 'role', 'groups']);
    }

    #[Test]
    public function a_user_with_forty_groups_still_yields_a_verifiable_id_token(): void
    {
        // Volumétrie cadrée : le claim `groups` n'est persisté dans AUCUNE
        // colonne (il vit dans le JWT, transporté en corps POST). Ce test
        // prouve l'émission ET la vérification d'un jeton volumineux.
        $user = $this->makeUser('prof');

        $groups = [];
        for ($i = 1; $i <= 40; $i++) {
            $groups[sprintf('CLASSE-%02d', $i)] = 'classe';
        }
        $this->attachGroups($user, $groups);

        $claims = $this->claimsFromFullFlow($user, 'openid profile groups');

        self::assertCount(40, (array) $claims['groups']);
        self::assertSame('CLASSE-01', ((array) $claims['groups'])[0], 'tri stable');
        $this->assertExactClaimKeys($claims, ['name', 'role', 'groups']);
    }

    #[Test]
    public function the_two_unique_constraints_that_make_a_repeated_group_impossible_are_still_in_place(): void
    {
        // Le claim `groups` promet des noms DÉDUPLIQUÉS (un client qui itère
        // créerait sinon deux salons). Cette promesse repose sur DEUX
        // contraintes SQL, pas sur le `unique()` du résolveur — qui n'est que
        // de la défense en profondeur et ne peut donc PAS être exercé sans
        // fabriquer en test un état que le schéma interdit (anti-patron
        // « table hand-rollée pour faire taire une erreur », review 54.3).
        //
        // Ce test verrouille les deux contraintes : le jour où l'une sauterait,
        // le chemin de déduplication deviendrait vivant — il vaut mieux
        // l'apprendre ici que par un doublon chez un client.
        $user = $this->makeUser('prof');
        $group = UserGroup::query()->create(['name' => '4B', 'type' => 'classe']);
        $user->userGroups()->attach($group->id);

        // 1. `user_groups.name` est UNIQUE : deux lignes ne peuvent pas porter
        //    le même nom nu, quels que soient leurs types.
        try {
            UserGroup::query()->create(['name' => '4B', 'type' => 'equipe']);
            self::fail('`user_groups.name` doit être UNIQUE');
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // attendu
        }

        // 2. Le pivot `(user_group_id, user_id)` est UNIQUE : un double
        //    rattachement (import rejoué) est impossible.
        try {
            $user->userGroups()->attach($group->id);
            self::fail('le pivot user_group_user doit être UNIQUE');
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // attendu
        }

        // Contrôle positif : malgré ces refus, le flux nominal produit bien le
        // claim attendu — les exceptions ci-dessus n'ont pas cassé l'état.
        $claims = $this->claimsFromFullFlow($user, 'openid groups');
        self::assertSame(['4B'], (array) $claims['groups']);
    }

    // ── AC4 — l'utilisateur disparu, et l'inécrasabilité des standards ────

    #[Test]
    public function a_user_deleted_between_authorization_and_exchange_gets_invalid_grant(): void
    {
        $user = $this->makeUser('prof');
        $client = $this->makeClient();

        // Contrôle POSITIF : la fixture produit bien un jeton quand le compte
        // existe (sinon ce test prouverait qu'une plomberie est cassée).
        $this->exchange($client, $this->obtainCode($client, $user, 'openid profile'))->assertOk();
        self::assertSame(1, OidcAccessToken::query()->count());

        $code = $this->obtainCode($client, $user, 'openid profile');

        $this->captureLogs();
        $user->delete();

        $response = $this->exchange($client, $code);

        $response->assertStatus(400);
        self::assertSame('invalid_grant', $response->json('error'));

        // Réponse INDISTINCTE d'un code inconnu : aucun oracle sur l'existence
        // d'un compte.
        self::assertSame(
            'The authorization code is invalid, expired or already used.',
            $response->json('error_description'),
        );

        // Jamais un id_token aux claims partiels, jamais un access_token orphelin.
        self::assertNull($response->json('id_token'));
        self::assertSame(1, OidcAccessToken::query()->count(), 'aucun SECOND jeton émis');

        self::assertContains(OidcErrorCodes::USER_MISSING, $this->loggedCodes());
    }

    #[Test]
    public function a_user_deactivated_between_authorization_and_exchange_gets_invalid_grant(): void
    {
        // Correctif review 55.2 (#1). `users.is_active = false` ne gardait RIEN
        // dans la chaîne OIDC : `SambaEduAuthGuard` valide l'état du compte côté
        // LDAP/AD, et le token endpoint est un appel serveur-à-serveur qui ne
        // traverse aucune session. Un compte désactivé pendant la fenêtre de
        // 60 s du code obtenait donc un id_token complet — et un access_token
        // utilisable 10 minutes de plus.
        //
        // La symétrie attendue est celle du client : `$client->enabled` était
        // vérifié, `$user->is_active` non.
        $user = $this->makeUser('prof');
        $client = $this->makeClient();

        // Contrôle POSITIF : la fixture émet bien un jeton tant que le compte
        // est actif — sans lui, le refus ci-dessous ne prouverait rien.
        $this->exchange($client, $this->obtainCode($client, $user, 'openid profile'))->assertOk();
        self::assertSame(1, OidcAccessToken::query()->count());

        $code = $this->obtainCode($client, $user, 'openid profile');

        $this->captureLogs();
        $user->forceFill(['is_active' => false])->save();

        $response = $this->exchange($client, $code);

        $response->assertStatus(400);
        self::assertSame('invalid_grant', $response->json('error'));

        // Indistinct d'un code inconnu ET d'un compte supprimé : la réponse ne
        // dit pas à un appelant dans quel état est le compte visé.
        self::assertSame(
            'The authorization code is invalid, expired or already used.',
            $response->json('error_description'),
        );

        self::assertNull($response->json('id_token'));
        self::assertSame(1, OidcAccessToken::query()->count(), 'aucun SECOND jeton émis');

        // Seul le journal distingue — sinon l'exploitant ne comprendrait pas
        // pourquoi une intégration cesse brusquement de fonctionner.
        self::assertContains(OidcErrorCodes::USER_INACTIVE, $this->loggedCodes());
        self::assertNotContains(OidcErrorCodes::USER_MISSING, $this->loggedCodes());
    }

    #[Test]
    public function business_claims_can_never_override_a_standard_claim(): void
    {
        // L'ordre du `array_merge` dans l'émetteur EST la garantie. Un
        // résolveur bugué — ou compromis — qui renverrait `sub`/`aud`/`exp` ne
        // doit rien pouvoir altérer.
        $client = $this->makeClient();

        $issued = app(OidcIdTokenIssuer::class)->issueIdToken(
            $client,
            'sujet.legitime',
            'nonce-legitime',
            [
                'sub' => 'attaquant',
                'aud' => 'client-etranger',
                'iss' => 'https://attaquant.example',
                'exp' => 99999999999,
                'jti' => 'jti-choisi',
                'nonce' => 'nonce-force',
                'name' => 'Claim Métier Légitime',
            ],
        );

        $claims = (array) JWT::decode($issued['token'], new Key($this->testPublicKeyPem(), 'RS256'));

        self::assertSame('sujet.legitime', $claims['sub']);
        self::assertSame($client->client_id, $claims['aud']);
        self::assertSame($this->testIssuer, $claims['iss']);
        self::assertSame($issued['exp'], $claims['exp']);
        self::assertSame($issued['jti'], $claims['jti']);
        self::assertSame('nonce-legitime', $claims['nonce']);

        // Contrôle positif : un claim métier NON standard, lui, passe bien —
        // sans quoi le test ne prouverait qu'un paramètre ignoré.
        self::assertSame('Claim Métier Légitime', $claims['name']);
    }

    #[Test]
    public function the_access_token_row_carries_the_user_id_used_by_userinfo(): void
    {
        // Migration additive 55.2 : c'est cette clé — et non le `sub` — qui
        // permettra à `/userinfo` de résoudre l'utilisateur.
        $user = $this->makeUser('prof');
        $client = $this->makeClient();

        $this->exchange($client, $this->obtainCode($client, $user, 'openid profile groups'))->assertOk();

        $token = OidcAccessToken::query()->first();

        self::assertSame((int) $user->id, (int) $token?->user_id);
        self::assertSame('openid profile groups', $token?->scope);
        self::assertSame($user->id, $token?->user?->id);
    }
}
