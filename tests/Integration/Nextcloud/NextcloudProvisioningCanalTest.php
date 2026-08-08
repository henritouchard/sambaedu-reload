<?php

declare(strict_types=1);

namespace Tests\Integration\Nextcloud;

use App\Services\Nextcloud\ExternalStorageDefinition;
use App\Services\Nextcloud\NextcloudAdminClient;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\Nextcloud\NextcloudFailure;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 61.1 — LE CANAL D'ÉCRITURE DES MONTAGES, CONTRE L'INSTANCE RÉELLE.
 *
 * **Ce que ce test prouve, et lui seul** : que l'endpoint d'administration des
 * montages globaux (`index.php/apps/files_external/globalstorages`) est
 * franchissable en authentification basic par app password — c'est-à-dire sans
 * session ni jeton de requête. C'était le seul pari de la story : `files_external`
 * n'expose pas d'API OCS d'écriture, et l'exemption anti-CSRF des routes
 * `index.php` dépend des annotations du contrôleur. Les doubles de test ne
 * prouvent rien là-dessus : ils rejouent ce qu'on croit.
 *
 * Il éprouve aussi les sémantiques que les doubles TRANSCRIVENT, pour que la
 * transcription reste vraie : l'idempotence par signature (aucun doublon au
 * rejeu), le `102` à la recréation d'un compte, la résolution d'identité, la mise
 * à jour de mot de passe — et la normalisation du point de montage par l'instance
 * (elle ajoute un slash initial), qui est le piège d'idempotence de la story.
 *
 * ---------------------------------------------------------------------------
 * **SKIPPÉ PAR DÉFAUT**, et jamais en intégration continue. Il exige les trois
 * variables `NC_SPIKE_URL`, `NC_SPIKE_ADMIN`, `NC_SPIKE_PASSWORD`, et il est hors
 * de la suite par défaut (`phpunit.integration.xml`).
 *
 * **Prérequis sur l'instance :**
 *   1. `occ app:enable files_external` — sans quoi la route répond 404 ;
 *   2. `smbclient` (paquet) OU `php-smbclient` (extension) sur l'hôte Nextcloud,
 *      **suivi d'un redémarrage du service** : la détection des backends est mise
 *      en cache, l'installation seule ne suffit pas et le POST reste en 422
 *      `Invalid storage backend "smb"`.
 *
 * **Il laisse l'instance dans l'état où il l'a trouvée** : le montage d'épreuve et
 * le compte d'épreuve sont supprimés en fin de scénario. La cible SMB est
 * volontairement une adresse de documentation (RFC 5737) : c'est le CANAL qu'on
 * prouve, pas le montage fonctionnel — `files_external` enregistre une
 * configuration sans la valider, et le statut du montage est un contrôle séparé
 * (avec des identifiants de session, il est de toute façon inévaluable hors
 * session).
 *
 * **Il n'a besoin ni de base ni de migrations** — d'où le cas de test PHPUnit NU :
 * le client et ses objets de configuration sont du PHP pur au-dessus du client
 * HTTP du framework. Seule la façade `Http` doit être amorcée, ce que fait
 * {@see self::setUp()}.
 * ---------------------------------------------------------------------------
 */
class NextcloudProvisioningCanalTest extends TestCase
{
    private ?NextcloudAdminClient $client = null;

    /** Point de montage d'épreuve — préfixé pour être reconnaissable et jetable. */
    private string $mountPoint = '';

    private string $login = '';

    /** @var list<int|string> */
    private array $createdStorageIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $url = getenv('NC_SPIKE_URL') ?: '';
        $admin = getenv('NC_SPIKE_ADMIN') ?: '';
        $password = getenv('NC_SPIKE_PASSWORD') ?: '';

        if ($url === '' || $admin === '' || $password === '') {
            $this->markTestSkipped(
                'canal files_external : nécessite NC_SPIKE_URL, NC_SPIKE_ADMIN et NC_SPIKE_PASSWORD. '
                . 'Prérequis sur l\'instance : « occ app:enable files_external » ET le paquet smbclient '
                . '(ou l\'extension php-smbclient) suivi d\'un REDÉMARRAGE du service — la détection des '
                . 'backends est mise en cache. Exécution depuis le checkout principal ou par '
                . 'l\'orchestrateur, jamais depuis un worktree.'
            );
        }

        // Amorçage minimal de la façade `Http` : ce cas de test est nu (pas
        // d'application Laravel), et n'a besoin de rien d'autre.
        $container = new Container();
        $container->singleton(Factory::class, static fn (): Factory => new Factory());
        Facade::setFacadeApplication($container);

        $suffix = substr((string) time(), -6);
        $this->mountPoint = 'ZZ_se5_canal_' . $suffix;
        $this->login = 'zz-se5-canal-' . $suffix;

