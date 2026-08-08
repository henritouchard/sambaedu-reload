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
        // règles : c'est par l'enum de mode et la commande de rattachement qu'un
        // « juste un petit partage pour dépanner » arriverait.
        'app/Enums/NextcloudInstanceMode.php',
        'app/Console/Commands/NextcloudIdentityCommand.php',
    ];

    /** @var array<string, string> */
    private const FORBIDDEN_RULES = [
        // 1. Aucun droit écrit côté Nextcloud.
        //
        // ---------------------------------------------------------------------
        // **Story 61.2 — LA RÈGLE VISE LA ROUTE, PAS LE NOM DE L'APPLICATION.**
        // Elle portait sur `files_sharing` nu ; elle porte désormais sur
        // `apps/files_sharing`, c'est-à-dire sur le PRÉFIXE DE ROUTE par lequel
        // passe tout partage OCS — création, lecture, suppression. Ce qu'elle
        // interdisait, elle l'interdit toujours : l'aiguille de vérification
        // ci-dessous est inchangée et reste détectée.
        //
        // Ce qu'elle autorise désormais : lire `files_sharing.api_enabled` dans
        // l'INVENTAIRE DES CAPACITÉS de l'instance (`cloud/capabilities`), ce que
        // la sonde du mode délégué doit faire pour savoir si le partage est
        // seulement activé. Un nom d'application dans un inventaire lu n'est pas
        // une route d'écriture, et refuser de le lire aurait obligé à masquer ce
        // nom derrière une concaténation — c'est-à-dire à contourner la garde au
        // lieu de la préciser. Un contrôle négatif épingle cette lecture.
        // ---------------------------------------------------------------------
        'partage OCS' => '#apps/files_sharing#i',
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
        // résultat, échec, action de montage — plus, depuis 61.2, la configuration
        // et le client du compte porteur, sa sonde, la garde de sélection de mode
        // et le rattachement d'identité.
        self::assertGreaterThanOrEqual(
            14,
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
            // Story 61.2 — la LECTURE de la capacité de partage dans l'inventaire
            // de l'instance : autorisée, et distincte d'une route de partage.
            "\$capabilities['files_sharing']['api_enabled'] ?? false",
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
            'setUserPassword',
            'updateGlobalStorage',
        ], $methods, 'la surface du client est FERMÉE : ni partage, ni groupe, ni quota');
    }

    /**
     * **LA SURFACE DU CLIENT DÉLÉGUÉ EST FERMÉE, ET ELLE EST EN LECTURE SEULE**
     * (story 61.2, AC8).
     *
     * Pourquoi cette liste-ci n'a pas fait bouger la précédente : le client
     * d'administration n'a gagné AUCUNE méthode en 61.2. Le mode délégué parle à
     * l'instance avec d'autres identifiants, donc avec un autre client — le
     * croisement des deux credentials est ainsi impossible par typage, et non par
     * discipline.
     *
     * Les deux méthodes sont des LECTURES : la sonde du mode (l'espace du porteur
     * répond, le partage est activé) et la vérification d'une identité avant
     * rattachement. Les écritures du délégué — créer l'arborescence, émettre un
     * octroi — appartiennent au backend de 61.3 : les poser ici produirait du code
     * mort, et une méthode qui existe finit par être appelée.
     */
    #[Test]
    public function the_delegate_client_surface_is_closed_and_read_only(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\App\Services\Nextcloud\NextcloudDelegateClient::class))
                ->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        sort($methods);

        self::assertSame([
            '__construct',
            'findUserByExactId',
            'probe',
        ], $methods, 'le client délégué ne fait que LIRE : aucune création d\'arborescence, aucun octroi');
    }

    /**
     * **AUCUNE ÉCRITURE DÉLÉGUÉE DANS LE CODE DE PRODUCTION** (story 61.2).
     *
     * Le scan de vocabulaire attrape déjà les routes de partage. Celui-ci ferme
     * l'autre moitié : le verbe WebDAV qui crée un dossier. Le mode délégué crée
     * son arborescence par `MKCOL` — en 61.3, pas ici. Sa mesure contre l'instance
     * réelle (AC9) se fait en HTTP NU côté test, jamais par une méthode de
     * production : c'est la seule façon de mesurer sans que le dépôt gagne une
     * capacité qu'aucune story n'a encore autorisée.
     */
    #[Test]
    public function no_production_code_creates_a_remote_collection(): void
    {
        $offenders = [];

        foreach ((new Finder())->files()->in(realpath(self::repoPath('app')))->name('*.php') as $file) {
            if (preg_match('/[\'"]MKCOL[\'"]/', (string) $file->getContents()) === 1) {
                $offenders[] = str_replace(self::repoPath(''), '', (string) $file->getRealPath());
            }
        }

        self::assertSame(
            [],
            $offenders,
            'la création d\'arborescence distante appartient au backend de 61.3 : fichiers fautifs : '
            . json_encode($offenders, JSON_UNESCAPED_SLASHES),
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
