<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Nextcloud;

use App\Services\Nextcloud\NextcloudDelegateClient;
use App\Services\Nextcloud\NextcloudDelegateConfig;
use App\Services\Nextcloud\NextcloudFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 61.2 — AC3 : la sonde du mode délégué, EN LECTURE SEULE, avec des
 * diagnostics distincts.
 *
 * ---------------------------------------------------------------------------
 * **LES DOUBLES REJOUENT DES CODES MESURÉS**, pas ce que le code émet. Relevés du
 * 2026-08-08 sur `nc-spike` (Nextcloud 34.0.2) avec un compte ORDINAIRE :
 *   `PROPFIND` profondeur 0 sur l'espace du porteur → **207** (Multi-Status,
 *   surtout pas 200) ; les endpoints d'administration → **403**.
 * Un double qui rejouerait `200` aurait validé une sonde qui échoue en vrai.
 * ---------------------------------------------------------------------------
 */
class NextcloudDelegateClientTest extends TestCase
{
    private const SECRET = 'AppPasswordPorteurTresSecret';

    private function client(): NextcloudDelegateClient
    {
        return new NextcloudDelegateClient(
            NextcloudDelegateConfig::fromValues('https://cloud.etab.fr/', 'se5porteur', self::SECRET),
        );
    }

    /** Enveloppe de capacités OCS, forme réelle. */
    private static function capabilities(bool $sharingEnabled): array
    {
        return ['ocs' => [
            'meta' => ['status' => 'ok', 'statuscode' => 100, 'message' => 'OK'],
            'data' => [
                'version' => ['major' => 34, 'minor' => 0, 'micro' => 2, 'string' => '34.0.2'],
                'capabilities' => [
                    'core' => ['pollinterval' => 60],
                    'files_sharing' => ['api_enabled' => $sharingEnabled, 'resharing' => true],
                ],
            ],
        ]];
    }

    // =====================================================================
    // Les trois diagnostics
    // =====================================================================

    #[Test]
    public function a_green_probe_states_what_it_could_not_observe(): void
    {
        Http::fake([
            '*/remote.php/dav/files/se5porteur/' => Http::response('<d:multistatus/>', 207),
            '*/ocs/v1.php/cloud/capabilities*' => Http::response(self::capabilities(true), 200),
        ]);

        $probe = $this->client()->probe();

        self::assertTrue($probe->isOk());
        self::assertTrue($probe->authenticated);
        self::assertTrue($probe->sharingEnabled);

        // L'inobservable est DIT : aucune écriture d'épreuve n'a été émise, donc la
        // capacité réelle d'écrire n'est pas affirmée.
        self::assertStringContainsString('ne peut PAS établir sans écrire', $probe->message);
        self::assertStringContainsString('premier usage réel', $probe->message);
    }

    #[Test]
    public function an_unreachable_instance_is_its_own_diagnostic(): void
    {
        Http::fake(['*' => static fn (): never => throw new ConnectionException('cURL error 7: Failed to connect')]);

        $probe = $this->client()->probe();

        self::assertFalse($probe->isOk());
        self::assertFalse($probe->reachable);
        self::assertSame(NextcloudFailure::Injoignable, $probe->failure);
        self::assertStringContainsString('injoignable', $probe->message);
    }

    #[Test]
    public function refused_delegate_credentials_are_their_own_diagnostic(): void
    {
        Http::fake(['*/remote.php/dav/files/se5porteur/' => Http::response('', 401)]);

        $probe = $this->client()->probe();

        self::assertFalse($probe->isOk());
        self::assertTrue($probe->reachable, 'l\'instance a répondu : elle n\'est pas injoignable');
        self::assertFalse($probe->authenticated);
        self::assertStringContainsString('compte porteur', $probe->message);
        self::assertStringContainsString('app password', $probe->message);
    }

    /** Un identifiant de porteur qui ne désigne aucun compte : cause distincte. */
    #[Test]
    public function an_unknown_delegate_identifier_says_so(): void
    {
        Http::fake(['*/remote.php/dav/files/se5porteur/' => Http::response('', 404)]);

        $probe = $this->client()->probe();

        self::assertFalse($probe->authenticated);
        self::assertStringContainsString('aucun espace de fichiers', $probe->message);
        self::assertSame(404, $probe->httpStatus);
    }

    #[Test]
    public function a_disabled_sharing_api_is_its_own_diagnostic(): void
    {
        Http::fake([
            '*/remote.php/dav/files/se5porteur/' => Http::response('<d:multistatus/>', 207),
            '*/ocs/v1.php/cloud/capabilities*' => Http::response(self::capabilities(false), 200),
        ]);

        $probe = $this->client()->probe();

        self::assertFalse($probe->isOk());
        self::assertTrue($probe->authenticated, 'le porteur s\'authentifie : ce n\'est pas un refus d\'identifiants');
        self::assertFalse($probe->sharingEnabled);
        self::assertStringContainsString('partage est désactivé', $probe->message);
    }

    /** Enveloppe illisible : on ne PRÉSUME pas que le partage est actif. */
    #[Test]
    public function an_unreadable_capabilities_payload_is_not_taken_for_a_green_light(): void
    {
        Http::fake([
            '*/remote.php/dav/files/se5porteur/' => Http::response('<d:multistatus/>', 207),
            '*/ocs/v1.php/cloud/capabilities*' => Http::response(['unexpected' => true], 200),
        ]);

        self::assertFalse($this->client()->probe()->isOk());
    }

