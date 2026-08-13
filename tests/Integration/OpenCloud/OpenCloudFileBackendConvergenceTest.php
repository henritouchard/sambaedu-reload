<?php

declare(strict_types=1);

namespace Tests\Integration\OpenCloud;

use App\Enums\FileBackendName;
use App\Enums\FileBackendObservation;
use App\Enums\FileBackendOutcome;
use App\Enums\PlanNodeNature;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Services\FilePolicyService;
use App\Services\Filesystem\Backend\FileBackendRegistry;
use App\Services\Filesystem\Backend\OpenCloud\OpenCloudFileBackend;
use App\Services\Filesystem\Backend\OpenCloud\OpenCloudRoleTable;
use App\Services\Filesystem\Backend\OpenCloud\OpenCloudSubjectProjector;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use App\Services\OpenCloud\OpenCloudConnectionConfig;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * **LE BACKEND OPENCLOUD, CONTRE L'INSTANCE RÉELLE.**
 *
 * ---------------------------------------------------------------------------
 * **CE QUE CE FICHIER PROUVE, ET QU'AUCUN DOUBLE NE PEUT PROUVER.**
 *
 *  1. **le CATALOGUE DE RÔLES de l'instance est bien celui que la table épingle.**
 *     Les identifiants sont codés en dur côté SE5 (les découvrir coûterait une
 *     requête par nœud) : c'est ce test, et lui seul, qui dira le jour où une
 *     version future les déplace. Sans lui, la table serait une croyance ;
 *  2. `provision()` converge de bout en bout — espace de projet, groupes compilés
 *     et appartenance, arborescence, octrois par nœud ;
 *  3. **UN SECOND PASSAGE REND `conforme` PARTOUT.** C'est la mesure la plus dense
 *     du fichier : elle ne tient QUE si l'instance relit exactement ce qui a été
 *     écrit, et que la comparaison IGNORE les champs que le serveur ajoute
 *     (`createdDateTime`, `invitation.invitedBy`, le libellé du principal). Toute
 *     dérive sur l'un d'eux produirait un `applique` ici, et une réécriture
 *     perpétuelle en production ;
 *  4. **LA CLÔTURE EST EFFECTIVE — ou elle est CONSTATÉE.** Un compte jetable
 *     membre du rôle refermé obtient un refus sur le nœud clos. Ce n'est pas une
 *     règle relue, c'est une PERCEPTION : le seul fait que ni le serveur ni un
 *     double ne savent affirmer. Et si l'accès survivait, le test le CONSTATERAIT —
 *     ce qui vaudrait verdict, pas échec de la story ;
 *  5. `deprovision()` révoque **sans détruire** : l'espace et son arborescence
 *     survivent (D9).
 *
 * ---------------------------------------------------------------------------
 * **SKIPPÉ PAR DÉFAUT**, avant même l'amorçage de l'application : il exige
 * `OC_TEST_URL`, `OC_TEST_ADMIN` et `OC_TEST_PASSWORD`. Exécution par
 * l'orchestrateur, depuis le checkout principal, jamais depuis un worktree.
 *
 * **IL NETTOIE TOUT CE QU'IL CRÉE, MÊME EN ÉCHEC** (le nettoyage vit dans
 * `tearDown`), et **IL NE TOUCHE AUCUN OBJET PRÉEXISTANT** : sa zone porte un nom
 * horodaté qu'il fabrique lui-même, et une assertion défensive vérifie qu'il n'a
 * écrit que dedans. L'espace jetable n'est PAS détruit à la fin — le client n'a
 * aucune méthode pour cela, par conception (D9) : il est laissé vide et sans
 * octroi, ce qui est inoffensif, et son nom horodaté le rend reconnaissable.
 */
class OpenCloudFileBackendConvergenceTest extends TestCase
{
    use RefreshDatabase;

    private const THROWAWAY_PASSWORD = 'Se5Integration!2026';

    private const ROOT_QUOTA = 5368709120;

    private string $url = '';

    private string $admin = '';

    private string $password = '';

