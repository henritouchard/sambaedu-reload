import { expect, test } from '@playwright/test';

/**
 * Smoke test du socle e2e (Story 21.1, AC1).
 *
 * Vérifie le bout-en-bout minimal : l'instance e2e de la VM répond et la page
 * de login s'affiche. C'est le canari du socle — si ce test passe, alors le
 * harnais hôte, le `baseURL`, le reset DB (globalSetup) et le vhost e2e sont
 * tous opérationnels.
 *
 * Cible : `GET /authentication/login` (route nommée `auth.login`,
 * cf. routes/web.php → AuthController::showLogin → view('auth.login')).
 * On asserte des sélecteurs STABLES du formulaire (les `id`/`name` des champs),
 * jamais du texte traduit fragile.
 */
test('la page de login s’affiche', async ({ page }) => {
  const response = await page.goto('/authentication/login');

  // L'instance e2e répond et sert la page (pas un 5xx / vhost cassé).
  expect(response?.ok(), 'la réponse HTTP de /authentication/login doit être 2xx').toBeTruthy();

  // Champs identifiants du formulaire de login — sélecteurs structurels stables
  // (cf. resources/views/auth/login.blade.php : id="login", id="password").
  await expect(page.locator('#login')).toBeVisible();
  await expect(page.locator('#password')).toBeVisible();

  // Le formulaire POST vers la route d'authentification est bien présent.
  await expect(page.locator('form[method="POST"]')).toBeVisible();
});
