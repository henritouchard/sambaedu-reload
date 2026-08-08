<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Nextcloud;

use App\Exceptions\Nextcloud\NextcloudConfigurationException;
use App\Services\Nextcloud\ExternalStorageDefinition;
use App\Services\Nextcloud\NextcloudAdminClient;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\Nextcloud\NextcloudFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 61.1 — le client Nextcloud, éprouvé SANS RÉSEAU.
 *
 * Les fakes transcrivent des sémantiques MESURÉES, ils n'en inventent aucune :
 * enveloppe OCS `{ocs:{meta:{status,statuscode},data}}` (production SE4),
 * statuscode `102` « existe déjà » (spike 60.0), autocomplétion silencieuse en
 * l'absence (spike 60.0), refus nets `401/403/404`.
 */
class NextcloudAdminClientTest extends TestCase
{
    private const SECRET = 'ceci-est-le-secret-admin-nextcloud';

    private function config(string $url = 'https://cloud.etab.fr/'): NextcloudConnectionConfig
    {
        return NextcloudConnectionConfig::fromValues($url, 'admin', self::SECRET, 'se4fs');
    }

    private function client(string $url = 'https://cloud.etab.fr/'): NextcloudAdminClient
    {
        return new NextcloudAdminClient($this->config($url));
    }

    /** @param array<string, mixed> $data */
    private static function ocs(int $statuscode, array $data = [], string $message = 'OK'): array
    {
        return ['ocs' => [
            'meta' => ['status' => $statuscode < 300 ? 'ok' : 'failure', 'statuscode' => $statuscode, 'message' => $message],
            'data' => $data,
        ]];
    }

    // =====================================================================
    // AC1 / AC2 — normalisation, en-têtes, secret
    // =====================================================================

    #[Test]
    public function the_base_url_tolerates_a_trailing_slash_and_never_doubles_it(): void
    {
        Http::fake(['*' => Http::response(self::ocs(100), 200)]);

        $this->client('https://cloud.etab.fr/')->getUser('alice');

        Http::assertSent(static fn (Request $r): bool => str_starts_with(
            $r->url(),
            'https://cloud.etab.fr/ocs/v1.php/cloud/users/alice?',
        ));
    }

    #[Test]
    public function a_url_without_scheme_is_refused_by_name_before_any_call(): void
    {
        Http::fake();

        try {
            NextcloudConnectionConfig::fromValues('cloud.etab.fr', 'admin', 'x', 'se4fs');
            self::fail('une URL sans schéma doit être refusée');
        } catch (NextcloudConfigurationException $e) {
            self::assertStringContainsString('schéma est requis', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function an_incomplete_configuration_names_each_missing_setting(): void
    {
        try {
            NextcloudConnectionConfig::fromValues('', '', '', '');
            self::fail('une configuration vide doit être refusée');
        } catch (NextcloudConfigurationException $e) {
            // TROIS éléments de connexion, pas quatre : l'hôte SMB n'en est pas
            // un (revue #1) — il n'est jamais nommé comme un manque.
            self::assertCount(3, $e->missing);
            self::assertStringContainsString('l\'URL du serveur Nextcloud', $e->getMessage());
            self::assertStringContainsString('l\'identifiant du compte admin', $e->getMessage());
            self::assertStringContainsString('l\'app password admin', $e->getMessage());
            self::assertStringNotContainsString('SMB', $e->getMessage());
        }
    }

    /**
     * Revue #1 — L'HÔTE SMB NE BLOQUE PAS LA CONNEXION.
     *
     * L'écran le déclare `nullable` et sans astérisque ; le laisser vide est donc
     * un geste que l'interface INVITE à faire. Tant qu'il faisait échouer la
     * construction, `makeOrNull()` avalait le refus et la création de compte comme
     * la propagation de mot de passe devenaient définitivement muettes.
     */
    #[Test]
    public function an_empty_smb_host_does_not_prevent_connecting(): void
    {
        Http::fake(['*' => Http::response(self::ocs(100), 200)]);

        $config = NextcloudConnectionConfig::fromValues('https://cloud.etab.fr', 'admin', self::SECRET, '');

        self::assertSame('', $config->smbHost);

        (new NextcloudAdminClient($config))->getUser('alice');

        Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), '/ocs/v1.php/cloud/users/alice'));
    }

