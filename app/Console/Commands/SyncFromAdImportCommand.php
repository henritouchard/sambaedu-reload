<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\SEConfig;
use App\Repositories\EstablishmentRepository;
use App\Services\AppProfile\AppProfileAdImporter;
use App\Services\AppProfile\AppProfileLegacyApplicationLinker;
use App\Services\AppStore\LegacyWpkgImporter;
use App\Services\Network\DhcpService;
use App\Services\Parc\WorkstationGroupService;
use App\Services\PermissionService;
use App\Services\Permissions\RightsMigrationService;
use App\Services\ServiceCredentialTotpManager;
use App\Services\ShortcutsService;
use App\Services\UserGroupService;
use App\Services\UserSyncService;
use App\Services\WorkstationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

/**
 * Pendant CLI de la page « Synchronisation depuis l'AD » (sync-from-ad).
 *
 * Rejoue les mêmes imports, dans le même ordre, avec la même sémantique
 * (skip si déjà importé, arrêt sur erreur, contexte établissement, dry-run
 * de la migration des droits). Utilisable :
 *   - en interactif : sélection établissement + multi-sélection des imports ;
 *   - en une ligne (post-install / scripté) :
 *       php artisan import:sync-from-ad --all --no-interaction --continue-on-error
 *
 * Chaque import délègue au service existant via un logger (level, message)
 * routé vers la sortie console — exactement comme le composant Livewire.
 */
