<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

use App\Exceptions\Nextcloud\NextcloudConfigurationException;
use App\Models\User;
use App\Services\FilePolicyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Story 61.1 — L'ORCHESTRATION DU PROVISIONNEMENT : sonde, montages, comptes.
 *
 * Un seul service exécuté par DEUX portes — la commande `nextcloud:provision` et
 * le bouton de l'écran (via un traitement en file). C'est la doctrine
 * « les ops multi-instance sont des commandes artisan, jamais des procédures
 * manuelles » : le bouton n'est pas un second chemin, c'est le même geste enfilé.
 *
 * **Fail-closed en amont, fail-soft en aval.** La configuration incomplète, la
 * capacité éteinte, l'instance injoignable ou le privilège insuffisant arrêtent
 * TOUT avant la première écriture (code de sortie 2) : un provisionnement à moitié
 * fait laisse une instance dans un état que personne n'a décrit. En revanche,
 * l'échec sur UN utilisateur est compté et rapporté, jamais bloquant pour les
 * autres (code de sortie 1, compteurs à l'appui).
 *
 * **Ce service n'écrit AUCUN droit et n'exécute AUCUN processus.** Ni partage
 * Nextcloud, ni groupe Nextcloud, ni restriction d'applicabilité — et aucune
 * commande système : cette story est 100 % HTTP + SQL. La seule instance qui
 * tranche un accès est Samba/POSIX, avec les identifiants de l'utilisateur de
 * session. Un test d'architecture l'épingle sur tout le namespace, commentaires
 * compris — d'où la description des interdits par leur FONCTION plutôt que par
 * leur nom : un nom cité dans un docblock finit toujours par être copié en appel.
 *
 * **Le verrou est un verrou de FICHIER.** `Cache::lock()` par défaut s'appuie sur
 * APCu, qui ne verrouille pas entre processus : la commande et l'ouvrier de file
 * se croiseraient sans se voir, et le second passage recréerait les montages que
 * le premier est en train de créer — le doublon exact que l'idempotence par
 * signature existe pour éviter.
 */
final class NextcloudProvisioningService
{
    /** Clé du verrou d'exécution — un seul provisionnement à la fois, tous chemins confondus. */
    public const LOCK_KEY = 'nextcloud:provision';

    /**
     * Durée de vie du verrou. Généreuse : le balayage des comptes est linéaire en
     * nombre d'utilisateurs et chaque compte non résolu coûte deux appels réseau.
     *
     * **Publique parce qu'elle contraint le traitement en file** : le délai
     * maximal d'un job DOIT rester inférieur à ce TTL
     * ({@see \App\Jobs\ProvisionNextcloudJob::$timeout}), sinon un job tué par
     * l'ouvrier laisserait un verrou qu'aucun `finally` ne relâche — un SIGKILL
     * ne s'intercepte pas — et la commande comme le bouton répondraient « déjà en
     * cours » pendant tout le reste du TTL. Un test de garde épingle l'ordre des
     * deux valeurs.
     */
    public const LOCK_SECONDS = 1800;

    /** Clé du dernier rapport, relu par l'écran. */
    public const REPORT_CACHE_KEY = 'nextcloud:provision:last-report';

    /**
     * Clé du marqueur « une exécution est en cours », posé AVANT le balayage.
     *
     * Le rapport n'est mis en cache qu'à la FIN : sans ce marqueur, une exécution
     * interrompue (ouvrier tué, service redémarré) ne laisse aucune trace, et
     * l'écran affiche le rapport de la fois d'avant comme si de rien n'était. Le
     * marqueur, lui, survit à l'interruption — sa durée de vie est celle du
     * verrou, exactement — et l'écran peut dire « en cours depuis 14:02 », ce qui
     * est la seule information dont l'exploitant a besoin pour savoir s'il attend
     * ou s'il s'inquiète.
     */
    public const RUNNING_CACHE_KEY = 'nextcloud:provision:running';

    private const REPORT_CACHE_MINUTES = 1440;

    /**
     * Taille des lots de balayage du stock. Le périmètre peut compter des milliers
     * de comptes ; les charger d'un bloc ferait tenir tout l'annuaire en mémoire
     * pour n'en lire que deux colonnes.
     */
    private const USER_CHUNK = 200;

    public function __construct(
        private readonly NextcloudClientFactory $factory,
        private readonly NextcloudUserProvisioner $users,
    ) {
    }

    /**
     * Exécute le provisionnement.
     *
     * @param  bool  $dryRun  N'émet AUCUNE écriture — ni montage, ni compte, ni cache d'identité.
     * @param  bool  $mounts  Traiter les montages external storage.
     * @param  bool  $withUsers  Balayer le stock d'utilisateurs.
     */
    public function run(bool $dryRun = false, bool $mounts = true, bool $withUsers = true): NextcloudProvisioningReport
    {
        $report = new NextcloudProvisioningReport($dryRun, now()->toIso8601String());

        $lock = Cache::store('file')->lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            $report->recordRefusal(
                'Un provisionnement Nextcloud est déjà en cours (commande ou traitement en file) : '
                . 'rien n\'a été tenté.',
            );
            $this->remember($report);

            return $report;
        }

        $this->markRunning($dryRun);

        try {
            return $this->execute($report, $dryRun, $mounts, $withUsers);
        } finally {
            // Un `finally` ne s'exécute pas sur SIGKILL — c'est précisément
            // pourquoi le marqueur porte une durée de vie propre : il s'efface
            // ici quand tout va bien, et expire de lui-même quand rien ne va.
            Cache::forget(self::RUNNING_CACHE_KEY);
            $lock->release();
        }
    }

    /**
     * Dernier rapport connu, en TABLEAU — c'est ce que l'écran affiche.
     *
     * @return array<string, mixed>|null
     */
    public function lastReport(): ?array
    {
        $data = Cache::get(self::REPORT_CACHE_KEY);

        return is_array($data) ? $data : null;
    }

    /**
     * Exécution en cours, s'il y en a une — ou la TRACE d'une exécution
     * interrompue, ce qui est la même chose vue de l'écran : dans les deux cas
     * quelque chose a commencé et n'a pas rendu de rapport.
     *
     * @return array{started_at: string, dry_run: bool}|null
     */
    public function runningSince(): ?array
    {
        $data = Cache::get(self::RUNNING_CACHE_KEY);

        if (! is_array($data) || ! is_string($data['started_at'] ?? null)) {
            return null;
        }

        return ['started_at' => $data['started_at'], 'dry_run' => (bool) ($data['dry_run'] ?? false)];
    }

    /** Sonde de connexion isolée — le « Tester la connexion » de l'écran. */
    public function probe(): NextcloudConnectionProbe
    {
        try {
            return $this->factory->make()->probe();
        } catch (NextcloudConfigurationException $e) {
            return NextcloudConnectionProbe::unreachable($e->getMessage());
        }
    }

    // =========================================================================
    // Interne
    // =========================================================================

    private function execute(
        NextcloudProvisioningReport $report,
        bool $dryRun,
        bool $mounts,
        bool $withUsers,
    ): NextcloudProvisioningReport {
        try {
            $client = $this->factory->make();
        } catch (NextcloudConfigurationException $e) {
            $report->recordRefusal($e->getMessage());
            $this->remember($report);

            return $report;
        }

        $probe = $client->probe();
        $report->recordProbe($probe);

        if (! $probe->isOk()) {
            // On s'arrête AVANT la première écriture. Une instance qui refuse la
            // lecture des montages refusera leur écriture, et le dire tout de suite
            // évite un rapport moitié vert qu'il faudrait interpréter.
            $this->remember($report);

            return $report;
        }

        if ($mounts) {
            $this->provisionMounts($client, $report, $dryRun);
        }

        if ($withUsers) {
            $this->adoptUsers($client, $report, $dryRun);
        }

        $this->remember($report);

        Log::info('nextcloud.provision.done', [
            'dry_run' => $dryRun,
            'exit_code' => $report->exitCode(),
            'users' => $report->userCounters(),
        ]);

        return $report;
    }

    /**
     * AC3 — les deux montages, idempotents par SIGNATURE.
     *
     * L'ordre est : lire l'existant, apparier par signature canonique, puis créer
     * ou mettre à jour. Jamais l'inverse : créer d'abord et dédoublonner ensuite
     * est le mode de défaut classique de `files_external`, qui n'a aucune notion
     * d'unicité et accumule des entrées identiques dans l'écran d'administration.
     *
     * **Un montage étranger n'est ni supprimé ni modifié.** SE5 ne gouverne que ce
     * qu'il a déclaré (drift STRICT : hors du plan, hors du geste) — y compris un
     * troisième montage SMB en identifiants de session créé à la main par
     * l'administrateur de l'instance. Cette méthode n'appelle donc AUCUNE
     * suppression, et un test l'épingle.
     *
     * ---------------------------------------------------------------------------
     * **LE CHAMP `status` DE L'INSTANCE N'EST PAS UN CRITÈRE DE SUCCÈS.** Mesuré le
     * 2026-08-08 : un montage fraîchement créé revient `status: 4`,
     * `statusMessage: "Storage unauthorized. Session unavailable"`. C'est la
     * conséquence NORMALE du mécanisme « identifiants de session » — hors session
     * utilisateur, personne n'a d'identifiants à présenter à Samba, donc le statut
     * est inévaluable. Le lire ferait échouer un provisionnement parfaitement
     * abouti, à chaque fois. Ce code ne le lit pas ; le runbook explique à
     * l'exploitant pourquoi il le verra dans l'écran d'administration Nextcloud.
     * ---------------------------------------------------------------------------
     */
    private function provisionMounts(
        NextcloudAdminClient $client,
        NextcloudProvisioningReport $report,
        bool $dryRun,
    ): void {
        $definitions = ExternalStorageDefinition::canonicalSet($this->smbHost());

        $listing = $client->listGlobalStorages();

        if ($listing->isFailure()) {
            foreach ($definitions as $definition) {
                $report->recordMount($definition->label(), NextcloudMountAction::Echec, $listing->message);
            }

            return;
        }

        /** @var list<array<string, mixed>> $existing */
        $existing = $listing->value('storages', []);

        $bySignature = [];
        foreach ($existing as $entry) {
            $signature = ExternalStorageDefinition::signatureOf($entry);
            if ($signature !== null && ! array_key_exists($signature, $bySignature)) {
                $bySignature[$signature] = $entry;
            }
        }

        foreach ($definitions as $definition) {
            $match = $bySignature[$definition->signature()] ?? null;

            if ($match === null) {
                if ($dryRun) {
                    $report->recordMount($definition->label(), NextcloudMountAction::Simule, 'absent de l\'instance : serait créé.');

                    continue;
                }

                $created = $client->createGlobalStorage($definition);
                $created->isFailure()
                    ? $report->recordMount($definition->label(), NextcloudMountAction::Echec, $created->message)
                    : $report->recordMount($definition->label(), NextcloudMountAction::Cree, $this->mountDetail($definition));

                continue;
            }

            $divergences = $definition->divergences($match);

            if ($divergences === []) {
                $report->recordMount($definition->label(), NextcloudMountAction::Conforme, $this->mountDetail($definition));

                continue;
            }

            if ($dryRun) {
                $report->recordMount(
                    $definition->label(),
                    NextcloudMountAction::Simule,
                    'divergent (' . implode(', ', $divergences) . ') : serait mis à jour.',
                );

                continue;
            }

            $id = $match['id'] ?? null;
            if (! is_int($id) && ! (is_string($id) && $id !== '')) {
                $report->recordMount(
                    $definition->label(),
                    NextcloudMountAction::Echec,
                    'montage reconnu mais sans identifiant exploitable : mise à jour impossible.',
                );

                continue;
            }

            $updated = $client->updateGlobalStorage($id, $definition);
            $updated->isFailure()
                ? $report->recordMount($definition->label(), NextcloudMountAction::Echec, $updated->message)
                : $report->recordMount(
                    $definition->label(),
                    NextcloudMountAction::MisAJour,
                    'divergences corrigées : ' . implode(', ', $divergences),
                );
        }
    }

    /**
     * AC5/AC6 — le balayage du stock : adoption, jamais création.
     *
     * **Périmètre** : `source = 'ad'` et comptes actifs. Les identités fédérées
     * sont exclues et COMPTÉES — elles n'ont ni répertoire personnel ni mot de
     * passe AD, donc ni montage ni compte Nextcloud à aligner ; les compter plutôt
     * que les ignorer est ce qui permet de vérifier que le total du rapport
     * correspond à la population.
     */
    private function adoptUsers(
        NextcloudAdminClient $client,
        NextcloudProvisioningReport $report,
        bool $dryRun,
    ): void {
        // Les hors-périmètre sont comptés d'un seul dénombrement : les détailler
        // n'apprendrait rien (aucun geste n'est attendu sur eux).
        $report->recordExcludedCount(
            User::query()
                ->where(function ($query): void {
                    $query->where('source', '!=', 'ad')->orWhereNull('source');
                })
                ->count()
        );

        User::query()
            ->where('source', 'ad')
            ->where('is_active', true)
            ->whereNotNull('login')
            ->where('login', '!=', '')
            ->orderBy('id')
            ->chunkById(self::USER_CHUNK, function ($users) use ($client, $report, $dryRun): void {
                foreach ($users as $user) {
                    $this->users->adopt($user, $client, $report, $dryRun);
                }
            });
    }

    /**
     * Hôte SMB : réglage de la configuration, avec pour DÉFAUT le nom du serveur de
     * fichiers déjà connu de l'instance (`sambaedu.se4fs_name`) — celui-là même que
     * l'agent substitue au jeton `<se4fs>` dans les UNC des lecteurs. Aucun
     * littéral en dur ici : le montage web et le lecteur SMB doivent pointer sur le
     * même serveur, sans quoi ils divergeraient sans que rien ne le dise.
     */
    private function smbHost(): string
    {
        $configured = trim((string) FilePolicyService::globalConfig()['nextcloud_smb_host']);

        return $configured !== '' ? $configured : trim((string) config('sambaedu.se4fs_name', ''));
    }

    private function mountDetail(ExternalStorageDefinition $definition): string
    {
        return sprintf(
            'SMB //%s/%s%s, identifiants de session, applicable à tous.',
            $definition->host,
            $definition->share,
            $definition->root === '' ? '' : '/' . $definition->root,
        );
    }

    /**
     * Pose le marqueur d'exécution. Sa durée de vie est celle du verrou : au-delà,
     * le verrou est de toute façon caduc, et un marqueur qui survivrait au verrou
     * ferait croire à une exécution là où il n'y en a plus.
     */
    private function markRunning(bool $dryRun): void
    {
        Cache::put(
            self::RUNNING_CACHE_KEY,
            ['started_at' => now()->toIso8601String(), 'dry_run' => $dryRun],
            now()->addSeconds(self::LOCK_SECONDS),
        );
    }

    private function remember(NextcloudProvisioningReport $report): void
    {
        Cache::put(self::REPORT_CACHE_KEY, $report->toArray(), now()->addMinutes(self::REPORT_CACHE_MINUTES));
    }
}
