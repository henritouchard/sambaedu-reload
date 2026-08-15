<?php

declare(strict_types=1);

namespace Tests\Feature\Nextcloud;

use App\Models\QuotaRule;
use App\Models\User;
use App\Services\FilePolicyService;
use App\Services\Filesystem\XfsQuotaService;
use App\Services\Nextcloud\NextcloudAdminClient;
use App\Services\Nextcloud\NextcloudClientFactory;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\Nextcloud\NextcloudProvisioningReport;
use App\Services\Nextcloud\NextcloudUserProvisioner;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CORRECTION DE REVUE 61.3 #1, **RECADRÉE PAR LA STORY 63.4** — CE QUI NE SE
 * RÉSOUT PAS NE SE DEVINE PAS.
 *
 * ---------------------------------------------------------------------------
 * **CE QUI ÉTAIT FAUX.** Le plafond d'un compte de l'instance se choisissait d'après
 * un « profil » déduit de `users.role` — une colonne qui ne garde rien dans ce
 * produit — avec un repli MUET vers le plus bas. Et les groupes passés au calcul
 * étaient toujours vides, ce qui rendait toute règle de quota par GROUPE
 * inatteignable pour un compte de l'instance.
 *
 * **LE PROFIL LUI-MÊME A DISPARU** (63.4) : le plafond par défaut est d'INSTANCE, et
 * ce qu'on demande encore à l'annuaire, ce sont les GROUPES. La doctrine, elle,
 * survit transposée : un annuaire MUET n'est pas un compte sans groupe, et ne fait
 * jamais retomber personne sur le défaut.
 *
 * **Un plafond faux est pire qu'un plafond absent** : absent, il se voit ; faux, il
 * s'applique. Ces tests épinglent les deux directions — ce qui ne s'écrit pas, et ce
 * qui s'écrit maintenant qu'il peut être résolu — plus le COÛT, qui est le troisième
 * défaut possible et le seul qui ne se voit qu'en production.
 * ---------------------------------------------------------------------------
 */
class NextcloudQuotaProfileTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://cloud.etab.fr';

    protected function setUp(): void
    {
        parent::setUp();

        FilePolicyService::setGlobal(true, true, true, self::URL, 'admin', 'se4fs', true);
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, 'sekret');
    }

    /** @param array<string, mixed> $data */
    private static function ocs(int $code, array $data = [], string $message = 'OK'): array
    {
        return ['ocs' => [
            'meta' => ['status' => $code < 300 ? 'ok' : 'failure', 'statuscode' => $code, 'message' => $message],
            'data' => $data,
        ]];
    }

    /**
     * L'instance répond à tout : la lecture d'un compte rend un plafond « aucun »,
     * ce qui rend TOUTE écriture visible (si le voulu était déjà là, on ne saurait
     * pas distinguer « rien à faire » de « rien décidé »).
     */
    private function fakeInstance(): void
    {
        Http::fake(['*' => Http::response(self::ocs(100, ['quota' => ['quota' => 'none']]), 200)]);
    }

    private function directory(array $entries): RecordingQuotaService
    {
        $fake = new RecordingQuotaService($entries);
        $this->app->instance(XfsQuotaService::class, $fake);

        return $fake;
    }

    private function user(string $login): User
    {
        $user = User::query()->create([
            'login' => $login,
            // **`role` est délibérément renseigné à contre-emploi** : s'il gouvernait
            // encore quoi que ce soit, ces tests le verraient immédiatement.
            'role' => 'autre',
            'is_active' => true,
            'source' => 'ad',
        ]);
        $user->nextcloud_user_id = $login;
        $user->saveQuietly();

        return $user->fresh();
    }

    private function rule(string $type, int $hardMb, ?string $target = null): void
    {
        QuotaRule::query()->create([
            'type' => $type,
            'target' => $target,
            'partition' => QuotaRule::PARTITION_HOME,
            'quota_soft_mb' => max(0, $hardMb - 100),
            'quota_hard_mb' => $hardMb,
            'is_active' => true,
        ]);
    }

    private function client(): NextcloudAdminClient
    {
        return app(NextcloudClientFactory::class)->make();
    }

    private function provisioner(): NextcloudUserProvisioner
    {
        return app(NextcloudUserProvisioner::class);
    }

    private static function quotaWrites(): array
    {
        $sent = [];
        Http::recorded(static function (Request $request) use (&$sent): bool {
            if ($request->method() === 'PUT' && str_contains($request->url(), '/cloud/users/')) {
                parse_str($request->body(), $form);
                $sent[] = $form;
            }

            return true;
        });

        return $sent;
    }

    // =====================================================================
    // (a) NE JAMAIS DEVINER
    // =====================================================================

    /**
     * **LE TEST CENTRAL DE LA CORRECTION.** L'annuaire ne répond pas pour ce compte :
     * ses appartenances sont INDÉTERMINABLES. Aucun plafond n'est écrit, et le cas
     * est COMPTÉ.
     *
     * ⚠️ La doctrine a survécu à la story 63.4, transposée : un annuaire muet ne
     * fait **JAMAIS** retomber un compte sur le défaut d'instance. Il pourrait être
     * couvert par une règle de groupe plus large — écrire le défaut rétrécirait son
     * plafond sans que rien ne le signale.
     */
    #[Test]
    public function an_indeterminable_identity_writes_no_quota_and_is_counted(): void
    {
        $this->rule(QuotaRule::TYPE_DEFAULT, 1024);
        $this->directory([]); // l'annuaire ne connaît personne
        $this->fakeInstance();

        $report = new NextcloudProvisioningReport();
        $this->provisioner()->adopt($this->user('m.dupont'), $this->client(), $report, dryRun: false);

        self::assertSame([], self::quotaWrites(), 'un annuaire muet n\'écrit aucun plafond, et ne retombe jamais sur le défaut');
        self::assertSame(1, $report->userCounters()['quotas_indetermines']);
        self::assertSame(['m.dupont'], $report->quotaUnresolvedLogins());

        // Le compte reste ADOPTÉ : son montage fonctionne, seul le plafond est en
        // suspens. Et ce n'est pas un échec — le code de sortie ne bascule pas.
        self::assertSame(1, $report->userCounters()['adoptes']);
        self::assertSame(0, $report->userCounters()['echecs']);
        self::assertFalse($report->hasFailures());
    }

    /** Le constat voyage jusqu'au rapport sérialisé — sinon il n'atteint personne. */
    #[Test]
    public function the_unresolved_identity_notice_survives_serialisation(): void
    {
        $this->rule(QuotaRule::TYPE_DEFAULT, 1024);
        $this->directory([]);
        $this->fakeInstance();

        $report = new NextcloudProvisioningReport();
        $this->provisioner()->adopt($this->user('m.dupont'), $this->client(), $report, dryRun: false);

        $array = $report->toArray();

        self::assertSame(1, $array['users']['quotas_indetermines']);
        self::assertSame(['m.dupont'], $array['quota_unresolved']);
    }

    /** L'échantillon nominatif est BORNÉ : une panne d'annuaire concerne tout le monde. */
    #[Test]
    public function the_nominal_sample_of_unresolved_identities_is_bounded(): void
    {
        $this->rule(QuotaRule::TYPE_DEFAULT, 1024);
        $this->directory([]);
        $this->fakeInstance();

        $report = new NextcloudProvisioningReport();
        $provisioner = $this->provisioner();

        for ($i = 0; $i < NextcloudProvisioningReport::MAX_SAMPLED_QUOTA_LOGINS + 5; $i++) {
            $provisioner->adopt($this->user('eleve' . $i), $this->client(), $report, dryRun: false);
        }

        self::assertSame(
            NextcloudProvisioningReport::MAX_SAMPLED_QUOTA_LOGINS + 5,
            $report->userCounters()['quotas_indetermines'],
            'le COMPTEUR, lui, n\'est pas borné : c\'est lui qui porte l\'ampleur',
        );
        self::assertCount(NextcloudProvisioningReport::MAX_SAMPLED_QUOTA_LOGINS, $report->quotaUnresolvedLogins());
    }

    // =====================================================================
    // (b) UNE SEULE SOURCE DE VÉRITÉ — L'ANNUAIRE
    // =====================================================================

    /**
     * ⚠️ **CE SCÉNARIO A CHANGÉ DE SENS, ET C'EST LE POINT DE LA STORY 63.4.**
     *
     * Il épinglait qu'un enseignant et un élève, sans règle nominative ni règle de
     * groupe, recevaient des plafonds DIFFÉRENTS — chacun celui de son « profil ».
     * Ce profil se devinait par comparaison de sous-chaîne sur des noms de groupes,
     * et de deux façons contradictoires selon l'écran qui posait la question.
     *
     * Le voici épinglé **positivement dans l'autre sens** : deux comptes que rien ne
     * couvre reçoivent désormais **exactement le même plafond**, celui de
     * l'instance. C'est le comportement voulu ; le supprimer en silence aurait laissé
     * l'inversion invisible.
     */
    #[Test]
    public function two_accounts_without_any_rule_now_receive_the_very_same_ceiling(): void
    {
        $this->rule(QuotaRule::TYPE_DEFAULT, 1024);
        $this->directory([
            'm.dupont' => ['groups' => ['profs', 'professeurs']],
            'j.martin' => ['groups' => ['3A']],
        ]);
        $this->fakeInstance();

        $report = new NextcloudProvisioningReport();
        $provisioner = $this->provisioner();

        $provisioner->adopt($this->user('m.dupont'), $this->client(), $report, dryRun: false);
        $provisioner->adopt($this->user('j.martin'), $this->client(), $report, dryRun: false);

        $attendu = (string) (1024 * 1024 * 1024);

        self::assertSame(
            [['key' => 'quota', 'value' => $attendu], ['key' => 'quota', 'value' => $attendu]],
            self::quotaWrites(),
            'le plafond par défaut est d\'INSTANCE : les groupes ne le font plus varier',
        );
        self::assertSame(0, $report->userCounters()['quotas_indetermines']);
    }

    /**
     * **UNE RÈGLE PAR GROUPE S'APPLIQUE ENFIN.** Elle ne le pouvait PAS : les groupes
     * passés au calcul étaient toujours vides, donc aucune règle `TYPE_GROUP` ne
     * pouvait jamais atteindre un compte Nextcloud.
     */
    #[Test]
    public function a_group_quota_rule_finally_reaches_a_nextcloud_account(): void
    {
        $this->rule(QuotaRule::TYPE_DEFAULT, 1024);
        $this->rule(QuotaRule::TYPE_GROUP, 8192, 'documentalistes');
        $this->directory(['m.dupont' => ['groups' => ['documentalistes', '3A']]]);
        $this->fakeInstance();

        $report = new NextcloudProvisioningReport();
        $this->provisioner()->adopt($this->user('m.dupont'), $this->client(), $report, dryRun: false);

        self::assertSame(
            [['key' => 'quota', 'value' => (string) (8192 * 1024 * 1024)]],
            self::quotaWrites(),
        );
    }

    /**
     * Une règle NOMINATIVE prime sur tout et ne demande AUCUN aller-retour
     * d'annuaire : refuser de l'écrire parce que l'annuaire est muet retirerait un
     * plafond parfaitement déterminé.
     */
    #[Test]
    public function a_nominative_rule_is_written_without_consulting_the_directory(): void
    {
        $this->rule(QuotaRule::TYPE_DEFAULT, 1024);
        $this->rule(QuotaRule::TYPE_USER, 512, 'm.dupont');
        $directory = $this->directory([]); // annuaire muet
        $this->fakeInstance();

        $report = new NextcloudProvisioningReport();
        $this->provisioner()->adopt($this->user('m.dupont'), $this->client(), $report, dryRun: false);

        self::assertSame(
            [['key' => 'quota', 'value' => (string) (512 * 1024 * 1024)]],
            self::quotaWrites(),
        );
        self::assertSame(0, $directory->lookups, 'une règle nominative n\'a pas besoin de l\'annuaire');
        self::assertSame(0, $report->userCounters()['quotas_indetermines']);
    }

    /** Sans AUCUNE règle, SE5 n'a pas d'opinion : rien n'est écrit (drift STRICT, inchangé). */
    #[Test]
    public function without_any_rule_nothing_is_written_and_nothing_is_counted(): void
    {
        $this->directory(['m.dupont' => ['groups' => []]]);
        $this->fakeInstance();

        $report = new NextcloudProvisioningReport();
        $this->provisioner()->adopt($this->user('m.dupont'), $this->client(), $report, dryRun: false);

        self::assertSame([], self::quotaWrites());
        self::assertSame(0, $report->userCounters()['quotas_indetermines']);
    }

    // =====================================================================
    // LE COÛT — la régression qui ne se voit qu'en production
    // =====================================================================

    /**
     * **AUCUNE RÈGLE DE QUOTA ⇒ ZÉRO ALLER-RETOUR D'ANNUAIRE, quelle que soit la
     * taille de la population.** C'est le cas courant aujourd'hui : la plupart des
     * instances ne configurent aucun quota. Sans ce garde-fou, la correction aurait
     * ajouté un aller-retour par personne à un balayage d'établissement pour un
     * résultat toujours identique (« SE5 n'a rien à dire »).
     */
    #[Test]
    public function a_sweep_without_any_quota_rule_never_touches_the_directory(): void
    {
        $directory = $this->directory([
            'a' => ['groups' => []],
            'b' => ['groups' => []],
            'c' => ['groups' => []],
        ]);
        $this->fakeInstance();

        $report = new NextcloudProvisioningReport();
        $provisioner = $this->provisioner();

        foreach (['a', 'b', 'c'] as $login) {
            $provisioner->adopt($this->user($login), $this->client(), $report, dryRun: false);
        }

        self::assertSame(0, $directory->lookups);
        self::assertSame([], self::quotaWrites());
    }

    /**
     * **LE COÛT EST BORNÉ PAR LA POPULATION, PAS PAR LE NOMBRE DE LECTURES.** Le
     * coût est celui de l'entrée d'annuaire, lue une fois par compte. Et un compte
     * revisité dans la foulée ne recoûte rien.
     */
    #[Test]
    public function a_sweep_costs_at_most_one_directory_lookup_per_account(): void
    {
        $this->rule(QuotaRule::TYPE_DEFAULT, 1024);
        $directory = $this->directory([
            'a' => ['groups' => ['3A']],
            'b' => ['groups' => ['profs']],
            'c' => ['groups' => ['admins']],
        ]);
        $this->fakeInstance();

        $report = new NextcloudProvisioningReport();
        $provisioner = $this->provisioner();

        // Chaque compte est visité DEUX fois de suite : le second passage doit être
        // gratuit côté annuaire.
        foreach (['a', 'b', 'c'] as $login) {
            $user = $this->user($login);
            $provisioner->adopt($user, $this->client(), $report, dryRun: false);
            $provisioner->adopt($user, $this->client(), $report, dryRun: false);
        }

        self::assertSame(3, $directory->lookups, 'un aller-retour par COMPTE, jamais un par lecture');
    }

    /** La simulation LIT et compte, mais n'ÉCRIT rien — y compris le plafond. */
    #[Test]
    public function a_dry_run_never_writes_a_quota(): void
    {
        $this->rule(QuotaRule::TYPE_DEFAULT, 4096);
        $this->directory(['m.dupont' => ['groups' => []]]);
        $this->fakeInstance();

        $report = new NextcloudProvisioningReport(dryRun: true);
        $this->provisioner()->adopt($this->user('m.dupont'), $this->client(), $report, dryRun: true);

        self::assertSame([], self::quotaWrites());
    }
}
