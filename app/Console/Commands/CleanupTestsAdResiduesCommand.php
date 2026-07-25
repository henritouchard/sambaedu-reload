<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Config\LdapDnHelper;
use App\LdapModels\DeviceGroupModel;
use App\LdapModels\DeviceGroupTagModel;
use App\Models\AppProfile;
use App\Models\WorkstationGroup;
use App\Services\AdSync\AdSyncService;
use Illuminate\Console\Command;

/**
 * Filet de sécurité contre les résidus de tests Legacy↔Laravel
 * (`tests/Integration/LegacyLaravelComparison/`).
 *
 * Deux familles de patterns :
 *   - `Test*_<10 digits>`  → suffixe `time()`        (scripts Legacy/compare_*)
 *   - `Test*_<13+ hex>`    → suffixe `uniqid()`      (PHPUnit)
 *
 * Les noms manuels (`testparcglobal`, `test_parc`, …) sans suffixe machine
 * ne matchent pas le pattern par défaut et sont préservés.
 *
 * Dry-run par défaut. Pour appliquer : `--apply`.
 */
class CleanupTestsAdResiduesCommand extends Command
{
    protected $signature = 'tests:cleanup-ad-residues
                            {--apply : Effectue les suppressions (sinon dry-run)}
                            {--pattern= : Regex PCRE de remplacement (défaut : résidus auto-générés)}
                            {--no-confirm : Saute la confirmation interactive avant --apply}';

    protected $description = 'Supprime les résidus de tests dans AD (OU=Parcs, OU=Computers) et Postgres (workstation_groups, app_profiles).';

    /**
     * Pattern par défaut : préfixe `Test` + suffixe timestamp Unix (10 chiffres)
     * ou hex `uniqid()` (≥13). Insensible à la casse côté préfixe pour
     * couvrir `test_legacy_<ts>` aussi.
     */
    private const DEFAULT_PATTERN = '/^test[a-z0-9_]*_(\d{10}|[a-f0-9]{13,})$/i';

    public function handle(
        LdapDnHelper $dnHelper,
        AdSyncService $adSyncService,
    ): int {
        $apply = (bool) $this->option('apply');
        $pattern = $this->option('pattern') ?: self::DEFAULT_PATTERN;

        if (@preg_match($pattern, '') === false) {
            $this->error("Pattern regex invalide : {$pattern}");

            return self::FAILURE;
        }

        $this->info($apply ? '=== MODE APPLY (suppressions effectives) ===' : '=== MODE DRY-RUN (aucune suppression) ===');
        $this->line("Pattern : {$pattern}");
        $this->newLine();

        $parcsCns = $this->collectMatching(
            DeviceGroupTagModel::in($dnHelper->parcsDn())->get(),
            fn ($entry) => $entry->getParcName() ?? '',
            $pattern,
        );
        $computersOus = $this->collectMatching(
            DeviceGroupModel::in($dnHelper->computers())->get(),
            fn ($entry) => $entry->getGroupName() ?? '',
            $pattern,
        );
        $sqlGroups = WorkstationGroup::query()->get()
            ->filter(fn ($g) => preg_match($pattern, (string) $g->name) === 1)
            ->values();
        $sqlProfiles = AppProfile::query()->get()
            ->filter(fn ($p) => preg_match($pattern, (string) $p->name) === 1)
            ->values();

        $this->table(
            ['Scope', 'Candidats'],
            [
                ['AD — CN dans OU=Parcs', count($parcsCns)],
                ['AD — OU dans OU=Computers', count($computersOus)],
                ['SQL — workstation_groups', $sqlGroups->count()],
                ['SQL — app_profiles', $sqlProfiles->count()],
            ],
        );

        $total = count($parcsCns) + count($computersOus) + $sqlGroups->count() + $sqlProfiles->count();
        if ($total === 0) {
            $this->info('Rien à nettoyer.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->newLine();
            $this->line('Détail des candidats :');
            foreach ($parcsCns as $e) {
                $this->line('  [Parcs CN]      '.$e->getDn());
            }
            foreach ($computersOus as $e) {
                $this->line('  [Computers OU]  '.$e->getDn());
            }
            foreach ($sqlGroups as $g) {
                $this->line("  [SQL group]     id={$g->id} name={$g->name}");
            }
            foreach ($sqlProfiles as $p) {
                $this->line("  [SQL profile]   id={$p->id} name={$p->name}");
            }
            $this->newLine();
            $this->comment('Relancez avec --apply pour effectuer les suppressions.');

            return self::SUCCESS;
        }

        if (! $this->option('no-confirm') && ! $this->confirm("Confirmer la suppression de {$total} entité(s) ?", false)) {
            $this->warn('Annulé.');

            return self::SUCCESS;
        }

        $stats = ['parcs_cn' => 0, 'computers_ou' => 0, 'sql_group' => 0, 'sql_profile' => 0, 'errors' => 0];

        foreach ($parcsCns as $entry) {
            // Story 38.7 — SE5 n'écrit plus dans OU=Parcs ; la suppression d'un
            // résidu de test se fait par delete() direct sur l'objet LDAP (le
            // service d'écriture AppProfileAdSyncService a été retiré).
            try {
                $entry->delete();
                $stats['parcs_cn']++;
                $this->line("  ✓ AD CN supprimé : {$entry->getDn()}");
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->error("  ✗ AD CN {$entry->getDn()} : {$e->getMessage()}");
            }
        }

        foreach ($computersOus as $entry) {
            $name = $entry->getGroupName() ?? '';
            try {
                $adSyncService->deleteWorkstationGroupByName($name);
                $stats['computers_ou']++;
                $this->line("  ✓ AD OU supprimé : {$entry->getDn()}");
            } catch (\Throwable $e) {
                try {
                    $entry->delete();
                    $stats['computers_ou']++;
                    $this->line("  ✓ AD OU supprimé (fallback direct) : {$entry->getDn()}");
                } catch (\Throwable $e2) {
                    $stats['errors']++;
                    $this->error("  ✗ AD OU {$entry->getDn()} : {$e2->getMessage()}");
                }
            }
        }

        foreach ($sqlGroups as $g) {
            try {
                $g->delete();
                $stats['sql_group']++;
                $this->line("  ✓ SQL workstation_group supprimé : id={$g->id} name={$g->name}");
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->error("  ✗ SQL workstation_group id={$g->id} : {$e->getMessage()}");
            }
        }

        foreach ($sqlProfiles as $p) {
            try {
                $p->delete();
                $stats['sql_profile']++;
                $this->line("  ✓ SQL app_profile supprimé : id={$p->id} name={$p->name}");
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->error("  ✗ SQL app_profile id={$p->id} : {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Bilan : Parcs CN=%d, Computers OU=%d, SQL groups=%d, SQL profiles=%d, erreurs=%d',
            $stats['parcs_cn'], $stats['computers_ou'], $stats['sql_group'], $stats['sql_profile'], $stats['errors'],
        ));

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @template TEntry
     *
     * @param  iterable<TEntry>  $entries
     * @param  callable(TEntry): string  $nameExtractor
     * @return array<int,TEntry>
     */
    private function collectMatching(iterable $entries, callable $nameExtractor, string $pattern): array
    {
        $matched = [];
        foreach ($entries as $entry) {
            $name = $nameExtractor($entry);
            if ($name !== '' && preg_match($pattern, $name) === 1) {
                $matched[] = $entry;
            }
        }

        return $matched;
    }
}
