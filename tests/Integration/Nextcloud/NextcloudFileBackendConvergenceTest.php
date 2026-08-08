<?php

declare(strict_types=1);

namespace Tests\Integration\Nextcloud;

use App\Enums\FileBackendName;
use App\Enums\FileBackendObservation;
use App\Enums\FileBackendOutcome;
use App\Enums\PlanNodeNature;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Services\FilePolicyService;
use App\Services\Filesystem\Backend\FileBackendRegistry;
use App\Services\Filesystem\Backend\Nextcloud\NextcloudFileBackend;
use App\Services\Filesystem\Backend\Nextcloud\NextcloudSubjectProjector;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\ServiceCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 61.3 (AC8) — **LE BACKEND LUI-MÊME, CONTRE L'INSTANCE RÉELLE.**
 *
 * ---------------------------------------------------------------------------
 * **POURQUOI CE FICHIER EXISTE À CÔTÉ DU TEST DE CANAL.**
 *
 * {@see NextcloudTeamFolderBackendTest} mesure le PROTOCOLE en appels nus : il
 * verrouille les sémantiques que les doubles rejouent. Il ne prouve pas pour autant
 * que {@see NextcloudFileBackend} CONVERGE — un adaptateur peut parler un protocole
 * parfaitement mesuré et se tromper d'ordre, comparer l'envoyé au relu, ou déclarer
 * conforme ce qu'il n'a pas lu. Ce fichier-ci exécute le VRAI backend, résolu par le
 * registre comme en production, sur un plan complet, contre l'instance de sondage.
 *
 * Il prouve quatre choses qu'aucun double ne peut prouver :
 *
 *  1. `provision()` converge de bout en bout — dossier d'équipe, groupe structurel,
 *     interrupteur des permissions avancées, groupes compilés et leur appartenance,
 *     arborescence, règles de clôture, plafonds ;
 *  2. **UN SECOND PASSAGE REND `conforme` PARTOUT.** C'est la mesure la plus dense du
 *     fichier : elle ne tient QUE si l'instance relit exactement ce qui a été écrit —
 *     masque de clôture `31` NON coercé (décision n°2), libellé d'affichage ajouté par
 *     le serveur IGNORÉ par la comparaison (piège n°3 de l'epic), point de montage
 *     normalisé reconnu. Toute dérive sur l'un des trois produirait un `applique` ici,
 *     et un ré-écriture perpétuelle en production ;
 *  3. la CLÔTURE est EFFECTIVE : un compte jetable du rôle refermé obtient un refus
 *     sur le nœud clos que le backend a posé — pas une règle relue, une PERCEPTION ;
 *  4. `deprovision()` révoque **sans détruire** : le dossier d'équipe et son contenu
 *     survivent (D9).
 *
 * ---------------------------------------------------------------------------
 * **CE CAS DE TEST N'EST PAS NU, et il ne peut pas l'être.** Le backend lit sa
 * configuration dans l'état persisté, verrouille son passage sur le cache disque, et
 * traduit des identités par des modèles Eloquent : il lui faut une application. La
 * base est SQLite `:memory:` — FORCÉE avant l'amorçage, parce que la suite
 * d'intégration hérite sinon du `.env` du poste. La garde de base du dépôt
 * ({@see TestCase}) reste en vigueur par-dessus.
 *
 * **SKIPPÉ PAR DÉFAUT** (avant même l'amorçage de l'application) : il exige
 * `NC_SPIKE_URL`, `NC_SPIKE_ADMIN` et `NC_SPIKE_PASSWORD`. Exécution par
 * l'orchestrateur, depuis le checkout principal, jamais depuis un worktree.
 *
 * **IL NETTOIE TOUT CE QU'IL CRÉE, MÊME EN ÉCHEC** (le nettoyage vit dans
 * `tearDown`) : dossier d'équipe jetable, groupes compilés, comptes jetables. Le
 * groupe structurel `se5_administration` n'est supprimé QUE s'il n'existait pas avant
 * le test. Il ne touche jamais les dossiers d'équipe `1` et `2`, ni les groupes et
 * comptes de l'état de sondage préexistant.
 */
class NextcloudFileBackendConvergenceTest extends TestCase
{
    use RefreshDatabase;

    /** Les dossiers d'équipe de l'instance de sondage : ON N'Y TOUCHE PAS. */
    private const PROTECTED_FOLDER_IDS = [1, 2];

    /** Les groupes et comptes de l'état de sondage préexistant : ON N'Y TOUCHE PAS. */
    private const PROTECTED_GROUPS = ['classe3a', 'equipe3a', 'spike603classe'];