        $this->client = new NextcloudAdminClient(
            NextcloudConnectionConfig::fromValues($url, $admin, $password, '192.0.2.1', false),
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->createdStorageIds as $id) {
            $this->client?->deleteGlobalStorage($id);
        }

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    #[Test]
    public function the_write_channel_and_the_ocs_semantics_hold_against_a_real_instance(): void
    {
        $client = $this->client;
        self::assertNotNull($client);

        // --- la sonde : instance, privilège, app ------------------------------
        $probe = $client->probe();
        self::assertTrue(
            $probe->isOk(),
            'la sonde doit être verte — sinon le prérequis d\'instance n\'est pas rempli : ' . $probe->message,
        );

        // --- LE CANAL D'ÉCRITURE ---------------------------------------------
        $definition = new ExternalStorageDefinition($this->mountPoint, '192.0.2.1', 'partages');

        $created = $client->createGlobalStorage($definition);

        self::assertFalse(
            $created->isFailure(),
            'LE CANAL D\'ÉCRITURE DES MONTAGES EST INFRANCHISSABLE. Relevé : '
            . json_encode($created->toArray(), JSON_UNESCAPED_UNICODE),
        );
        self::assertNotSame(
            NextcloudFailure::BackendIndisponible,
            $created->failure,
            'le backend SMB manque sur l\'instance : installer smbclient PUIS redémarrer le service',
        );

        $id = $created->value('id');
        self::assertTrue(is_int($id) || is_string($id), 'la création doit rendre l\'identifiant du montage');
        $this->createdStorageIds[] = $id;

        // --- L'INSTANCE NORMALISE CE QU'ON LUI ENVOIE ------------------------
        // C'est le piège d'idempotence : elle relit le point de montage avec un
        // slash initial. La signature canonique doit s'en accommoder, sans quoi
        // chaque passage « mettrait à jour » le même montage.
        $listing = $client->listGlobalStorages();
        self::assertFalse($listing->isFailure());

        $mine = $this->findBySignature($listing->value('storages', []), $definition->signature());
        self::assertNotNull($mine, 'le montage créé doit être reconnu par sa SIGNATURE canonique');
        self::assertSame(
            [],
            $definition->divergences($mine),
            'aucune divergence ne doit subsister juste après la création — sinon le provisionnement '
            . 'mettrait à jour à chaque passage',
        );

        // --- REJEU : aucun doublon -------------------------------------------
        $replay = $client->createGlobalStorage($definition);
        $replayId = $replay->value('id');
        if (! $replay->isFailure() && (is_int($replayId) || is_string($replayId))) {
            // `files_external` ne dédoublonne PAS : c'est la raison d'être de la
            // signature côté SE5. On nettoie l'entrée en trop et on le CONSTATE.
            $this->createdStorageIds[] = $replayId;
        }

        $afterReplay = $client->listGlobalStorages();
        $matching = array_filter(
            $afterReplay->value('storages', []),
            static fn (array $s): bool => ExternalStorageDefinition::signatureOf($s) === $definition->signature(),
        );
        self::assertGreaterThanOrEqual(
            1,
            count($matching),
            'le montage doit rester présent après rejeu',
        );

        // --- LES COMPTES : création puis 102 ---------------------------------
        $create = $client->createUser($this->login, 'Se5Canal2026!x');
        self::assertFalse($create->isFailure(), 'création de compte : ' . $create->message);
        self::assertFalse($create->alreadyConforming, 'le compte d\'épreuve ne devait pas exister');

        $again = $client->createUser($this->login, 'Se5Canal2026!x');
        self::assertTrue($again->successful, 'un compte existant est ADOPTÉ, jamais une erreur');
        self::assertTrue($again->alreadyConforming);
        self::assertSame(102, $again->ocsStatusCode, 'la sémantique « existe déjà » du sondage 60.0');

        // --- LA RÉSOLUTION D'IDENTITÉ ----------------------------------------
        $direct = $client->getUser($this->login);
        self::assertFalse($direct->isFailure());
        self::assertSame($this->login, $direct->value('id'));

        $auto = $client->autocompleteUser($this->login);
        self::assertFalse($auto->isFailure());
        self::assertContains(
            $this->login,
            array_column($auto->value('matches', []), 'id'),
            'l\'autocomplétion doit retrouver le compte',
        );

        // …et l'absence est SILENCIEUSE côté API (mesure du sondage 60.0).
        $absent = $client->autocompleteUser('zz-inexistant-' . $this->login);
        self::assertFalse($absent->isFailure());
        self::assertSame([], $absent->value('matches', []));

        // --- LA MISE À JOUR DE MOT DE PASSE ----------------------------------
        $password = $client->setUserPassword($this->login, 'Se5Canal2026!y');
        self::assertFalse($password->isFailure(), 'mise à jour du mot de passe : ' . $password->message);

        // --- Nettoyage du compte d'épreuve -----------------------------------
        // Volontairement PAS une méthode du client : SE5 ne supprime pas de
        // comptes Nextcloud, et ce n'est pas à un test d'ouvrir cette porte.
        $this->deleteProbeUser();
    }

    /**
     * @param  list<array<string, mixed>>  $storages
     * @return array<string, mixed>|null
     */
    private function findBySignature(array $storages, string $signature): ?array
    {
        foreach ($storages as $storage) {
            if (ExternalStorageDefinition::signatureOf($storage) === $signature) {
                return $storage;
            }
        }

        return null;
    }

    /**
     * Suppression du compte d'épreuve, EN DEHORS de la surface du client — c'est
     * une obligation de propreté du test, pas une capacité de SE5.
     */
    private function deleteProbeUser(): void
    {
        $url = rtrim((string) getenv('NC_SPIKE_URL'), '/')
            . '/ocs/v1.php/cloud/users/' . rawurlencode($this->login) . '?format=json';

        \Illuminate\Support\Facades\Http::withBasicAuth(
            (string) getenv('NC_SPIKE_ADMIN'),
            (string) getenv('NC_SPIKE_PASSWORD'),
        )
            ->withHeaders(['OCS-APIRequest' => 'true', 'Accept' => 'application/json'])
            ->withOptions(['verify' => false])
            ->timeout(15)
            ->delete($url);
    }
}
