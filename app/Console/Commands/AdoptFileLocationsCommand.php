<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActiveCloud;
use App\Enums\FileBackendName;
use App\Exceptions\Filesystem\FileLocationException;
use App\Exceptions\Nextcloud\NextcloudConfigurationException;
use App\Exceptions\OpenCloud\OpenCloudConfigurationException;
use App\Services\FilePolicyService;
use App\Services\Filesystem\FileLocations;
use App\Services\Filesystem\FileLocationService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\OpenCloud\OpenCloudConnectionConfig;
use App\Services\Shortcuts\PortalShortcutIcon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * LA REPRISE, EN COMMANDE — PAS EN MIGRATION.
 *
 * Doctrine du dépôt : une opération d'exploitation est une COMMANDE, jamais
 * une procédure à rejouer à la main (patron
 * {@see AdImmutableKeyCommand}).
 *
 * **Pourquoi une commande et pas une migration de schéma.** Une migration qui
 * lève bloque `php artisan migrate` et rend l'instance non déployable ; or
 * les deux refus ci-dessous sont des états NORMAUX sur certaines instances
 * (aucun cloud configuré, ou les deux). Une commande peut dire non sans
 * bloquer le reste du déploiement.
 *
 * **Ce qu'elle dérive.** Les quatre booléens historiques de `files.policy`
 * (`home`, `shares`, et les deux capacités cloud) deviennent les deux
 * emplacements et le cloud actif — jamais l'inverse : `files.policy` n'est
 * pas modifié.
 *
 * **« Cloud configuré » signifie capacité active ET connexion complète**
 * ({@see NextcloudConnectionConfig::current()} /
 * {@see OpenCloudConnectionConfig::current()} qui ne lèvent pas) — pas
 * « connexion vérifiée » : ce dernier état n'existe pas de façon durable dans
 * le dépôt (le diagnostic de sonde vit en cache APCu, illisible depuis un
 * processus `artisan`). Elle n'émet donc AUCUN appel réseau : elle ne lit que
 * `files.policy` et `service_credentials`.
 *
 * **Elle n'invente jamais un emplacement.** Si les quatre booléens ne
 * suffisent pas à déterminer une décision cohérente (deux clouds configurés,
 * ou un emplacement web-uniquement sans aucun cloud), elle REFUSE en nommant
 * le cas plutôt que de choisir à la place de l'administrateur.
 */
final class AdoptFileLocationsCommand extends Command
{
    protected $signature = 'files:adopt-locations
        {--dry-run : Affiche la décision calculée et ses motifs sans rien écrire}
        {--force : Écrase une décision déjà enregistrée qui diffère de celle calculée, ou répare une ligne illisible}';

    protected $description = 'Dérive les deux emplacements de fichiers et le cloud actif depuis les quatre réglages historiques du plan de fichiers';

    protected $help = <<<'HELP'
    Calcule, depuis les quatre réglages historiques (accès au home, aux
    partages, à Nextcloud, à OpenCloud), la décision équivalente dans le
    nouveau modèle à deux emplacements + un cloud actif, et l'enregistre.

      <info>php artisan files:adopt-locations --dry-run</info>   voir ce qui serait écrit
      <info>php artisan files:adopt-locations</info>              appliquer la reprise

    <comment>Ce qu'elle ne fait jamais.</comment> Elle ne déplace, ne lit ni n'écrit aucun octet de
    donnée utilisateur. Elle n'émet AUCUN appel réseau — « cloud configuré » veut dire
    capacité active et connexion complète, jamais une connexion sondée en direct.

    <comment>L'icône du raccourci de portail.</comment> Quand la décision retenue comporte un cloud
    actif, la commande publie aussi l'icône du raccourci « Mes fichiers en ligne » —
    sans quoi le raccourci, qui suit désormais le cloud actif, arriverait sur les
    bureaux avec l'icône de <comment>rundll32.exe</comment>. C'est idempotent, non bloquant (un échec
    laisse le raccourci sans icône), et <comment>--dry-run</comment> ne publie rien.