    private const PROTECTED_ACCOUNTS = ['admin', 'eleve1', 'prof1', 'se5porteur', 'spike603eleve', 'spike603prof'];

    private const THROWAWAY_PASSWORD = 'Se5Integration!2026';

    /** Plafond de ZONE demandé au plan — relu tel quel côté instance. */
    private const ROOT_QUOTA = 5368709120;

    private string $url = '';

    private string $admin = '';

    private string $password = '';

    /** Le point de montage jetable — c'est aussi la racine du plan. */
    private string $root = '';

    private UserGroup $classe;

    private User $eleve;

    private User $prof;

    /** Identifiants des comptes jetables CRÉÉS sur l'instance. */
    private string $eleveAccount = '';

    private string $profAccount = '';

    /** Le groupe structurel préexistait-il ? S'il préexistait, on ne le supprime pas. */
    private bool $structuralGroupPreexisted = false;

    /** @var list<string> le relevé BRUT, imprimé en fin de scénario */
    private array $log = [];

    protected function setUp(): void
    {
        // LE SKIP D'ABORD : sans instance, on n'amorce même pas l'application.
        $this->url = rtrim((string) (getenv('NC_SPIKE_URL') ?: ''), '/');
        $this->admin = (string) (getenv('NC_SPIKE_ADMIN') ?: '');
        $this->password = (string) (getenv('NC_SPIKE_PASSWORD') ?: '');

        if ($this->url === '' || $this->admin === '' || $this->password === '') {
            $this->markTestSkipped(
                'backend Nextcloud de bout en bout : nécessite NC_SPIKE_URL, NC_SPIKE_ADMIN et '
                . 'NC_SPIKE_PASSWORD (instance de sondage, exécution depuis le checkout principal).'
            );
        }

        // La suite d'intégration ne fixe PAS la base : sans ceci, l'application
        // s'amorcerait sur le `.env` du poste. La garde de {@see TestCase} refuserait
        // de démarrer — autant nommer la base explicitement, et jetable.
        foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_DRIVER' => 'array'] as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }

        parent::setUp();

        // La projection d'annuaire n'a rien à faire ici : ce sont des groupes
        // NEXTCLOUD que le backend compile, pas des groupes d'annuaire.
        UserGroupObserver::disableSync();
        Queue::fake();

        $suffix = substr((string) time(), -6) . bin2hex(random_bytes(2));

        $this->root = 'SE5_BE_' . $suffix;
        $this->eleveAccount = 'se5it_be_eleve_' . $suffix;
        $this->profAccount = 'se5it_be_prof_' . $suffix;

        // La configuration que le backend LIRA — le même chemin qu'en production
        // (capacité, réglages, secret chiffré), jamais une injection de complaisance.
        FilePolicyService::setGlobal(true, true, true, $this->url, $this->admin, '', false);
        app(ServiceCredentials::class)->put(NextcloudConnectionConfig::CREDENTIAL_NAME, $this->password);

        // Le groupe interne : son nom COMPILÉ est ce que le backend écrira sur
        // l'instance (`se5_<nom court>_<rôle d'arête>`).
        $this->classe = UserGroup::query()->create(['name' => 'ZZ613' . substr($suffix, -4), 'type' => 'classe']);

        $this->eleve = $this->user('zz613eleve_' . $suffix, $this->eleveAccount);
        $this->prof = $this->user('zz613prof_' . $suffix, $this->profAccount);

        $this->classe->users()->attach($this->eleve->id, ['role' => 'member']);
        $this->classe->users()->attach($this->prof->id, ['role' => 'manager']);

        // Les comptes jetables, côté instance. Le backend ne crée JAMAIS de compte
        // (c'est le provisionnement 61.1) : il consomme le cache d'identité.
        foreach ([$this->eleveAccount, $this->profAccount] as $account) {
            $created = $this->rest('POST', 'ocs/v1.php/cloud/users', [
                'userid' => $account,
                'password' => self::THROWAWAY_PASSWORD,
            ]);
            $this->note('compte jetable ' . $account, $created);
        }

        $probe = $this->rest('GET', 'ocs/v1.php/cloud/groups/' . rawurlencode(NextcloudSubjectProjector::ADMIN_GROUP));
        $this->structuralGroupPreexisted = $this->ocsCode($probe) === 100 || $this->ocsCode($probe) === 200;
    }

