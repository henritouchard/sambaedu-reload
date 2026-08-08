<?php

declare(strict_types=1);

namespace Tests\Feature\Nextcloud;

use App\Models\User;
use App\Services\FilePolicyService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\Nextcloud\NextcloudFailure;
use App\Services\Nextcloud\NextcloudIdentityLinker;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 61.2 — AC7 : LE RATTACHEMENT EXPLICITE D'IDENTITÉ.
 *
 * ---------------------------------------------------------------------------
 * **LE TEST PIVOT EST LE REFUS** ({@see self::an_unconfirmed_identity_is_never_written()}).
 * La revue 61.1 a fermé le scénario `p.durand` / `p.durand-martin` : une identité
 * non confirmée écrite en base fait que le prochain changement de mot de passe AD
 * écrase le mot de passe du compte d'une AUTRE personne, journalisé comme un
 * succès. Qu'un humain ait tapé l'identifiant n'y change rien — une faute de frappe
 * produit exactement le même défaut.
 * ---------------------------------------------------------------------------
 */
class NextcloudIdentityAttachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'AppPasswordAdmin');

        $this->configure();
    }

    private function configure(): void
    {
        FilePolicyService::setGlobal(true, true, true, 'https://cloud.etab.fr', 'admin', 'se4fs', true);
    }

    private function user(string $login, ?string $nextcloudId = null): User
    {
        $user = User::query()->create([
            'login' => $login,
            'role' => 'prof',
            'is_active' => true,
            'source' => 'ad',
        ]);

        if ($nextcloudId !== null) {
            $user->nextcloud_user_id = $nextcloudId;
            $user->saveQuietly();
        }

        return $user;
    }

    /** @param array<string, mixed> $data */
    private static function ocs(int $code, array $data = []): array
    {
        return ['ocs' => ['meta' => ['status' => 'ok', 'statuscode' => $code, 'message' => 'OK'], 'data' => $data]];
    }

    // =====================================================================
    // La sonde directe auprès de l'instance
    // =====================================================================

    #[Test]
    public function a_confirmed_identity_is_written_to_the_cache_column(): void
    {
        $this->user('p.durand');

        Http::fake(['*/ocs/v1.php/cloud/users/*' => Http::response(
            self::ocs(100, ['id' => 'uuid-4f2a', 'enabled' => true]),
            200,
        )]);

        $result = app(NextcloudIdentityLinker::class)->link('p.durand', 'uuid-4f2a');

        self::assertTrue($result->successful);
        self::assertFalse($result->alreadyConforming);
        self::assertSame('uuid-4f2a', User::query()->where('login', 'p.durand')->value('nextcloud_user_id'));
    }

    #[Test]
    public function an_unconfirmed_identity_is_never_written(): void
    {
        $this->user('p.durand');

        // L'instance ne connaît pas ce compte : OCS 998.
        Http::fake(['*/ocs/v1.php/cloud/users/*' => Http::response(self::ocs(998), 200)]);

        $result = app(NextcloudIdentityLinker::class)->link('p.durand', 'p.durand-martin');

        self::assertTrue($result->isFailure());
        self::assertSame(NextcloudFailure::Absent, $result->failure);
        self::assertStringContainsString('Rien n\'a été écrit', $result->message);
        self::assertNull(User::query()->where('login', 'p.durand')->value('nextcloud_user_id'));
    }

    #[Test]
    public function an_unreachable_instance_refuses_rather_than_writes(): void
    {
        $this->user('p.durand');

        Http::fake(['*' => static fn (): never => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7')]);

        $result = app(NextcloudIdentityLinker::class)->link('p.durand', 'p.durand');

        self::assertTrue($result->isFailure());
        self::assertSame(NextcloudFailure::Injoignable, $result->failure);
        self::assertNull(User::query()->where('login', 'p.durand')->value('nextcloud_user_id'));
    }

    /** L'instance fait autorité sur l'orthographe de ses comptes. */
    #[Test]
    public function the_identifier_written_is_the_one_the_instance_returns(): void
    {
        $this->user('p.durand');

        Http::fake(['*/ocs/v1.php/cloud/users/*' => Http::response(self::ocs(100, ['id' => 'P.Durand']), 200)]);

        app(NextcloudIdentityLinker::class)->link('p.durand', 'p.durand');

        self::assertSame('P.Durand', User::query()->where('login', 'p.durand')->value('nextcloud_user_id'));
    }

    // =====================================================================
    // CORRECTION DE REVUE #2 — UNE IDENTITÉ NEXTCLOUD N'EST PORTÉE QUE PAR UN
    // SEUL UTILISATEUR SE5
    //
    // `link()` vérifiait que l'identité EXISTE à distance, jamais qu'elle est
    // LIBRE. Deux logins SE5 pointant le même compte Nextcloud, et la
    // propagation de mot de passe de l'un écrase le compte de l'autre — c'est
    // exactement le scénario que la correction #2 de la revue 61.1 avait fermé,
    // rouvert par la porte « geste d'admin vérifié ».
    // =====================================================================

    #[Test]
    public function an_identity_already_held_by_another_user_is_refused_by_naming_the_holder(): void
    {
        $this->user('a.dupont', 'compte-partage');
        $this->user('p.durand');

        // L'instance confirmerait volontiers l'existence du compte : ce n'est pas
        // elle qui refuse, c'est SE5.
        Http::fake(['*/ocs/v1.php/cloud/users/*' => Http::response(self::ocs(100, ['id' => 'compte-partage']), 200)]);

        $result = app(NextcloudIdentityLinker::class)->link('p.durand', 'compte-partage');

        self::assertTrue($result->isFailure());
        self::assertStringContainsString('a.dupont', $result->message);
        self::assertStringContainsString('déjà rattachée', $result->message);

        // RIEN n'est écrit…
        self::assertNull(User::query()->where('login', 'p.durand')->value('nextcloud_user_id'));
        // …et le détenteur légitime conserve la sienne.
        self::assertSame('compte-partage', User::query()->where('login', 'a.dupont')->value('nextcloud_user_id'));

        // Le refus est LOCAL : aucun round-trip n'a même été tenté.
        Http::assertNothingSent();
    }

    /**
     * **LE TEST DE SÉCURITÉ QUI COMPTE** : après une tentative refusée, une
     * propagation de mot de passe ne peut pas atteindre le compte du tiers — c'est
     * la conséquence concrète que l'unicité empêche.
     */
    #[Test]
    public function a_refused_attachment_leaves_the_third_party_account_out_of_reach(): void
    {
        $this->user('a.dupont', 'compte-partage');
        $this->user('p.durand');

        Http::fake(['*/ocs/v1.php/cloud/users/*' => Http::response(self::ocs(100, ['id' => 'compte-partage']), 200)]);
        app(NextcloudIdentityLinker::class)->link('p.durand', 'compte-partage');

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => Http::response(self::ocs(100), 200)]);

        app(\App\Services\Nextcloud\NextcloudUserProvisioner::class)
            ->propagatePassword('p.durand', 'NouveauMotDePasse1!');

        // Aucun `PUT` ne part vers le compte du tiers : sans identité en cache, la
        // propagation ne devine JAMAIS.
        Http::assertNotSent(static fn (\Illuminate\Http\Client\Request $r): bool => str_contains(
            $r->url(),
            'compte-partage',
        ));
    }

    /** L'orthographe rendue par l'instance est contrôlée elle aussi. */
    #[Test]
    public function a_conflict_revealed_by_the_instance_spelling_is_refused_too(): void
    {
        $this->user('a.dupont', 'P.Durand');
        $this->user('p.durand');

        // Saisi « p.durand », l'instance répond « P.Durand » — c'est CETTE valeur
        // qui serait écrite, et elle est déjà prise.
        Http::fake(['*/ocs/v1.php/cloud/users/*' => Http::response(self::ocs(100, ['id' => 'P.Durand']), 200)]);

        $result = app(NextcloudIdentityLinker::class)->link('p.durand', 'p.durand');

        self::assertTrue($result->isFailure());
        self::assertStringContainsString('a.dupont', $result->message);
        self::assertNull(User::query()->where('login', 'p.durand')->value('nextcloud_user_id'));
    }

    /** Le détenteur légitime, lui, rejoue son propre rattachement sans rien casser. */
    #[Test]
    public function the_legitimate_holder_can_still_replay_its_own_attachment(): void
    {
        $this->user('a.dupont', 'compte-partage');
        Http::fake();

        $result = app(NextcloudIdentityLinker::class)->link('a.dupont', 'compte-partage');

        self::assertTrue($result->successful);
        self::assertTrue($result->alreadyConforming);
        self::assertSame('compte-partage', User::query()->where('login', 'a.dupont')->value('nextcloud_user_id'));
        Http::assertNothingSent();
    }

    /**
     * L'index unique en base est la défense en PROFONDEUR — et il laisse cohabiter
     * autant de `NULL` qu'il y a d'utilisateurs sans identité Nextcloud en cache
     * (SQLite comme PostgreSQL traitent les NULL comme distincts).
     */
    #[Test]
    public function the_unique_index_still_allows_many_users_without_any_identity(): void
    {
        $this->user('sans.cache.1');
        $this->user('sans.cache.2');
        $this->user('sans.cache.3');

        self::assertSame(3, User::query()->whereNull('nextcloud_user_id')->count());

        // …mais il refuse bien un doublon si un chemin d'écriture oubliait la garde.
        $this->user('porteur', 'compte-unique');
        $other = $this->user('autre');
        $other->nextcloud_user_id = 'compte-unique';

        $this->expectException(\Illuminate\Database\QueryException::class);
        $other->saveQuietly();
    }

    // =====================================================================
    // Idempotence, détachement, cas limites
    // =====================================================================

    /** Rejouer le même rattachement n'écrit rien et ne parle même pas à l'instance. */
    #[Test]
    public function relinking_the_same_identity_is_a_no_op_without_any_call(): void
    {
        $this->user('p.durand', 'uuid-4f2a');
        Http::fake();

        $result = app(NextcloudIdentityLinker::class)->link('p.durand', 'uuid-4f2a');

        self::assertTrue($result->successful);
        self::assertTrue($result->alreadyConforming);
        Http::assertNothingSent();
    }

    #[Test]
    public function clearing_removes_the_cache_and_touches_nothing_remote(): void
    {
        $this->user('p.durand', 'uuid-4f2a');
        Http::fake();

        $result = app(NextcloudIdentityLinker::class)->clear('p.durand');

        self::assertTrue($result->successful);
        self::assertNull(User::query()->where('login', 'p.durand')->value('nextcloud_user_id'));
        self::assertStringContainsString('rien n\'a été modifié côté Nextcloud', $result->message);
        Http::assertNothingSent();
    }

    #[Test]
    public function clearing_an_unlinked_user_is_a_no_op(): void
    {
        $this->user('p.durand');
        Http::fake();

        $result = app(NextcloudIdentityLinker::class)->clear('p.durand');

        self::assertTrue($result->alreadyConforming);
        Http::assertNothingSent();
    }

    #[Test]
    public function an_unknown_se5_user_is_refused_without_any_call(): void
    {
        Http::fake();

        $result = app(NextcloudIdentityLinker::class)->link('inconnu', 'quelque-chose');

        self::assertTrue($result->isFailure());
        self::assertStringContainsString('aucun utilisateur SE5', $result->message);
        Http::assertNothingSent();
    }

    #[Test]
    public function an_empty_identifier_is_refused_without_any_call(): void
    {
        $this->user('p.durand');
        Http::fake();

        self::assertTrue(app(NextcloudIdentityLinker::class)->link('p.durand', '  ')->isFailure());
        Http::assertNothingSent();
    }

    /** Capacité éteinte : le refus nomme la cause, sans appel. */
    #[Test]
    public function a_disabled_capability_refuses_by_naming_it(): void
    {
        FilePolicyService::setGlobal(true, true, false, 'https://cloud.etab.fr', 'admin', 'se4fs', true);
        $this->user('p.durand');
        Http::fake();

        $result = app(NextcloudIdentityLinker::class)->link('p.durand', 'p.durand');

        self::assertTrue($result->isFailure());
        self::assertStringContainsString('Accès Nextcloud', $result->message);
        Http::assertNothingSent();
    }

    // =====================================================================
    // La commande artisan — le même geste, sans écran
    // =====================================================================

    #[Test]
    public function the_command_reports_the_current_state_without_any_call(): void
    {
        $this->user('p.durand', 'uuid-4f2a');
        Http::fake();

        $this->artisan('nextcloud:identity p.durand')
            ->expectsOutputToContain('uuid-4f2a')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    #[Test]
    public function the_command_sets_after_verification(): void
    {
        $this->user('p.durand');

        Http::fake(['*/ocs/v1.php/cloud/users/*' => Http::response(self::ocs(100, ['id' => 'uuid-4f2a']), 200)]);

        $this->artisan('nextcloud:identity p.durand --set=uuid-4f2a')->assertExitCode(0);

        self::assertSame('uuid-4f2a', User::query()->where('login', 'p.durand')->value('nextcloud_user_id'));
    }

    #[Test]
    public function the_command_exits_1_when_the_identity_is_not_confirmed(): void
    {
        $this->user('p.durand');

        Http::fake(['*/ocs/v1.php/cloud/users/*' => Http::response(self::ocs(998), 200)]);

        $this->artisan('nextcloud:identity p.durand --set=p.durand-martin')->assertExitCode(1);

        self::assertNull(User::query()->where('login', 'p.durand')->value('nextcloud_user_id'));
    }

    #[Test]
    public function the_command_clears_and_is_replayable(): void
    {
        $this->user('p.durand', 'uuid-4f2a');
        Http::fake();

        $this->artisan('nextcloud:identity p.durand --clear')->assertExitCode(0);
        $this->artisan('nextcloud:identity p.durand --clear')->assertExitCode(0);

        self::assertNull(User::query()->where('login', 'p.durand')->value('nextcloud_user_id'));
        Http::assertNothingSent();
    }

    #[Test]
    public function the_command_refuses_contradictory_options(): void
    {
        $this->user('p.durand');

        $this->artisan('nextcloud:identity p.durand --set=x --clear')->assertExitCode(2);
    }
}
