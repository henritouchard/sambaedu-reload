<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Finder\Finder;

/**
 * LES GARDES DU CHANTIER OPENCLOUD — positives ET négatives.
 *
 * ---------------------------------------------------------------------------
 * **LA LIGNE QUI COMPTE LE PLUS ICI : LE BACKEND EST 100 % HTTP, LE DÉPLOIEMENT
 * EXÉCUTE DES CONTENEURS, ET LES DEUX NE SE RENCONTRENT JAMAIS.**
 *
 * L'instance est hébergée chez nous — et c'est précisément ce qui rend la
 * tentation dangereuse. Un backend qui se replierait sur `docker exec` ou sur
 * l'outil en ligne de commande du produit marcherait parfaitement ici, et
 * deviendrait inexécutable le jour où l'instance serait ailleurs. Ce défaut-là ne
 * se voit qu'en production, sur une autre machine que celle du développeur.
 *
 * La garde est donc en DEUX MOITIÉS : le vocabulaire d'exécution est INTERDIT
 * sous le namespace du backend, et il est EXIGÉ sous celui du déploiement. Sans
 * la seconde moitié, la première serait verte pour la pire des raisons — plus
 * personne ne déploierait rien.
 * ---------------------------------------------------------------------------
 */
class OpenCloudNamespaceTest extends TestCase
{
    /** Le namespace du BACKEND : sous la ligne de contrat, 100 % HTTP. */
    private const BACKEND_DIR = 'app/Services/Filesystem/Backend/OpenCloud';

    /** Le namespace de la CONNEXION : configuration, transport, sonde. Aucune écriture. */
    private const CONNECTION_DIR = 'app/Services/OpenCloud';

    /** Le namespace du DÉPLOIEMENT : le SEUL endroit où un conteneur s'exécute. */
    private const DEPLOYMENT_DIR = 'app/Services/OpenCloud/Deployment';

    /** @var array<string, string> */
    private const FORBIDDEN_RULES = [
        'exécution de processus (façade)' => '/(?<!\w)Process::|Illuminate\\\\Support\\\\Facades\\\\Process\b|Symfony\\\\Component\\\\Process\b/',
        'exécution système (fonctions)' => '/\b(shell_exec|passthru|proc_open|popen|system|exec)\s*\(/',
        'client HTTP en curl nu' => '/\bcurl_(init|exec|setopt|setopt_array|close)\s*\(/',
        'invocation de conteneur' => '/\bdocker(\s+(run|exec|compose|ps|stop|rm))?\b/i',
        'outil en ligne de commande du produit' => '/\bopencloud\s+(init|server|proxy|version)\b/i',
        'commandes de système de fichiers' => '/\b(setfacl|getfacl|chown|chgrp|sudo)\b/',
    ];

    /**
     * Formes qu'un contributeur pressé écrirait, et que le scan DOIT voir. Sans ce
     * méta-test, une règle mal orthographiée passerait éternellement au vert.
     *
     * @var array<string, string>
     */
    private const NEEDLES = [
        'exécution de processus (façade)' => '$r = Process::run("docker restart oc");',
        'exécution système (fonctions)' => '$out = shell_exec("opencloud init");',
        'client HTTP en curl nu' => '$ch = curl_init($url);',
        'invocation de conteneur' => "\$this->runner->run(['docker', 'exec', 'oc']);",
        'outil en ligne de commande du produit' => '// au pire, un opencloud init suffirait',
        'commandes de système de fichiers' => '// on posera un setfacl côté serveur',
    ];

    private static function repoPath(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . ltrim($relative, '/');
    }

    /**
     * @param  list<string>  $rules
     * @return list<string>
     */
    private function violations(string $content, array $rules): array
    {
        $found = [];
        foreach ($rules as $label) {
            if (preg_match(self::FORBIDDEN_RULES[$label], $content) === 1) {
                $found[] = $label;
            }
        }

        return $found;
    }

