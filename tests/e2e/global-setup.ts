import { execFileSync } from 'node:child_process';
import type { FullConfig } from '@playwright/test';

/**
 * globalSetup Playwright — reset de la DB e2e AVANT la suite (Story 21.1).
 *
 * Canal de reset = SSH (DP-1, Option A tranchée par henri 2026-06-04) :
 * l'hôte ouvre une session SSH vers la VM et exécute
 *   `cd <projet> && php artisan e2e:reset`
 * qui recrée `sambaedu_e2e` depuis la template `sambaedu_e2e_template`
 * (DROP + CREATE … TEMPLATE, ~centisecondes, pas de re-migration).
 *
 * AUCUNE surface HTTP destructive n'est exposée : le reset n'est atteignable
 * que par celui qui détient la clé SSH de la VM. Le garde-fou STRUCTUREL de la
 * commande artisan (refus si APP_ENV !== e2e OU DB non suffixée `_e2e`) reste
 * la défense ultime quel que soit le canal.
 *
 * Paramètres SSH configurables par variables d'environnement (aucun secret
 * en dur ; valeurs par défaut alignées sur la cible `/vm` du projet) :
 *   - E2E_SSH_HOST       (def. 192.168.122.50)
 *   - E2E_SSH_USER       (def. root)
 *   - E2E_SSH_PORT       (def. 22)
 *   - E2E_SSH_KEY        (def. ~/.ssh/id_se4fs_vm) — chemin de la clé privée
 *   - E2E_PROJECT_PATH   (def. /var/www/sambaedu-reload) — racine projet sur la VM
 *   - E2E_ARTISAN        (def. php artisan)            — invocation artisan sur la VM
 *   - E2E_RESET_DISABLED (def. unset) — si "1"/"true", saute le reset
 *                         (utile pour itérer en local sans toucher la DB e2e).
 */
export default async function globalSetup(_config: FullConfig): Promise<void> {
  if (isTruthy(process.env.E2E_RESET_DISABLED)) {
    // eslint-disable-next-line no-console
    console.warn(
      '[e2e:global-setup] E2E_RESET_DISABLED actif — reset DB e2e SAUTÉ (la suite tourne sur l’état courant).',
    );
    return;
  }

  const host = process.env.E2E_SSH_HOST ?? '192.168.122.50';
  const user = process.env.E2E_SSH_USER ?? 'root';
  const port = process.env.E2E_SSH_PORT ?? '22';
  const key = process.env.E2E_SSH_KEY ?? `${process.env.HOME ?? ''}/.ssh/id_se4fs_vm`;
  const projectPath = process.env.E2E_PROJECT_PATH ?? '/var/www/sambaedu-reload';
  const artisan = process.env.E2E_ARTISAN ?? 'php artisan';

  // Commande distante : on se place dans le projet puis on lance le reset.
  // `execFileSync` ne passe pas par un shell LOCAL, mais le sshd distant
  // exécute la remote command via `sh -c` : on single-quote donc le path
  // interpolé (review 21-1 P-1 — défense en profondeur ; `artisan` reste une
  // invocation de commande, par construction multi-mots, documentée comme
  // telle). La commande artisan applique elle-même son garde-fou structurel —
  // si la VM n'est pas en env `e2e`, elle refuse et sort en erreur (le
  // globalSetup propage l'échec).
  const remoteCommand = `cd ${singleQuote(projectPath)} && ${artisan} e2e:reset`;

  const sshArgs = [
    '-i', key,
    '-p', port,
    '-o', 'BatchMode=yes',
    '-o', 'StrictHostKeyChecking=accept-new',
    `${user}@${host}`,
    remoteCommand,
  ];

  // eslint-disable-next-line no-console
  console.info(`[e2e:global-setup] Reset DB e2e via SSH → ${user}@${host}:${port} (${remoteCommand})`);

  try {
    const output = execFileSync('ssh', sshArgs, {
      stdio: ['ignore', 'pipe', 'pipe'],
      encoding: 'utf-8',
    });
    if (output.trim().length > 0) {
      // eslint-disable-next-line no-console
      console.info(`[e2e:global-setup] ${output.trim()}`);
    }
  } catch (error: unknown) {
    const detail = error instanceof Error ? error.message : String(error);
    throw new Error(
      `[e2e:global-setup] Échec du reset DB e2e via SSH (${user}@${host}:${port}).\n` +
        `Vérifier : clé SSH (E2E_SSH_KEY=${key}), instance e2e provisionnée sur la VM ` +
        `(APP_ENV=e2e, DB sambaedu_e2e + template), et que la branche déployée est bien à jour.\n` +
        `Détail : ${detail}`,
    );
  }
}

function isTruthy(value: string | undefined): boolean {
  return value === '1' || value?.toLowerCase() === 'true';
}

/** Single-quote POSIX : neutralise toute métachar pour le `sh -c` distant. */
function singleQuote(value: string): string {
  return `'${value.replace(/'/g, "'\\''")}'`;
}
