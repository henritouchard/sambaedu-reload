<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\Nextcloud;

use App\Services\Filesystem\Backend\Nextcloud\NextcloudTeamFolderClient;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\Nextcloud\NextcloudFailure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Story 61.3 — LE CANAL DES DOSSIERS D'ÉQUIPE, sur ses réponses MESURÉES.
 *
 * Deux pièges y sont épinglés, et ce sont les deux qui coûtent le plus cher :
 * **le code de transport ment** (un refus d'administration peut se cacher derrière
 * un `200`), et **le relu n'est pas l'envoyé** (le point de montage revient avec une
 * barre oblique que personne n'a écrite).
 */
class NextcloudTeamFolderClientTest extends TestCase
{
    private function client(): NextcloudTeamFolderClient
    {
        return new NextcloudTeamFolderClient(NextcloudConnectionConfig::fromValues(
            'https://nuage.exemple.fr',
            'admin',
            'secret-app-password',
            '',
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function ocs(int $code, array $data = [], string $message = ''): array
    {
        return ['ocs' => ['meta' => ['status' => $code < 300 ? 'ok' : 'failure', 'statuscode' => $code, 'message' => $message], 'data' => $data]];
    }

    // =========================================================================
    // Le code HTTP ment
    // =========================================================================

    /**
     * **MESURÉ : une opération d'administration refusée rend `200` avec un refus
     * DANS LE CORPS, et ne fait rien.**
     *
     * Croire le code de transport ferait rapporter « appliqué » sur un geste qui n'a
     * pas eu lieu — et l'écran serait vert pendant qu'aucun dossier n'existe.
     */
    #[Test]
    public function a_200_carrying_a_refusal_in_its_body_is_a_failure(): void
    {
        Http::fake(['*' => Http::response(self::ocs(403, [], 'Insufficient privileges'), 200)]);

        $result = $this->client()->createFolder('Classe_3A');

        self::assertTrue($result->isFailure(), 'le verdict est dans le CORPS, jamais dans le code HTTP');
        self::assertSame(NextcloudFailure::Privilege, $result->failure);
        self::assertSame(200, $result->httpStatus);
        self::assertStringContainsString('administrateur', $result->message);
    }

    /** « Existe déjà » est un ÉTAT, pas une erreur : rejouer est une opération normale. */
    #[Test]
    public function an_already_existing_group_is_conforming_not_failed(): void
    {
        Http::fake(['*' => Http::response(self::ocs(102), 200)]);

        $result = $this->client()->ensureGroup('se5_3a_member');

        self::assertFalse($result->isFailure());
        self::assertTrue($result->alreadyConforming);
    }

    // =========================================================================
    // Le relu n'est pas l'envoyé
    // =========================================================================

    /**
     * **DEUXIÈME OCCURRENCE DU MÊME PIÈGE DANS L'EPIC** : le point de montage revient
     * avec une barre oblique de tête. Comparer sur l'envoyé ferait recréer un dossier
     * à chaque passage, ou déclarer absent un dossier parfaitement en place.
     */
    #[Test]
    public function the_mount_point_is_compared_on_the_value_read_back(): void
    {
        self::assertSame('Classe_3A', NextcloudTeamFolderClient::mountPointOf(['mount_point' => '/Classe_3A']));
        self::assertSame('Classe_3A', NextcloudTeamFolderClient::mountPointOf(['mount_point' => 'Classe_3A']));
        self::assertSame('Classe_3A', NextcloudTeamFolderClient::mountPointOf(['mountpoint' => 'Classe_3A/']));
        self::assertSame('', NextcloudTeamFolderClient::mountPointOf([]));
    }

    /** L'inventaire est normalisé en liste, que l'instance rende une liste ou une table. */
    #[Test]
    public function the_folder_inventory_is_normalised_whatever_its_shape(): void
    {
        Http::fake(['*' => Http::response([
            '1' => ['id' => 1, 'mount_point' => 'Classe_3A', 'quota' => 5368709120, 'acl' => false, 'groups' => ['se5_3a_member' => 1]],
            '2' => ['id' => 2, 'mount_point' => 'TF_Classe_3B', 'quota' => -3, 'acl' => true, 'groups' => []],
        ], 200)]);

        $folders = $this->client()->listFolders()->value('folders', []);

        self::assertCount(2, $folders);
        self::assertSame(1, $folders[0]['id']);
    }

    // =========================================================================
    // La forme des écritures
    // =========================================================================

    /** Corps en FORMULAIRE, en-tête d'API posé, jamais de JSON (mesuré). */
    #[Test]
    public function writes_go_out_as_a_form_never_as_json(): void
    {
        Http::fake(['*' => Http::response(self::ocs(100, ['id' => 3]), 200)]);

        $this->client()->createFolder('Classe_3A');

        Http::assertSent(static function (Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'index.php/apps/groupfolders/folders')
                && str_contains($request->url(), 'format=json')
                && $request->hasHeader('OCS-APIRequest', 'true')
                && str_starts_with((string) $request->header('Content-Type')[0], 'application/x-www-form-urlencoded')
                && $request->body() === 'mountpoint=Classe_3A';
        });
    }

    /**
     * L'INTERRUPTEUR des permissions avancées prend un entier, et rien d'autre — la
     * route ne pose AUCUNE règle. Un booléen dans un corps de formulaire est le
     * piège mesuré de la story 61.1.
     */
    #[Test]
    public function the_advanced_permissions_toggle_sends_an_integer_never_a_boolean(): void
    {
        Http::fake(['*' => Http::response(self::ocs(100), 200)]);

        $this->client()->enableAdvancedPermissions(3);

        Http::assertSent(static function (Request $request): bool {
            return str_contains($request->url(), 'folders/3/acl')
                && $request->body() === 'acl=1';
        });
    }

    /** Le plafond d'une ZONE, en octets, sur le dossier — jamais sur un compte. */
    #[Test]
    public function the_zone_quota_is_posted_in_bytes_on_the_folder(): void
    {
        Http::fake(['*' => Http::response(self::ocs(100), 200)]);

        $this->client()->setQuota(3, 5368709120);

        Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), 'folders/3/quota')
            && $r->body() === 'quota=5368709120');
    }

    /** Les membres d'un groupe sont relus triés : la comparaison ne dépend pas de l'ordre. */
    #[Test]
    public function group_members_are_read_back_sorted(): void
    {
        Http::fake(['*' => Http::response(self::ocs(100, ['users' => ['zoe', 'alice', 'bruno']]), 200)]);

        self::assertSame(['alice', 'bruno', 'zoe'], $this->client()->groupMembers('se5_3a_member')->value('members'));
    }

    // =========================================================================
    // La surface
    // =========================================================================

    /**
     * **LA SURFACE EST FERMÉE.** Aucun partage (il ment, mesuré au spike), aucun
     * quota de COMPTE (frontière D8 : budgéter une personne appartient au
     * provisionnement des comptes). Une méthode absente ne s'appelle pas par
     * distraction.
     */
    #[Test]
    public function the_client_surface_is_closed(): void
    {
        $methods = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(NextcloudTeamFolderClient::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($methods);

        self::assertSame([
            '__construct',
            'addGroup',
            'addUserToGroup',
            'createFolder',
            'deleteFolder',
            'enableAdvancedPermissions',
            'ensureGroup',
            'groupMembers',
            'listFolders',
            'mountPointOf',
            'removeGroup',
            'removeUserFromGroup',
            'setGroupPermissions',
            'setQuota',
        ], $methods);
    }

    /**
     * **AUCUN CHEMIN DE PRODUCTION NE SUPPRIME UN DOSSIER D'ÉQUIPE** (D9).
     *
     * La méthode existe pour la seule obligation du test d'intégration : laisser
     * l'instance dans l'état où il l'a trouvée. Qu'elle existe et qu'elle ne soit
     * jamais appelée en production sont deux faits différents ; le second se
     * vérifie — précédent exact de la suppression de montage en 61.1.
     */
    #[Test]
    public function the_folder_deletion_is_never_called_by_production_code(): void
    {
        $callers = [];

        foreach (
            (new \Symfony\Component\Finder\Finder())
                ->files()
                ->in(dirname(__DIR__, 6) . '/app')
                ->name('*.php') as $file
        ) {
            if (str_contains((string) $file->getContents(), 'deleteFolder')) {
                $callers[] = str_replace(dirname(__DIR__, 6) . '/', '', (string) $file->getRealPath());
            }
        }

        self::assertSame(
            ['app/Services/Filesystem/Backend/Nextcloud/NextcloudTeamFolderClient.php'],
            $callers,
            'la révocation retire les octrois ; elle ne détruit NI le dossier NI son contenu (D9). '
            . 'Seule la déclaration de la méthode est attendue ici.',
        );
    }
}
