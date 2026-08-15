<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActiveCloud;
use App\Enums\FileBackendName;
use App\Exceptions\Filesystem\FileLocationException;
use App\Exceptions\Nextcloud\NextcloudConfigurationException;
use App\Exceptions\OpenCloud\OpenCloudConfigurationException;
use App\Services\FilePolicyService;
use App\Services\Filesystem\FileLocationPolicyMirror;
use App\Services\Filesystem\FileLocations;
use App\Services\Filesystem\FileLocationService;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\OpenCloud\OpenCloudConnectionConfig;
use App\Services\ServiceCredentials;
use App\Services\Shortcuts\PortalShortcutIcon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
 * emplacements et le cloud actif. C'est la SEULE dérivation dans ce sens du
 * dépôt, et elle est un geste explicite, joué une fois.
 *
 * **Ce qu'elle écrit** (correction de revue) : la décision **et son miroir**,
 * dans la même transaction, exactement comme l'écran
 * ({@see FileLocationPolicyMirror}). Écrire la seule décision laissait
 * `files.policy` annoncer une capacité cloud que la décision ne portait plus —
 * divergence silencieuse, transitoire (le premier enregistrement d'écran la
 * réparait) et pourtant suffisante pour qu'un répertoire géré soit créé sur une
 * instance injoignable.
 *
 * **« Cloud configuré » signifie capacité active ET connexion complète**
 * ({@see NextcloudConnectionConfig::fromValues()} /
 * {@see OpenCloudConnectionConfig::fromValues()} qui ne lèvent pas sur l'état
 * persisté — la capacité, elle, est jugée à part : voir
 * {@see self::nextcloudConnection()}) — pas
 * « connexion vérifiée » : ce dernier état n'existe pas de façon durable dans
 * le dépôt (le diagnostic de sonde vit en cache APCu, illisible depuis un
 * processus `artisan`). Elle n'émet donc AUCUN appel réseau : elle ne lit que
 * `files.policy` et `service_credentials`.
 *
 * **Elle n'invente jamais un emplacement.** Si les quatre booléens ne
 * suffisent pas à déterminer une décision cohérente (deux clouds configurés,
 * ou un emplacement web-uniquement sans aucun cloud), elle REFUSE en nommant
 * le cas plutôt que de choisir à la place de l'administrateur.
 *
 * **`--cloud=` REMPLACE LA DÉRIVATION, PAS LA VÉRIFICATION.** Deux héritages
 * n'ont AUCUNE sortie tant que le cloud actif se déduit des capacités :
 *  - les deux clouds configurés — la commande refuse de choisir, et l'écran,
 *    qui affiche le bandeau de reprise tant qu'aucune décision n'est
 *    enregistrée, ne propose aucun contrôle de décision ;
 *  - un emplacement web-uniquement (`home` ou `shares` coupé) avec les deux
 *    capacités cloud ÉTEINTES — l'administrateur peut y déclarer une connexion
 *    complète depuis l'écran, mais la capacité, elle, n'a plus qu'un seul
 *    écrivain : le miroir, écrit par une décision que le bandeau interdit de
 *    prendre. L'instance reste sans issue, hors SQL.
 *
 * L'option fait du choix de l'administrateur une ENTRÉE. Elle ne relâche
 * cependant rien : le produit désigné doit avoir une CONNEXION COMPLÈTE, sans
 * quoi la commande refuse en nommant ce qui manque — on ne pose pas un cloud
 * injoignable parce que quelqu'un l'a écrit sur la ligne de commande. Seule la
 * CAPACITÉ cesse d'être une condition, parce qu'elle est un RÉSULTAT : le
 * miroir l'allume dans le même geste transactionnel que la décision.
 */
final class AdoptFileLocationsCommand extends Command
{
    protected $signature = 'files:adopt-locations
        {--dry-run : Affiche la décision calculée et ses motifs sans rien écrire}
        {--cloud= : Désigne le cloud actif au lieu de le dériver — « nextcloud », « opencloud » ou « aucun » ; le produit désigné doit avoir une connexion complète}
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

