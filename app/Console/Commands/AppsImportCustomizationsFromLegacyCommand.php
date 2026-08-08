<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AppKind;
use App\Models\AppCustomization;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Importe les fichiers legacy `/etc/sambaedu/applications/{firefox,thunderbird}/*.json`
 * vers la table `app_customizations`.
 *
 * Story 4.8 — AC 12. Idempotent (updateOrCreate sur clé composite).
 *
 * Conventions de nom fichier :
 *   - `default.json` / `custom.json`     → scope global (NULL/NULL, is_default=true)
 *   - `<login>.json`                      → User
 *   - `<name>.json`                       → UserGroup ou WorkstationGroup (premier match)
 *   - Orphans (aucun match)               → NON importés, log warning
 */
class AppsImportCustomizationsFromLegacyCommand extends Command
{
    protected $signature = 'apps:import-customizations-from-legacy'
        . ' {--kind=all : Périmètre de l\'import : firefox, thunderbird ou all.}'
        . ' {--dry-run : Scanne sans écrire en DB}'
        . ' {--verbose-files : Détaille chaque fichier scanné}';

    protected $description = 'Importe les overrides legacy JSON vers app_customizations.';

    protected $help = <<<'HELP'
    Importe les personnalisations d'applications du serveur SE4 — les fichiers JSON de
    <info>/etc/sambaedu/applications/{firefox,thunderbird}/</info> — vers la table des
    personnalisations SE5.

    Le nom de chaque fichier détermine sa portée :

      <comment>default.json</comment>, <comment>custom.json</comment>   personnalisation globale, par défaut
      <comment>&lt;login&gt;.json</comment>                un utilisateur
      <comment>&lt;nom&gt;.json</comment>                  un groupe d'utilisateurs ou un groupe de postes

    Un fichier dont le nom ne correspond à AUCUN utilisateur ni groupe connu n'est pas
    importé : il est seulement signalé dans les journaux. Contrôlez cette liste avant
    de conclure que l'import est complet.

      <info>php artisan apps:import-customizations-from-legacy --dry-run</info>
      <info>php artisan apps:import-customizations-from-legacy --kind=firefox</info>

    Import de migration, à jouer une fois au moment de basculer un établissement.
    Il est idempotent : le rejouer met à jour au lieu de dupliquer.
    HELP;

    public function handle(): int
    {
        $kindOption = (string) $this->option('kind');
        $dryRun = (bool) $this->option('dry-run');
        $verboseFiles = (bool) $this->option('verbose-files');

        $kinds = match ($kindOption) {
            'all' => [AppKind::Firefox, AppKind::Thunderbird],
            'firefox' => [AppKind::Firefox],
            'thunderbird' => [AppKind::Thunderbird],
            default => null,
        };

        if ($kinds === null) {
            $this->error('Option --kind invalide. Valeurs : firefox|thunderbird|all.');
            return self::FAILURE;
        }

        $base = rtrim((string) config('app-customizations.fs_base_path', '/etc/sambaedu/applications'), '/');

        $scanned = 0;
        $imported = 0;
        $skipped = 0;
        $orphans = 0;

        foreach ($kinds as $kind) {
            $dir = $base . '/' . $kind->alias();
            if (! is_dir($dir)) {
                $this->warn("Dossier absent : {$dir} — skip.");
                continue;
            }

            $files = glob($dir . '/*.json') ?: [];
            foreach ($files as $path) {
                $scanned++;
                $filename = basename($path);
                $key = preg_replace('/\.json$/i', '', $filename) ?? '';

                if ($verboseFiles) {
                    $this->line("Scan : {$path}");
                }

                $raw = @file_get_contents($path);
                if ($raw === false) {
                    $this->warn("Lecture échouée : {$path}");
                    continue;
                }
                $policies = json_decode($raw, true);
                if (! is_array($policies)) {
                    $this->warn("JSON invalide : {$path}");
                    continue;
                }

                if (in_array($key, ['default', 'custom'], true)) {
                    $result = $this->upsertDefault($kind, $policies, $dryRun);
                } else {
                    $owner = $this->lookupOwner($key);
                    if ($owner === null) {
                        $orphans++;
                        Log::warning('[AppsImportCustomizations] orphan skipped', [
                            'kind' => $kind->alias(),
                            'file' => $filename,
                            'key' => $key,
                        ]);
                        $this->warn("Orphan : {$filename} — aucun owner pour '{$key}'");
                        continue;
                    }
                    $result = $this->upsertScoped($kind, $owner, $policies, $dryRun);
                }

                if ($result === 'imported') {
                    $imported++;
                } else {
                    $skipped++;
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%d fichiers scannés, %d importés, %d skippés (déjà en DB ou dry-run), %d orphelins.',
            $scanned,
            $imported,
            $skipped,
            $orphans,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $policies
     */
    private function upsertDefault(AppKind $kind, array $policies, bool $dryRun): string
    {
        $existing = AppCustomization::query()
            ->ofKind($kind)
            ->defaults()
            ->first();

        $wouldChange = $existing === null
            || json_encode($existing->policies_json) !== json_encode($policies);

        if ($dryRun) {
            return $wouldChange ? 'imported' : 'skipped';
        }

        AppCustomization::updateOrCreate(
            [
                'app_kind' => $kind->value,
                'customizable_type' => null,
                'customizable_id' => null,
                'is_default' => true,
            ],
            [
                'policies_json' => $policies,
            ],
        );

        return $wouldChange ? 'imported' : 'skipped';
    }

    /**
     * @param  array<string,mixed>  $policies
     */
    private function upsertScoped(AppKind $kind, Model $owner, array $policies, bool $dryRun): string
    {
        $existing = AppCustomization::query()
            ->ofKind($kind)
            ->where('customizable_type', $owner::class)
            ->where('customizable_id', $owner->getKey())
            ->first();

        $wouldChange = $existing === null
            || json_encode($existing->policies_json) !== json_encode($policies);

        if ($dryRun) {
            return $wouldChange ? 'imported' : 'skipped';
        }

        AppCustomization::updateOrCreate(
            [
                'app_kind' => $kind->value,
                'customizable_type' => $owner::class,
                'customizable_id' => $owner->getKey(),
            ],
            [
                'policies_json' => $policies,
                'is_default' => false,
            ],
        );

        return $wouldChange ? 'imported' : 'skipped';
    }

    private function lookupOwner(string $key): ?Model
    {
        if ($key === '') {
            return null;
        }

        /** @var Model|null $user */
        $user = User::query()->where('login', $key)->first();
        if ($user !== null) {
            return $user;
        }

        /** @var Model|null $group */
        $group = UserGroup::query()->where('name', $key)->first();
        if ($group !== null) {
            return $group;
        }

        /** @var Model|null $ws */
        $ws = WorkstationGroup::query()->where('name', $key)->first();
        if ($ws !== null) {
            return $ws;
        }

        return null;
    }
}