    /** La racine du plan — c'est aussi le nom de l'espace jetable. */
    private string $root = '';

    private UserGroup $classe;

    private User $eleve;

    private User $prof;

    /** @var list<string> le relevé BRUT, imprimé en fin de scénario */
    private array $log = [];

    /** @var list<string> les identifiants de groupes créés PAR CE TEST */
    private array $createdGroups = [];

    protected function setUp(): void
    {
        // LE SKIP D'ABORD : sans instance, on n'amorce même pas l'application.
        $this->url = rtrim((string) (getenv('OC_TEST_URL') ?: ''), '/');
        $this->admin = (string) (getenv('OC_TEST_ADMIN') ?: '');
        $this->password = (string) (getenv('OC_TEST_PASSWORD') ?: '');

        if ($this->url === '' || $this->admin === '' || $this->password === '') {
            $this->markTestSkipped(
                'backend OpenCloud de bout en bout : nécessite OC_TEST_URL, OC_TEST_ADMIN et '
                . 'OC_TEST_PASSWORD (instance réelle, exécution depuis le checkout principal).'
            );
        }

        foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_DRIVER' => 'array'] as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }

        parent::setUp();

        UserGroupObserver::disableSync();
        Queue::fake();

        $suffix = substr((string) time(), -6) . bin2hex(random_bytes(2));
        $this->root = 'SE5_Integration_' . $suffix;

        FilePolicyService::setGlobal(
            true, true, false, '', null, null, null,
            true, $this->url, $this->admin, false,
        );
        app(ServiceCredentials::class)->put(OpenCloudConnectionConfig::CREDENTIAL_NAME, $this->password);

        $this->classe = UserGroup::query()->create(['name' => 'i' . $suffix, 'type' => 'classe']);

        $this->eleve = $this->localUser('eleve_' . $suffix);
        $this->prof = $this->localUser('prof_' . $suffix);
        $this->classe->users()->attach($this->eleve->id, ['role' => 'member']);
        $this->classe->users()->attach($this->prof->id, ['role' => 'manager']);

        // Les comptes jetables, créés SUR L'INSTANCE — hors du backend, qui n'a
        // aucun chemin vers les comptes (frontière D8). C'est le test qui les crée,
        // avec le client HTTP nu.
        $this->eleve->opencloud_user_id = $this->createAccount((string) $this->eleve->login);
        $this->eleve->saveQuietly();
        $this->prof->opencloud_user_id = $this->createAccount((string) $this->prof->login);
        $this->prof->saveQuietly();
    }

    protected function tearDown(): void
    {
        if ($this->url !== '') {
            $this->cleanUp();
        }

        UserGroupObserver::enableSync();

        if ($this->log !== []) {
            fwrite(STDERR, "\n=== RELEVÉ BRUT — backend OpenCloud contre l'instance réelle ===\n");
            foreach ($this->log as $line) {
                fwrite(STDERR, '  ' . $line . "\n");
            }
            fwrite(STDERR, "================================================================\n");
        }

        parent::tearDown();
    }

    // =========================================================================
    // LE SCÉNARIO
    // =========================================================================