    protected function tearDown(): void
    {
        // NETTOYAGE — tenté quoi qu'il arrive, exigé nulle part. Il vit ici et pas en
        // fin de scénario pour la seule raison qui compte : un échec d'assertion
        // interrompt le scénario, jamais `tearDown`.
        if ($this->url !== '') {
            $folderId = $this->folderId($this->root);
            if ($folderId !== null && ! in_array($folderId, self::PROTECTED_FOLDER_IDS, true)) {
                $this->rest('DELETE', 'index.php/apps/groupfolders/folders/' . $folderId);
            }

            foreach ([$this->eleveAccount, $this->profAccount] as $account) {
                if ($account === '' || in_array($account, self::PROTECTED_ACCOUNTS, true)) {
                    continue;
                }
                $this->rest('DELETE', 'ocs/v1.php/cloud/users/' . rawurlencode($account));
            }

            // `isset` et pas une supposition : si le décor s'est interrompu avant la
            // création du groupe interne, il n'y a aucun nom compilé à retirer.
            foreach (isset($this->classe) ? $this->compiledGroups() : [] as $group) {
                if ($group === '' || in_array($group, self::PROTECTED_GROUPS, true)) {
                    continue;
                }
                $this->rest('DELETE', 'ocs/v1.php/cloud/groups/' . rawurlencode($group));
            }

            // Le groupe STRUCTUREL n'est retiré que s'il n'existait pas AVANT ce
            // test : il est du décor de l'instance, pas de notre scénario.
            if (! $this->structuralGroupPreexisted) {
                $this->rest(
                    'DELETE',
                    'ocs/v1.php/cloud/groups/' . rawurlencode(NextcloudSubjectProjector::ADMIN_GROUP),
                );
            }
        }

        UserGroupObserver::enableSync();

        if ($this->log !== []) {
            fwrite(STDERR, "\n=== RELEVÉ BRUT 61.3 (backend) ===\n" . implode("\n", $this->log) . "\n");
        }

        parent::tearDown();
    }

    // =========================================================================
    // LE SCÉNARIO
    // =========================================================================

