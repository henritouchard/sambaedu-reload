<?php

declare(strict_types=1);

namespace Tests\Unit\Services\OpenCloud;

use App\Services\OpenCloud\OpenCloudConnectionConfig;
use App\Services\OpenCloud\OpenCloudFailure;
use App\Services\OpenCloud\OpenCloudGraphTransport;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LE TRANSPORT, ÉPROUVÉ SUR LES CORPS **MESURÉS** LE 2026-08-13.
 *
 * Chaque cas de ce fichier rejoue une réponse relevée contre l'instance réelle, à
 * la virgule près. C'est la règle qui protège du défaut le plus coûteux de ce
 * chantier — *un double bâti sur les intentions du code se valide lui-même* : un
 * corps qui n'a pas de ligne correspondante au relevé est un défaut, pas un
 * raccourci.
 */
class OpenCloudGraphTransportTest extends TestCase
{
    private function transport(): OpenCloudGraphTransport
    {
        return new OpenCloudGraphTransport(
            OpenCloudConnectionConfig::fromValues('https://nuage.exemple.fr', 'admin', 'secret'),
        );
    }

    /**
     * **UN `409` EST UN SUCCÈS — le piège SYMÉTRIQUE de celui de l'autre produit.**
     *
     * Là-bas, un `200` enveloppait un refus ; ici, un code d'échec enveloppe un
     * état CONFORME. Le prendre pour un échec ferait rapporter rouge une zone
     * parfaitement provisionnée, et le rejeu ne convergerait jamais.
     */
    #[Test]
    public function an_already_exists_conflict_is_a_conforming_state_not_a_failure(): void
    {
        Http::fake(['*' => Http::response([
            'error' => [
                'code' => 'nameAlreadyExists',
                'innererror' => ['date' => '2026-08-13T13:46:14Z', 'request-id' => 'a8e0a0eb4efb/s5Roy5OHOr-000032'],
                'message' => 'group already exists',
            ],
        ], 409)]);

        $result = $this->transport()->post('graph/v1.0/groups', ['displayName' => 'se5_3a'], 'création du groupe');

        self::assertFalse($result->isFailure());
        self::assertTrue($result->alreadyConforming);
        self::assertNull($result->failure);
    }

    /** Même sémantique pour un octroi rejoué — le corps mesuré est différent, le verdict non. */
    #[Test]
    public function a_replayed_invitation_conflict_is_also_conforming(): void
    {
        Http::fake(['*' => Http::response([
            'error' => [
                'code' => 'nameAlreadyExists',
                'message' => 'error creating share: error: already exists: resource_id:{storage_id:"39cc9ee5" '
                    . 'opaque_id:"0fee2937"} grantee:{type:GRANTEE_TYPE_GROUP}',
            ],
        ], 409)]);

        $result = $this->transport()->post('graph/v1beta1/drives/x/root/invite', [], 'pose d\'un octroi');

        self::assertTrue($result->alreadyConforming);
    }