class SyncFromAdImportCommand extends Command
{
    protected $signature = 'import:sync-from-ad
        {imports?* : Codes des imports à exécuter (ex: users_establishment workstations). Vide = interactif ou --all}
        {--all : Exécute tous les imports dans l\'ordre}
        {--etab= : Code établissement (UAI ou 0 pour le domaine entier)}
        {--rights-execute : Applique réellement la migration des droits (sinon dry-run)}
        {--continue-on-error : Poursuit les imports suivants même en cas d\'erreur}
        {--list : Affiche les imports disponibles et quitte}';

    protected $description = 'Rejoue en CLI les imports de la page « Synchronisation depuis l\'AD » (interactif ou non-interactif)';

    /** Décidé une fois au lancement : la migration des droits applique-t-elle réellement ? */
    private bool $rightsExecute = false;

    public function handle(EstablishmentRepository $establishmentRepository): int
    {
        $definitions = $this->importDefinitions();

        if ((bool) $this->option('list')) {
            $this->renderList($definitions);

            return self::SUCCESS;
        }

        // 1. Quels imports exécuter (ordre canonique préservé) ?
        $selected = $this->resolveSelectedImports($definitions);
        if ($selected === null) {
            return self::FAILURE; // code inconnu déjà signalé
        }
        if ($selected === []) {
            $this->warn('Aucun import sélectionné.');

            return self::SUCCESS;
        }

        // 2. Contexte établissement : posé même si aucun import scopé (inoffensif),
        //    requis par la garde ensureEstablishmentContextSelected() des étapes scopées.
        $needsEtab = $this->anyScoped($selected, $definitions);
        $etab = $this->resolveEstablishment($establishmentRepository, $needsEtab);
        $this->primeEstablishmentContext($etab);

        // 3. Décision dry-run / exécution pour la migration des droits.
        $this->rightsExecute = $this->resolveRightsExecute($selected);

        // 4. Confirmation (interactif uniquement).
        if ($this->input->isInteractive()) {
            $label = $etab === '0' ? 'Domaine entier' : $etab;
            if (! confirm(sprintf('Lancer %d import(s) sur « %s » ?', count($selected), $label), default: true)) {
                $this->warn('Annulé.');

                return self::SUCCESS;
            }
        }

        // 5. Exécution.
        return $this->runImports($selected, $definitions, $etab);
    }

    /**
     * Registre déclaratif des imports, dans l'ordre exact de la page sync-from-ad.
     *
     * @return array<string, array{title: string, scoped: bool}>
     */
    private function importDefinitions(): array
    {
        return [
            'users_establishment' => ['title' => '1. Importer les utilisateurs de l\'établissement', 'scoped' => true],
            'user_groups'         => ['title' => '2. Importer les groupes utilisateurs', 'scoped' => false],
            'workstations'        => ['title' => '3. Importer les postes de travail', 'scoped' => true],
            'physical_groups'     => ['title' => '4. Importer les groupes physiques (salles)', 'scoped' => true],
            'logical_groups'      => ['title' => '5. Importer les groupes logiques (parcs)', 'scoped' => true],
            'wpkg_applications'   => ['title' => '6. Importer les applications WPKG', 'scoped' => false],
            'app_profiles'        => ['title' => '7. Importer les profils applicatifs', 'scoped' => true],
            'shortcuts'           => ['title' => '8. Importer les raccourcis', 'scoped' => false],
            'rights_profiles'     => ['title' => '9. Rapatrier les profils LDAP custom', 'scoped' => false],
            'rights_migration'    => ['title' => '10. Migrer les droits legacy → Spatie', 'scoped' => false],
            'dhcp_reservations'   => ['title' => '11. Importer les réservations DHCP', 'scoped' => false],
            'se4install_totp'     => ['title' => '12. Importer le TOTP de se4install', 'scoped' => false],
        ];
    }

    /**
     * Résout la liste des imports à exécuter (--all, positionnel, ou multiselect interactif).
     * Retourne null si un code invalide a été fourni (erreur déjà affichée).
     *
     * @param  array<string, array{title: string, scoped: bool}>  $definitions
     * @return list<string>|null
     */
    private function resolveSelectedImports(array $definitions): ?array
    {
        $codes = array_keys($definitions);

        if ((bool) $this->option('all')) {
            return $codes;
        }

        $args = array_values(array_filter((array) $this->argument('imports'), static fn ($v) => $v !== null && $v !== ''));
        if ($args !== []) {
            $unknown = array_diff($args, $codes);
            if ($unknown !== []) {
                $this->error('Import(s) inconnu(s) : ' . implode(', ', $unknown));
                $this->line('Codes valides : ' . implode(', ', $codes));

                return null;
            }

            return $this->inCanonicalOrder($args, $codes);
        }

        // Rien de spécifié et pas de TTY : on refuse d'agir implicitement.
        if (! $this->input->isInteractive()) {
            $this->error('Précisez un ou plusieurs imports, --all, ou --list.');
            $this->line('Codes valides : ' . implode(', ', $codes));

            return null;
        }

        $options = [];
        foreach ($definitions as $code => $def) {
            $options[$code] = $def['title'];
        }

        $chosen = multiselect(
            label: 'Quels imports exécuter ?',
            options: $options,
            default: $codes,
            hint: 'Espace pour (dé)cocher, Entrée pour valider',
        );

        return $this->inCanonicalOrder($chosen, $codes);
    }

    /**
     * Résout le contexte établissement : --etab, sinon sélection interactive si requise,
     * sinon « 0 » (domaine entier).
     */
    private function resolveEstablishment(EstablishmentRepository $repository, bool $needsEtab): string
    {
        $option = $this->option('etab');
        if ($option !== null && $option !== '') {
            return (string) $option;
        }

        if ($needsEtab && $this->input->isInteractive()) {
            $options = [];
            foreach ($repository->getAll() as $code => $label) {
                $options[(string) $code] = sprintf('%s (%s)', $label, $code);
            }

            return (string) select(
                label: 'Contexte de synchronisation (établissement)',
                options: $options,
                default: '0',
            );
        }

        return '0';
    }

    /**
     * La migration des droits (étape 10) applique-t-elle réellement les changements ?
     * Par défaut dry-run, comme le « Tout exécuter » de la page.
     *
     * @param  list<string>  $selected
     */
    private function resolveRightsExecute(array $selected): bool
    {
        if (! in_array('rights_migration', $selected, true)) {
            return false;
        }

        if ((bool) $this->option('rights-execute')) {
            return true;
        }

        if ($this->input->isInteractive()) {
            return select(
                label: 'Migration des droits legacy → Spatie',
                options: [
                    'dry_run' => 'Aperçu (dry-run, aucune écriture)',
                    'execute' => 'Exécuter (applique la migration)',
                ],
                default: 'dry_run',
            ) === 'execute';
        }

        return false;
    }

    /**
     * Pose le contexte établissement pour la durée du process : session + cache SEConfig.
     * Le priming explicite via establishment($code) garantit que getCurrentEstablishmentCode()
     * renvoie la bonne valeur même hors requête HTTP.
     */
    private function primeEstablishmentContext(string $etab): void
    {
        $code = $etab === '' ? '0' : $etab;
        session(['etab' => $code]);
        SEConfig::establishment($code);
    }

    /**
     * Boucle d'exécution : mirror de runStep/runAllSteps du composant Livewire.
     *
     * @param  list<string>  $selected
     * @param  array<string, array{title: string, scoped: bool}>  $definitions
     */
    private function runImports(array $selected, array $definitions, string $etab): int
    {
        $this->newLine();
        $this->info('Contexte établissement : ' . ($etab === '0' ? 'Domaine entier' : $etab));
        $this->newLine();

        $results = [];
        $aborted = false;

        foreach ($selected as $code) {
            $this->line('▶ ' . $definitions[$code]['title']);

            $logger = function (string $level, string $message): void {
                $this->line('   ' . $this->formatLogLine($level, $message));
            };

            try {
                $stats = $this->runImport($code, $logger);
                $status = $this->statusFor($code, $stats);
                $results[$code] = $status;
                $this->line('   ' . $this->statusLabel($status));
            } catch (Throwable $e) {
                $results[$code] = 'error';
                $this->error('   ✗ Erreur : ' . $e->getMessage());
                Log::error('[import:sync-from-ad] Erreur import ' . $code, [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                if (! (bool) $this->option('continue-on-error')) {
                    $this->error('Import interrompu suite à une erreur.');
                    $aborted = true;
                    break;
                }
            }

            $this->newLine();
        }

        $this->renderSummary($results);

        $hasError = in_array('error', $results, true);

        return ($hasError || $aborted) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Exécute un import et retourne ses stats — switch identique au composant.
     *
     * @return array<string, mixed>
     */
    private function runImport(string $code, callable $logger): array
    {
        return match ($code) {
            'users_establishment' => app(UserSyncService::class)->importFromAd($logger, 'all'),
            'user_groups'         => app(UserGroupService::class)->importFromUsersAdGroups($logger),
            'workstations'        => app(WorkstationService::class)->importFromAd($logger),
            'physical_groups'     => app(WorkstationGroupService::class)->importFromAd($logger),
            'logical_groups'      => app(WorkstationGroupService::class)->importLogicalGroupsFromAd($logger),
            'wpkg_applications'   => app(LegacyWpkgImporter::class)->importFromLegacy($logger),
            'app_profiles'        => $this->runAppProfiles($logger),
            'shortcuts'           => $this->runShortcuts($logger),
            'rights_profiles'     => app(PermissionService::class)->importCustomProfilesFromAd($logger),
            'rights_migration'    => $this->runRightsMigration($logger),
            'dhcp_reservations'   => $this->runDhcpReservations($logger),
            'se4install_totp'     => $this->runSe4installTotp($logger),
        };
    }

    /**
     * Étape 7 : import des profils applicatifs + liaison aux applications WPKG legacy.
     *
     * @return array<string, mixed>
     */
    private function runAppProfiles(callable $logger): array
    {
        $stats = app(AppProfileAdImporter::class)->importFromAd($logger);

        $linkStats = app(AppProfileLegacyApplicationLinker::class)->linkFromLegacy($logger);

        return array_merge($stats, [
            'applications_linked'          => $linkStats['applications_linked'],
            'profiles_linked'              => $linkStats['profiles_linked'],
            'profiles_without_legacy_parc' => $linkStats['profiles_without_legacy_parc'],
            'applications_missing'         => $linkStats['applications_missing'],
            'legacy_unavailable'           => $linkStats['legacy_unavailable'],
        ]);
    }

    /**
     * Étape 8 : import des raccourcis depuis le JSON (le service ne prend pas de logger).
     *
     * @return array<string, mixed>
     */
    private function runShortcuts(callable $logger): array
    {
        $logger('info', 'Lecture du fichier JSON des raccourcis...');

        $stats = app(ShortcutsService::class)->importFromJson();

        $logger('info', sprintf(
            '%d créé(s), %d mis à jour, %d erreur(s)',
            $stats['created'] ?? 0,
            $stats['updated'] ?? 0,
            $stats['errors'] ?? 0,
        ));

        return $stats;
    }

    /**
     * Étape 10 : migration des droits legacy → Spatie. Dry-run par défaut.
     *
     * @return array<string, mixed>
     */
    private function runRightsMigration(callable $logger): array
    {
        $dryRun = ! $this->rightsExecute;
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $logger('info', $dryRun ? 'Aperçu (dry-run) en cours…' : 'Migration en cours…');

        // 1er paramètre = dryRun (positionnel pour rester simple à mocker/tester).
        $report = app(RightsMigrationService::class)->migrate($dryRun);

        $logger('info', "{$prefix}Utilisateurs scannés : {$report['users_scanned']}");
        $logger('info', "{$prefix}Rôles assignés : {$report['roles_assigned']}");
        $logger('info', "{$prefix}Délégations positives : {$report['delegations_created']}");

        if (($report['negatives_created'] ?? 0) > 0) {
            $logger('info', "{$prefix}Délégations négatives : {$report['negatives_created']}");
        }
        if (($report['fallbacks_ignored'] ?? 0) > 0) {
            $logger('warning', "{$prefix}Fallbacks buggés ignorés : {$report['fallbacks_ignored']}");
        }
        foreach ($report['warnings'] ?? [] as $warning) {
            $logger('warning', "{$prefix}{$warning}");
        }
        if (! empty($report['unmappable'])) {
            $logger('warning', "{$prefix}Cas non mappables : " . count($report['unmappable']));
        }

        return $report;
    }

    /**
     * Étape 11 : import one-shot des réservations DHCP legacy.
     *
     * @return array<string, mixed>
     */
    private function runDhcpReservations(callable $logger): array
    {
        $path = (string) config('sambaedu.dhcp.reservations_file', '/etc/sambaedu/reservations.inc');
        $logger('info', 'Lecture de ' . $path);

        return app(DhcpService::class)->importFromLegacyFile($path, $logger);
    }

    /**
     * Étape 12 : adoption du TOTP se4install. Marqué « sauté » si rien n'a été importé.
     *
     * @return array<string, mixed>
     */
    private function runSe4installTotp(callable $logger): array
    {
        $path = (string) config('sambaedu.se4install_hashes_file', '/etc/sambaedu/hashes');
        $logger('info', 'Lecture de ' . $path . '...');

        $stats = app(ServiceCredentialTotpManager::class)->importSe4installFromLegacyHashes($path, $logger);

        if (! ($stats['imported'] ?? false)) {
            $stats['already_imported'] = true;
        }

        return $stats;
    }

    /**
     * Statut final d'une étape (mirror runStep) : skipped si déjà importé,
     * dry_run pour la migration des droits non appliquée, sinon success.
     *
     * @param  array<string, mixed>  $stats
     */
    private function statusFor(string $code, array $stats): string
    {
        if (! empty($stats['already_imported'])) {
            return 'skipped';
        }
        if ($code === 'rights_migration' && ! $this->rightsExecute) {
            return 'dry_run';
        }

        return 'success';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'success' => '<info>✓ Succès</info>',
            'skipped' => '<comment>↷ Sauté (déjà importé)</comment>',
            'dry_run' => '<comment>🔍 Aperçu (dry-run)</comment>',
            'error'   => '<error>✗ Erreur</error>',
            default   => $status,
        };
    }

    private function formatLogLine(string $level, string $message): string
    {
        return match ($level) {
            'success' => '<info>✓</info> ' . $message,
            'error'   => '<error>✗</error> ' . $message,
            'warning' => '<comment>⚠</comment> ' . $message,
            default   => 'ℹ ' . $message,
        };
    }

    /**
     * @param  array<string, string>  $results
     */
    private function renderSummary(array $results): void
    {
        if ($results === []) {
            return;
        }

        $this->newLine();
        $rows = [];
        foreach ($results as $code => $status) {
            $rows[] = [$code, $this->statusLabel($status)];
        }
        $this->table(['Import', 'Statut'], $rows);
    }

    /**
     * @param  array<string, array{title: string, scoped: bool}>  $definitions
     */
    private function renderList(array $definitions): void
    {
        $rows = [];
        foreach ($definitions as $code => $def) {
            $rows[] = [$code, $def['title'], $def['scoped'] ? 'oui' : 'non'];
        }
        $this->table(['Code', 'Import', 'Scopé établissement'], $rows);
    }

    /**
     * @param  list<string>  $selected
     * @param  array<string, array{title: string, scoped: bool}>  $definitions
     */
    private function anyScoped(array $selected, array $definitions): bool
    {
        foreach ($selected as $code) {
            if ($definitions[$code]['scoped']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Réordonne une sélection selon l'ordre canonique du registre.
     *
     * @param  array<int, string>  $subset
     * @param  list<string>  $canonical
     * @return list<string>
     */
    private function inCanonicalOrder(array $subset, array $canonical): array
    {
        return array_values(array_filter($canonical, static fn (string $code): bool => in_array($code, $subset, true)));
    }
}