    #[Test]
    public function the_three_causes_produce_three_distinct_messages(): void
    {
        $diagnose = function (array $stubs): string {
            Http::swap(new \Illuminate\Http\Client\Factory());
            Http::fake($stubs);

            return $this->client()->probe()->message;
        };

        $messages = [
            'injoignable' => $diagnose(['*' => static fn (): never => throw new ConnectionException('cURL error 7')]),
            'identifiants' => $diagnose(['*/remote.php/dav/files/se5porteur/' => Http::response('', 401)]),
            'partage' => $diagnose([
                '*/remote.php/dav/files/se5porteur/' => Http::response('<d:multistatus/>', 207),
                '*/ocs/v1.php/cloud/capabilities*' => Http::response(self::capabilities(false), 200),
            ]),
        ];

        self::assertCount(3, array_unique($messages), 'trois causes, trois messages distincts');

        foreach ($messages as $message) {
            self::assertStringNotContainsString(self::SECRET, $message);
        }
    }

    // =====================================================================
    // La forme des appels
    // =====================================================================

    #[Test]
    public function the_probe_only_reads_and_carries_the_delegate_auth(): void
    {
        Http::fake([
            '*/remote.php/dav/files/se5porteur/' => Http::response('<d:multistatus/>', 207),
            '*/ocs/v1.php/cloud/capabilities*' => Http::response(self::capabilities(true), 200),
        ]);

        $this->client()->probe();

        Http::assertSentCount(2);

        Http::assertSent(static function (Request $request): bool {
            if (! str_contains($request->url(), 'remote.php/dav/files/se5porteur/')) {
                return false;
            }

            return $request->method() === 'PROPFIND'
                && $request->header('Depth') === ['0']
                && $request->header('OCS-APIRequest') === ['true']
                && $request->hasHeader('Authorization', 'Basic ' . base64_encode('se5porteur:' . self::SECRET));
        });

        // Aucune écriture : ni POST, ni PUT, ni MKCOL, ni DELETE. Sonder ne modifie
        // jamais l'instance — la leçon de l'enregistrement DNS effacé par un test
        // « inoffensif ».
        Http::assertNotSent(static fn (Request $r): bool => in_array(
            $r->method(),
            ['POST', 'PUT', 'DELETE', 'MKCOL', 'PATCH', 'MOVE'],
            true,
        ));
    }

    #[Test]
    public function an_incomplete_delegate_configuration_names_what_is_missing(): void
    {
        Http::fake();

        try {
            NextcloudDelegateConfig::fromValues('https://cloud.etab.fr', '', '');
            self::fail('une configuration incomplète doit être refusée');
        } catch (\App\Exceptions\Nextcloud\NextcloudConfigurationException $e) {
            self::assertStringContainsString('l\'identifiant du compte porteur', $e->getMessage());
            self::assertStringContainsString('l\'app password du compte porteur', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    // =====================================================================
    // AC7 — la vérification d'identité en compte ordinaire
    // =====================================================================

    /** @param list<array{id: string, source: string}> $entries */
    private static function autocomplete(array $entries): array
    {
        return ['ocs' => [
            'meta' => ['status' => 'ok', 'statuscode' => 200, 'message' => 'OK'],
            'data' => $entries,
        ]];
    }

    #[Test]
    public function an_exact_identifier_is_confirmed(): void
    {
        Http::fake(['*/core/autocomplete/get*' => Http::response(self::autocomplete([
            ['id' => 'p.durand-martin', 'source' => 'users', 'label' => 'Paul Durand-Martin'],
            ['id' => 'p.durand', 'source' => 'users', 'label' => 'Pierre Durand'],
        ]), 200)]);

        $result = $this->client()->findUserByExactId('p.durand');

        self::assertTrue($result->successful);
        self::assertSame('p.durand', $result->value('id'));
    }

    /**
     * **Le scénario de sécurité de la revue 61.1, côté délégué** : un unique
     * candidat qui n'est pas l'homonyme n'est PAS une identité.
     */
    #[Test]
    public function a_single_non_homonym_candidate_is_never_confirmed(): void
    {
        Http::fake(['*/core/autocomplete/get*' => Http::response(self::autocomplete([
            ['id' => 'p.durand-martin', 'source' => 'users', 'label' => 'Paul Durand-Martin'],
        ]), 200)]);

        $result = $this->client()->findUserByExactId('p.durand');

        self::assertTrue($result->isFailure());
        self::assertSame(NextcloudFailure::Absent, $result->failure);
        self::assertStringContainsString('exactement', $result->message);
    }

    /** L'absence est silencieuse côté API (spike 60.0) — jamais côté SE5. */
    #[Test]
    public function an_empty_autocomplete_is_a_named_failure(): void
    {
        Http::fake(['*/core/autocomplete/get*' => Http::response(self::autocomplete([]), 200)]);

        $result = $this->client()->findUserByExactId('inconnu');

        self::assertTrue($result->isFailure());
        self::assertSame(NextcloudFailure::Absent, $result->failure);
    }

    /** L'instance fait autorité sur l'orthographe de ses comptes. */
    #[Test]
    public function the_identifier_is_returned_as_the_instance_spells_it(): void
    {
        Http::fake(['*/core/autocomplete/get*' => Http::response(self::autocomplete([
            ['id' => 'P.Durand', 'source' => 'users'],
        ]), 200)]);

        self::assertSame('P.Durand', $this->client()->findUserByExactId('p.durand')->value('id'));
    }

    #[Test]
    public function a_refused_delegate_account_is_a_privilege_failure_without_the_secret(): void
    {
        Http::fake(['*/core/autocomplete/get*' => Http::response('', 401)]);

        $result = $this->client()->findUserByExactId('p.durand');

        self::assertSame(NextcloudFailure::Privilege, $result->failure);
        self::assertStringNotContainsString(self::SECRET, $result->message);
    }
}