    /**
     * **UN `409` SANS SON CODE APPLICATIF N'EST PAS « DÉJÀ CONFORME ».**
     *
     * Le chemin d'exploitation est concret : la modification d'un octroi passe par
     * `PATCH …/permissions/{id}`. Si un `409` y valait « conforme » sur le seul
     * statut, le nœud rendrait **`applique`** sur un octroi resté ce qu'il était,
     * sans relecture — un mensonge qui dure un cycle, et que rien dans le rapport
     * ne signale.
     */
    #[Test]
    public function a_conflict_without_its_application_code_is_a_refusal_not_a_conforming_state(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 'invalidRequest', 'message' => 'conflicting operation in progress'],
        ], 409)]);

        $result = $this->transport()->patch(
            'graph/v1beta1/drives/x/root/permissions/y',
            ['roles' => ['a8d5fe5e-96e3-418d-825b-534dbdf22b99']],
            'modification d\'un octroi',
        );

        self::assertTrue($result->isFailure(), 'un 409 non typé NE DOIT PAS passer pour un état conforme');
        self::assertFalse($result->alreadyConforming);
        self::assertSame(OpenCloudFailure::Refus, $result->failure);
    }

    /**
     * **`404 page not found` N'EST PAS `404 itemNotFound` — et c'est le sens
     * DANGEREUX.**
     *
     * Une route erronée rend un corps en **texte brut** : `json()` donne `null`,
     * donc aucun code applicatif. Si le statut décidait seul, `delete()` — dont
     * l'absence vaut conforme — rendrait « rien à retirer, déjà absent », et la
     * révocation conclurait « aucun octroi de ce plan n'était en place » sur des
     * accès parfaitement intacts. Le fail-OPEN sur une révocation est le pire des
     * deux sens.
     */
    #[Test]
    public function a_plain_text_route_not_found_is_never_read_as_already_absent(): void
    {
        Http::fake(['*' => Http::response('404 page not found', 404)]);

        $result = $this->transport()->delete('graph/v1.0/drives/x/root/permissions/y', 'retrait d\'un octroi');

        self::assertTrue($result->isFailure(), 'une route erronée NE DOIT PAS valoir « déjà absent »');
        self::assertFalse($result->alreadyConforming);
        self::assertNotSame(OpenCloudFailure::Absent, $result->failure);
    }

    /**
     * **UN RETRAIT DE CE QUI EST DÉJÀ ABSENT EST CONFORME.** Mesuré :
     * `404 itemNotFound`. « Il n'y avait rien à retirer » EST l'état voulu.
     */
    #[Test]
    public function deleting_something_already_absent_is_conforming(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 'itemNotFound', 'message' => 'error: not found: opaque_id:"inexistant-0000"'],
        ], 404)]);

        $result = $this->transport()->delete('graph/v1beta1/drives/x/root/permissions/y', 'retrait d\'un octroi');

        self::assertFalse($result->isFailure());
        self::assertTrue($result->alreadyConforming);
    }

    /** Mais un `404` sur une LECTURE reste une cible absente, pas un état conforme. */
    #[Test]
    public function a_missing_target_on_a_read_is_an_absent_failure(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 'itemNotFound', 'message' => 'stat: error: not found: '],
        ], 404)]);

        $result = $this->transport()->get('graph/v1beta1/drives/x/items/y/permissions', 'lecture des octrois');

        self::assertTrue($result->isFailure());
        self::assertSame(OpenCloudFailure::Absent, $result->failure);
    }

    /**
     * **LE RÔLE DE LA MAUVAISE FAMILLE EST UN DÉFAUT DE TRADUCTION, PAS UNE PANNE.**
     *
     * Il MÉRITE son propre cas : sa correction est dans NOTRE code, et le confondre
     * avec un « refus de l'instance » enverrait l'exploitant chercher sur son
     * serveur ce qui est chez nous.
     */
    #[Test]
    public function a_role_from_the_wrong_family_is_named_as_such(): void
    {
        Http::fake(['*' => Http::response([
            'error' => [
                'code' => 'invalidRequest',
                'message' => 'role not applicable to this resource',
            ],
        ], 400)]);

        $result = $this->transport()->post('graph/v1beta1/drives/x/root/invite', [], 'pose d\'un octroi');

        self::assertSame(OpenCloudFailure::RoleInapplicable, $result->failure);
        self::assertStringContainsString('deux familles de rôles disjointes', $result->message);
    }

    /**
     * **LE CORPS DE LISTE A DEUX FORMES, ET LA SECONDE EST UN TABLEAU NU.** Mesuré
     * sur les définitions de rôles : pas d'enveloppe `{"value":…}`. Un appelant qui
     * ne lirait que l'enveloppe verrait une liste vide et ne s'en apercevrait
     * jamais.
     */
    #[Test]
    public function a_bare_json_array_is_read_as_a_collection(): void
    {
        Http::fake(['*' => Http::response([
            ['@libre.graph.weight' => 10, 'displayName' => 'Can view', 'id' => 'b1e2218d'],
            ['@libre.graph.weight' => 60, 'displayName' => 'Can edit', 'id' => 'fb6c3e19'],
        ], 200)]);

        $result = $this->transport()->get('graph/v1beta1/roleManagement/permissions/roleDefinitions', 'lecture');

        self::assertCount(2, $result->entries());
        self::assertSame('Can view', $result->entries()[0]['displayName']);
    }

    /** Et l'enveloppe classique se lit de la même façon. */
    #[Test]
    public function an_enveloped_collection_is_read_the_same_way(): void
    {
        Http::fake(['*' => Http::response(['value' => [['id' => 'a'], ['id' => 'b']]], 200)]);

        self::assertCount(2, $this->transport()->get('graph/v1.0/drives', 'lecture')->entries());
    }

    /** `401` nu (mot de passe faux, mesuré) : privilège, jamais « instance injoignable ». */
    #[Test]
    public function a_bare_unauthorized_is_a_privilege_failure(): void
    {
        Http::fake(['*' => Http::response('', 401)]);

        $result = $this->transport()->get('graph/v1.0/me', 'lecture du compte connecté');

        self::assertSame(OpenCloudFailure::Privilege, $result->failure);
    }

    /**
     * **LE SECRET NE SORT PAR AUCUN CANAL** : ni message, ni forme journalisable —
     * y compris sur un refus, qui est le chemin le plus court vers un journal.
     */
    #[Test]
    public function the_secret_never_appears_in_any_message_or_log_shape(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 'accessDenied', 'message' => 'insufficient permissions'],
        ], 403)]);

        $result = $this->transport()->post('graph/v1.0/groups', ['displayName' => 'x'], 'création du groupe');

        self::assertStringNotContainsString('secret', $result->message);
        self::assertStringNotContainsString('secret', json_encode($result->toArray(), JSON_UNESCAPED_UNICODE));
    }

    /**
     * **LE GESTE HORS-GRAPH : la création d'un dossier, et ses deux codes qui
     * mentent.** `405` = déjà présent (conforme) ; `409` = parent manquant (échec
     * nommé, et c'est lui qui impose l'ordre parents d'abord).
     */
    #[Test]
    public function a_remote_folder_that_already_exists_is_conforming(): void
    {
        Http::fake(['*' => Http::response('', 405)]);

        $result = $this->transport()->sendRaw('MKCOL', 'dav/spaces/x/_travail', 'création', [405], [409]);

        self::assertFalse($result->isFailure());
        self::assertTrue($result->alreadyConforming);
    }

    /**
     * Et un parent manquant est un échec NOMMÉ — c'est ce `409` qui impose l'ordre
     * de création parents d'abord, et le confondre avec le `405` ferait poser des
     * octrois sur des dossiers qui n'existent pas.
     */
    #[Test]
    public function a_remote_folder_whose_parent_is_missing_fails_by_name(): void
    {
        Http::fake(['*' => Http::response('', 409)]);

        $result = $this->transport()->sendRaw('MKCOL', 'dav/spaces/x/a/b', 'création', [405], [409]);

        self::assertTrue($result->isFailure());
        self::assertSame(OpenCloudFailure::Absent, $result->failure);
    }

    #[Test]
    public function a_remote_folder_actually_created_is_a_plain_success(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $result = $this->transport()->sendRaw('MKCOL', 'dav/spaces/x/y', 'création', [405], [409]);

        self::assertFalse($result->isFailure());
        self::assertFalse($result->alreadyConforming);
    }
}