    <comment>Les deux refus.</comment> Elle REFUSE et n'écrit rien si les DEUX clouds sont
    configurés à la fois (elle ne choisit pas à la place de l'administrateur), ou si
    un emplacement doit désigner un cloud (accès au home ou aux partages coupé) alors
    qu'AUCUN cloud n'est configuré (elle n'invente pas un emplacement).

    <comment>Idempotente.</comment> Rejouée sur une décision déjà enregistrée et identique, elle
    l'annonce et n'écrit rien. Si la décision enregistrée DIFFÈRE de celle calculée,
    elle refuse de l'écraser sans <comment>--force</comment> — et <comment>--dry-run</comment>, qui n'écrit jamais,
    se contente de montrer les deux états côte à côte.

    <comment>Réparer une ligne illisible.</comment> Si le réglage enregistré ne se relit pas (édité à
    la main, import cassé), la commande le dit et s'arrête. <comment>--force</comment> est le geste de
    réparation : il remplace la ligne illisible par la décision calculée.

    <comment>Codes de retour.</comment> <info>0</info> n'est rendu que si la décision enregistrée est
    EFFECTIVEMENT à jour — déjà conforme, ou écrite par cette exécution. En
    simulation, <info>0</info> signifie donc « rien à faire », et tout ce qui resterait à
    écrire rend <info>1</info> : sans quoi un enchaînement du genre
    <info>files:adopt-locations --dry-run && basculer</info> basculerait une instance dont
    les emplacements ne sont pas repris.
    <info>1</info> couvre aussi : les deux refus nommés ci-dessus, une décision existante
    différente sans <comment>--force</comment>, et une décision enregistrée illisible sans
    <comment>--force</comment>.
    HELP;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $capabilities = FilePolicyService::capabilities();
        $nextcloud = $this->cloudStatus(
            $capabilities['nextcloud'],
            'Accès Nextcloud',
            static fn () => NextcloudConnectionConfig::current(),
            NextcloudConfigurationException::class,
        );
        $opencloud = $this->cloudStatus(
            $capabilities['opencloud'],
            'Accès OpenCloud',
            static fn () => OpenCloudConnectionConfig::current(),
            OpenCloudConfigurationException::class,
        );

        if ($nextcloud['configured'] && $opencloud['configured']) {
            $this->error('REFUS : les deux clouds sont configurés à la fois.');
            $this->line('La reprise ne choisit pas à la place de l\'administrateur.');
            $this->line(sprintf('  Nextcloud : %s', $nextcloud['url']));
            $this->line(sprintf('  OpenCloud : %s', $opencloud['url']));
            $this->line('Tranchez lequel est actif dans Administration › Fichiers, puis rejouez cette commande.');

            return self::FAILURE;
        }

        $cloudActif = match (true) {
            $nextcloud['configured'] => ActiveCloud::Nextcloud,
            $opencloud['configured'] => ActiveCloud::OpenCloud,
            default => ActiveCloud::Aucun,
        };

        $webOnlyWithoutCloud = [];
        if (! $capabilities['home'] && $cloudActif === ActiveCloud::Aucun) {
            $webOnlyWithoutCloud[] = ['objet' => 'l\'espace perso', 'motif' => 'l\'accès au lecteur du home est coupé (home = false)'];
        }
        if (! $capabilities['shares'] && $cloudActif === ActiveCloud::Aucun) {
            $webOnlyWithoutCloud[] = ['objet' => 'l\'espace partagé', 'motif' => 'l\'accès au lecteur des partages est coupé (shares = false)'];
        }

        if ($webOnlyWithoutCloud !== []) {
            $this->error('REFUS : un emplacement doit désigner un cloud, mais aucun cloud n\'est configuré.');
            $this->line('La reprise n\'invente jamais un emplacement.');
            foreach ($webOnlyWithoutCloud as $case) {
                $this->line(sprintf('  %s : %s.', ucfirst($case['objet']), $case['motif']));
            }
            $this->renderCloudStatuses($nextcloud, $opencloud);
            $this->line('Configurez un cloud, ou réactivez la capacité, dans Administration › Fichiers.');

            return self::FAILURE;
        }

        try {
            $computed = FileLocations::make(
                $capabilities['home'] ? FileBackendName::Posix : $this->cloudBackend($cloudActif),
                $capabilities['shares'] ? FileBackendName::Posix : $this->cloudBackend($cloudActif),
                $cloudActif,
            );
        } catch (FileLocationException $e) {
            $this->error('Décision interne incohérente : '.$e->getMessage());

            return self::FAILURE;
        }

        // La lecture de l'existant peut LEVER : la ligne persistée se modifie en
        // SQL, en tinker ou par un import, et la garde est rejouée à la lecture.
        // Sans ce filet, l'outil censé réparer une ligne corrompue serait
        // précisément celui qui plante dessus — y compris en --dry-run.
        try {
            $existing = FileLocationService::isDecided() ? FileLocationService::current() : null;
        } catch (FileLocationException $e) {
            if (! $force) {
                $this->error('REFUS : une décision est déjà enregistrée mais elle est ILLISIBLE — elle n\'est pas remplacée sans --force.');
                $this->line('  Motif : '.$e->getMessage());
                $this->line('Rejouez avec --force pour la remplacer par la décision calculée ; d\'ici là, la ligne enregistrée reste inchangée.');

                return self::FAILURE;
            }

            $this->warn('La décision déjà enregistrée est illisible et --force la remplace — motif : '.$e->getMessage());
            $existing = null;
        }

        if ($existing !== null && $existing->toArray() === $computed->toArray()) {
            $this->info('Déjà conforme : rien à écrire.');
            // « Rien à écrire » parle des EMPLACEMENTS. L'icône, elle, est de
            // l'état dérivé : une instance déjà conforme mais jamais passée par
            // l'écran des fichiers n'en a aucune, et son raccourci-portail
            // partirait sans icône. La publication est donc rejouée ici aussi.
            $this->publishPortalIcon($computed, $dryRun);
            $this->renderDecision($computed);
            $this->renderMotifs($capabilities, $nextcloud, $opencloud);

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line('Simulation — rien ne sera écrit.');
            $this->renderDecision($computed);
            $this->renderMotifs($capabilities, $nextcloud, $opencloud);
            if ($existing !== null) {
                $this->warn('Une décision différente est déjà enregistrée — rejouez sans --dry-run, avec --force, pour l\'écraser.');
                $this->renderComparison($existing, $computed);
            }

            // Une simulation qui rend 0 alors qu'il reste à écrire ferait passer
            // un enchaînement `--dry-run && <geste réel>` pour un feu vert sur
            // une instance dont les emplacements ne sont pas repris. En
            // simulation, 0 signifie donc « rien à faire » — et on n'y arrive
            // que par le chemin « déjà conforme » ci-dessus.
            return self::FAILURE;
        }

        if ($existing !== null && ! $force) {
            $this->error('REFUS : une décision est déjà enregistrée et diffère de celle calculée — elle n\'est pas écrasée sans --force.');
            $this->renderComparison($existing, $computed);
            $this->renderMotifs($capabilities, $nextcloud, $opencloud);

            return self::FAILURE;
        }

        FileLocationService::set($computed);

        Log::info('[files:adopt-locations] décision adoptée', [
            'action_type' => 'files.locations.adopted',
            ...$computed->toArray(),
        ]);

        $this->publishPortalIcon($computed, $dryRun);

        $this->info('Décision enregistrée.');
        $this->renderDecision($computed);
        $this->renderMotifs($capabilities, $nextcloud, $opencloud);

        return self::SUCCESS;
    }

    /**
     * L'ICÔNE DU RACCOURCI-PORTAIL, PUBLIÉE PAR LA REPRISE ELLE-MÊME.
     *
     * `shortcuts.portal_icon` ({@see PortalShortcutIcon::SETTING_KEY}) est une
     * clé NEUVE : elle est absente de toute instance déjà déployée. Or le
     * raccourci « Mes fichiers en ligne » ne dépend plus d'un geste d'écran mais
     * du cloud actif, écrit ICI. Sans cette publication, le parcours de mise en
     * service documenté — jouer cette commande, puis déployer — poserait sur
     * tous les bureaux un `.lnk` dont la cible est `rundll32.exe`, affichant
     * donc l'icône de `rundll32.exe` : exactement la panne que
     * {@see PortalShortcutIcon} existe pour empêcher.
     *
     * **Idempotente** (republier une icône inchangée ne réécrit rien) et
     * **FAIL-SOFT** : une publication qui échoue ne fait pas échouer la reprise.
     * Ce que la commande doit garantir, c'est la décision d'emplacement ; un
     * raccourci sans icône reste un chemin d'accès, une reprise avortée n'en est
     * pas un. L'échec est déjà journalisé par le service — la commande n'en
     * rend visible qu'une ligne pour le terminal qui la joue.
     *
     * **`--dry-run` ne publie RIEN** : une simulation n'écrit pas, pas même de
     * l'état dérivé — y compris sur le chemin « déjà conforme », qui rend 0 sans
     * passer par l'écriture des emplacements.
     */
    private function publishPortalIcon(FileLocations $decision, bool $dryRun): void
    {
        if ($dryRun || $decision->cloudActif === ActiveCloud::Aucun) {
            return;
        }

        try {
            if (app(PortalShortcutIcon::class)->publish() === null) {
                $this->warn('L\'icône du raccourci-portail n\'a pas pu être publiée — le raccourci sera posé sans icône.');
            }
        } catch (Throwable $e) {
            $this->warn(sprintf(
                'L\'icône du raccourci-portail n\'a pas pu être publiée (%s) — le raccourci sera posé sans icône.',
                $e->getMessage(),
            ));
        }
    }

    /**
     * Statut « configuré » d'un produit cloud — capacité active ET connexion
     * complète, SANS aucun appel réseau : {@see NextcloudConnectionConfig::current()}
     * et son jumeau OpenCloud ne lisent que `files.policy` et
     * `service_credentials`.
     *
     * Le motif tient en DEUX champs, et sur deux lignes à l'affichage : `reason`
     * dit LAQUELLE des deux causes s'applique — capacité éteinte, ou capacité
     * active avec une connexion incomplète — et `detail` nomme CE QUI MANQUE,
     * en reprenant le message de l'exception de configuration. Les fondre en
     * une seule phrase rendrait la distinction que l'AC7 exige illisible.
     *
     * @param  callable(): (NextcloudConnectionConfig|OpenCloudConnectionConfig)  $resolve
     * @param  class-string<NextcloudConfigurationException|OpenCloudConfigurationException>  $exceptionClass
     * @return array{configured: bool, reason: string, detail: ?string, url: ?string}
     */
    private function cloudStatus(bool $capability, string $capabilityLabel, callable $resolve, string $exceptionClass): array
    {
        if (! $capability) {
            return [
                'configured' => false,
                'reason' => sprintf('capacité « %s » désactivée', $capabilityLabel),
                'detail' => null,
                'url' => null,
            ];
        }

        try {
            $config = $resolve();
        } catch (Throwable $e) {
            if (! $e instanceof $exceptionClass) {
                throw $e;
            }

            return [
                'configured' => false,
                'reason' => 'capacité active mais connexion incomplète',
                'detail' => $e->getMessage(),
                'url' => null,
            ];
        }

        return [
            'configured' => true,
            'reason' => 'connexion complète',
            'detail' => null,
            'url' => $config->baseUrl,
        ];
    }

    /**
     * Le backend du cloud actif — garanti non `null` par les deux refus
     * nommés ci-dessus : cette méthode n'est appelée que lorsque `home` ou
     * `shares` vaut `false`, cas où `$webOnlyWithoutCloud` a déjà fait sortir
     * la commande si `$cloudActif` valait `ActiveCloud::Aucun`.
     */
    private function cloudBackend(ActiveCloud $cloudActif): FileBackendName
    {
        return $cloudActif->backend() ?? throw new LogicException(
            'Cloud actif absent alors qu\'un cloud était garanti configuré à ce stade — bug interne à la commande.',
        );
    }

    private function renderDecision(FileLocations $locations): void
    {
        $this->table(
            ['réglage', 'valeur'],
            [
                ['espace perso', $locations->espacePerso->value],
                ['espace partagé', $locations->espacePartage->value],
                ['cloud actif', $locations->cloudActif->value],
            ],
        );
    }

    /**
     * LES MOTIFS, sur TOUS les chemins où une décision est affichée — pas
     * seulement dans les refus. Une décision montrée sans son motif ne se
     * relit pas : l'exploitant voit « espace perso = nextcloud » sans savoir
     * que c'est l'accès au home coupé qui l'a produit, ni pourquoi c'est
     * Nextcloud et pas OpenCloud qui a été retenu.
     *
     * @param  array<string, bool>  $capabilities
     * @param  array{configured: bool, reason: string, detail: ?string, url: ?string}  $nextcloud
     * @param  array{configured: bool, reason: string, detail: ?string, url: ?string}  $opencloud
     */
    private function renderMotifs(array $capabilities, array $nextcloud, array $opencloud): void
    {
        $this->line('Motifs :');
        $this->line(sprintf(
            '  Espace perso : %s',
            $capabilities['home']
                ? 'l\'accès au home est actif ⇒ serveur de fichiers'
                : 'l\'accès au home est coupé ⇒ le cloud actif',
        ));
        $this->line(sprintf(
            '  Espace partagé : %s',
            $capabilities['shares']
                ? 'l\'accès aux partages est actif ⇒ serveur de fichiers'
                : 'l\'accès aux partages est coupé ⇒ le cloud actif',
        ));
        $this->renderCloudStatuses($nextcloud, $opencloud);
    }

    /**
     * Les deux statuts cloud, chacun sur sa ligne, et le détail de ce qui
     * manque sur la ligne suivante quand il y en a un.
     *
     * @param  array{configured: bool, reason: string, detail: ?string, url: ?string}  $nextcloud
     * @param  array{configured: bool, reason: string, detail: ?string, url: ?string}  $opencloud
     */
    private function renderCloudStatuses(array $nextcloud, array $opencloud): void
    {
        foreach (['Nextcloud' => $nextcloud, 'OpenCloud' => $opencloud] as $produit => $statut) {
            $this->line(sprintf('  %s : %s', $produit, $statut['reason']));

            if ($statut['detail'] !== null) {
                $this->line(sprintf('    %s', $statut['detail']));
            }
        }
    }

    private function renderComparison(FileLocations $existing, FileLocations $computed): void
    {
        $this->table(
            ['réglage', 'enregistré', 'calculé'],
            [
                ['espace perso', $existing->espacePerso->value, $computed->espacePerso->value],
                ['espace partagé', $existing->espacePartage->value, $computed->espacePartage->value],
                ['cloud actif', $existing->cloudActif->value, $computed->cloudActif->value],
            ],
        );
    }
}
