<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Gpo\AgentBootstrapDeployResult;
use App\Services\Gpo\AgentBootstrapPublisher;
use Illuminate\Console\Command;

/**
 * Story 27.16 — `gpo:deploy-agent-bootstrap`.
 *
 * Déploie automatiquement la GPO-dispatcher figée d'amorçage agent
 * `SE_agent_bootstrap` (publication SYSVOL sous contexte Administrator + blocage
 * d'héritage et lien sur l'OU computers de l'établissement). Câblée dans
 * `scripts/update.sh` / `scripts/install.sh` — donc **fail-soft** : si le DC est
 * injoignable ou si `admin_passwd` est absent, la commande émet un warning et
 * sort proprement (`skip`) SANS jamais faire échouer l'install/update.
 *
 * Exit codes :
 *  - `0` : déployé, publié-sans-lien, dry-run, OU skip (garde). Tout sauf erreur dure.
 *  - `0` également sur `failed` par défaut (fail-soft pour le câblage scripts) ;
 *    `--strict` force un exit `1` sur `failed` (utile en CI / diagnostic manuel).
 *
 * Options :
 *  - `--force`   : republie même si la version SYSVOL est à jour.
 *  - `--dry-run` : affiche ce qui serait fait, aucun side effect.
 *  - `--strict`  : exit 1 sur échec réel (sinon fail-soft exit 0).
 *
 * Logs (channel `gpo`, corrélés par `operation_id`) : `gpo.create`,
 * `gpo.sysvol.write`, `gpo.link.add`, `gpo.inheritance.set`. Aucun secret en clair.
 */
class GpoDeployAgentBootstrapCommand extends Command
{
    /** @var string */
    protected $signature = 'gpo:deploy-agent-bootstrap
        {--force : Republie la GPO même si la version SYSVOL est déjà à jour.}
        {--dry-run : Affiche les actions sans aucun side effect.}
        {--strict : Exit 1 sur échec réel (défaut : fail-soft exit 0 pour le câblage scripts).}';

    /** @var string */
    protected $description = 'Publie + isole la GPO bootstrap agent SE_agent_bootstrap (idempotent, fail-soft).';

    public function handle(AgentBootstrapPublisher $publisher): int
    {
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $strict = (bool) $this->option('strict');

        $this->info(sprintf(
            'Déploiement bootstrap agent SE_agent_bootstrap%s%s…',
            $force ? ' (--force)' : '',
            $dryRun ? ' (--dry-run)' : '',
        ));

        $result = $publisher->deploy($force, $dryRun);

        $this->line(sprintf('operation_id: %s', $result->operationId));

        return match ($result->kind) {
            AgentBootstrapDeployResult::KIND_DEPLOYED => $this->ok(sprintf(
                'GPO %s publiée + héritage bloqué + liée à %s.',
                $result->guid,
                $result->targetOuDn,
            )),
            AgentBootstrapDeployResult::KIND_PUBLISHED_WITHOUT_LINK => $this->warnOk(sprintf(
                'GPO publiée (%s) mais aucune OU cible détectée — héritage/lien non appliqués. %s',
                $result->guid,
                $result->message,
            )),
            AgentBootstrapDeployResult::KIND_DRY_RUN => $this->ok(sprintf(
                '[dry-run] aucune écriture. OU cible détectée : %s',
                $result->targetOuDn ?? '(aucune)',
            )),
            AgentBootstrapDeployResult::KIND_SKIPPED => $this->skipped($result->message),
            AgentBootstrapDeployResult::KIND_FAILED => $this->failed($result->message, $strict),
            default => $this->failed('Résultat inattendu : ' . $result->kind, $strict),
        };
    }

    private function ok(string $message): int
    {
        $this->info('✔ ' . $message);

        return self::SUCCESS;
    }

    private function warnOk(string $message): int
    {
        $this->warn('⚠ ' . $message);

        return self::SUCCESS;
    }

    /** Garde fail-soft : skip non bloquant (exit 0). */
    private function skipped(string $reason): int
    {
        $this->warn('⏭ Skip (non bloquant) : ' . $reason);

        return self::SUCCESS;
    }

    /** Échec réel : fail-soft (exit 0) sauf --strict (exit 1). */
    private function failed(string $message, bool $strict): int
    {
        $this->error('✘ Échec : ' . $message);

        if ($strict) {
            return self::FAILURE;
        }

        $this->warn('(fail-soft : exit 0 — la GPO sera reprise au prochain passage. Utiliser --strict pour un exit non nul.)');

        return self::SUCCESS;
    }
}
