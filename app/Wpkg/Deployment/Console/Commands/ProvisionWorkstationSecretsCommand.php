<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Console\Commands;

use App\Models\Workstation;
use App\Wpkg\Deployment\Console\Commands\Concerns\CsvEscapesHostnames;
use App\Wpkg\Deployment\Models\WorkstationApiSecret;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Story 15.5 / AC5.3 — Provisionne un secret aléatoire 32 bytes par poste actif.
 *
 * Mode normal : génère un secret pour chaque `Workstation::active()` SANS
 * ligne `workstation_api_secrets`, et affiche le secret clair sur stdout
 * au format CSV `hostname,secret`.
 *
 * Mode `--force` : régénère le secret pour TOUS les postes actifs (utiliser
 * avec précaution — équivaut à une rotation massive).
 *
 * Sécurité stdout :
 *   - Si stdout n'est pas un TTY (pipe, fichier, CI), on refuse d'afficher
 *     les secrets sauf si `--unsafe-output-secrets` est fourni explicitement.
 *   - Évite la fuite accidentelle des secrets dans les logs CI.
 */
final class ProvisionWorkstationSecretsCommand extends Command
{
    use CsvEscapesHostnames;

    protected $signature = 'wpkg:provision-secrets
                            {--force : Régénère tous les secrets (rotation massive)}
                            {--unsafe-output-secrets : Forcer l\'affichage stdout même hors TTY}';

    protected $description = 'Provisionne un secret API par poste actif (Story 15.5 / Auth Phase 2).';

    public function handle(): int
    {
        if (! $this->canSafelyOutputSecrets()) {
            $this->error('Refus d\'afficher les secrets : stdout n\'est pas un TTY.');
            $this->error('Utilisez --unsafe-output-secrets pour passer outre (déconseillé en CI).');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        $query = Workstation::query()->where('status', 'active');

        if (! $force) {
            $query->whereNotIn(
                'id',
                WorkstationApiSecret::query()->select('workstation_id')
            );
        }

        $workstations = $query->get(['id', 'name']);

        if ($workstations->isEmpty()) {
            $this->info('Aucun poste à provisionner.');

            return self::SUCCESS;
        }

        $this->line('hostname,secret');

        $count = 0;
        foreach ($workstations as $workstation) {
            $secret = $this->generateSecret();

            if ($force) {
                $existing = WorkstationApiSecret::where('workstation_id', $workstation->id)->first();
                if ($existing !== null) {
                    // Force = re-provisionning : on rote le secret existant.
                    $existing->update([
                        'previous_secret_hash' => $existing->secret_hash,
                        'previous_valid_until' => now()->addDays(
                            (int) config('sambaedu.wpkg.secret_rotation_overlap_days', 7)
                        ),
                        'secret_hash' => Hash::make($secret),
                        'rotated_at' => now(),
                        'revoked_at' => null,
                    ]);
                } else {
                    WorkstationApiSecret::create([
                        'workstation_id' => $workstation->id,
                        'secret_hash' => Hash::make($secret),
                    ]);
                }
            } else {
                WorkstationApiSecret::create([
                    'workstation_id' => $workstation->id,
                    'secret_hash' => Hash::make($secret),
                ]);
            }

            // CSV escape : si le hostname contient une virgule ou un guillemet,
            // on le quote. Les hostnames Windows ne devraient pas en contenir,
            // mais on reste défensif.
            $this->line($this->csvEscape($workstation->name) . ',' . $secret);
            $count++;
        }

        Log::channel('wpkg-deploy')->info('[wpkg:provision-secrets] secrets provisionnés', [
            'event' => 'wpkg_provision_secrets',
            'count' => $count,
            'force' => $force,
        ]);

        $this->info(sprintf(
            '%d secret(s) provisionné(s)%s.',
            $count,
            $force ? ' (mode force)' : ''
        ));

        return self::SUCCESS;
    }

    /**
     * @internal protégé pour permettre les overrides en test.
     */
    protected function generateSecret(): string
    {
        return Str::random(32);
    }

    /**
     * Détecte si stdout est un TTY pour décider d'autoriser l'affichage des
     * secrets clairs. En testing, on autorise toujours (les secrets ne fuient
     * que dans les assertions du test, pas dans des logs CI).
     */
    private function canSafelyOutputSecrets(): bool
    {
        if ($this->option('unsafe-output-secrets')) {
            return true;
        }

        if (app()->environment('testing')) {
            return true;
        }

        // PHP_SAPI 'cli' + posix_isatty si dispo.
        if (function_exists('posix_isatty') && defined('STDOUT')) {
            return @posix_isatty(STDOUT);
        }

        // Conservateur : on refuse si on ne peut pas vérifier.
        return false;
    }

}