    #[Test]
    public function the_backend_converges_a_whole_plan_against_a_real_instance(): void
    {
        $backend = $this->backend();
        $plan = $this->plan();

        // --- 1. provision : la convergence complète ---------------------------
        $report = $backend->provision($plan);
        $this->note('provision', ['body' => json_encode($report->toArray(), JSON_UNESCAPED_UNICODE)]);

        self::assertSame(
            [],
            $report->failures(),
            'LE BACKEND DOIT CONVERGER CONTRE L\'INSTANCE RÉELLE. Relevé : '
            . json_encode($report->toArray(), JSON_UNESCAPED_UNICODE),
        );
        self::assertSame($plan->nodePaths(), array_column($report->toArray()['nodes'], 'path'));

        // Le dossier d'équipe, relu depuis l'inventaire.
        $folderId = $this->folderId($this->root);
        self::assertNotNull($folderId, 'le dossier d\'équipe doit figurer dans l\'inventaire RELU');
        self::assertNotContains($folderId, self::PROTECTED_FOLDER_IDS, 'garde : jamais les dossiers du spike');

        $folder = $this->folder((int) $folderId);
        self::assertTrue((bool) ($folder['acl'] ?? false), 'sans l\'interrupteur, les règles n\'ont AUCUN effet');

        // --- 2. LE GROUPE STRUCTUREL : la décision n°3, VÉRIFIÉE --------------
        //
        // Le risque était consigné : « si l'AC8 montre qu'un admin voit les dossiers
        // d'équipe sans appartenance, ce groupe devient inutile ». Le test de canal
        // voisin mesure le contraire (409 sans appartenance, 201 après). Ici on
        // vérifie que le backend POSE bien cette parade, et qu'elle porte les quatre
        // verbes — sans quoi rien de ce qui suit n'aurait été écrit.
        self::assertSame(
            15,
            (int) ($folder['groups'][NextcloudSubjectProjector::ADMIN_GROUP] ?? -1),
            'le groupe STRUCTUREL d\'administration monte le dossier pour le compte qui écrit',
        );
        self::assertContains(
            $this->admin,
            $this->groupMembers(NextcloudSubjectProjector::ADMIN_GROUP),
            'le compte d\'administration DOIT être membre du groupe structurel',
        );

        // Les groupes COMPILÉS et leurs plafonds, relus.
        [$members, $managers] = $this->compiledGroups();
        self::assertSame(1, (int) ($folder['groups'][$members] ?? -1), 'la lecture seule vaut 1');
        self::assertSame(15, (int) ($folder['groups'][$managers] ?? -1), 'les quatre verbes valent 15, jamais 31');
        self::assertSame([$this->eleveAccount], $this->groupMembers($members), 'l\'appartenance est EXACTE');
        self::assertSame([$this->profAccount], $this->groupMembers($managers), 'le rôle d\'arête est filtré');

        // --- 3. LA CLÔTURE, telle que l'instance la relit ---------------------
        $rules = $this->aclRules($this->root . '/_profs');
        $this->note('règles relues sur _profs', ['body' => json_encode($rules)]);
        self::assertContains(
            ['type' => 'group', 'id' => $members, 'mask' => 31, 'permissions' => 0],
            $rules,
            'LE MASQUE 31 SE RELIT TEL QU\'IL A ÉTÉ ÉCRIT : l\'instance ne le rabat pas (décision n°2)',
        );

        // --- 4. LE SECOND PASSAGE : conforme partout, donc ZÉRO DÉRIVE --------
        $replay = $backend->provision($plan);
        $this->note('second passage', ['body' => json_encode($replay->toArray(), JSON_UNESCAPED_UNICODE)]);

        foreach ($replay->toArray()['nodes'] as $node) {
            self::assertSame(
                FileBackendOutcome::Conforme->value,
                $node['outcome'],
                'SECOND PASSAGE SUR UN ÉTAT CONFORME : le nœud « ' . $node['path'] . '» doit être conforme. '
                . 'Un « applique » ici signifierait que la comparaison porte sur autre chose que le RELU — '
                . 'masque coercé, libellé ajouté par le serveur compté comme un écart, point de montage '
                . 'normalisé non reconnu. C\'est la dérive permanente que tout l\'epic combat.',
            );
        }

        // --- 5. LA PERCEPTION EFFECTIVE : ce qu'aucune règle relue ne dit -----
        $closed = $this->davAs($this->eleveAccount, 'PROPFIND', $this->root . '/_profs');
        $this->note('élève sur le dossier CLOS (posé par le backend)', $closed);
        self::assertSame(404, $closed['status'], 'le dossier refermé PAR LE BACKEND est INATTEIGNABLE pour le rôle clos');

        $listing = $this->davAs($this->eleveAccount, 'PROPFIND', $this->root, '1');
        self::assertStringNotContainsString('_profs', $listing['body'], 'il DISPARAÎT même de son listing');
        self::assertStringContainsString('_travail', $listing['body'], 'le reste de la zone lui reste visible');

        $open = $this->davAs($this->profAccount, 'PROPFIND', $this->root . '/_profs');
        self::assertSame(207, $open['status'], 'le rôle octroyé, lui, garde son accès');

        // --- 6. inspect : l'état RELU, reprojeté en vocabulaire de plan --------
        $inspection = $backend->inspect($plan);
        $this->note('inspect', ['body' => json_encode($inspection->toArray(), JSON_UNESCAPED_UNICODE)]);

        self::assertSame($plan->nodePaths(), array_column($inspection->toArray()['nodes'], 'path'));
        self::assertSame(FileBackendObservation::Observe, $inspection->for(PlanNode::ROOT_PATH)?->status);

        $subjects = array_map(
            static fn ($grant): string => $grant->subject->type . '#' . $grant->subject->id,
            $inspection->for(PlanNode::ROOT_PATH)?->grants ?? [],
        );
        self::assertContains(
            'user_group#' . $this->classe->id,
            $subjects,
            'un nom de groupe compilé redevient l\'identité INTERNE : aucun identifiant distant ne remonte',
        );

        $closure = array_map(
            static fn (PlanSubject $subject): string => $subject->type . '#' . $subject->id,
            $inspection->for('_profs')?->closure ?? [],
        );
        self::assertContains(
            'user_group#' . $this->classe->id,
            $closure,
            'LA CLÔTURE EST OBSERVÉE, donc comparable : c\'est l\'évolution que le comparateur annonçait',
        );

        // --- 7. LES DEUX PLAFONDS, chacun sur son objet (D8) -------------------
        $quota = $backend->quota($plan);
        $this->note('quota', ['body' => json_encode($quota->toArray(), JSON_UNESCAPED_UNICODE)]);

        self::assertSame(FileBackendOutcome::Applique, $quota->for(PlanNode::ROOT_PATH)?->outcome);
        self::assertSame(
            self::ROOT_QUOTA,
            (int) ($this->folder((int) $folderId)['quota'] ?? 0),
            'le plafond de ZONE est relu tel qu\'il a été posé',
        );
        self::assertSame(
            FileBackendOutcome::NonExprimable,
            $quota->for('_profs')?->outcome,
            'le plafond d\'un SOUS-dossier est une limite du MODÈLE, dite et non tue',
        );

        // --- 8. deprovision : RÉVOQUER SANS DÉTRUIRE (D9) ---------------------
        $removal = $backend->deprovision($plan);
        $this->note('deprovision', ['body' => json_encode($removal->toArray(), JSON_UNESCAPED_UNICODE)]);

        self::assertSame([], $removal->failures(), json_encode($removal->toArray(), JSON_UNESCAPED_UNICODE));

        $folder = $this->folder((int) $folderId);
        self::assertSame(
            [NextcloudSubjectProjector::ADMIN_GROUP],
            array_keys((array) ($folder['groups'] ?? [])),
            'les groupes du plan ont quitté la carte ; le groupe STRUCTUREL reste',
        );
        self::assertSame([], $this->aclRules($this->root . '/_profs'), 'les règles sont retirées');
        self::assertSame(
            207,
            $this->davAs($this->admin, 'PROPFIND', $this->root . '/_profs')['status'],
            'LE DOSSIER ET SON CONTENU SURVIVENT : la révocation ne détruit rien (D9)',
        );
    }