    #[Test]
    public function every_rule_has_a_needle_that_the_scanner_actually_sees(): void
    {
        self::assertSame(
            array_keys(self::FORBIDDEN_RULES),
            array_keys(self::NEEDLES),
            'chaque règle doit avoir son aiguille de vérification',
        );

        foreach (self::NEEDLES as $label => $needle) {
            self::assertContains(
                $label,
                $this->violations($needle, array_keys(self::FORBIDDEN_RULES)),
                sprintf('la règle « %s » ne détecte pas son aiguille : le scan est aveugle', $label),
            );
        }

        // Contrôles NÉGATIFS : le vocabulaire LÉGITIME ne déclenche rien. Sans eux,
        // une règle trop large rendrait le namespace inécrivable et finirait
        // désactivée — pire qu'absente.
        foreach ([
            "Http::withBasicAuth(\$user, \$secret)->get(\$url);",
            "'graph/v1beta1/drives/' . \$spaceId . '/root/permissions'",
            "\$this->transport->sendRaw('MKCOL', \$path, \$operation, [405], [409]);",
            "/** L'espace de projet est ADOPTÉ sur l'inventaire relu. */",
            "\$spaces->setSpaceQuota(\$spaceId, \$bytes);",
        ] as $honest) {
            self::assertSame(
                [],
                $this->violations($honest, array_keys(self::FORBIDDEN_RULES)),
                'faux positif sur : ' . $honest,
            );
        }
    }

