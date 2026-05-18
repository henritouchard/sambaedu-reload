<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

/**
 * Story 16.10 — T7.2 / D9.
 *
 * **STUB Phase 3+ — non implémenté en 16.10.**
 *
 * Scaffolde la commande `workstation:jwt:rotate-keys` pour signaler
 * l'extensibilité de la rotation kid (cf. Tech Spec Annexe B Q4). En 16.10
 * un seul `kid` est actif ; la rotation est manuelle (regénérer paire +
 * bump `active_kid` + révocation explicite des JWT en cours).
 *
 * Implémentation prévue Phase 3+ :
 *
 *  1. Générer une nouvelle paire RS256.
 *  2. Ajouter l'entrée `keys[<new_kid>] = ['private' => ..., 'public' => ...]`.
 *  3. Bumper `active_kid = <new_kid>`.
 *  4. Période de grâce configurable (les anciens JWT signés avec l'ancien
 *     `kid` restent vérifiables tant que `keys[<old_kid>]` existe).
 *  5. À la fin de la grâce, retirer `keys[<old_kid>]` + révoquer cascade.
 *
 * Pour 16.10, lancer cette commande lève `RuntimeException`.
 */
class WorkstationJwtRotateKeys extends Command
{
    /** @var string */
    protected $signature = 'workstation:jwt:rotate-keys
        {--grace-days=7 : (Phase 3+) Grace period in days where old kid is still accepted.}';

    /** @var string */
    protected $description = '(Phase 3+ STUB) Rotate JWT signing key (new kid) with grace period.';

    public function handle(): int
    {
        throw new RuntimeException(
            'Not implemented — Phase 3+. '
            .'See _bmad-output/planning-artifacts/tech-spec-epic-16-17-phase2.md '
            .'Annexe B Q4 for the design. For Phase 2, rotate manually by editing '
            .'config/auth_v1.php active_kid + regenerating the paired files via '
            .'php artisan auth:ca:init --force, then revoke all in-flight JWTs via '
            .'php artisan workstation:revoke for each enrolled UUID.'
        );
    }
}
