<?php

declare(strict_types=1);

namespace Tests\Feature\E2e;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 21.1 — Test host du GARDE-FOU STRUCTUREL (D-2, AC9).
 *
 * Cœur sécurité de l'epic : on prouve que `e2e:reset` et `e2e:build-template`
 * REFUSENT de s'exécuter dès que l'environnement n'est pas e2e OU que la base
 * cible n'est pas suffixée `_e2e`.
 *
 * Stratégie : ces tests tournent sur le canal PHPUnit existant (SQLite
 * :memory:, APP_ENV=testing). Le garde-fou s'exécute EN PREMIER dans le
 * `handle()` et lève une exception AVANT toute connexion de maintenance et tout
 * DROP/CREATE. On vérifie donc le CHEMIN DE REFUS sans jamais toucher Postgres
 * ni la VM (aucune opération destructive réelle n'est atteinte).
 *
 * Note : on n'écrit volontairement AUCUN test du chemin « autorisé » (qui
 * exécuterait un vrai DROP DATABASE) — ce serait un test destructif contre une
 * vraie instance Postgres, hors scope du canal host (validé manuellement par
 * henri sur l'instance e2e de la VM, cf. docs/qa/e2e-setup.md).
 */
class E2eResetGuardTest extends TestCase
{
    /**
     * Configure une connexion `pgsql` factice (jamais ouverte : le garde-fou
     * refuse avant) avec un nom de base donné, et un APP_ENV donné.
     */
    private function configureEnv(string $appEnv, string $database): void
    {
        Config::set('app.env', $appEnv);
        Config::set('database.default', 'pgsql');
        Config::set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => '5432',
            'database' => $database,
            'username' => 'irrelevant',
            'password' => 'irrelevant',
            'search_path' => 'public',
        ]);
    }

    #[Test]
    public function reset_refuse_si_app_env_nest_pas_e2e(): void
    {
        // APP_ENV=production mais base bien suffixée _e2e → doit quand même
        // refuser (l'env prime). FAILURE = exit code 1, aucun DROP atteint.
        $this->configureEnv('production', 'sambaedu_e2e');

        $this->artisan('e2e:reset')->assertExitCode(1);
    }

    #[Test]
    public function reset_refuse_si_base_non_suffixee_e2e(): void
    {
        // APP_ENV=e2e mais base = DB de prod → doit refuser sur le suffixe.
        $this->configureEnv('e2e', 'sambaedu');

        $this->artisan('e2e:reset')->assertExitCode(1);
    }

    #[Test]
    public function reset_refuse_si_base_suffixe_e2e_template_seulement(): void
    {
        // Cas piège : `_e2e_template` se termine par `_template`, PAS `_e2e`.
        // Le reset ne doit JAMAIS cibler la template.
        $this->configureEnv('e2e', 'sambaedu_e2e_template');

        $this->artisan('e2e:reset')->assertExitCode(1);
    }

    // NOTE SÉCURITÉ : aucun test du chemin « autorisé » (APP_ENV=e2e + base
    // `_e2e`) n'est écrit ici. Il franchirait le garde-fou et tenterait un VRAI
    // DROP DATABASE si un Postgres était joignable — exactement ce que le canal
    // host doit éviter (cf. story 21.1 T8). Le chemin nominal est validé par
    // henri sur l'instance e2e de la VM (docs/qa/e2e-setup.md).

    #[Test]
    public function build_template_refuse_si_app_env_nest_pas_e2e(): void
    {
        $this->configureEnv('staging', 'sambaedu_e2e');

        $this->artisan('e2e:build-template')->assertExitCode(1);
    }

    #[Test]
    public function build_template_refuse_si_base_non_suffixee_e2e(): void
    {
        $this->configureEnv('e2e', 'sambaedu');

        $this->artisan('e2e:build-template')->assertExitCode(1);
    }

    #[Test]
    public function build_template_refuse_si_base_est_deja_une_template(): void
    {
        // Review 21-1 P-10 : cas contre-intuitif documenté. `DB_DATABASE` doit
        // pointer la base CIBLE (`sambaedu_e2e`) — le nom de template en est
        // dérivé (`_template` concaténé). Une DB configurée directement sur
        // `sambaedu_e2e_template` ne finit pas par `_e2e` → refus du garde-fou
        // primaire (sinon on construirait `sambaedu_e2e_template_template`).
        $this->configureEnv('e2e', 'sambaedu_e2e_template');

        $this->artisan('e2e:build-template')->assertExitCode(1);
    }
}