    /**
     * **LE BACKEND N'EXÉCUTE RIEN — NI PROCESSUS, NI CONTENEUR, NI OUTIL DU
     * PRODUIT.** Il est falsifiable, donc testable sans réseau, à chaque exécution
     * de la suite.
     */
    #[Test]
    public function the_file_backend_executes_nothing_at_all(): void
    {
        $dir = realpath(self::repoPath(self::BACKEND_DIR));
        self::assertNotFalse($dir, self::BACKEND_DIR . ' doit exister');

        $inspected = 0;
        $offenders = [];

        foreach ((new Finder())->files()->in($dir)->name('*.php') as $file) {
            $inspected++;
            $found = $this->violations((string) $file->getContents(), array_keys(self::FORBIDDEN_RULES));
            if ($found !== []) {
                $offenders[$file->getRelativePathname()] = $found;
            }
        }

        // Méta-test de PÉRIMÈTRE : backend, projection, projecteur, table de rôles,
        // octroi observé, client d'espaces, client d'annuaire.
        self::assertGreaterThanOrEqual(7, $inspected, 'la garde doit inspecter le namespace RÉEL du backend');

        self::assertSame(
            [],
            $offenders,
            'LE BACKEND EST 100 % HTTP. Un repli sur un conteneur ou sur l\'outil local suppose un accès '
            . 'système AU SERVEUR, qu\'on n\'a pas sur une instance distante — et ce défaut ne se voit '
            . 'qu\'en production. Fichiers fautifs : '
            . json_encode($offenders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * **LA CONNEXION NON PLUS N'EXÉCUTE RIEN** — hors le sous-dossier du
     * déploiement, qui est justement le seul à devoir le faire.
     */
    #[Test]
    public function the_connection_namespace_executes_nothing_outside_the_deployment(): void
    {
        $offenders = [];

        foreach ((new Finder())->files()->in(realpath(self::repoPath(self::CONNECTION_DIR)))
            ->exclude('Deployment')->name('*.php') as $file) {
            $found = $this->violations((string) $file->getContents(), array_keys(self::FORBIDDEN_RULES));
            if ($found !== []) {
                $offenders[$file->getRelativePathname()] = $found;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'LA CONNEXION EST DU RÉGLAGE ET DU HTTP. Fichiers fautifs : '
            . json_encode($offenders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * **LA MOITIÉ POSITIVE — et elle n'est pas décorative.** Sans elle, la garde
     * ci-dessus serait verte pour la pire des raisons : plus personne ne
     * déploierait rien, et l'interdit protégerait un vide.
     *
     * Elle constate une propriété plus forte que « quelqu'un exécute des
     * conteneurs » : **le côté PHP n'en parle même pas.** Le pilote compose des
     * verbes fermés, un seul fichier lance un processus (l'implémentation du seam),
     * et le mot « conteneur » n'existe qu'au-delà de la frontière de privilège,
     * dans le script root. C'est ce découpage qui fait qu'un bug du pilote ne peut
     * pas faire tourner un conteneur arbitraire.
     */
    #[Test]
    public function only_the_privileged_script_beyond_the_seam_invokes_containers(): void
    {
        $processRunners = [];

        foreach ((new Finder())->files()->in(realpath(self::repoPath(self::DEPLOYMENT_DIR)))->name('*.php') as $file) {
            $content = (string) $file->getContents();

            // NÉGATIF : aucun fichier PHP n'invoque un conteneur — pas même le
            // pilote de déploiement, qui ne fait que NOMMER des verbes fermés.
            self::assertSame(
                0,
                preg_match(self::FORBIDDEN_RULES['invocation de conteneur'], $content),
                'un fichier PHP invoque un conteneur : ' . $file->getRelativePathname(),
            );

            if (preg_match(self::FORBIDDEN_RULES['exécution système (fonctions)'], $content) === 1) {
                $processRunners[] = $file->getRelativePathname();
            }
        }

        // Un SEUL fichier lance un processus : l'implémentation du seam.
        self::assertSame(
            ['SudoOpenCloudHelperRunner.php'],
            $processRunners,
            'un seul fichier a le droit de lancer un processus : l\'implémentation du seam privilégié',
        );

        // POSITIF : au-delà de la frontière, le script root, lui, exécute bien des
        // conteneurs — sinon rien ne serait déployé.
        $script = (string) file_get_contents(self::repoPath('scripts/system/sambaedu-opencloud-helper.sh'));
        self::assertSame(
            1,
            preg_match('/\bdocker\s+compose\s+-f[^\n]*\bup\b/', $script),
            'le script privilégié DOIT faire converger la composition : sans lui, la garde négative '
            . 'ci-dessus est verte pour la mauvaise raison',
        );
    }

    /**
     * **LE SCRIPT PRIVILÉGIÉ N'A AUCUN VERBE DESTRUCTEUR.** C'est une propriété
     * STRUCTURELLE : `docker compose down` n'est jamais formé, aucune option de
     * suppression de volume n'apparaît, et il n'existe pas de sous-commande de
     * suppression à appeler. « Les données survivent à l'outil » n'est donc pas une
     * promesse de commentaire.
     */
    #[Test]
    public function the_privileged_script_cannot_destroy_anything(): void
    {
        $script = self::repoPath('scripts/system/sambaedu-opencloud-helper.sh');
        self::assertFileExists($script, 'le script privilégié doit être livré par le dépôt');

        $content = (string) file_get_contents($script);

        foreach ([
            'suppression de composition' => '/compose[^\n]*\bdown\b/i',
            'suppression de volumes' => '/--volumes|\bdocker\s+volume\s+rm\b/i',
            'suppression de conteneur' => '/\bdocker\s+rm\b/i',
            'purge d\'images' => '/\bdocker\s+(system\s+)?prune\b/i',
            'effacement récursif' => '/\brm\s+-[a-z]*r/i',
        ] as $label => $pattern) {
            self::assertSame(
                0,
                preg_match($pattern, $content),
                'LE SCRIPT PEUT DÉTRUIRE : ' . $label,
            );
        }

        // Et sa liste de verbes est FERMÉE — quatre, et pas un de plus.
        self::assertMatchesRegularExpression('/deploy\)\s/', $content);
        self::assertMatchesRegularExpression('/status\)\s/', $content);
        self::assertMatchesRegularExpression('/stop\)\s+/', $content);
        self::assertMatchesRegularExpression('/logs\)\s+/', $content);
        self::assertSame(
            1,
            preg_match('/sous-commande inconnue/', $content),
            'un verbe hors liste doit être REFUSÉ, jamais ignoré',
        );
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * **LE SECRET N'ENTRE JAMAIS DANS UNE LIGNE DE COMMANDE.**
     *
     * L'en-tête du script privilégié explique lui-même pourquoi le mot de passe
     * arrive par l'entrée standard : `/proc/<pid>/cmdline` est lisible par
     * N'IMPORTE QUEL utilisateur de la machine, et `sudo` journalise la commande
     * complète. Le passer ensuite en argument au moteur de conteneurs
     * (`-e NOM=valeur`) le remettrait exactement là où l'en-tête refuse qu'il
     * soit — et ce fut le cas.
     *
     * La forme correcte est l'ENVIRONNEMENT : on exporte, et le moteur hérite par
     * `-e NOM` **sans valeur**. `/proc/<pid>/environ`, lui, n'est lisible que par
     * le propriétaire du processus.
     *
     * Le scan est TEXTUEL et il a ses aiguilles, comme les autres gardes de ce
     * fichier : une règle qui ne voit pas sa propre aiguille est une garde
     * aveugle, verte pour toujours.
     * ═══════════════════════════════════════════════════════════════════════
     */
    #[Test]
    public function no_secret_ever_reaches_a_command_line_in_the_privileged_script(): void
    {
        $rules = [
            'secret en argument nommé du moteur' => '/(?:-e|--env)\s+["\x27]?[A-Za-z_]*'
                . '(?:PASSWORD|PASSWD|SECRET|TOKEN|APIKEY)[A-Za-z_]*=/i',
            'interpolation du secret dans une commande' => '/\b(?:docker|sudo|sh|bash)\b[^\n]*\$\{?secret\b/i',
        ];

        // Méta-test : chaque règle VOIT la forme qu'un contributeur pressé
        // écrirait. Sans lui, une expression mal écrite passerait éternellement.
        foreach ([
            'secret en argument nommé du moteur' => '            -e "IDM_ADMIN_PASSWORD=${secret}" \\',
            'interpolation du secret dans une commande' => '    docker run --rm -e X=$secret "$IMAGE" init',
        ] as $label => $needle) {
            self::assertSame(
                1,
                preg_match($rules[$label], $needle),
                sprintf('la règle « %s » ne détecte pas son aiguille : le scan est aveugle', $label),
            );
        }

        // Contrôles NÉGATIFS : la forme CORRECTE ne déclenche rien, et une
        // variable d'environnement non secrète non plus.
        foreach ([
            '            -e IDM_ADMIN_PASSWORD \\',
            '            -e OC_INSECURE=true \\',
            '        export IDM_ADMIN_PASSWORD="$secret"',
        ] as $honest) {
            foreach ($rules as $label => $pattern) {
                self::assertSame(0, preg_match($pattern, $honest), 'faux positif (' . $label . ') sur : ' . $honest);
            }
        }

        $script = (string) file_get_contents(self::repoPath('scripts/system/sambaedu-opencloud-helper.sh'));

        foreach ($rules as $label => $pattern) {
            self::assertSame(
                0,
                preg_match($pattern, $script),
                'LE SECRET EST DANS UNE LIGNE DE COMMANDE (' . $label . ') : /proc/<pid>/cmdline est '
                . 'lisible par tous, et sudo journalise la commande complète.',
            );
        }

        // POSITIF : le secret est bien transmis, et il l'est par l'ENVIRONNEMENT.
        // Sans cette moitié, la garde ci-dessus serait verte le jour où plus rien
        // ne serait transmis du tout — et l'instance ne s'initialiserait plus.
        self::assertSame(
            1,
            preg_match('/export\s+IDM_ADMIN_PASSWORD=/', $script),
            'le secret doit être EXPORTÉ dans l\'environnement du processus',
        );
        self::assertSame(
            1,
            preg_match('/-e\s+IDM_ADMIN_PASSWORD\s*\\\\/', $script),
            'le moteur doit HÉRITER de la variable (« -e NOM », sans valeur)',
        );
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * **LES VOLUMES N'APPARTIENNENT JAMAIS À UN UID ÉCRIT EN DUR.**
     *
     * Le fichier de configuration de l'instance porte ses secrets internes —
     * jeton de signature, clés d'API de service, mot de passe de l'annuaire. Le
     * poser sur l'uid `1000`, c'est-à-dire sur le premier compte HUMAIN d'une
     * Debian ordinaire, revient à donner à un utilisateur sans privilège de quoi
     * forger un jeton pour n'importe quel compte de l'instance et lire les
     * fichiers de tous les élèves.
     *
     * L'identité d'exécution se RÉSOUT donc à l'exécution, depuis un compte
     * système dédié — et l'absence de ce compte est un refus nommé, jamais un
     * repli.
     * ═══════════════════════════════════════════════════════════════════════
     */
    #[Test]
    public function the_instance_volumes_never_belong_to_a_hardcoded_uid(): void
    {
        $script = (string) file_get_contents(self::repoPath('scripts/system/sambaedu-opencloud-helper.sh'));

        // NÉGATIF : aucun uid/gid littéral affecté à l'identité d'exécution.
        self::assertSame(
            0,
            preg_match('/\bRUN_(?:UID|GID)=\s*["\x27]?\d/', $script),
            'L\'IDENTITÉ D\'EXÉCUTION EST UN LITTÉRAL : un uid en dur finit sur le premier compte humain '
            . 'de la machine, et avec lui les secrets internes de l\'instance.',
        );

        // POSITIF : elle est dérivée d'un compte système dédié, et son absence
        // est un refus NOMMÉ — jamais un repli silencieux.
        self::assertSame(1, preg_match('/\bRUN_USER="[a-z0-9_-]+"/', $script), 'un compte dédié doit être nommé');
        self::assertSame(
            1,
            preg_match('/RUN_UID="\$\(id -u "\$RUN_USER"/', $script),
            'l\'uid doit être RÉSOLU depuis le compte dédié',
        );
        self::assertSame(
            1,
            preg_match('/useradd --system/', $script),
            'le compte système doit être créé s\'il manque',
        );
        self::assertMatchesRegularExpression(
            '/n\'a pas pu être (créé|résolue)/',
            $script,
            'l\'échec de résolution doit être un refus NOMMÉ',
        );

        // Et le processus doit tourner SOUS ce compte : la propriété des volumes
        // et l'identité d'exécution se posent ensemble, ou aucune des deux ne
        // tient.
        self::assertSame(
            1,
            preg_match('/user: "\$\{RUN_UID\}:\$\{RUN_GID\}"/', $script),
            'la composition doit faire tourner le service sous le compte dédié',
        );

        // L'installeur le pose lui aussi, pour qu'un déploiement ne soit pas le
        // premier à créer un compte système.
        self::assertSame(
            1,
            preg_match('/useradd --system[^;]{0,240}sambaedu-opencloud/', (string) file_get_contents(self::repoPath('scripts/install.sh'))),
            'l\'installeur doit poser le compte système dédié',
        );
    }

    /**
     * **LE SEAM DU SYSTÈME D'EXTENSIONS N'EST PAS ÉLARGI — dans les DEUX SENS.**
     *
     * Élargir ses verbes pour ce chantier reconstituerait le couplage défait le
     * 2026-08-08 : administrer une instance et installer une extension ne sont pas
     * le même livrable. La frontière se vérifie donc des deux côtés — le chantier
     * OpenCloud ne connaît pas le système d'extensions, et le système d'extensions
     * ne connaît pas OpenCloud.
     */
    #[Test]
    public function the_extension_system_boundary_holds_in_both_directions(): void
    {
        $crossings = [];

        foreach ([self::BACKEND_DIR, self::CONNECTION_DIR] as $dir) {
            foreach ((new Finder())->files()->in(realpath(self::repoPath($dir)))->name('*.php') as $file) {
                if (preg_match('/\bExtension[A-Z]\w*|App\\\\Services\\\\Extensions\b|sambaedu-ext/', (string) $file->getContents()) === 1) {
                    $crossings[] = $file->getRelativePathname();
                }
            }
        }

        self::assertSame(
            [],
            $crossings,
            'LE CHANTIER OPENCLOUD NE CONNAÎT PAS LE SYSTÈME D\'EXTENSIONS : '
            . json_encode($crossings, JSON_UNESCAPED_SLASHES),
        );

        // L'autre sens : le helper des extensions ignore tout d'OpenCloud, et son
        // canal d'installation n'a pas bougé.
        $helper = (string) file_get_contents(self::repoPath('scripts/system/sambaedu-ext-helper.sh'));
        self::assertSame(0, preg_match('/opencloud/i', $helper), 'le helper des extensions a été élargi');

        foreach ((new Finder())->files()->in(realpath(self::repoPath('app/Services/Extensions')))->name('*.php') as $file) {
            self::assertSame(
                0,
                preg_match('/opencloud/i', (string) $file->getContents()),
                'le système d\'extensions connaît OpenCloud : ' . $file->getRelativePathname(),
            );
        }
    }

    /**
     * **LE `sub` D'UN JETON N'EST PAS UNE CLÉ DE JOINTURE — et la tentation est
     * PLUS FORTE ICI QUE PARTOUT AILLEURS.**
     *
     * SE5 est le fournisseur d'identité de l'instance qu'il vient lui-même de
     * déployer : il serait trivial de « savoir » que le compte distant porte le
     * login publié en revendication. Ce serait un choix de CLAIM, révocable, et il
     * marcherait jusqu'au jour où quelqu'un le change — où l'accès d'un élève
     * irait chez un autre.
     */
    #[Test]
    public function the_file_backend_never_joins_on_a_federation_claim(): void
    {
        $offenders = [];

        foreach ((new Finder())->files()->in(realpath(self::repoPath(self::BACKEND_DIR)))->name('*.php') as $file) {
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
     * **LA FRONTIÈRE D8, TENUE DES DEUX CÔTÉS.**
     *
     * Deux plafonds, deux objets : la recette plafonne une ZONE (l'espace de
     * projet), la règle de quota budgète une PERSONNE (son lecteur personnel). Une
     * frontière tenue d'un seul côté n'est pas une frontière.
     */
    #[Test]
    public function the_zone_quota_and_the_person_quota_never_cross(): void
    {
        $reads = false;

        foreach ((new Finder())->files()->in(realpath(self::repoPath(self::BACKEND_DIR)))->name('*.php') as $file) {
            $content = (string) $file->getContents();

            // Le backend LIT l'annuaire des comptes — il n'a pas le choix : l'API
            // refuse de filtrer sur l'identifiant de connexion, donc retrouver un
            // compte n'a pas d'autre chemin que l'énumération. Ce qui lui est
            // interdit, c'est d'ÉCRIRE dessus.
            if (preg_match('#[\'"]graph/v1\.0/users[\'"]#', $content) === 1) {
                $reads = true;
            }

            self::assertSame(
                0,
                preg_match('/->(post|patch|delete)\(\s*self::USERS/', $content),
                'le backend ÉCRIT sur les comptes : la frontière D8 est franchie par ' . $file->getRelativePathname(),
            );
            self::assertSame(
                0,
                preg_match('/[\'"]quota[\'"]\s*=>[^\n]*user|setUserQuota/i', $content),
                'le backend budgète une PERSONNE : ' . $file->getRelativePathname(),
            );
            self::assertSame(
                0,
                preg_match('#me/drive|driveType[\'"]?\s*[=:]+\s*[\'"]personal#i', $content),
                'le backend atteint un lecteur PERSONNEL : ' . $file->getRelativePathname(),
            );
        }

        self::assertTrue($reads, 'le backend DOIT pouvoir lire l\'annuaire des comptes (aucun filtre côté API)');

        // L'autre sens : le client d'administration ne plafonne AUCUNE zone et
        // n'écrit rien du tout.
        $admin = (string) file_get_contents(self::repoPath('app/Services/OpenCloud/OpenCloudAdminClient.php'));
        self::assertSame(0, preg_match('/->(post|patch|delete)\(/', $admin), 'la sonde ÉCRIT');
        self::assertSame(0, preg_match('/quota/i', $admin), 'la sonde plafonne quelque chose');
    }

    /**
     * **LES SURFACES DE CLIENT SONT FERMÉES ET ÉPINGLÉES.** Une méthode absente ne
     * s'appelle pas par distraction ; une méthode ajoutée devient un GESTE, pas une
     * dérive.
     *
     * Le point le plus important de cette liste est ce qu'elle NE contient PAS :
     * aucune suppression d'espace. Révoquer, c'est retirer les octrois — détruire
     * une zone n'est le geste d'aucune réconciliation (D9), et une méthode de
     * suppression dans le code de production serait une arme chargée posée sur la
     * table.
     */
    #[Test]
    public function every_client_surface_is_closed_and_pinned(): void
    {
        self::assertSame([
            '__construct',
            'baseUrl',
            'createSpace',
            'deleteItemPermission',
            'deleteRootPermission',
            'inviteOnItem',
            'inviteOnRoot',
            'listChildren',
            'listItemPermissions',
            'listRootPermissions',
            'listSpaces',
            'makeFolder',
            'readSpace',
            'setSpaceQuota',
            'updateItemPermission',
            'updateRootPermission',
        ], $this->publicMethodsOf(\App\Services\Filesystem\Backend\OpenCloud\OpenCloudSpaceClient::class),
            'la surface du client d\'espaces est FERMÉE : aucune suppression d\'espace, aucun quota de compte');

        self::assertSame([
            '__construct',
            'addUserToGroup',
            'createGroup',
            'groupMembers',
            'listGroups',
            'listUsers',
            'removeUserFromGroup',
        ], $this->publicMethodsOf(\App\Services\Filesystem\Backend\OpenCloud\OpenCloudDirectoryClient::class),
            'la surface du client d\'annuaire est FERMÉE : aucune création de compte, aucune suppression de groupe');

        self::assertSame(
            ['__construct', 'probe'],
            $this->publicMethodsOf(\App\Services\OpenCloud\OpenCloudAdminClient::class),
            'le client d\'administration LIT, et ne fait que ça',
        );
    }

    /**
     * **LE SECRET NE SORT PAR AUCUN CANAL PUBLIC** : une seule classe du chantier
     * a une méthode publique capable de le rendre, et c'est l'objet de
     * configuration — celui que le seul client HTTP interroge.
     *
     * La garde porte sur la surface PUBLIQUE, lue par réflexion, et pas sur un
     * motif textuel : un générateur privé de secret est parfaitement légitime (le
     * déploiement en a un), et l'interdire par un scan de noms aurait produit une
     * garde qu'on finit par contourner en renommant la méthode.
     */
    #[Test]
    public function no_class_but_the_configuration_can_ever_hand_out_the_secret(): void
    {
        $exposers = [];

        foreach ([self::BACKEND_DIR, self::CONNECTION_DIR] as $dir) {
            foreach ((new Finder())->files()->in(realpath(self::repoPath($dir)))->name('*.php') as $file) {
                $class = 'App\\Services\\' . (str_starts_with($dir, 'app/Services/Filesystem')
                    ? 'Filesystem\\Backend\\OpenCloud\\'
                    : 'OpenCloud\\')
                    . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

                if (! class_exists($class) && ! interface_exists($class)) {
                    continue;
                }
                if ($class === \App\Services\OpenCloud\OpenCloudConnectionConfig::class) {
                    continue;
                }

                foreach ($this->publicMethodsOf($class) as $method) {
                    if (preg_match('/(password|secret|credential)/i', $method) === 1) {
                        $exposers[] = $file->getRelativePathname() . '::' . $method;
                    }
                }
            }
        }

        self::assertSame(
            [],
            $exposers,
            'LE SECRET N\'EST LISIBLE QUE PAR LE CLIENT HTTP : ' . json_encode($exposers, JSON_UNESCAPED_SLASHES),
        );

        // POSITIF : la configuration, elle, sait le rendre — et elle le MASQUE à
        // l'introspection.
        self::assertContains(
            'adminPassword',
            $this->publicMethodsOf(\App\Services\OpenCloud\OpenCloudConnectionConfig::class),
        );
        self::assertContains(
            '__debugInfo',
            $this->publicMethodsOf(\App\Services\OpenCloud\OpenCloudConnectionConfig::class),
        );
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * **LE VERDICT : LA LIGNE DE CONTRAT NE BOUGE PAS, ET C'EST OBSERVABLE.**
     *
     * Le cadrage de cet epic pose une affirmation vérifiable : *si la coupe est
     * bonne, un troisième produit s'insère sans rien rouvrir ; s'il oblige à
     * retoucher le contrat, c'est que le contrat était faux.* Cette phrase n'a de
     * valeur que rendue MESURABLE, et c'est l'objet de ce test.
     *
     * Il énumère les fichiers de la ligne de contrat — l'interface, les cinq
     * structures de rapport, le backend d'aperçu, les deux vocabulaires de
     * résultat, tout le namespace du plan, le comparateur, et les deux backends
     * antérieurs — et il constate qu'AUCUN d'eux ne prononce le nom du produit
     * arrivant. En regard, il constate que les TROIS points d'extension déclarés,
     * eux, le prononcent : sans cette seconde moitié, la première serait verte
     * parce que rien n'aurait été branché.
     *
     * **Ce test n'interdit rien ; il MESURE.** Si un quatrième fichier devait un
     * jour rejoindre la liste des connaissants, ce test rougirait — et ce rouge
     * serait l'information la plus utile de tout l'epic, pas un obstacle à
     * contourner.
     * ═══════════════════════════════════════════════════════════════════════
     */
    #[Test]
    public function the_contract_line_never_learns_the_name_of_a_third_product(): void
    {
        $knowing = [];

        foreach ([
            'app/Services/Filesystem/Backend/FileBackend.php',
            'app/Services/Filesystem/Backend/ReconciliationReport.php',
            'app/Services/Filesystem/Backend/InspectionReport.php',
            'app/Services/Filesystem/Backend/NodeReconciliation.php',
            'app/Services/Filesystem/Backend/NodeObservation.php',
            'app/Services/Filesystem/Backend/ObservedGrant.php',
            'app/Services/Filesystem/Backend/PreviewBackend.php',
            'app/Enums/FileBackendOutcome.php',
            'app/Enums/FileBackendObservation.php',
            'app/Services/Filesystem/PlanStateComparator.php',
        ] as $relative) {
            $path = self::repoPath($relative);
            self::assertFileExists($path, 'la garde doit trouver ' . $relative);

            if (preg_match('/opencloud/i', (string) file_get_contents($path)) === 1) {
                $knowing[] = $relative;
            }
        }

        foreach ([
            'app/Services/Filesystem/Plan',
            'app/Services/Filesystem/Backend/Posix',
            'app/Services/Filesystem/Backend/Nextcloud',
            'app/Services/Extensions',
        ] as $dir) {
            foreach ((new Finder())->files()->in(realpath(self::repoPath($dir)))->name('*.php') as $file) {
                if (preg_match('/opencloud/i', (string) $file->getContents()) === 1) {
                    $knowing[] = $dir . '/' . $file->getRelativePathname();
                }
            }
        }

        self::assertSame(
            [],
            $knowing,
            'LA LIGNE DE CONTRAT A BOUGÉ. Un troisième produit devait s\'insérer par les trois points '
            . 'd\'extension déclarés et par eux seuls ; ces fichiers-ci le connaissent désormais, et c\'est '
            . 'un CONSTAT à rapporter — pas un rouge à faire disparaître : '
            . json_encode($knowing, JSON_UNESCAPED_SLASHES),
        );

        // LA MOITIÉ POSITIVE : les trois points d'extension, et EUX SEULS, savent.
        foreach ([
            'app/Enums/FileBackendName.php',
            'app/Services/Filesystem/Backend/FileBackendRegistry.php',
            'app/Services/Filesystem/Backend/FileBackendSelection.php',
        ] as $extensionPoint) {
            self::assertSame(
                1,
                preg_match('/opencloud/i', (string) file_get_contents(self::repoPath($extensionPoint))),
                'le point d\'extension « ' . $extensionPoint . ' » ne branche rien : la garde ci-dessus '
                . 'serait verte pour la mauvaise raison',
            );
        }
    }

    /** @return list<string> */
    private function publicMethodsOf(string $class): array
    {
        $methods = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($methods);

        return $methods;
    }
}