    #[Test]
    public function every_call_carries_the_ocs_header_and_the_admin_basic_auth(): void
    {
        Http::fake(['*' => Http::response(self::ocs(100), 200)]);

        $this->client()->getUser('alice');

        Http::assertSent(static function (Request $r): bool {
            return $r->hasHeader('OCS-APIRequest', 'true')
                && $r->hasHeader('Authorization', 'Basic ' . base64_encode('admin:' . self::SECRET));
        });
    }

    #[Test]
    public function ocs_calls_ask_for_json_so_the_response_is_never_xml(): void
    {
        Http::fake(['*' => Http::response(self::ocs(100), 200)]);

        $this->client()->getUser('alice');

        Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), 'format=json'));
    }

    /** AC1 : le secret n'apparaît dans AUCUN message d'erreur rendu. */
    #[Test]
    public function no_failure_message_ever_contains_the_secret(): void
    {
        $messages = [];

        Http::fake(['*' => Http::response('nope', 403)]);
        $messages[] = $this->client()->getUser('alice')->message;
        $messages[] = $this->client()->listGlobalStorages()->message;
        $messages[] = $this->client()->probe()->message;

        Http::fake(['*' => Http::response('boom', 500)]);
        $messages[] = $this->client()->createUser('alice', 'MotDePasseAd!')->message;

        Http::fake(['*' => static fn (): never => throw new ConnectionException('cURL error 6: could not resolve host')]);
        $messages[] = $this->client()->getUser('alice')->message;
        $messages[] = $this->client()->probe()->message;

        foreach ($messages as $message) {
            self::assertStringNotContainsString(self::SECRET, $message);
            self::assertStringNotContainsString('MotDePasseAd!', $message);
        }
    }

    // =====================================================================
    // AC1 / AC9 — les trois diagnostics de la sonde
    // =====================================================================

    #[Test]
    public function the_probe_reports_an_unreachable_instance(): void
    {
        Http::fake(['*' => static fn (): never => throw new ConnectionException('cURL error 7')]);

        $probe = $this->client()->probe();

        self::assertFalse($probe->isOk());
        self::assertFalse($probe->reachable);
        self::assertSame(NextcloudFailure::Injoignable, $probe->failure);
        self::assertStringContainsString('injoignable', $probe->message);
    }

    #[Test]
    public function the_probe_reports_an_insufficient_privilege(): void
    {
        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response('forbidden', 403),
        ]);

        $probe = $this->client()->probe();

        self::assertFalse($probe->isOk());
        self::assertTrue($probe->reachable);
        self::assertFalse($probe->administrator);
        self::assertSame(NextcloudFailure::Privilege, $probe->failure);
        self::assertStringContainsString('administrateur', $probe->message);
    }

    #[Test]
    public function the_probe_reports_a_missing_external_storage_app(): void
    {
        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response('not found', 404),
        ]);

        $probe = $this->client()->probe();

        self::assertFalse($probe->isOk());
        self::assertTrue($probe->administrator);
        self::assertFalse($probe->externalStorageEnabled);
        self::assertStringContainsString('files_external', $probe->message);
    }

    /**
     * Le scénario de la RÈGLE D'ARRÊT de l'AC10 : le canal d'écriture refusé pour
     * cause de protection anti-CSRF sur route `index.php`. Il doit être NOMMÉ,
     * avec son code — c'est ce qui permet d'escalader sur des faits.
     */
    #[Test]
    public function the_probe_names_a_csrf_refusal_with_its_code(): void
    {
        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response('precondition failed', 412),
        ]);

        $probe = $this->client()->probe();

        self::assertFalse($probe->isOk());
        self::assertSame(412, $probe->httpStatus);
        self::assertStringContainsString('CSRF', $probe->message);
    }

    #[Test]
    public function the_probe_is_green_when_the_three_conditions_hold(): void
    {
        Http::fake([
            '*/ocs/v2.php/cloud/capabilities*' => Http::response(self::ocs(100), 200),
            '*/globalstorages*' => Http::response([], 200),
        ]);

        self::assertTrue($this->client()->probe()->isOk());
    }

    #[Test]
    public function bad_credentials_are_reported_as_a_privilege_failure_not_as_unreachable(): void
    {
        Http::fake(['*/ocs/v2.php/cloud/capabilities*' => Http::response('unauthorized', 401)]);

        $probe = $this->client()->probe();

        self::assertTrue($probe->reachable);
        self::assertSame(NextcloudFailure::Privilege, $probe->failure);
    }

    // =====================================================================
    // AC2 — les sémantiques OCS traduites
    // =====================================================================

    #[Test]
    public function creating_a_user_that_already_exists_is_conforming_never_a_failure(): void
    {
        Http::fake(['*' => Http::response(self::ocs(102, [], 'User already exists'), 200)]);

        $result = $this->client()->createUser('alice', 'MotDePasse1!');

        self::assertTrue($result->successful);
        self::assertTrue($result->alreadyConforming);
        self::assertFalse($result->isFailure());
        self::assertSame(102, $result->ocsStatusCode);
    }

    #[Test]
    public function creating_a_user_that_did_not_exist_is_a_plain_success(): void
    {
        Http::fake(['*' => Http::response(self::ocs(100), 200)]);

        $result = $this->client()->createUser('alice', 'MotDePasse1!');

        self::assertTrue($result->successful);
        self::assertFalse($result->alreadyConforming);

        Http::assertSent(static function (Request $r): bool {
            return $r->method() === 'POST'
                && str_contains($r->url(), '/ocs/v1.php/cloud/users')
                && $r['userid'] === 'alice';
        });
    }

    #[Test]
    public function an_ocs_privilege_refusal_names_the_operation_and_the_missing_privilege(): void
    {
        Http::fake(['*' => Http::response(self::ocs(997, [], 'Unauthorised'), 200)]);

        $result = $this->client()->createUser('alice', 'x');

        self::assertTrue($result->isPrivilegeFailure());
        self::assertStringContainsString('création du compte nextcloud « alice »', mb_strtolower($result->message));
        self::assertStringContainsString('administrateur de l\'instance', $result->message);
    }

    #[Test]
    public function an_ocs_missing_target_is_distinct_from_a_privilege_refusal(): void
    {
        Http::fake(['*' => Http::response(self::ocs(998, [], 'The requested user could not be found'), 200)]);

        $result = $this->client()->getUser('inconnu');

        self::assertSame(NextcloudFailure::Absent, $result->failure);
    }

    #[Test]
    public function an_unreadable_response_is_its_own_failure(): void
    {
        Http::fake(['*' => Http::response('<html>maintenance</html>', 200)]);

        self::assertSame(NextcloudFailure::Illisible, $this->client()->getUser('alice')->failure);
    }

    #[Test]
    public function a_connection_error_is_reported_as_unreachable(): void
    {
        Http::fake(['*' => static fn (): never => throw new ConnectionException('timeout')]);

        self::assertSame(NextcloudFailure::Injoignable, $this->client()->getUser('alice')->failure);
    }

    // =====================================================================
    // AC6 — la résolution d'identité
    // =====================================================================

    #[Test]
    public function autocomplete_returns_only_user_matches(): void
    {
        Http::fake(['*' => Http::response(self::ocs(200, [
            ['id' => 'alice', 'source' => 'users'],
            ['id' => 'classe3a', 'source' => 'groups'],
        ]), 200)]);

        $result = $this->client()->autocompleteUser('alice');

        self::assertSame([['id' => 'alice', 'source' => 'users']], $result->value('matches'));
    }

    /** Mesure du spike 60.0 : un login inconnu rend ZÉRO résultat, pas une erreur. */
    #[Test]
    public function autocomplete_on_an_unknown_login_is_empty_and_not_an_error(): void
    {
        Http::fake(['*' => Http::response(self::ocs(200, []), 200)]);

        $result = $this->client()->autocompleteUser('fantome');

        self::assertFalse($result->isFailure());
        self::assertSame([], $result->value('matches'));
    }

    #[Test]
    public function setting_a_password_uses_the_key_value_put_of_the_legacy_pattern(): void
    {
        Http::fake(['*' => Http::response(self::ocs(200), 200)]);

        $this->client()->setUserPassword('alice', 'NouveauMdp1!');

        Http::assertSent(static function (Request $r): bool {
            return $r->method() === 'PUT'
                && str_contains($r->url(), '/ocs/v2.php/cloud/users/alice')
                && $r['key'] === 'password';
        });
    }

    // =====================================================================
    // AC3 — les montages
    // =====================================================================

    /**
     * Corps en `application/x-www-form-urlencoded` — la forme MESURÉE le
     * 2026-08-08 (`backendOptions[host]=…`).
     */
    #[Test]
    public function creating_a_storage_posts_the_smb_backend_with_session_credentials_as_a_form(): void
    {
        Http::fake(['*' => Http::response(['id' => 4], 201)]);

        $definition = new ExternalStorageDefinition('Documents', 'se4fs', 'users', '$user');
        $this->client()->createGlobalStorage($definition);

        Http::assertSent(static function (Request $r): bool {
            return $r->method() === 'POST'
                && str_contains($r->url(), '/index.php/apps/files_external/globalstorages')
                && str_contains((string) $r->header('Content-Type')[0], 'application/x-www-form-urlencoded')
                && $r['backend'] === 'smb'
                && $r['authMechanism'] === 'password::sessioncredentials'
                && $r['backendOptions']['host'] === 'se4fs'
                && $r['backendOptions']['share'] === 'users'
                && $r['backendOptions']['root'] === '$user'
                && $r['priority'] === 100
                // Sur le fil, l'encodage de formulaire fait disparaître les listes
                // vides — ce que Nextcloud lit comme « aucune restriction ». On le
                // CONSTATE ici plutôt que de le supposer.
                && ! str_contains($r->body(), 'applicable');
        });
    }

    /**
     * L'applicabilité « à tous » est déclarée dans la définition. Sur le fil,
     * l'encodage de formulaire fait disparaître les listes vides — et c'est
     * exactement ce que Nextcloud lit comme « aucune restriction ». L'intention
     * doit donc être vérifiable dans le code, pas seulement dans le corps HTTP.
     */
    #[Test]
    public function the_definition_declares_no_applicability_restriction_at_all(): void
    {
        $payload = (new ExternalStorageDefinition('Partages', 'se4fs', 'partages'))->toPayload();

        self::assertSame([], $payload['applicableUsers']);
        self::assertSame([], $payload['applicableGroups']);
        self::assertArrayNotHasKey('mountOptions', $payload, 'le repartage reste au défaut de l\'instance');
        self::assertSame('password::sessioncredentials', $payload['authMechanism']);
    }

    /**
     * Aucune valeur booléenne ne part sur le fil : en formulaire, `false`
     * s'encode en chaîne et l'instance la relit VRAIE (mesuré). Un booléen envoyé
     * ici serait un réglage qu'on croit poser et qui vaut l'inverse.
     */
    #[Test]
    public function the_storage_payload_carries_no_boolean_value(): void
    {
        foreach (ExternalStorageDefinition::canonicalSet('se4fs') as $definition) {
            $payload = $definition->toPayload();
            array_walk_recursive(
                $payload,
                static function (mixed $value): void {
                    self::assertFalse(is_bool($value), 'aucun booléen ne doit partir en formulaire');
                },
            );
        }
    }

    /**
     * LE QUATRIÈME DIAGNOSTIC (mesuré 2026-08-08) : app active, compte admin, et
     * pourtant `422 Invalid storage backend "smb"` — il faut `smbclient` sur
     * l'hôte, ET un redémarrage du service.
     */
    #[Test]
    public function a_missing_smb_backend_on_the_instance_is_its_own_named_diagnostic(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Invalid storage backend "smb"'], 422)]);

        $result = $this->client()->createGlobalStorage(new ExternalStorageDefinition('Partages', 'se4fs', 'partages'));

        self::assertSame(NextcloudFailure::BackendIndisponible, $result->failure);
        self::assertStringContainsString('smbclient', $result->message);
        self::assertStringContainsString('redémarrez le service', $result->message);
    }

    /** Un 422 pour une AUTRE raison ne doit pas usurper le diagnostic ci-dessus. */
    #[Test]
    public function another_422_stays_a_plain_refusal(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Storage name too long'], 422)]);

        $result = $this->client()->createGlobalStorage(new ExternalStorageDefinition('Partages', 'se4fs', 'partages'));

        self::assertSame(NextcloudFailure::Refus, $result->failure);
    }

    #[Test]
    public function listing_storages_normalises_the_payload_into_a_list(): void
    {
        Http::fake(['*' => Http::response([
            '3' => ['id' => 3, 'mountPoint' => 'Partages'],
            '7' => ['id' => 7, 'mountPoint' => 'Documents'],
        ], 200)]);

        $result = $this->client()->listGlobalStorages();

        self::assertFalse($result->isFailure());
        self::assertCount(2, $result->value('storages'));
        self::assertSame(3, $result->value('storages')[0]['id']);
    }

    #[Test]
    public function a_forbidden_on_the_storage_endpoint_is_a_named_privilege_failure(): void
    {
        Http::fake(['*' => Http::response('nope', 403)]);

        $result = $this->client()->listGlobalStorages();

        self::assertTrue($result->isPrivilegeFailure());
        self::assertStringContainsString('privilège requis', $result->message);
    }
}