    <comment>Désigner le cloud plutôt que le déduire.</comment> <info>--cloud=</info> accepte <info>nextcloud</info>,
    <info>opencloud</info> ou <info>aucun</info>, et rien d'autre : toute autre valeur est refusée en
    rappelant ce vocabulaire.

      <info>php artisan files:adopt-locations --cloud=nextcloud</info>

    C'est le geste que les deux refus ci-dessus attendent : l'administrateur tranche
    lui-même. Il débloque aussi l'instance dont un emplacement doit désigner un cloud
    alors qu'aucune CAPACITÉ n'est allumée — l'écran y interdit toute décision tant
    que la reprise n'a pas eu lieu, et la capacité n'a plus d'autre écrivain que
    cette reprise.

    <comment>Elle ne remplace que la DÉDUCTION.</comment> Le produit désigné doit avoir une connexion
    complète — sinon refus nommant ce qui manque : désigner un cloud ne le rend pas
    joignable. Seule la capacité cesse d'être une condition, parce qu'elle est un
    RÉSULTAT : la reprise l'allume elle-même, avec la décision, dans la même
    transaction. Avec <info>--cloud=aucun</info>, l'accès au home ET aux partages doivent être
    actifs : un emplacement ne peut pas désigner un cloud absent.

    <comment>Idempotente.</comment> Rejouée sur une décision déjà enregistrée et identique, elle
    l'annonce et n'écrit rien. Si la décision enregistrée DIFFÈRE de celle calculée,
    elle refuse de l'écraser sans <comment>--force</comment> — et <comment>--dry-run</comment>, qui n'écrit jamais,
    se contente de montrer les deux états côte à côte.

    <comment>Réparer une ligne illisible.</comment> Si le réglage enregistré ne se relit pas (édité à
    la main, import cassé), la commande le dit et s'arrête. <comment>--force</comment> est le geste de
    réparation : il remplace la ligne illisible par la décision calculée.

    <comment>Ce qu'elle écrit.</comment> La décision (<info>files.locations</info>) ET sa projection sur les
    quatre réglages historiques (<info>files.policy</info>), dans la même transaction : écrire l'une
    sans l'autre laisserait l'instance annoncer une capacité cloud que la décision ne
    porte plus.

    <comment>⚠️ --force contourne la garde de données.</comment> C'est le SEUL geste du dépôt qui écrase
    une décision d'emplacement déjà enregistrée sans passer par la garde qui refuse, à
    l'écran, de déplacer un espace qui porte des données (chantier « Epic 64 — la
    bascule d'autorité »). Aucune donnée n'est déplacée pour autant : les fichiers
    restent où ils sont, et c'est bien le problème — l'instance déclarerait un
    emplacement que personne n'a déménagé. À n'employer que pour réparer, jamais pour
    « décider plus vite ».

