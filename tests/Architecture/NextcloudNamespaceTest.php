<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Story 61.1 — **CETTE STORY N'ÉCRIT AUCUN DROIT, ET N'EXÉCUTE RIEN.**
 *
 * Elle ajoute un CHEMIN D'ACCÈS (le web), pas une autorité. Le montage
 * `files_external` relaie à Samba les identifiants de l'utilisateur connecté, et
 * c'est l'ACL POSIX du kernel qui tranche — exactement comme pour le lecteur SMB.
 * Trois façons de perdre cette propriété, toutes plausibles, toutes fermées ici :
 *
 *  1. **Un partage OCS** (`files_sharing/api/v1/shares`) — la « solution » la plus
 *     naturelle quand un accès manque. Il crée un SECOND plan de permissions sur
 *     la même zone, et le sondage 60.0 a mesuré qu'il ment : une instruction de
 *     retrait est acceptée `200 OK` et relue à `1`. Le garde-fou d'epic
 *     (« une seule autorité d'écriture par zone ») ne survivrait pas à son ajout.
 *  2. **Un groupe Nextcloud** (`cloud/groups`) — indissociable du précédent : il
 *     n'existe que pour restreindre l'applicabilité d'un montage ou porter un
 *     partage. Restreindre l'applicabilité, c'est déplacer le filtre de Samba vers
 *     Nextcloud.
 *  3. **Un shell-out** — cette story est 100 % HTTP + SQL. La première commande
 *     système écrite ici serait le début d'un backend, et un backend a un contrat
 *     (Epic 60) que cette story a promis de ne pas toucher.
 *
 * Chaque règle est adossée à un MÉTA-TEST : un scan qui ne détecte rien parce
 * qu'il ne regarde rien passerait sinon éternellement au vert. Le scan est
 * TEXTUEL et porte aussi sur les commentaires — c'est volontaire : un docblock qui
 * cite un chemin interdit finit par être copié en appel.
 */
class NextcloudNamespaceTest extends TestCase
{
    private const NEXTCLOUD_DIR = 'app/Services/Nextcloud';

    /**
     * Le code NOUVEAU de la story qui vit hors du namespace. Il est tenu par les
     * mêmes règles : c'est par la commande et le traitement en file qu'un
     * shell-out « juste pour dépanner » arriverait.
     *
     * @var list<string>
     */
    private const STORY_FILES = [
        'app/Console/Commands/NextcloudProvisionCommand.php',
        'app/Jobs/ProvisionNextcloudJob.php',
        'app/Exceptions/Nextcloud/NextcloudConfigurationException.php',
        // Story 61.2 — le code nouveau qui vit hors du namespace obéit aux mêmes
        // règles : c'est par la commande de rattachement qu'un « juste un petit
        // partage pour dépanner » arriverait. (L'enum de mode figurait ici jusqu'au
        // recadrage du 2026-08-08 ; il n'existe plus.)
        'app/Console/Commands/NextcloudIdentityCommand.php',
    ];

    /** @var array<string, string> */
    private const FORBIDDEN_RULES = [
        // 1. Aucun droit écrit côté Nextcloud.
        //
        // ---------------------------------------------------------------------
        // **LA RÈGLE S'EST RESSERRÉE (recadrage du 2026-08-08).** Elle portait sur
        // `files_sharing` nu ; la story 61.2 l'avait ÉLARGIE à `apps/files_sharing`
        // — c'est-à-dire au seul préfixe de route — pour laisser la sonde du mode
        // délégué lire `files_sharing.api_enabled` dans l'inventaire des capacités
        // de l'instance. Le mode délégué était la seule raison d'envisager ces
        // routes ; il a disparu, et la garde revient donc à sa forme LARGE : plus
        // aucun code de ce dépôt n'a de motif de prononcer `files_sharing`, sous
        // quelque forme que ce soit.
        //
        // Une garde qu'on resserre parce que le besoin qui l'avait desserrée a
        // disparu est le sens de marche attendu.
        // ---------------------------------------------------------------------
        'partage OCS' => '#files_sharing#i',
        'groupe Nextcloud' => '#cloud/groups#i',

        // 2. Aucune exécution : 100 % HTTP + SQL.
        'exécution de processus (façade)' => '/(?<!\w)Process::|Illuminate\\\\Support\\\\Facades\\\\Process\b|Symfony\\\\Component\\\\Process\b/',
        'exécution système (fonctions)' => '/\b(shell_exec|passthru|proc_open|popen|system|exec)\s*\(/',
        'commandes de système de fichiers' => '/\b(setfacl|getfacl|chown|chgrp|sudo)\b/',

        // 3. Un seul point de sortie HTTP, et c'est le client du framework.
        'client HTTP en curl nu' => '/\bcurl_(init|exec|setopt|setopt_array|close)\s*\(/',

        // 4. La ligne de contrat de l'Epic 60 reste INTOUCHÉE : cette story
        //    n'implémente pas de backend, ne nomme aucune case d'enum, n'écrit
        //    pas la colonne. Le jour où l'un de ces noms apparaît ici, ce n'est
        //    plus un chemin d'accès qu'on ajoute, c'est une autorité.
        'contrat de backend de fichiers' => '/\bFileBackend(Name|Registry|Outcome|Observation)?\b/',
    ];

    /**
     * Formes qu'un contributeur pressé écrirait, et que le scan DOIT voir.
     *
     * @var array<string, string>
     */
    private const NEEDLES = [
        'partage OCS' => "\$this->post('ocs/v2.php/apps/files_sharing/api/v1/shares', \$p);",
        'groupe Nextcloud' => "\$this->post('ocs/v1.php/cloud/groups', ['groupid' => \$g]);",
        'exécution de processus (façade)' => '$r = Process::run("occ app:enable files_external");',
        'exécution système (fonctions)' => '$out = shell_exec("occ user:add");',
        'commandes de système de fichiers' => '// on posera un setfacl côté serveur',
        'client HTTP en curl nu' => '$ch = curl_init($url);',
        'contrat de backend de fichiers' => 'use App\\Enums\\FileBackendName;',
    ];

    private static function repoPath(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . ltrim($relative, '/');
    }

    /** @return list<string> */
    private function violations(string $content): array
    {
        $violations = [];

        foreach (self::FORBIDDEN_RULES as $label => $pattern) {
            if (preg_match($pattern, $content) === 1) {
                $violations[] = $label;
            }
        }

        return $violations;
    }

    #[Test]
    public function the_nextcloud_namespace_writes_no_right_and_executes_nothing(): void
    {
        $dir = realpath(self::repoPath(self::NEXTCLOUD_DIR));
        self::assertNotFalse($dir, self::NEXTCLOUD_DIR . ' doit exister');

        $inspected = 0;
        $offenders = [];

        foreach ((new Finder())->files()->in($dir)->name('*.php') as $file) {
            $inspected++;
            $found = $this->violations((string) $file->getContents());
            if ($found !== []) {
                $offenders[$file->getRelativePathname()] = $found;
            }
        }

        // Méta-test de PÉRIMÈTRE : client, configuration, fabrique, définition de
        // montage, provisionnement, provisionneur d'utilisateurs, rapport, sonde,
        // résultat, échec, action de montage — plus, depuis 61.2, le rattachement
        // d'identité et le vérificateur de connexion. (La configuration, le client
        // et la sonde du compte porteur ont été retirés le 2026-08-08 : le seuil
        // baisse de 14 à 13, il ne se relâche pas.)
        self::assertGreaterThanOrEqual(
            13,
            $inspected,
            'la garde doit inspecter le namespace RÉEL de la story',
        );

        self::assertSame(
            [],
            $offenders,
            'LA STORY 61.1 A CESSÉ D\'ÊTRE UN CHEMIN D\'ACCÈS. Elle ajoute le web SANS déplacer '
            . 'l\'autorité : aucun partage OCS, aucun groupe Nextcloud, aucun processus, aucun curl nu, '
            . 'et rien du contrat de backend de l\'Epic 60. Fichiers fautifs : '
            . json_encode($offenders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    #[Test]
    public function the_story_files_outside_the_namespace_obey_the_same_rules(): void
    {
        foreach (self::STORY_FILES as $relative) {
            $path = self::repoPath($relative);
            self::assertFileExists($path, 'la garde doit trouver ' . $relative);

            self::assertSame(
                [],
                $this->violations((string) file_get_contents($path)),
                'LIGNE FRANCHIE PAR ' . $relative,
            );
        }
    }

    #[Test]
    public function the_scanner_actually_detects_each_forbidden_form(): void
    {
        self::assertSame(
            array_keys(self::FORBIDDEN_RULES),
            array_keys(self::NEEDLES),
            'chaque règle doit avoir son aiguille de vérification',
        );

        foreach (self::NEEDLES as $label => $needle) {
            self::assertContains(
                $label,
                $this->violations($needle),
                sprintf('la règle « %s » ne détecte pas son aiguille : le scan est aveugle', $label),
            );
        }

        // Contrôles NÉGATIFS : le vocabulaire LÉGITIME de la story ne déclenche
        // rien. Sans eux, une règle trop large rendrait le namespace inécrivable
        // et finirait désactivée — pire qu'absente.
        foreach ([
            "\$this->post('ocs/v1.php/cloud/users', ['userid' => \$login]);",
            "Http::withBasicAuth(\$user, \$secret)->get(\$url);",
            "'index.php/apps/files_external/globalstorages'",
            "'password::sessioncredentials'",
            '/** Samba tranche seul : SE5 n\'écrit aucun droit ici. */',
            "'remote.php/dav/files/' . rawurlencode(\$user)",
        ] as $honest) {
            self::assertSame([], $this->violations($honest), 'faux positif sur : ' . $honest);
        }
    }

    /**
     * **LE CLIENT N'A PAS DE MÉTHODE POUR CE QU'IL NE DOIT PAS FAIRE** (AC4).
     *
     * Le scan textuel ci-dessus attrape l'étourderie. Celui-ci constate la
     * PROPRIÉTÉ : la surface publique du client est fermée, et elle ne contient
     * ni partage, ni groupe, ni quota. Une méthode absente ne s'appelle pas par
     * distraction.
     */
    #[Test]
    public function the_client_surface_is_closed_and_contains_no_permission_writer(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\App\Services\Nextcloud\NextcloudAdminClient::class))
                ->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        sort($methods);

        self::assertSame([
            '__construct',
            'autocompleteUser',
            'createGlobalStorage',
            'createUser',
            'deleteGlobalStorage',
            'getUser',
            'listGlobalStorages',
            'probe',
            // Story 61.3 — LA SEULE MÉTHODE QUI S'AJOUTE, et elle est énumérée ici
            // pour que son ajout soit un GESTE, pas une dérive. Ce n'est pas un
            // droit : c'est le budget d'une PERSONNE, et l'état par-utilisateur est
            // exactement ce que ce client gouverne. Le plafond d'une ZONE reste hors
            // d'ici (frontière D8).
            'setUserPassword',
            'setUserQuota',
            'updateGlobalStorage',
        ], $methods, 'la surface du client est FERMÉE : ni partage, ni groupe, ni dossier d\'équipe');
    }

    /**
     * Story 61.3 — le NAMESPACE DU BACKEND, seul écrivain légitime des deux canaux
     * que les aiguilles ci-dessous interdisent partout ailleurs.
     */
    private const BACKEND_NAMESPACE_DIR = 'app/Services/Filesystem/Backend/Nextcloud';

    /**
     * **LA CRÉATION D'ARBORESCENCE DISTANTE A DÉSORMAIS UN PROPRIÉTAIRE — UN SEUL.**
     *
     * L'aiguille de la story 61.1 interdisait le verbe de création de collection
     * dans TOUT le code de production, parce qu'aucun code de production n'avait de
     * raison de le prononcer : le backend qui en a besoin n'existait pas. Il existe.
     * L'aiguille n'est donc pas RETIRÉE — elle est RE-PÉRIMÉTRÉE : elle interdit ce
     * verbe partout SAUF sous le namespace du backend.
     *
     * Une garde qu'on re-périmètre au moment où un besoin légitime apparaît, et qui
     * nomme ce périmètre, reste une garde. Une garde qu'on supprime parce qu'elle
     * gêne n'en est plus une.
     *
     * Le contrôle est en DEUX MOITIÉS, et la seconde n'est pas décorative : si le
     * backend ne contenait AUCUNE création de collection, la première moitié serait
     * verte pour la pire des raisons — l'arborescence ne serait créée par personne.
     */
    #[Test]
    public function only_the_backend_namespace_creates_a_remote_collection(): void
    {
        $offenders = [];
        $backendUses = false;

        foreach ((new Finder())->files()->in(realpath(self::repoPath('app')))->name('*.php') as $file) {
            if (preg_match('/[\'"]MKCOL[\'"]/', (string) $file->getContents()) !== 1) {
                continue;
            }

            $relative = str_replace(self::repoPath(''), '', (string) $file->getRealPath());

            if (str_starts_with($relative, self::BACKEND_NAMESPACE_DIR . '/')) {
                $backendUses = true;

                continue;
            }

            $offenders[] = $relative;
        }

        // NÉGATIF : personne d'autre.
        self::assertSame(
            [],
            $offenders,
            'la création d\'arborescence distante appartient au SEUL backend de fichiers Nextcloud '
            . '(« ' . self::BACKEND_NAMESPACE_DIR . ' ») : fichiers fautifs : '
            . json_encode($offenders, JSON_UNESCAPED_SLASHES),
        );

        // POSITIF : lui, oui.
        self::assertTrue(
            $backendUses,
            'le backend de fichiers Nextcloud DOIT créer l\'arborescence : si plus personne ne le fait, '
            . 'la garde ci-dessus est verte pour la mauvaise raison.',
        );
    }

    /**
     * **LES GROUPES DE L'INSTANCE : MÊME RE-PÉRIMÉTRAGE, MÊME MOTIF.**
     *
     * La story 61.1 interdisait ce canal parce que, à l'époque, un groupe distant
     * n'existait que pour restreindre un montage ou porter un partage — c'est-à-dire
     * pour déplacer l'arbitrage des droits hors de l'autorité de la zone. Depuis
     * 61.3, un groupe distant est l'ARTEFACT COMPILÉ d'une audience du plan, dans une
     * zone dont Nextcloud EST l'autorité. Le motif de l'interdiction a disparu là, et
     * seulement là.
     */
    #[Test]
    public function only_the_backend_namespace_writes_instance_groups(): void
    {
        $offenders = [];
        $backendUses = false;

        foreach ((new Finder())->files()->in(realpath(self::repoPath('app')))->name('*.php') as $file) {
            if (preg_match('#cloud/groups#i', (string) $file->getContents()) !== 1) {
                continue;
            }

            $relative = str_replace(self::repoPath(''), '', (string) $file->getRealPath());

            if (str_starts_with($relative, self::BACKEND_NAMESPACE_DIR . '/')) {
                $backendUses = true;

                continue;
            }

            $offenders[] = $relative;
        }

        self::assertSame(
            [],
            $offenders,
            'les groupes de l\'instance n\'appartiennent qu\'au backend de fichiers Nextcloud : fichiers '
            . 'fautifs : ' . json_encode($offenders, JSON_UNESCAPED_SLASHES),
        );

        self::assertTrue($backendUses, 'le backend DOIT compiler les audiences du plan en groupes distants');
    }

    /**
     * **LE PARTAGE OCS RESTE INTERDIT PARTOUT — Y COMPRIS AU BACKEND.**
     *
     * C'est la seule des trois aiguilles qui ne bouge pas, et c'est le point : le
     * mécanisme de partage est celui dont le sondage d'ouverture d'epic a MESURÉ
     * qu'il ment (une instruction de retrait acceptée en succès, sans effet, relue
     * avec un accès). Le backend de 61.3 ne s'en sert pas — il emploie un dossier
     * d'équipe et ses permissions avancées, qui savent, eux, refermer. Le jour où ce
     * nom réapparaît dans le code de production, la clôture a cessé d'être effective
     * sans que rien d'autre ne le dise.
     *
     * Elle s'est même RESSERRÉE le 2026-08-08 : elle portait sur le préfixe de route
     * pour laisser la sonde du mode délégué lire une capacité de l'instance ; le
     * mode délégué ayant disparu, elle est revenue à sa forme large.
     */
    #[Test]
    public function no_production_code_anywhere_creates_an_ocs_share(): void
    {
        $offenders = [];

        foreach ((new Finder())->files()->in(realpath(self::repoPath('app')))->name('*.php') as $file) {
            if (preg_match('#files_sharing#i', (string) $file->getContents()) === 1) {
                $offenders[] = str_replace(self::repoPath(''), '', (string) $file->getRealPath());
            }
        }

        self::assertSame(
            [],
            $offenders,
            'AUCUN code de production n\'a de motif de prononcer le mécanisme de partage : il propage depuis '
            . 'l\'ancêtre et accepte le retrait SANS EFFET. Fichiers fautifs : '
            . json_encode($offenders, JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Story 61.3 — **AUCUN OUTIL EN LIGNE DE COMMANDE DANS LE BACKEND.**
     *
     * L'outil d'administration de l'instance sait tout faire, et le sondage 60.0 s'en
     * servait pour poser sa clôture. Il suppose un accès système AU SERVEUR
     * NEXTCLOUD — qu'on n'a pas sur une instance distante, et qu'on n'aura jamais sur
     * une instance tierce. S'y replier serait la simplification qui rend le backend
     * inutilisable là où il doit servir, et elle ne se verrait qu'en production.
     *
     * Le backend est donc 100 % HTTP : falsifiable, testable sans réseau, à chaque
     * exécution de la suite.
     */
    #[Test]
    public function the_file_backend_shells_out_to_nothing(): void
    {
        $dir = realpath(self::repoPath(self::BACKEND_NAMESPACE_DIR));
        self::assertNotFalse($dir, self::BACKEND_NAMESPACE_DIR . ' doit exister');

        $inspected = 0;
        $offenders = [];

        foreach ((new Finder())->files()->in($dir)->name('*.php') as $file) {
            $inspected++;
            $content = (string) $file->getContents();

            $found = [];
            foreach ([
                'exécution de processus (façade)' => self::FORBIDDEN_RULES['exécution de processus (façade)'],
                'exécution système (fonctions)' => self::FORBIDDEN_RULES['exécution système (fonctions)'],
                'client HTTP en curl nu' => self::FORBIDDEN_RULES['client HTTP en curl nu'],
                'partage OCS' => self::FORBIDDEN_RULES['partage OCS'],
                // L'outil d'administration de l'instance, nommé. Le citer dans un
                // commentaire suffit à ce qu'il finisse appelé — c'est le constat
                // qui a fait porter tous ces scans sur le texte, commentaires
                // compris.
                'outil d\'administration en ligne de commande' => '/\bocc\s+[a-z]+:/i',
            ] as $label => $pattern) {
                if (preg_match($pattern, $content) === 1) {
                    $found[] = $label;
                }
            }

            if ($found !== []) {
                $offenders[$file->getRelativePathname()] = $found;
            }
        }

        self::assertGreaterThanOrEqual(
            7,
            $inspected,
            'la garde doit inspecter le namespace RÉEL du backend',
        );

        self::assertSame(
            [],
            $offenders,
            'LE BACKEND EST 100 % HTTP. Un shell-out suppose un accès système au serveur Nextcloud, qu\'on '
            . 'n\'a pas sur une instance distante ou tierce. Fichiers fautifs : '
            . json_encode($offenders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Story 61.3 — **LE `sub` D'UN JETON N'EST PAS UNE CLÉ DE JOINTURE.**
     *
     * L'Epic 55 publie `sub = login`. C'est un choix de CLAIM, révocable, pas un
     * contrat de jointure — et s'en servir pour retrouver un compte marcherait
     * aujourd'hui, sur cette instance, avec ce réglage. La vérité de liaison est le
     * cache d'identité : reconstructible, vérifié à distance, porteur d'une garde
     * d'unicité. Le backend ne connaît donc RIEN du vocabulaire de la fédération.
     */
    #[Test]
    public function the_file_backend_never_joins_on_a_federation_claim(): void
    {
        $offenders = [];

        foreach ((new Finder())->files()->in(realpath(self::repoPath(self::BACKEND_NAMESPACE_DIR)))->name('*.php') as $file) {
            $content = (string) $file->getContents();
            $found = [];

            foreach ([
                'claim OIDC' => '/[\'"]sub[\'"]\s*=>|\bclaims?\[|\bgetClaim\b|\bidToken\b/i',
                'vocabulaire de fédération' => '/\bExternalIdentity\b|\bOidc[A-Z]|\bKeycloak\b|\bJwt\b/',
            ] as $label => $pattern) {
                if (preg_match($pattern, $content) === 1) {
                    $found[] = $label;
                }
            }

            if ($found !== []) {
                $offenders[$file->getRelativePathname()] = $found;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'LE CACHE D\'IDENTITÉ EST LA SEULE CLÉ DE JOINTURE. Fichiers fautifs : '
            . json_encode($offenders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Story 61.3 — **LA FRONTIÈRE D8, TENUE DES DEUX CÔTÉS.**
     *
     * Deux plafonds, deux objets, et ils ne se recouvrent jamais : la recette
     * plafonne une ZONE (le dossier d'équipe), la règle de quota budgète une
     * PERSONNE (son compte sur l'instance). Les confondre ferait écrire un quota
     * d'utilisateur par une recette de partage — la violation exacte que D8 nomme.
     *
     * La garde est symétrique, parce qu'une frontière tenue d'un seul côté n'est
     * pas une frontière : le backend n'a aucun chemin vers les comptes, et le
     * provisionnement des comptes n'a aucun chemin vers les dossiers d'équipe.
     */
    #[Test]
    public function the_zone_quota_and_the_person_quota_never_cross(): void
    {
        $backendCrossings = [];

        foreach ((new Finder())->files()->in(realpath(self::repoPath(self::BACKEND_NAMESPACE_DIR)))->name('*.php') as $file) {
            if (preg_match('#cloud/users#i', (string) $file->getContents()) === 1) {
                $backendCrossings[] = $file->getRelativePathname();
            }
        }

        // Le backend PARLE aux comptes — l'appartenance à un groupe passe par la
        // même route. Ce qu'il ne fait JAMAIS, c'est écrire un quota de compte : la
        // mise à jour clé/valeur d'un compte porte une clé, et cette clé-là lui est
        // interdite.
        foreach ((new Finder())->files()->in(realpath(self::repoPath(self::BACKEND_NAMESPACE_DIR)))->name('*.php') as $file) {
            if (preg_match('/[\'"]key[\'"]\s*=>\s*[\'"]quota[\'"]/i', (string) $file->getContents()) === 1) {
                self::fail('le backend écrit un quota de COMPTE : la frontière D8 est franchie par '
                    . $file->getRelativePathname());
            }
        }

        self::assertNotSame([], $backendCrossings, 'le backend converge bien l\'appartenance des comptes aux groupes');

        $provisioningCrossings = [];

        foreach ([
            'app/Services/Nextcloud/NextcloudUserProvisioner.php',
            'app/Services/Nextcloud/NextcloudProvisioningService.php',
            'app/Services/Nextcloud/NextcloudAdminClient.php',
        ] as $relative) {
            $path = self::repoPath($relative);
            self::assertFileExists($path, 'la garde doit trouver ' . $relative);

            if (preg_match('#groupfolders#i', (string) file_get_contents($path)) === 1) {
                $provisioningCrossings[] = $relative;
            }
        }

        self::assertSame(
            [],
            $provisioningCrossings,
            'LE PROVISIONNEMENT DES COMPTES NE PLAFONNE PAS UNE ZONE. Fichiers fautifs : '
            . json_encode($provisioningCrossings, JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * **LE PROVISIONNEMENT NE SUPPRIME AUCUN MONTAGE** (drift STRICT).
     *
     * La méthode de suppression existe pour la seule obligation du test
     * d'intégration (laisser l'instance de sondage dans l'état trouvé). Qu'elle
     * existe et qu'elle ne soit jamais appelée en production sont deux faits
     * différents ; le second se vérifie.
     */
    #[Test]
    public function the_provisioning_never_deletes_a_mount(): void
    {
        $callers = [];

        foreach ([
            (new Finder())->files()->in(realpath(self::repoPath('app')))->name('*.php'),
            (new Finder())->files()->in(realpath(self::repoPath('resources/views/pages')))->name('*.blade.php'),
        ] as $finder) {
            foreach ($finder as $file) {
                if (str_contains((string) $file->getContents(), 'deleteGlobalStorage')) {
                    $callers[] = str_replace(self::repoPath(''), '', (string) $file->getRealPath());
                }
            }
        }

        self::assertSame(
            ['app/Services/Nextcloud/NextcloudAdminClient.php'],
            $callers,
            'SE5 ne gouverne que ce qu\'il a déclaré : un montage ne se supprime pas depuis le '
            . 'provisionnement. Seule la déclaration de la méthode est attendue ici.',
        );
    }
}
