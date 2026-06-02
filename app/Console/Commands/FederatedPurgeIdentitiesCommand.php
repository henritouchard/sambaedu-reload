<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\Federated\ExternalIdentityLifecycleService;
use App\Models\ExternalIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Story 20.2 — D-1 / D-6 / D-8.
 *
 * Purge RGPD des identités externes fédérées dont la rétention PII a expiré.
 * Calquée sur {@see TrashPurgeCommand} (patron éprouvé) : `--dry-run`,
 * `--force`, garde-fou de configuration, fail-soft, audit best-effort.
 *
 * PRINCIPE (D-1) : ANONYMISATION, jamais hard-delete. Pour chaque identité dont
 * `last_login_at < now - pii_ttl_days` ET non encore anonymisée, on délègue à
 * {@see ExternalIdentityLifecycleService::anonymize()} qui vide la PII, réécrit
 * `external_sub` en `anon:<sha256>` (D-5), pose `anonymized_at`, désactive les
 * `User` liés et soft-delete la ligne (qui SURVIT pour l'audit 20.4 + les FK).
 *
 * GARDE-FOU (D-8) : tant que `federated_auth.retention.anonymize_enabled` est
 * `false`, OU que `pii_ttl_days <= 0` sans `--force`, la commande est NO-OP SAFE
 * (warning + exit SUCCESS, aucune anonymisation silencieuse non validée
 * juridiquement). `--force` ignore le garde-fou TTL <= 0 (purge consciente).
 *
 * `--dry-run` : énumère les candidats sans rien modifier.
 *
 * Fail-soft : un échec sur une identité n'arrête pas la boucle ; `FAILURE`
 * n'est retourné que si TOUTES les anonymisations candidates ont échoué.
 *
 * Logs (channel `federated-auth`) : AUCUNE PII — id interne + hash de sub
 * uniquement (AC16). L'anonymisation effective est tracée par le service.
 *
 * Planifiée dans `Console\Kernel::schedule()` quotidiennement, conditionnée par
 * `->when()` lisant le toggle config (prise d'effet sans redéploiement).
 */
class FederatedPurgeIdentitiesCommand extends Command
{
    protected $signature = 'federated:purge-identities
        {--dry-run : Liste les identités candidates sans anonymiser}
        {--force : Ignore le garde-fou pii_ttl_days <= 0 (purge même si non configurée)}';

    protected $description = 'Anonymise les identités externes fédérées dont la rétention PII a expiré (Story 20.2 — RGPD)';

    public function __construct(private readonly ExternalIdentityLifecycleService $lifecycle)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $start = microtime(true);
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $enabled = (bool) config('federated_auth.retention.anonymize_enabled', false);
        $ttlDays = (int) config('federated_auth.retention.pii_ttl_days', 0);

        // 1. Garde-fou toggle (D-8) : tant que la base légale n'est pas validée,
        // la commande ne purge pas. No-op safe (exit SUCCESS), même en --force :
        // le toggle est une décision juridique consciente, pas un garde-fou
        // technique contournable.
        // P-1 : le `--dry-run` est sans effet de bord — il doit pouvoir énumérer
        // les candidats MÊME toggle OFF (état par défaut), pour permettre au DPO
        // d'auditer l'impact AVANT d'activer la rétention. On avertit seulement
        // qu'il s'agit d'une simulation.
        if (! $enabled) {
            Log::channel('federated-auth')->info('[federated:purge-identities] retention.disabled', [
                'action_type' => 'federated.retention.disabled',
                'anonymize_enabled' => false,
                'dry_run' => $dryRun,
            ]);

            if (! $dryRun) {
                $this->warn('Rétention désactivée (federated_auth.retention.anonymize_enabled=false) — aucune anonymisation. Valide la base légale avant d\'activer (D-8).');
                $this->info('Anonymisées : 0. Conservées : 0. Erreurs : 0.');

                return self::SUCCESS;
            }

            $this->warn('Rétention désactivée (anonymize_enabled=false) — SIMULATION uniquement, aucune anonymisation ne sera effectuée même hors --dry-run.');
        }

        // 2. Garde-fou TTL invalide (calqué trash:purge) : TTL <= 0 sans --force
        // => no-op safe (warning + SUCCESS, jamais d'erreur bloquante en cron).
        if ($ttlDays <= 0 && ! $force) {
            Log::channel('federated-auth')->warning('[federated:purge-identities] retention.ttl_unset', [
                'action_type' => 'federated.retention.ttl_unset',
                'pii_ttl_days' => $ttlDays,
            ]);
            $this->warn('pii_ttl_days non configuré (<= 0) — aucune anonymisation. Configure FEDERATED_AUTH_RETENTION_PII_TTL_DAYS ou passe --force.');
            $this->info('Anonymisées : 0. Conservées : 0. Erreurs : 0.');

            return self::SUCCESS;
        }
        if ($ttlDays <= 0 && $force) {
            // Mode --force + TTL=0 : purge toute identité non récemment active.
            // ttl effectif 0 → `last_login_at < now` (toute identité connectée
            // dans le passé devient candidate). Décision consciente.
            Log::channel('federated-auth')->info('[federated:purge-identities] retention.force_ttl_zero', [
                'action_type' => 'federated.retention.force_ttl_zero',
                'pii_ttl_days' => $ttlDays,
            ]);
            $this->warn('Mode --force : pii_ttl_days <= 0, anonymisation de toute identité expirée (last_login_at < maintenant).');
            $ttlDays = 0;
        }

        // 3. Sélection des candidats (scope modèle). withTrashed() : une identité
        // déjà soft-deletée mais non anonymisée porte encore de la PII à purger.
        $candidates = ExternalIdentity::withTrashed()
            ->retentionExpired($ttlDays)
            ->orderBy('id')
            ->get();

        if ($dryRun) {
            return $this->renderDryRun($candidates, $start);
        }

        // 4. Anonymisation effective (fail-soft).
        $anonymized = 0;
        $failed = 0;

        foreach ($candidates as $identity) {
            try {
                $this->lifecycle->anonymize($identity);
                $anonymized++;
            } catch (\Throwable $e) {
                $failed++;
                Log::channel('federated-auth')->error('[federated:purge-identities] anonymize.failed', [
                    'action_type' => 'federated.identity.anonymize_failed',
                    'identity_id' => $identity->id,
                    'error' => $e->getMessage(),
                ]);
                $this->line(sprintf('  [ERREUR] identité #%d — %s', $identity->id, $e->getMessage()));
            }
        }

        $duration = round(microtime(true) - $start, 2);
        $this->info(sprintf(
            'Anonymisées : %d. Erreurs : %d. Durée : %ss.',
            $anonymized,
            $failed,
            $duration
        ));

        // Fail-soft (calqué trash:purge) : FAILURE uniquement si TOUTES les
        // anonymisations candidates ont échoué (cas dégradé).
        if ($candidates->count() > 0 && $anonymized === 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Mode --dry-run : affichage sans toucher la DB. AUCUNE PII (id + hash de
     * sub uniquement — AC16).
     *
     * @param  \Illuminate\Support\Collection<int,ExternalIdentity>  $candidates
     */
    private function renderDryRun(\Illuminate\Support\Collection $candidates, float $start): int
    {
        $rows = [];
        foreach ($candidates as $identity) {
            $rows[] = [
                $identity->id,
                $this->lifecycle->hashSub($identity->external_sub),
                $identity->last_login_at?->format('Y-m-d H:i') ?? '(jamais)',
                $identity->trashed() ? 'soft-deletée' : 'active/désactivée',
            ];
        }

        if ($rows !== []) {
            $this->table(['ID', 'sub (sha256)', 'last_login_at', 'état'], $rows);
        }

        $duration = round(microtime(true) - $start, 2);
        $this->info(sprintf(
            '[DRY-RUN] Candidats à anonymiser : %d. Aucune modification effectuée. Durée : %ss.',
            $candidates->count(),
            $duration
        ));

        return self::SUCCESS;
    }
}
