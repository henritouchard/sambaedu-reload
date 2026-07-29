<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\Oidc\Keys\OidcKeyManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Story 55.1 — Task 1.
 *
 * `php artisan oidc:keys:init` — initialise la paire de signature RS256
 * **dédiée à OIDC** (`storage/keys/oidc/{private,public}.pem`).
 *
 * **Doctrine ops du projet** : une opération multi-instance est une COMMANDE
 * ARTISAN IDEMPOTENTE, jamais une procédure manuelle à rejouer (patron
 * `auth:ca:init`). Elle est donc rejouable sans risque par `update.sh` sur
 * chaque instance : une paire déjà présente est signalée et laissée INTACTE.
 *
 * `--force` régénère (avec sauvegarde `.bak-*` des anciens fichiers) après
 * confirmation. ⚠️ Tous les id_tokens signés par l'ancienne clé deviennent
 * invérifiables dès que les clients rafraîchissent le JWKS.
 *
 * Codes retour : 0 succès (y compris no-op), 1 erreur.
 */
class OidcKeysInit extends Command
{
    /** @var string */
    protected $signature = 'oidc:keys:init
        {--force : Régénère la paire (sauvegarde les fichiers existants). Demande confirmation.}';

    /** @var string */
    protected $description = 'Initialise la paire de signature RS256 dédiée au fournisseur OIDC (SSO des extensions).';

    public function handle(OidcKeyManager $keys): int
    {
        $force = (bool) $this->option('force');
        $noInteraction = (bool) $this->option('no-interaction');

        try {
            if ($force) {
                if (! $noInteraction && ! $this->confirm(
                    '⚠ Régénérer la clé de signature OIDC ? Les id_token déjà émis deviendront '
                    . 'invérifiables et les extensions devront rafraîchir le JWKS. Continuer ?',
                    false,
                )) {
                    $this->warn('Abandon demandé par l\'opérateur.');

                    return 0;
                }

                $report = $keys->forceRegen();
            } else {
                $report = $keys->initIfMissing();
            }
        } catch (Throwable $e) {
            $this->error('oidc:keys:init a échoué : ' . $e->getMessage());

            return 1;
        }

        $this->renderReport($report);

        return 0;
    }

    /** @param array{status: string, kid: string, files: array<string, string>} $report */
    private function renderReport(array $report): void
    {
        $this->line('');
        $this->info('============================================================');
        $this->info('  oidc:keys:init → ' . $report['status']);
        $this->info('============================================================');

        if ($report['status'] === 'already_initialized') {
            $this->line('La paire OIDC existe déjà — aucune action (commande idempotente).');
            $this->line('Utiliser --force pour la régénérer (rotation de clé).');
        } else {
            $this->line('Paire RS256 générée pour kid = ' . $report['kid'] . '.');
        }

        $this->line('');
        $this->info('Fichiers gérés :');
        foreach ($report['files'] as $label => $path) {
            $exists = is_file($path) ? 'OK' : 'MANQUANT';
            $perms = is_file($path) ? substr(sprintf('%o', fileperms($path)), -4) : '----';
            $this->line(sprintf('  %-8s  %-52s  [%s %s]', $label, $path, $exists, $perms));
        }

        $this->line('');
        $this->info('Vérification :');
        $this->line('  curl -s https://<host>/.well-known/openid-configuration | jq');
        $this->line('  curl -s https://<host>/oidc/jwks | jq');
        $this->line('');
        $this->info('Étape suivante — déclarer un client :');
        $this->line('  php artisan oidc:client:register "Mon extension" --redirect-uri=https://…/callback');
        $this->line('');
        $this->info('Documentation : docs/qa/domains/extensions.md (Section 11)');
    }
}