    <comment>Codes de retour.</comment> <info>0</info> n'est rendu que si la décision enregistrée est
    EFFECTIVEMENT à jour — déjà conforme, ou écrite par cette exécution. En
    simulation, <info>0</info> signifie donc « rien à faire », et tout ce qui resterait à
    écrire rend <info>1</info> : sans quoi un enchaînement du genre
    <info>files:adopt-locations --dry-run && basculer</info> basculerait une instance dont
    les emplacements ne sont pas repris.
    <info>1</info> couvre aussi : les deux refus nommés ci-dessus, les trois refus propres à
    <comment>--cloud=</comment> (valeur hors vocabulaire, connexion incomplète du produit désigné,
    <info>aucun</info> contredit par un emplacement qui doit désigner un cloud), une décision
    existante différente sans <comment>--force</comment>, et une décision enregistrée illisible sans
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
            fn (): NextcloudConnectionConfig => $this->nextcloudConnection(),
            NextcloudConfigurationException::class,
        );
        $opencloud = $this->cloudStatus(
            $capabilities['opencloud'],
            'Accès OpenCloud',
            fn (): OpenCloudConnectionConfig => $this->openCloudConnection(),
            OpenCloudConfigurationException::class,
        );

        $designated = $this->option('cloud');

        if ($designated !== null) {
            $chosen = ActiveCloud::tryFrom(is_string($designated) ? trim($designated) : '');

            if ($chosen === null) {
                $this->error(sprintf(
                    'REFUS : « %s » ne désigne aucun cloud connu.',
                    is_string($designated) ? $designated : '',
                ));
                $this->line(sprintf(
                    'Le vocabulaire de --cloud est fermé : %s.',
                    implode(', ', ActiveCloud::values()),
                ));

                return self::FAILURE;
            }

            if ($this->refuseDesignatedCloud($chosen, $capabilities, $nextcloud, $opencloud)) {
                return self::FAILURE;
            }

            $cloudActif = $chosen;
            $explicitCloud = $chosen;
        } else {
            $explicitCloud = null;

            if ($nextcloud['configured'] && $opencloud['configured']) {
                $this->error('REFUS : les deux clouds sont configurés à la fois.');
                $this->line('La reprise ne choisit pas à la place de l\'administrateur.');
                $this->line(sprintf('  Nextcloud : %s', $nextcloud['url']));
                $this->line(sprintf('  OpenCloud : %s', $opencloud['url']));
                $this->line('Désignez celui qui doit être actif et rejouez : --cloud=nextcloud ou --cloud=opencloud.');

                return self::FAILURE;
            }

            $cloudActif = match (true) {
                $nextcloud['configured'] => ActiveCloud::Nextcloud,
                $opencloud['configured'] => ActiveCloud::OpenCloud,
                default => ActiveCloud::Aucun,
            };

            $webOnlyWithoutCloud = $cloudActif === ActiveCloud::Aucun
                ? $this->locationsThatRequireACloud($capabilities)
                : [];

            if ($webOnlyWithoutCloud !== []) {
                $this->error('REFUS : un emplacement doit désigner un cloud, mais aucun cloud n\'est configuré.');
                $this->line('La reprise n\'invente jamais un emplacement.');
                $this->renderLocationsThatRequireACloud($webOnlyWithoutCloud);
                $this->renderCloudStatuses($nextcloud, $opencloud, false);
                $this->line('Configurez un cloud, ou réactivez la capacité, dans Administration › Fichiers.');
                $this->line('Si un cloud est DÉJÀ connecté sans que sa capacité soit allumée, désignez-le : --cloud=nextcloud ou --cloud=opencloud.');

                return self::FAILURE;
            }
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
            $this->renderMotifs($capabilities, $nextcloud, $opencloud, $explicitCloud);

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line('Simulation — rien ne sera écrit.');
            $this->renderDecision($computed);
            $this->renderMotifs($capabilities, $nextcloud, $opencloud, $explicitCloud);
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
            $this->renderMotifs($capabilities, $nextcloud, $opencloud, $explicitCloud);

            return self::FAILURE;
        }

        // LA SOURCE **ET SON MIROIR**, D'UN SEUL BLOC (correction de revue).
        //
        // Écrire `files.locations` sans projeter les quatre booléens laissait une
        // divergence silencieuse et transitoire : sur le cas « capacité allumée,
        // connexion incomplète », la reprise écrit `cloud actif = aucun` pendant
        // que `files.policy` continue d'annoncer la capacité active — et la
        // posabilité d'une autorité d'écriture à la création d'un répertoire
        // géré, qui ne regarde QUE la capacité côté Nextcloud, autorise alors un
        // répertoire servi par une instance injoignable, que rien ne saura
        // migrer. La divergence se réparait au premier enregistrement de
        // l'écran : elle était donc introuvable en diagnostic.
        //
        // Cette commande est le SECOND ÉCRIVAIN de la décision : elle en est
        // désormais un écrivain COMPLET, et les deux upserts sont atomiques.
        DB::transaction(function () use ($computed): void {
            FileLocationService::set($computed);
            app(FileLocationPolicyMirror::class)->write($computed);
        });

        Log::info('[files:adopt-locations] décision adoptée', [
            'action_type' => 'files.locations.adopted',
            ...$computed->toArray(),
        ]);

        $this->publishPortalIcon($computed, $dryRun);

        $this->info('Décision enregistrée.');
        $this->renderDecision($computed);
        $this->renderMotifs($capabilities, $nextcloud, $opencloud, $explicitCloud);

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
     * Statut d'un produit cloud, en DEUX faits SÉPARÉS — la capacité, et la
     * complétude de la connexion — SANS aucun appel réseau : les deux fabriques
     * de configuration ne lisent que `files.policy` et `service_credentials`.
     *
     * **Pourquoi les deux faits ne sont plus fondus.** « Configuré » (capacité
     * ET connexion) reste le critère de la DÉRIVATION. Mais `--cloud=` désigne
     * un produit dont la capacité est précisément ce que la reprise va ÉCRIRE :
     * la lui exiger en entrée referme la boucle que l'option existe pour ouvrir.
     * Le second fait — la connexion est-elle complète ? — est celui qu'elle
     * vérifie, et il se lit ici indépendamment du premier.
     *
     * `missing` nomme CE QUI MANQUE en reprenant le message de l'exception de
     * configuration ; l'affichage le met sur sa propre ligne, sous le motif —
     * fondre les deux rendrait illisible la distinction entre « capacité
     * éteinte » et « capacité active, connexion incomplète ».
     *
     * @param  callable(): (NextcloudConnectionConfig|OpenCloudConnectionConfig)  $resolve
     * @param  class-string<NextcloudConfigurationException|OpenCloudConfigurationException>  $exceptionClass
     * @return array{capability: bool, capabilityLabel: string, connectionComplete: bool, configured: bool, missing: ?string, url: ?string}
     */
    private function cloudStatus(bool $capability, string $capabilityLabel, callable $resolve, string $exceptionClass): array
    {
        try {
            $config = $resolve();
        } catch (Throwable $e) {
            if (! $e instanceof $exceptionClass) {
                throw $e;
            }

            return [
                'capability' => $capability,
                'capabilityLabel' => $capabilityLabel,
                'connectionComplete' => false,
                'configured' => false,
                'missing' => $e->getMessage(),
                'url' => null,
            ];
        }

        return [
            'capability' => $capability,
            'capabilityLabel' => $capabilityLabel,
            'connectionComplete' => true,
            'configured' => $capability,
            'missing' => null,
            'url' => $config->baseUrl,
        ];
    }

    /**
     * La connexion Nextcloud telle que `files.policy` et `service_credentials`
     * la portent, **sans la garde de capacité**.
     *
     * ⚠️ **C'est la seule raison pour laquelle cette commande n'appelle pas
     * {@see NextcloudConnectionConfig::current()}** : `current()` lève
     * `capabilityDisabled()` avant même de regarder les réglages, ce qui rendrait
     * `--cloud=` inopérant exactement sur l'héritage qu'il débloque. Les règles
     * de complétude, elles, ne sont PAS recopiées : elles vivent une seule fois,
     * dans `fromValues()`, que les deux chemins traversent.
     *
     * @throws NextcloudConfigurationException
     */
    private function nextcloudConnection(): NextcloudConnectionConfig
    {
        $policy = FilePolicyService::globalConfig();

        return NextcloudConnectionConfig::fromValues(
            (string) $policy['nextcloud_server_url'],
            (string) $policy['nextcloud_admin_user'],
            (string) (app(ServiceCredentials::class)->password(NextcloudConnectionConfig::CREDENTIAL_NAME) ?? ''),
            (string) $policy['nextcloud_smb_host'],
            (bool) $policy['nextcloud_verify_tls'],
        );
    }

    /**
     * Le jumeau OpenCloud, pour la même raison et à l'identique.
     *
     * @throws OpenCloudConfigurationException
     */
    private function openCloudConnection(): OpenCloudConnectionConfig
    {
        $policy = FilePolicyService::globalConfig();

        return OpenCloudConnectionConfig::fromValues(
            (string) $policy['opencloud_server_url'],
            (string) $policy['opencloud_admin_user'],
            (string) (app(ServiceCredentials::class)->password(OpenCloudConnectionConfig::CREDENTIAL_NAME) ?? ''),
            (bool) $policy['opencloud_verify_tls'],
        );
    }

    /**
     * LES DEUX REFUS PROPRES À `--cloud=`, et rien d'autre : l'option remplace
     * la dérivation, elle ne desserre aucune garde de cohérence.
     *
     * ① Le produit désigné doit avoir une CONNEXION COMPLÈTE. Le nommer sur la
     *    ligne de commande ne le rend pas joignable — poser un emplacement sur
     *    une instance dont on n'a ni l'adresse ni le compte reviendrait à
     *    déclarer un endroit où personne ne peut écrire.
     * ② `--cloud=aucun` exige que les DEUX emplacements puissent rester sur le
     *    serveur de fichiers. C'est la garde de cohérence du modèle (une
     *    autorité cloud sans cloud actif est irreprésentable) : elle ne se
     *    contourne pas en la déclarant.
     *
     * Rend `true` quand la commande doit s'arrêter — le refus est déjà affiché.
     *
     * @param  array<string, bool>  $capabilities
     * @param  array{capability: bool, capabilityLabel: string, connectionComplete: bool, configured: bool, missing: ?string, url: ?string}  $nextcloud
     * @param  array{capability: bool, capabilityLabel: string, connectionComplete: bool, configured: bool, missing: ?string, url: ?string}  $opencloud
     */
    private function refuseDesignatedCloud(
        ActiveCloud $chosen,
        array $capabilities,
        array $nextcloud,
        array $opencloud,
    ): bool {
        $statut = match ($chosen) {
            ActiveCloud::Nextcloud => $nextcloud,
            ActiveCloud::OpenCloud => $opencloud,
            ActiveCloud::Aucun => null,
        };

        if ($statut !== null && ! $statut['connectionComplete']) {
            $this->error(sprintf(
                'REFUS : --cloud=%s désigne un produit dont la connexion est INCOMPLÈTE.',
                $chosen->value,
            ));
            $this->line('Désigner un cloud ne le rend pas joignable.');
            $this->line(sprintf('  %s', (string) $statut['missing']));
            $this->line('Complétez la connexion dans Administration › Fichiers, puis rejouez cette commande.');

            return true;
        }

        if ($chosen === ActiveCloud::Aucun) {
            $required = $this->locationsThatRequireACloud($capabilities);

            if ($required !== []) {
                $this->error('REFUS : --cloud=aucun, alors qu\'un emplacement doit désigner un cloud.');
                $this->line('Un emplacement ne peut pas désigner un cloud absent.');
                $this->renderLocationsThatRequireACloud($required);
                $this->line('Désignez le produit à retenir (--cloud=nextcloud ou --cloud=opencloud), ou réactivez l\'accès au lecteur concerné dans Administration › Fichiers.');

                return true;
            }
        }

        return false;
    }

    /**
     * Les emplacements que l'héritage force à quitter le serveur de fichiers —
     * donc à désigner un cloud. La liste est vide quand `home` et `shares` sont
     * tous deux actifs.
     *
     * @param  array<string, bool>  $capabilities
     * @return list<array{objet: string, motif: string}>
     */
    private function locationsThatRequireACloud(array $capabilities): array
    {
        $required = [];

        if (! $capabilities['home']) {
            $required[] = ['objet' => 'l\'espace perso', 'motif' => 'l\'accès au lecteur du home est coupé (home = false)'];
        }
        if (! $capabilities['shares']) {
            $required[] = ['objet' => 'l\'espace partagé', 'motif' => 'l\'accès au lecteur des partages est coupé (shares = false)'];
        }

        return $required;
    }

    /** @param  list<array{objet: string, motif: string}>  $required */
    private function renderLocationsThatRequireACloud(array $required): void
    {
        foreach ($required as $case) {
            $this->line(sprintf('  %s : %s.', ucfirst($case['objet']), $case['motif']));
        }
    }

    /**
     * Le backend du cloud actif — garanti non `null` par les refus nommés
     * ci-dessus : cette méthode n'est appelée que lorsque `home` ou `shares`
     * vaut `false`, cas où la commande est déjà sortie si le cloud actif valait
     * `ActiveCloud::Aucun` — que celui-ci ait été dérivé
     * ({@see self::locationsThatRequireACloud()}) ou désigné
     * ({@see self::refuseDesignatedCloud()}).
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
     * Quand le cloud a été DÉSIGNÉ, les motifs le disent : sans cette ligne, la
     * décision affichée laisserait croire que la capacité l'a produite, alors
     * que c'est l'inverse — la capacité en découle.
     *
     * @param  array<string, bool>  $capabilities
     * @param  array{capability: bool, capabilityLabel: string, connectionComplete: bool, configured: bool, missing: ?string, url: ?string}  $nextcloud
     * @param  array{capability: bool, capabilityLabel: string, connectionComplete: bool, configured: bool, missing: ?string, url: ?string}  $opencloud
     */
    private function renderMotifs(array $capabilities, array $nextcloud, array $opencloud, ?ActiveCloud $explicitCloud): void
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

        if ($explicitCloud !== null) {
            $this->line(sprintf(
                '  Cloud actif : %s — désigné par --cloud, non dérivé ; la capacité correspondante est écrite par cette reprise.',
                $explicitCloud->value,
            ));
        }

        $this->renderCloudStatuses($nextcloud, $opencloud, $explicitCloud !== null);
    }

    /**
     * Les deux statuts cloud, chacun sur sa ligne, et ce qui manque sur la ligne
     * suivante quand il manque quelque chose.
     *
     * `$designated` dit LEQUEL des deux faits est en cause. Sans désignation, la
     * capacité est une CONDITION, et le motif la nomme en premier. Avec
     * `--cloud=`, elle est un RÉSULTAT : afficher « capacité désactivée » sous
     * une décision qui vient précisément de l'allumer contredirait la sortie
     * juste au-dessus.
     *
     * @param  array{capability: bool, capabilityLabel: string, connectionComplete: bool, configured: bool, missing: ?string, url: ?string}  $nextcloud
     * @param  array{capability: bool, capabilityLabel: string, connectionComplete: bool, configured: bool, missing: ?string, url: ?string}  $opencloud
     */
    private function renderCloudStatuses(array $nextcloud, array $opencloud, bool $designated): void
    {
        foreach (['Nextcloud' => $nextcloud, 'OpenCloud' => $opencloud] as $produit => $statut) {
            $capaciteEnCause = ! $designated && ! $statut['capability'];

            $motif = match (true) {
                $capaciteEnCause => sprintf('capacité « %s » désactivée', $statut['capabilityLabel']),
                $statut['connectionComplete'] => 'connexion complète',
                $designated => 'connexion incomplète',
                default => 'capacité active mais connexion incomplète',
            };

            $this->line(sprintf('  %s : %s', $produit, $motif));

            if (! $capaciteEnCause && $statut['missing'] !== null) {
                $this->line(sprintf('    %s', $statut['missing']));
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