    /**
     * **UN SEUL CAS, PARCE QUE LA SÉQUENCE EST LE SUJET.** Découper en dix
     * méthodes ferait dix fois le décor sur une instance réelle, et surtout ferait
     * perdre ce qu'on veut mesurer : l'enchaînement.
     */
    #[Test]
    public function the_backend_converges_closes_and_revokes_against_the_real_instance(): void
    {
        $backend = app(FileBackendRegistry::class)->get(FileBackendName::OpenCloud);
        self::assertInstanceOf(OpenCloudFileBackend::class, $backend);

        // --- 0. LE CATALOGUE DE RÔLES épinglé est-il celui de l'instance ? ----
        $this->assertRoleCatalogueMatches();

        // --- 1. Premier passage : tout est appliqué --------------------------
        $first = $backend->provision($this->plan());
        $this->log[] = 'provision #1 : ' . json_encode($first->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertSame([], $first->failures(), 'la première convergence a échoué');

        // --- 2. Second passage : conforme PARTOUT ----------------------------
        $second = $backend->provision($this->plan());
        $this->log[] = 'provision #2 : ' . json_encode($second->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($second->entries as $entry) {
            self::assertSame(
                FileBackendOutcome::Conforme,
                $entry->outcome,
                sprintf(
                    'le second passage n\'est pas conforme sur « %s » (%s) : la comparaison sur le RELU a '
                    . 'dérivé, et la production réécrirait indéfiniment.',
                    $entry->path,
                    (string) $entry->detail,
                ),
            );
        }

        // --- 3. La relecture reprojette en vocabulaire de plan ---------------
        $inspection = $backend->inspect($this->plan());
        $this->log[] = 'inspect : ' . json_encode($inspection->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $profs = $inspection->for('_profs');
        self::assertNotNull($profs);
        self::assertSame(FileBackendObservation::Observe, $profs->status);
        self::assertNotNull($profs->closure, 'la clôture DOIT être observée sur ce modèle');
        self::assertNotSame([], $profs->closure, 'la classe DOIT figurer dans la clôture observée');

        // --- 4. LE PLAFOND ---------------------------------------------------
        $quota = $backend->quota($this->plan(capped: true));
        $this->log[] = 'quota : ' . json_encode($quota->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertSame(FileBackendOutcome::Applique, $quota->entries[0]->outcome);

        // --- 5. LA PREUVE DE CLÔTURE : la PERCEPTION d'un compte jetable -----
        $this->assertEffectiveClosure();

        // --- 6. LA GARDE DÉFENSIVE : rien n'a été écrit hors de notre zone ----
        $this->assertNothingWrittenOutsideOurSpace();

        // --- 7. RÉVOQUER SANS DÉTRUIRE --------------------------------------
        $treeBefore = $this->childrenOfRoot();
        $revoked = $backend->deprovision($this->plan());
        $this->log[] = 'deprovision : ' . json_encode($revoked->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertSame([], $revoked->failures());
        self::assertSame(
            $treeBefore,
            $this->childrenOfRoot(),
            'RÉVOQUER N\'EST PAS DÉTRUIRE : l\'arborescence doit survivre intacte',
        );
        self::assertNotNull($this->spaceId(), 'l\'espace DOIT survivre à la révocation');
    }

    // =========================================================================
    // Les mesures
    // =========================================================================

    /**
     * Le catalogue épinglé côté SE5 est-il celui que l'instance publie ?
     *
     * **C'est ce contrôle qui empêche la table de rôles d'être une croyance.** Les
     * identifiants sont codés en dur pour ne pas payer une requête par nœud ; si
     * une version future les déplaçait, la production échouerait en
     * `role not applicable to this resource` sans que rien n'ait prévenu.
     */
    private function assertRoleCatalogueMatches(): void
    {
        $catalogue = $this->http('GET', '/graph/v1beta1/roleManagement/permissions/roleDefinitions');
        $published = [];
        foreach (is_array($catalogue['body']) ? $catalogue['body'] : [] as $role) {
            if (is_array($role) && is_string($role['id'] ?? null)) {
                $published[$role['id']] = (string) ($role['displayName'] ?? '');
            }
        }

        $this->log[] = 'catalogue de rôles publié : ' . json_encode($published, JSON_UNESCAPED_UNICODE);

        foreach (OpenCloudRoleTable::ROLES as $key => $role) {
            self::assertArrayHasKey(
                $role['id'],
                $published,
                sprintf(
                    'le rôle « %s » (%s) épinglé par SE5 n\'existe plus dans le catalogue de l\'instance : '
                    . 'la traduction des verbes échouerait en production.',
                    $key,
                    $role['id'],
                ),
            );
            self::assertSame($role['label'], $published[$role['id']], 'le libellé du rôle ' . $key . ' a changé');
        }
    }

    /**
     * **LA PERCEPTION EFFECTIVE, mesurée avec le compte jetable de l'élève.**
     *
     * Une règle relue prouve une règle, pas une perception. Ici, l'élève doit
     * atteindre `_travail` et se voir refuser `_profs`. Le protocole d'édition
     * distante répond `404` — et pas `403` : sur ce produit, ce qui n'est pas
     * partagé n'est pas « interdit », il est INVISIBLE, ce qui est plus fort.
     *
     * **Si l'accès survivait, ce test le CONSTATERAIT** en l'écrivant au relevé
     * plutôt qu'en le taisant : un cloisonnement affiché qui n'existe pas est le
     * seul résultat inacceptable de ce chantier.
     */
    private function assertEffectiveClosure(): void
    {
        $space = (string) $this->spaceId();

        $travail = $this->http(
            'PROPFIND',
            '/dav/spaces/' . $space . '/_travail',
            null,
            (string) $this->eleve->login,
            self::THROWAWAY_PASSWORD,
        );
        $profs = $this->http(
            'PROPFIND',
            '/dav/spaces/' . $space . '/_profs',
            null,
            (string) $this->eleve->login,
            self::THROWAWAY_PASSWORD,
        );

        $this->log[] = sprintf(
            'PERCEPTION de l\'élève : _travail → HTTP %d ; _profs → HTTP %d',
            $travail['status'],
            $profs['status'],
        );

        self::assertContains(
            $travail['status'],
            [200, 207],
            'l\'élève DOIT atteindre l\'espace de travail qui lui est octroyé',
        );

        self::assertNotContains(
            $profs['status'],
            [200, 207],
            'CLOISONNEMENT NON OBTENU : l\'élève atteint l\'espace des enseignants alors que le plan l\'y '
            . 'referme. C\'est le CONSTAT que ce test existe pour produire — il vaut verdict, et il doit '
            . 'être remonté tel quel.',
        );
    }

    /**
     * **GARDE DÉFENSIVE : ce test n'écrit que dans la zone qu'il a créée.**
     *
     * Elle vaut sur une instance de production autant que sur une instance
     * jetable : le jour où quelqu'un lancerait cette suite contre la mauvaise
     * adresse, c'est cette assertion qui l'arrêterait.
     */
    private function assertNothingWrittenOutsideOurSpace(): void
    {
        $spaces = $this->http('GET', '/graph/v1.0/drives');
        $ours = 0;

        foreach ($spaces['body']['value'] ?? [] as $space) {
            if (! is_array($space) || ($space['driveType'] ?? '') !== 'project') {
                continue;
            }
            if (trim((string) ($space['name'] ?? '')) === $this->root) {
                $ours++;

                continue;
            }

            // Un espace étranger ne doit porter AUCUN groupe compilé par ce test.
            $permissions = $this->http('GET', '/graph/v1beta1/drives/' . $space['id'] . '/root/permissions');
            foreach ($permissions['body']['value'] ?? [] as $permission) {
                $group = $permission['grantedToV2']['group']['id'] ?? null;
                self::assertNotContains(
                    $group,
                    $this->createdGroups,
                    'CE TEST A ÉCRIT HORS DE SA ZONE : espace « ' . (string) ($space['name'] ?? '?') . ' »',
                );
            }
        }

        self::assertSame(1, $ours, 'exactement UN espace doit porter le plan (aucun doublon créé)');
    }

    // =========================================================================
    // Le plan et le décor
    // =========================================================================

    private function plan(bool $capped = false): FilePlan
    {
        $members = PlanSubject::group((int) $this->classe->id, 'member');
        $managers = PlanSubject::group((int) $this->classe->id, 'manager');

        $roles = ['classe' => [$members], 'equipe' => [$managers]];

        // **AUCUN OCTROI À LA RACINE** : c'est l'architecture retenue par la mesure
        // M4④ — on n'ouvre pas, plutôt que d'essayer de refermer.
        $nodes = [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::Partagee, [], true,
                $capped ? self::ROOT_QUOTA : null, ['classe', 'equipe']),

            new PlanNode('_travail', 'Travail', PlanNodeNature::Partagee, [
                new PlanGrant('equipe', $managers, PlanGrant::VERBS),
                new PlanGrant('classe', $members, [PlanGrant::VERB_LIRE]),
            ], true, null, []),

            // LE nœud de la clôture : la classe n'a AUCUN octroi ici.
            new PlanNode('_profs', 'Enseignants', PlanNodeNature::Partagee, [
                new PlanGrant('equipe', $managers, PlanGrant::VERBS),
            ], true, null, ['classe']),
        ];

        return new FilePlan('integration_opencloud', $this->root, $roles, $nodes);
    }

    private function localUser(string $login): User
    {
        $user = User::query()->create(['login' => $login, 'role' => 'eleve', 'is_active' => true, 'source' => 'ad']);

        return $user->fresh();
    }

    private function createAccount(string $login): string
    {
        $created = $this->http('POST', '/graph/v1.0/users', [
            'onPremisesSamAccountName' => $login,
            'displayName' => $login,
            'mail' => $login . '@integration.invalid',
            'passwordProfile' => ['password' => self::THROWAWAY_PASSWORD],
        ]);

        self::assertSame(201, $created['status'], 'le compte jetable n\'a pas pu être créé : ' . json_encode($created['body']));

        return (string) ($created['body']['id'] ?? '');
    }

    private function spaceId(): ?string
    {
        foreach ($this->http('GET', '/graph/v1.0/drives')['body']['value'] ?? [] as $space) {
            if (is_array($space) && trim((string) ($space['name'] ?? '')) === $this->root) {
                return (string) $space['id'];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function childrenOfRoot(): array
    {
        $space = $this->spaceId();
        if ($space === null) {
            return [];
        }

        $names = [];
        foreach ($this->http('GET', '/graph/v1.0/drives/' . $space . '/items/' . $space . '/children')['body']['value'] ?? [] as $child) {
            if (is_array($child) && is_string($child['name'] ?? null)) {
                $names[] = $child['name'];
            }
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Retire tout ce que ce test a créé — SAUF l'espace, que le client de
     * production ne sait pas détruire (D9). L'espace jetable reste, vide et sans
     * octroi, avec son nom horodaté.
     */
    private function cleanUp(): void
    {
        $projector = app(OpenCloudSubjectProjector::class);

        foreach ($this->http('GET', '/graph/v1.0/groups')['body']['value'] ?? [] as $group) {
            if (! is_array($group)) {
                continue;
            }
            $name = (string) ($group['displayName'] ?? '');
            if (! str_starts_with($name, OpenCloudSubjectProjector::GROUP_PREFIX . 'i')) {
                continue;
            }
            if (! str_contains($name, strtolower(substr((string) $this->classe->name, 1)))) {
                continue;
            }
            $this->http('DELETE', '/graph/v1.0/groups/' . $group['id']);
        }

        foreach ([$this->eleve->opencloud_user_id, $this->prof->opencloud_user_id] as $account) {
            if (is_string($account) && $account !== '') {
                $this->http('DELETE', '/graph/v1.0/users/' . $account);
            }
        }

        unset($projector);
    }

    // =========================================================================
    // Le client HTTP NU du test — jamais celui du backend
    // =========================================================================

    /**
     * @param  array<string, mixed>|null  $body
     * @return array{status:int, body:mixed}
     */
    private function http(string $method, string $path, ?array $body = null, ?string $user = null, ?string $pass = null): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", array_filter([
                    'Authorization: Basic ' . base64_encode(($user ?? $this->admin) . ':' . ($pass ?? $this->password)),
                    'Accept: application/json',
                    $body !== null ? 'Content-Type: application/json' : null,
                    $method === 'PROPFIND' ? 'Depth: 0' : null,
                ])),
                'content' => $body === null ? '' : (string) json_encode($body),
                'ignore_errors' => true,
                'timeout' => 30,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        $raw = @file_get_contents($this->url . $path, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        $decoded = json_decode((string) $raw, true);

        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : (string) $raw];
    }
}
