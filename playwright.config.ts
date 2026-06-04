import { defineConfig, devices } from '@playwright/test';

/**
 * Configuration Playwright — Socle e2e SambaEdu (SE5), Story 21.1.
 *
 * Topologie : les tests s'exécutent sur l'HÔTE (poste de henri) et ciblent
 * par le réseau l'instance e2e dédiée servie par la VM (env Laravel `e2e`,
 * DB Postgres `sambaedu_e2e`). Playwright et ses navigateurs s'installent sur
 * l'hôte (`npx playwright install`). Aucun navigateur ni runner sur la VM.
 *
 * Décisions de cadrage (cf. story 21.1, tranchées par henri 2026-06-04) :
 *  - DP-1 : le reset de la DB e2e est déclenché par `globalSetup` via SSH
 *    (`php artisan e2e:reset` sur la VM). Aucune surface HTTP destructive.
 *  - DP-3 / D-3 : reset PAR SUITE (globalSetup), `workers: 1` au départ
 *    (DB partagée par la suite). Le parallélisme multi-worker (une DB par
 *    worker depuis la template) est une optimisation future hors scope.
 *
 * `baseURL` est paramétrable par la variable d'environnement `E2E_BASE_URL`
 * (URL HTTP du vhost e2e de la VM). Toutes les navigations relatives
 * (`page.goto('/authentication/login')`) sont résolues contre cette base.
 */

// Review 21-1 P-6 : pas de fallback silencieux (`http://localhost` ciblerait
// le poste hôte au lieu de la VM, avec un échec confus). On exige la variable.
const baseURL = process.env.E2E_BASE_URL;
if (!baseURL) {
  throw new Error(
    '[playwright.config] E2E_BASE_URL non défini — exporter E2E_BASE_URL=<URL du vhost e2e de la VM> '
      + '(cf. docs/qa/e2e-setup.md).',
  );
}

export default defineConfig({
  testDir: './tests/e2e',

  // D-3 : un seul worker au départ — la DB e2e est partagée par toute la
  // suite et reseedée une fois par `globalSetup`. NE PAS augmenter sans
  // passer à une DB par worker (optimisation future, hors scope 21.1).
  workers: 1,
  fullyParallel: false,

  // DP-1 / D-1 : reset (DROP + CREATE … TEMPLATE) déclenché AVANT la suite.
  // Le script ouvre une session SSH vers la VM et lance `php artisan e2e:reset`.
  globalSetup: './tests/e2e/global-setup.ts',

  // Échoue si un `test.only` est resté dans le code (hygiène CI/local).
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,

  reporter: process.env.CI ? 'github' : 'list',

  use: {
    baseURL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
