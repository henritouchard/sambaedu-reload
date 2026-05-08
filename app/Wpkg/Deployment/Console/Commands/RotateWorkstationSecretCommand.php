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
 * Story 15.5 / AC5.4 — Rotation du secret API d'un poste donné.
 *
 * Procédure :
 *   1. Génère un nouveau secret aléatoire 32 bytes.
 *   2. Stocke l'ancien `secret_hash` dans `previous_secret_hash` +
 *      `previous_valid_until = now + overlap_days` (7 jours par défaut).
 *   3. Le nouveau hash devient `secret_hash`. `rotated_at` = now().
 *   4. Affiche le secret clair sur stdout (CSV).
 *
 * Pendant la fenêtre de chevauchement, l'ancien secret reste valide pour
 * l'auth Bearer (cf. middleware `WorkstationBearerAuth`).
 *
 * Si aucun secret n'existe encore pour ce poste, un nouveau est créé
 * (équivalent provisioning unitaire).
 */
final class RotateWorkstationSecretCommand extends Command
{
    use CsvEscapesHostnames;

    protected $signature = 'wpkg:rotate-secret
                            {workstation : Hostname OU ID du poste à rotener}
                            {--unsafe-output-secrets : Forcer l\'affichage stdout même hors TTY}';

    protected $description = 'Rote le secret API d\'un poste (Story 15.5).';

    public function handle(): int
    {
        if (! $this->canSafelyOutputSecrets()) {
            $this->error('Refus d\'afficher le secret : stdout n\'est pas un TTY.');
            $this->error('Utilisez --unsafe-output-secrets pour passer outre.');

            return self::FAILURE;
        }

        $identifier = (string) $this->argument('workstation');
        $workstation = $this->resolveWorkstation($identifier);

        if ($workstation === null) {
            $this->error("Poste '{$identifier}' introuvable.");

            return self::FAILURE;
        }

        $newSecret = $this->generateSecret();
        $overlapDays = (int) config('sambaedu.wpkg.secret_rotation_overlap_days', 7);

        $secretRow = WorkstationApiSecret::firstOrNew(['workstation_id' => $workstation->id]);

        $isNewProvisioning = ! $secretRow->exists;

        if ($isNewProvisioning) {
            $secretRow->fill([
                'secret_hash' => Hash::make($newSecret),
            ]);
        } else {
            $secretRow->fill([
                'previous_secret_hash' => $secretRow->secret_hash,
                'previous_valid_until' => now()->addDays($overlapDays),
                'secret_hash' => Hash::make($newSecret),
                'rotated_at' => now(),
                'revoked_at' => null, // une rotation lève une révocation antérieure
            ]);
        }

        $secretRow->save();

        $this->line('hostname,secret');
        $this->line($this->csvEscape($workstation->name) . ',' . $newSecret);

        Log::channel('wpkg-deploy')->info('[wpkg:rotate-secret] secret tourné', [
            'event' => 'wpkg_rotate_secret',
            'workstation_id' => $workstation->id,
            'hostname' => $workstation->name,
            'is_new_provisioning' => $isNewProvisioning,
            'overlap_days' => $overlapDays,
        ]);

        if ($isNewProvisioning) {
            $this->info('Secret provisionné (poste sans secret antérieur).');
        } else {
            $this->info(sprintf(
                'Secret tourné. L\'ancien reste valide %d jour(s).',
                $overlapDays
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @internal protégé pour permettre les overrides en test.
     */
    protected function generateSecret(): string
    {
        return Str::random(32);
    }

    private function resolveWorkstation(string $identifier): ?Workstation
    {
        if (ctype_digit($identifier)) {
            return Workstation::find((int) $identifier);
        }

        return Workstation::where('name', $identifier)->first();
    }

    private function canSafelyOutputSecrets(): bool
    {
        if ($this->option('unsafe-output-secrets')) {
            return true;
        }

        if (app()->environment('testing')) {
            return true;
        }

        if (function_exists('posix_isatty') && defined('STDOUT')) {
            return @posix_isatty(STDOUT);
        }

        return false;
    }
}
