<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\V1\Jwt\WorkstationJwtRefreshService;
use App\Auth\V1\Jwt\WorkstationJwtRevocationChecker;
use App\Auth\V1\Models\WorkstationJwtRevocation;
use App\Auth\V1\Models\WorkstationRefreshToken;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Story 16.10 — T7.1 / D4.
 *
 * Commande Artisan `workstation:revoke <uuid>` :
 *
 *  - Marque révoqués **tous** les refresh tokens actifs du `workstation_uuid`
 *    via `WorkstationJwtRefreshService::revokeAllRefreshesForWorkstation`.
 *  - Insère une entrée `workstation_jwt_revocations` "marker" par workstation
 *    (jti synthétique unique + `revoked_at = now()` qui sert de cutoff pour
 *    le check workstation-wide du `WorkstationJwtRevocationChecker` — Q3
 *    review 16.10).
 *  - Push le flag cache APCu workstation-wide (clé `jwt:revoked_ws:<uuid>`
 *    valeur = timestamp `revoked_at`, TTL `manual_revoke_cache_ttl` default
 *    3600s) pour invalidation rapide multi-workers.
 *
 * **Effet sur les access tokens en cours (Q3 review 16.10)** : grâce au
 * check workstation-wide du Checker (`isRevoked($jti, $sub, $iat)`), tous
 * les JWT émis avec `iat <= revoked_at` pour ce poste sont désormais
 * invalidés en moins de 60s (TTL cache APCu). Plus de fenêtre 10h résiduelle
 * sur des access tokens en cours.
 *
 * Options :
 *
 *  - `--reason=manual_admin` : motif (par défaut `manual_admin`)
 *  - `--by=admin` : qui a déclenché (default `admin` — log uniquement)
 *  - `--dry-run` : affiche ce qui serait fait, ne touche pas la DB
 */
class WorkstationRevoke extends Command
{
    /** @var string */
    protected $signature = 'workstation:revoke
        {uuid : UUID du poste dont les jetons doivent être révoqués.}
        {--reason=manual_admin : Motif de la révocation (libellé libre, tracé).}
        {--by=admin : Auteur du déclenchement (audit uniquement).}
        {--dry-run : Affiche ce qui serait révoqué sans rien écrire en base.}';

    /** @var string */
    protected $description = 'Révoque tous les jetons de rafraîchissement actifs d\'un poste.';

    /** @var string */
    protected $help = <<<'HELP'
    Révoque tous les jetons de rafraîchissement encore actifs d'un poste, désigné par
    son identifiant unique.

      <info>php artisan workstation:revoke &lt;uuid&gt; --dry-run</info>   ce qui serait révoqué
      <info>php artisan workstation:revoke &lt;uuid&gt; --reason="poste volé" --by=henri</info>

    <comment>Conséquence :</comment> le poste ne peut plus renouveler son accès et devra se
    ré-enrôler. C'est le geste à faire sur un poste perdu, volé, ou sorti du parc.

    <comment>--reason</comment> et <comment>--by</comment> n'alimentent que la trace — mais c'est tout ce qui
    restera pour expliquer la révocation plus tard, renseignez-les.

    <comment>--dry-run</comment> n'écrit rien : utilisez-le pour confirmer que vous visez bien le
    bon poste avant d'agir.
    HELP;

    public function handle(WorkstationJwtRefreshService $refreshService, WorkstationJwtRevocationChecker $checker): int
    {
        $uuid = (string) $this->argument('uuid');
        $reason = (string) $this->option('reason');
        $by = (string) $this->option('by');
        $dryRun = (bool) $this->option('dry-run');

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            $this->error('Invalid UUID format : '.$uuid);

            return 2;
        }

        // 1. Compte les refresh actifs
        $activeCount = WorkstationRefreshToken::query()
            ->where('workstation_uuid', $uuid)
            ->whereNull('revoked_at')
            ->count();

        $this->info(sprintf('Workstation %s : %d active refresh token(s)', $uuid, $activeCount));

        if ($dryRun) {
            $this->warn('[--dry-run] No changes applied.');

            return 0;
        }

        if ($activeCount === 0) {
            // On insère quand même un marker JWT revocation pour signaler
            // « cet uuid est explicitement révoqué » — l'admin a sciemment
            // demandé.
            $this->insertMarkerRevocation($uuid, $reason, $by, $checker);
            $this->warn('No active refresh tokens to revoke. Marker entry inserted in workstation_jwt_revocations.');

            return 0;
        }

        // 2. Cascade refresh
        $count = $refreshService->revokeAllRefreshesForWorkstation($uuid, $reason, $by);

        // 3. Marker
        $this->insertMarkerRevocation($uuid, $reason, $by, $checker);

        $this->info(sprintf('Revoked %d refresh token(s) for workstation %s', $count, $uuid));
        $this->line('');
        $this->info('All in-flight access tokens for this workstation are also invalidated within ≤60s (cache TTL) via workstation-wide revocation marker.');

        Log::channel('auth-v1')->info('[WorkstationRevoke] auth.workstation.revoked', [
            'action_type' => 'auth.workstation.revoked',
            'workstation_uuid' => $uuid,
            'reason' => $reason,
            'revoked_by' => $by,
            'refresh_revoked_count' => $count,
        ]);

        return 0;
    }

    /**
     * Insère une entrée "marker" dans `workstation_jwt_revocations` (jti
     * synthétique unique) + push cache APCu.
     */
    private function insertMarkerRevocation(string $uuid, string $reason, string $by, WorkstationJwtRevocationChecker $checker): void
    {
        $jti = (string) Str::uuid();
        $now = Carbon::now();

        DB::transaction(function () use ($jti, $uuid, $reason, $by, $now): void {
            WorkstationJwtRevocation::query()->create([
                'id' => (string) Str::uuid(),
                'jti' => $jti,
                'workstation_uuid' => $uuid,
                'revoked_at' => $now,
                'reason' => $reason,
                'revoked_by' => $by,
                // Marker expire dans access_ttl (= max access TTL)
                'expires_at' => $now->copy()->addSeconds(
                    (int) config('auth_v1.jwt.access_ttl', 36000)
                ),
            ]);
        });

        // Push cache APCu — clés jti ET workstation-wide.
        $checker->pushRevocation($jti);
        $checker->pushWorkstationRevocation($uuid, $now->timestamp);
    }
}
