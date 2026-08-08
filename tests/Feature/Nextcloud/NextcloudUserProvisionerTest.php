<?php

declare(strict_types=1);

namespace Tests\Feature\Nextcloud;

use App\Models\User;
use App\Services\FilePolicyService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\Nextcloud\NextcloudUserProvisioner;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 61.1 — les crochets du cycle de vie utilisateur (AC5, AC6, AC7).
 *
 * Chaque branche a son test : compte créé, compte adopté (`102`), échec net,
 * capacité éteinte (aucun appel), propagation de mot de passe sous double
 * condition, instance à synchro LDAP qui refuse la propagation sans que ce soit
 * une panne.
 */
class NextcloudUserProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://cloud.etab.fr';

    private function configure(bool $enabled = true, string $smbHost = 'se4fs'): void
    {
        FilePolicyService::setGlobal(true, true, $enabled, self::URL, 'admin', $smbHost, true);
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'sekret');
    }

    private function provisioner(): NextcloudUserProvisioner
    {
        return app(NextcloudUserProvisioner::class);
    }

    /** @param array<string, mixed> $data */
    private static function ocs(int $code, array $data = [], string $message = 'OK'): array
    {
        return ['ocs' => [
            'meta' => ['status' => $code < 300 ? 'ok' : 'failure', 'statuscode' => $code, 'message' => $message],
            'data' => $data,
        ]];
    }

    // =====================================================================
    // AC5 — création au fil de l'eau
    // =====================================================================

    #[Test]
    public function creating_a_user_posts_the_account_with_the_password_in_hand(): void
    {
        $this->configure();
        User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        Http::fake(['*' => Http::response(self::ocs(100), 200)]);

        $this->provisioner()->ensureAccountAtCreation('alice', 'MotDePasseAd1!');

        Http::assertSent(static function (Request $r): bool {
            return $r->method() === 'POST'
                && str_contains($r->url(), '/ocs/v1.php/cloud/users')
                && $r['userid'] === 'alice'
                && $r['password'] === 'MotDePasseAd1!';
        });

        self::assertSame('alice', User::query()->where('login', 'alice')->value('nextcloud_user_id'));
    }

    /** `102` = déjà existant : ADOPTÉ, jamais une erreur, et l'identité est cachée. */
    #[Test]
    public function an_already_existing_account_is_adopted_and_cached(): void
    {
        $this->configure();
        User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        Http::fake(['*' => Http::response(self::ocs(102, [], 'User already exists'), 200)]);

        $this->provisioner()->ensureAccountAtCreation('alice', 'MotDePasseAd1!');

        self::assertSame('alice', User::query()->where('login', 'alice')->value('nextcloud_user_id'));
    }

    #[Test]
    public function a_net_failure_leaves_the_cache_empty_and_never_throws(): void
    {
        $this->configure();
        User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        Http::fake(['*' => Http::response('forbidden', 403)]);

        $this->provisioner()->ensureAccountAtCreation('alice', 'MotDePasseAd1!');

        self::assertNull(User::query()->where('login', 'alice')->value('nextcloud_user_id'));
    }

    /** Capacité éteinte ⇒ AUCUN appel nulle part. */
    #[Test]
    public function a_disabled_capability_emits_no_call_on_user_creation(): void
    {
        $this->configure(enabled: false);
        Http::fake();

        $this->provisioner()->ensureAccountAtCreation('alice', 'MotDePasseAd1!');

        Http::assertNothingSent();
    }

    // =====================================================================
    // AC7 — propagation du mot de passe
    // =====================================================================

    #[Test]
    public function the_password_is_propagated_when_capability_and_identity_both_hold(): void
    {
        $this->configure();
        $user = User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        $user->nextcloud_user_id = 'alice-nc';
        $user->saveQuietly();

        Http::fake(['*' => Http::response(self::ocs(200), 200)]);

        $this->provisioner()->propagatePassword('alice', 'NouveauMdp1!');

        Http::assertSent(static function (Request $r): bool {
            return $r->method() === 'PUT'
                && str_contains($r->url(), '/ocs/v2.php/cloud/users/alice-nc')
                && $r['key'] === 'password'
                && $r['value'] === 'NouveauMdp1!';
        });
    }

    /** Identité non résolue ⇒ on n'écrit pas à l'aveugle. */
    #[Test]
    public function no_propagation_happens_without_a_cached_identity(): void
    {
        $this->configure();
        User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        Http::fake();

        $this->provisioner()->propagatePassword('alice', 'NouveauMdp1!');

        Http::assertNothingSent();
    }

    #[Test]
    public function no_propagation_happens_when_the_capability_is_off(): void
    {
        $this->configure(enabled: false);
        $user = User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        $user->nextcloud_user_id = 'alice-nc';
        $user->saveQuietly();

        Http::fake();

        $this->provisioner()->propagatePassword('alice', 'NouveauMdp1!');

        Http::assertNothingSent();
    }

    /**
     * Revue #3 — UN REFUS CÔTÉ INSTANCE SE JOURNALISE EN DEBUG, JAMAIS EN
     * WARNING.
     *
     * Le test d'origine se terminait par `assertTrue(true)` : une façade qui
     * serait restée verte quel que soit le niveau émis. Or l'AC7 exige le niveau,
     * pas seulement l'absence d'exception — à la rentrée, une réinitialisation en
     * masse sur une instance à synchro LDAP produirait un WARNING par utilisateur
     * pour un état parfaitement normal.
     *
     * Les trois formes du même refus sont couvertes : `403` HTTP, statuscode OCS
     * `997` (les deux classés `Privilege` par le client) et le refus générique.
     */
    #[Test]
    #[DataProvider('ldapRefusals')]
    public function an_ldap_backed_instance_refusing_the_update_logs_debug_and_never_warns(
        mixed $body,
        int $status,
    ): void {
        $this->configure();
        $user = User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        $user->nextcloud_user_id = 'alice-nc';
        $user->saveQuietly();

        Http::fake(['*' => Http::response($body, $status)]);

        Log::spy();

        $this->provisioner()->propagatePassword('alice', 'NouveauMdp1!');

        Log::shouldHaveReceived('debug')
            ->withArgs(static fn (string $message, array $context = []): bool => $message === 'nextcloud.user.password.not_applicable'
                && ($context['login'] ?? null) === 'alice')
            ->once();

        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function ldapRefusals(): array
    {
        return [
            '403 HTTP' => ['forbidden', 403],
            'OCS 997' => [self::ocs(997, [], 'Cannot set password for LDAP user'), 200],
            'refus générique' => [self::ocs(996, [], 'Not modifiable'), 200],
        ];
    }

    // =====================================================================
    // Revue #1 — l'hôte SMB vide ne rend RIEN muet
    // =====================================================================

    /**
     * LE scénario qui était cassé. L'écran valide `nextcloud_smb_host` en
     * `nullable` et ne lui met pas d'astérisque : le laisser vide est un geste
     * que l'interface invite à faire. Tant qu'il faisait échouer la construction
     * de la configuration, `makeOrNull()` avalait l'exception et **les deux
     * crochets du cycle de vie devenaient définitivement muets**, sans trace.
     */
    #[Test]
    public function an_empty_smb_host_keeps_account_creation_and_password_propagation_alive(): void
    {
        $this->configure(smbHost: '');
        User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);

        Http::fake(['*' => Http::response(self::ocs(100), 200)]);

        $this->provisioner()->ensureAccountAtCreation('alice', 'MotDePasseAd1!');

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
            && str_contains($r->url(), '/ocs/v1.php/cloud/users'));
        self::assertSame('alice', User::query()->where('login', 'alice')->value('nextcloud_user_id'));

        $this->provisioner()->propagatePassword('alice', 'NouveauMdp1!');

        Http::assertSent(static fn (Request $r): bool => $r->method() === 'PUT'
            && str_contains($r->url(), '/ocs/v2.php/cloud/users/alice'));
    }

    // =====================================================================
    // Revue #2 — on n'adopte QUE l'homonyme
    // =====================================================================

    /**
     * LE scénario de sécurité, verrouillé.
     *
     * `p.durand` est absent de l'instance ; un compte tiers `p.durand-martin`
     * existe et l'autocomplétion — recherche FLOUE — le rend comme seul candidat.
     * L'adopter le graverait dans `users.nextcloud_user_id`, et le prochain
     * changement de mot de passe AD de `p.durand` écraserait **le mot de passe de
     * quelqu'un d'autre**, journalisé comme un succès.
     */
    #[Test]
    public function a_single_non_homonym_candidate_never_lands_in_the_identity_cache(): void
    {
        $this->configure();
        $user = User::query()->create(['login' => 'p.durand', 'role' => 'prof', 'is_active' => true, 'source' => 'ad']);

        Http::fake([
            '*/ocs/v1.php/cloud/users/p.durand*' => Http::response(self::ocs(998, [], 'not found'), 200),
            '*/ocs/v2.php/core/autocomplete/get*' => Http::response(self::ocs(200, [
                ['id' => 'p.durand-martin', 'source' => 'users'],
            ]), 200),
        ]);

        $report = new \App\Services\Nextcloud\NextcloudProvisioningReport();
        $client = app(\App\Services\Nextcloud\NextcloudClientFactory::class)->make();

        $this->provisioner()->adopt($user, $client, $report, dryRun: false);

        self::assertNull(User::query()->where('login', 'p.durand')->value('nextcloud_user_id'));
        self::assertSame(1, $report->userCounters()['introuvables']);
        self::assertStringContainsString('p.durand-martin', $report->userIssues()[0]['detail']);

        // Et la conséquence qui compte : sans identité cachée, AUCUNE propagation
        // de mot de passe n'est possible — le compte tiers est hors d'atteinte.
        Http::fake(['*' => Http::response(self::ocs(200), 200)]);

        $this->provisioner()->propagatePassword('p.durand', 'NouveauMdp1!');

        Http::assertNothingSent();
    }

    // =====================================================================
    // CORRECTION DE REVUE #2 — LA GARDE D'UNICITÉ VAUT AUSSI POUR LA
    // RÉSOLUTION AUTOMATIQUE
    //
    // Le geste manuel n'est pas le seul chemin d'écriture du cache : le balayage
    // en est un autre. La garde ferme la CLASSE de défauts, pas un de ses
    // chemins — et dans le balayage, le refus est COMPTÉ ET RAPPORTÉ, jamais une
    // exception qui interromprait le lot.
    // =====================================================================

    #[Test]
    public function an_identity_already_held_by_another_user_is_reported_and_never_overwritten(): void
    {
        $this->configure();

        $holder = User::query()->create(['login' => 'a.dupont', 'role' => 'prof', 'is_active' => true, 'source' => 'ad']);
        $holder->nextcloud_user_id = 'compte-partage';
        $holder->saveQuietly();

        $user = User::query()->create(['login' => 'p.durand', 'role' => 'prof', 'is_active' => true, 'source' => 'ad']);

        Http::fake([
            '*/ocs/v1.php/cloud/users/p.durand*' => Http::response(self::ocs(100, ['id' => 'compte-partage']), 200),
        ]);

        $report = new \App\Services\Nextcloud\NextcloudProvisioningReport();
        $client = app(\App\Services\Nextcloud\NextcloudClientFactory::class)->make();

        // Aucune exception : le lot continue.
        $this->provisioner()->adopt($user, $client, $report, dryRun: false);

        self::assertNull(User::query()->where('login', 'p.durand')->value('nextcloud_user_id'));
        self::assertSame('compte-partage', User::query()->where('login', 'a.dupont')->value('nextcloud_user_id'));

        // …et le refus est COMPTÉ, en nommant le détenteur.
        self::assertSame(1, $report->userCounters()['echecs']);
        self::assertSame(0, $report->userCounters()['adoptes']);
        self::assertStringContainsString('a.dupont', $report->userIssues()[0]['detail']);
    }

    /** Le détenteur légitime est ré-adopté sans bruit : sa propre valeur n'est pas un conflit. */
    #[Test]
    public function the_legitimate_holder_is_re_adopted_without_conflict(): void
    {
        $this->configure();

        $user = User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        Http::fake(['*' => Http::response(self::ocs(102, [], 'User already exists'), 200)]);

        $this->provisioner()->ensureAccountAtCreation('alice', 'MotDePasseAd1!');
        $this->provisioner()->ensureAccountAtCreation('alice', 'MotDePasseAd1!');

        self::assertSame('alice', $user->fresh()->nextcloud_user_id);
    }

    // =====================================================================
    // AC6 — le cache lu par le chemin legacy
    // =====================================================================

    #[Test]
    public function the_cached_identity_is_readable_and_null_when_absent(): void
    {
        $this->configure();
        $user = User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        User::query()->create(['login' => 'bob', 'role' => 'eleve', 'is_active' => true]);

        $user->nextcloud_user_id = 'alice-nc';
        $user->saveQuietly();

        self::assertSame('alice-nc', $this->provisioner()->cachedIdentity('alice'));
        self::assertNull($this->provisioner()->cachedIdentity('bob'));
        self::assertNull($this->provisioner()->cachedIdentity('inexistant'));
    }

    /** Le cache est HORS `$fillable` : aucune assignation de masse ne l'écrit. */
    #[Test]
    public function the_identity_cache_is_not_mass_assignable(): void
    {
        $user = User::query()->create([
            'login' => 'mallory',
            'role' => 'eleve',
            'is_active' => true,
            'nextcloud_user_id' => 'usurpe',
        ]);

        self::assertNull($user->fresh()->nextcloud_user_id);
    }
}