    // =========================================================================
    // Le décor
    // =========================================================================

    private function backend(): NextcloudFileBackend
    {
        $backend = app(FileBackendRegistry::class)->get(FileBackendName::Nextcloud);
        self::assertInstanceOf(NextcloudFileBackend::class, $backend, 'le registre doit résoudre le backend RÉEL');

        return $backend;
    }

    private function user(string $login, string $nextcloudId): User
    {
        $user = User::query()->create(['login' => $login, 'role' => 'eleve', 'is_active' => true, 'source' => 'ad']);
        $user->nextcloud_user_id = $nextcloudId;
        $user->saveQuietly();

        return $user->fresh();
    }

    /**
     * Le plan d'épreuve : le cas le plus étroit qui contienne quand même la
     * difficulté — une racine ouverte, un espace de travail, un espace des
     * enseignants où la classe est REFERMÉE, et deux plafonds (l'un exprimable,
     * l'autre non).
     */
    private function plan(): FilePlan
    {
        $members = PlanSubject::group((int) $this->classe->id, 'member');
        $managers = PlanSubject::group((int) $this->classe->id, 'manager');

        return new FilePlan(
            'classe_share',
            $this->root,
            ['classe' => [$members], 'equipe' => [$managers]],
            [
                new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::Partagee, [
                    new PlanGrant('equipe', $managers, PlanGrant::VERBS),
                    new PlanGrant('classe', $members, [PlanGrant::VERB_LIRE]),
                ], true, self::ROOT_QUOTA, []),

                new PlanNode('_travail', 'Travail', PlanNodeNature::Partagee, [
                    new PlanGrant('equipe', $managers, PlanGrant::VERBS),
                    new PlanGrant('classe', $members, [PlanGrant::VERB_LIRE]),
                ], true, null, []),

                // LE nœud de la clôture : la classe n'a AUCUN octroi ici, et son
                // accès hérité doit être refermé — c'est la fuite mesurée au sondage
                // 60.0, et la raison d'être de tout ce backend.
                new PlanNode('_profs', 'Enseignants', PlanNodeNature::Partagee, [
                    new PlanGrant('equipe', $managers, PlanGrant::VERBS),
                ], true, 2147483648, ['classe']),
            ],
        );
    }

    /**
     * Les deux noms de groupes que le backend COMPILE pour ce plan — recalculés par
     * le projecteur lui-même, jamais recopiés à la main : un nom recopié divergerait
     * silencieusement le jour où la convention bouge.
     *
     * @return array{0:string, 1:string}
     */
    private function compiledGroups(): array
    {
        $projector = app(NextcloudSubjectProjector::class);

        return [
            (string) $projector->groupNameForModel($this->classe, 'member'),
            (string) $projector->groupNameForModel($this->classe, 'manager'),
        ];
    }

    // =========================================================================
    // Lecture directe de l'instance — le témoin INDÉPENDANT du backend
    // =========================================================================

    /**
     * Les règles relues d'un chemin, réduites aux QUATRE champs écrits (le libellé
     * ajouté par le serveur est ignoré, exactement comme le fait le canal).
     *
     * Cette lecture est délibérément NUE : demander au backend de se relire
     * lui-même prouverait sa cohérence interne, pas ce que l'instance a retenu.
     *
     * @return list<array{type:string,id:string,mask:int,permissions:int}>
     */
    private function aclRules(string $path): array
    {
        $response = $this->davAs($this->admin, 'PROPFIND', $path, '0', '<?xml version="1.0"?>'
            . '<d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns"><d:prop>'
            . '<nc:acl-list/></d:prop></d:propfind>');

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($response['body'], LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return [];
        }

        $rules = [];
        foreach ($document->getElementsByTagNameNS('http://nextcloud.org/ns', 'acl') as $acl) {
            $text = static function (string $local) use ($acl): string {
                $node = $acl->getElementsByTagNameNS('http://nextcloud.org/ns', $local)->item(0);

                return $node === null ? '' : trim($node->textContent);
            };

            $rules[] = [
                'type' => $text('acl-mapping-type'),
                'id' => $text('acl-mapping-id'),
                'mask' => (int) $text('acl-mask'),
                'permissions' => (int) $text('acl-permissions'),
            ];
        }

        return $rules;
    }

    /** @return list<string> */
    private function groupMembers(string $group): array
    {
        $response = $this->rest('GET', 'ocs/v1.php/cloud/groups/' . rawurlencode($group));
        $users = json_decode($response['body'], true)['ocs']['data']['users'] ?? [];

        $members = [];
        foreach (is_array($users) ? $users : [] as $user) {
            if (is_string($user) && $user !== '') {
                $members[] = $user;
            }
        }
        sort($members, SORT_STRING);

        return $members;
    }

    private function folderId(string $mountPoint): ?int
    {
        foreach ($this->inventory() as $folder) {
            if (trim(trim((string) ($folder['mount_point'] ?? $folder['mountpoint'] ?? '')), '/') === $mountPoint) {
                return (int) ($folder['id'] ?? 0);
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function folder(int $id): array
    {
        foreach ($this->inventory() as $folder) {
            if ((int) ($folder['id'] ?? 0) === $id) {
                return $folder;
            }
        }

        return [];
    }

    /** @return list<array<string, mixed>> */
    private function inventory(): array
    {
        $response = $this->rest('GET', 'index.php/apps/groupfolders/folders');
        $decoded = json_decode($response['body'], true);
        $payload = $decoded['ocs']['data'] ?? $decoded;

        return array_values(array_filter(is_array($payload) ? $payload : [], 'is_array'));
    }

    // =========================================================================
    // Transport — curl NU, pour ne rien devoir au code sous test
    // =========================================================================

    /**
     * @param  array<string, mixed>  $form
     * @return array{status:int, body:string}
     */
    private function rest(string $method, string $path, array $form = []): array
    {
        return $this->send(
            $this->admin,
            $this->password,
            $method,
            $this->url . '/' . ltrim($path, '/') . '?format=json',
            $form === [] ? null : http_build_query($form),
            ['OCS-APIRequest: true', 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        );
    }

    /** @return array{status:int, body:string} */
    private function davAs(string $login, string $method, string $path, string $depth = '0', string $body = ''): array
    {
        $segments = array_map(rawurlencode(...), array_values(array_filter(explode('/', trim($path, '/')))));

        return $this->send(
            $login,
            $login === $this->admin ? $this->password : self::THROWAWAY_PASSWORD,
            $method,
            $this->url . '/remote.php/dav/files/' . rawurlencode($login)
                . ($segments === [] ? '' : '/' . implode('/', $segments)),
            $body === '' ? null : $body,
            ['Depth: ' . $depth, 'Content-Type: application/xml; charset=UTF-8'],
        );
    }

    /**
     * @param  list<string>  $headers
     * @return array{status:int, body:string}
     */
    private function send(string $login, string $password, string $method, string $url, ?string $body, array $headers): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERPWD => $login . ':' . $password,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return ['status' => $status, 'body' => (string) $raw];
    }

    /** @param array{status?:int, body?:string} $response */
    private function ocsCode(array $response): int
    {
        return (int) (json_decode((string) ($response['body'] ?? ''), true)['ocs']['meta']['statuscode'] ?? 0);
    }

    /** @param array{status?:int, body?:string} $response */
    private function note(string $label, array $response): void
    {
        $this->log[] = sprintf(
            '%-46s HTTP %d %s',
            $label,
            (int) ($response['status'] ?? 0),
            preg_replace('/\s+/', ' ', mb_substr((string) ($response['body'] ?? ''), 0, 600)),
        );
    }
}
