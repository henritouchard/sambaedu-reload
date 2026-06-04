<?php

declare(strict_types=1);

namespace App\Gpo\Support;

use App\Config\SambaEduConfig;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Wrapper isolé autour de `samba-tool` (sous-commandes `gpo *` principalement).
 *
 * **Seul point du namespace `App\Gpo` autorisé à appeler `Illuminate\Support\Facades\Process`
 * ou des fonctions PHP `exec()`/`shell_exec()`/`passthru()`/`proc_open()`** —
 * cf. `tests/Architecture/GpoNamespaceTest.php` (garde-fou archi Story 16.1
 * AC2.2). Toute autre classe qui voudrait exécuter `samba-tool` doit
 * passer par ce runner.
 *
 * Garanties :
 *
 * - Mode **array** pour `Process::run()` — pas de concaténation de string,
 *   échappement automatique des arguments (parité Story 15.1 / cf. risque
 *   d'injection identifié dans `sambaedu/includes/samba-tool.inc.php:54`).
 * - Timeout configurable (default `config('sambaedu.gpo.samba_tool_timeout')`).
 * - Mode `dry-run` : ne lance pas le binaire, retourne la commande qui aurait
 *   été lancée. Utile pour tests + Stories 16.2 / 16.4.
 * - Tous les appels sont logués sur le channel `gpo` (action_type
 *   `gpo.sambatool.exec` — niveau debug) avec stdout/stderr tronqués à 8 Ko.
 */
class SambaToolRunner
{
    /** Valeur de timeout en secondes, ou null pour utiliser la config. */
    private ?int $timeoutOverride = null;

    /** Quand true, n'exécute rien — retourne un résultat synthétique. */
    private bool $dryRun = false;

    /**
     * Liste des arguments globaux `samba-tool` ajoutés à chaque appel
     * (`--use-kerberos=required` ou équivalent). Lazy-loaded depuis la config.
     *
     * @var list<string>|null
     */
    private ?array $globalArgsCache = null;

    /**
     * Cache de la cible distante : `[list<string> $args, array<string,string> $env]`.
     * Lazy-loaded depuis {@see SambaEduConfig::ldap()}.
     *
     * @var array{0: list<string>, 1: array<string, string>}|null
     */
    private ?array $remoteTargetCache = null;

    /**
     * Exécute une sous-commande `samba-tool`. Le premier argument est
     * typiquement `gpo` suivi de la sous-commande (`listall`, `show`, etc.).
     *
     * @param  list<string>  $args  Arguments en mode array — ex.
     *                              `['gpo', 'listall']` ou `['gpo', 'show', $name]`.
     * @param  GpoActionLog|null  $log  Si fourni, l'exécution est tracée via
     *                                  {@see GpoActionLog::sambaToolExec()}.
     */
    public function run(array $args, ?GpoActionLog $log = null): ProcessResult
    {
        $command = $this->buildCommand($args);
        $timeout = $this->timeoutOverride ?? (int) config('sambaedu.gpo.samba_tool_timeout', 30);

        if ($this->dryRun) {
            $result = $this->fakeDryRunResult($command);
            $log?->sambaToolExec($command, 0, '[dry-run]', '', 0.0, ['dry_run' => true]);

            return $result;
        }

        [, $env] = $this->remoteTarget();

        $startedAt = microtime(true);
        $pending = Process::timeout($timeout);
        if ($env !== []) {
            // Mot de passe admin passé via env `PASSWD` (lu nativement par
            // samba-tool/credentials) → jamais dans argv (`ps`) ni dans le
            // log d'audit `sambaToolExec()` qui ne trace que `$command`.
            $pending = $pending->env($env);
        }
        $result = $pending->run($command);
        $durationMs = (microtime(true) - $startedAt) * 1000.0;

        $log?->sambaToolExec(
            command: $command,
            exitCode: $result->exitCode() ?? -1,
            stdout: $result->output(),
            stderr: $result->errorOutput(),
            durationMs: round($durationMs, 2),
        );

        return $result;
    }

    /**
     * Active le mode dry-run pour cet appel. À utiliser via une instance
     * dédiée (mode immutable côté caller) ou réinitialiser ensuite.
     */
    public function withDryRun(bool $enabled = true): self
    {
        $clone = clone $this;
        $clone->dryRun = $enabled;

        return $clone;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * Override du timeout pour ce runner (seconds).
     */
    public function withTimeout(int $seconds): self
    {
        $clone = clone $this;
        $clone->timeoutOverride = $seconds;

        return $clone;
    }

    /**
     * Construit la commande complète en mode array — bin samba-tool + args
     * fonctionnels + arguments globaux (`--use-kerberos=required`).
     *
     * @param  list<string>  $args
     * @return list<string>
     */
    private function buildCommand(array $args): array
    {
        $bin = (string) config('sambaedu.gpo.bin_path', '/usr/bin/samba-tool');

        [$remoteArgs] = $this->remoteTarget();

        return array_merge([$bin], $args, $remoteArgs, $this->globalArgs());
    }

    /**
     * Résout la cible distante (`-H ldap://<DC> -U <admin>`) + l'env `PASSWD`,
     * depuis la MÊME source que LdapRecord ({@see SambaEduConfig::ldap()}).
     *
     * Pourquoi `ldap://` et pas `ldaps://` : samba-tool refuse `ldaps://` sans
     * CA TLS configurée (`tls cafile`/`tls ca directories`). En `ldap://`, la
     * négociation SASL (GSSAPI/NTLMSSP) signe+chiffre le trafic ET les creds —
     * la confidentialité est donc préservée sans dépendre de la PKI.
     *
     * `samba-tool computer create` (et `user`/`group`) opère par défaut sur la
     * base AD LOCALE (`/var/lib/samba/private/sam.ldb`) ; sur un serveur SE5 qui
     * n'est pas le DC, ce fichier n'existe pas → exit 255. Le `-H` explicite
     * cible le DC établissement distant (parité avec le bind LdapRecord).
     *
     * Fallback défensif : si l'hôte n'est pas résolvable (ex. env `testing`
     * sans `etab_ip`, mode strict qui lève), on retombe sur le comportement
     * local historique (pas de `-H`) — les appels samba-tool restant capables
     * de tourner sur un DC local le cas échéant.
     *
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function remoteTarget(): array
    {
        if ($this->remoteTargetCache !== null) {
            return $this->remoteTargetCache;
        }

        $args = [];
        $env = [];

        try {
            $ldap = app(SambaEduConfig::class)->ldap();
            $host = $ldap->getHosts()[0] ?? '';

            if ($host !== '' && $ldap->adminName !== '') {
                $args = ['-H', 'ldap://' . $host, '-U', $ldap->adminName];
                if ($ldap->adminPassword !== '') {
                    $env['PASSWD'] = $ldap->adminPassword;
                }
            }
        } catch (\Throwable $e) {
            Log::channel('gpo')->warning('[SambaToolRunner] cible DC distante non résolue, fallback samba-tool local', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->remoteTargetCache = [$args, $env];
    }

    /**
     * Charge les arguments globaux depuis la config (`kerb_option` typiquement).
     *
     * @return list<string>
     */
    private function globalArgs(): array
    {
        if ($this->globalArgsCache !== null) {
            return $this->globalArgsCache;
        }

        $args = [];
        $kerb = (string) config('sambaedu.gpo.kerb_option', '');
        if ($kerb !== '') {
            // Format attendu : `--use-kerberos=required` (string complète,
            // pas de split sur espace pour préserver l'arg unique).
            $args[] = $kerb;
        }

        return $this->globalArgsCache = $args;
    }

    /**
     * Construit un faux ProcessResult pour le mode dry-run.
     *
     * Utilise l'API factory `Process::result()` qui retourne un
     * `Illuminate\Process\FakeProcessResult` — implémente bien
     * `Illuminate\Contracts\Process\ProcessResult`.
     *
     * @param  list<string>  $command
     */
    private function fakeDryRunResult(array $command): ProcessResult
    {
        return $this->syntheticResult('[dry-run] ' . implode(' ', $command));
    }

    /**
     * Construit un `ProcessResult` synthétique de succès (exit 0) via la factory
     * `Process::result()` — seule autorisée à connaître la signature interne de
     * `FakeProcessResult` (qui varie selon les versions de Laravel).
     *
     * Exposé `protected` pour que le runner FAKE e2e
     * ({@see \App\Ldap\Fakes\FakeSambaToolRunner}) construise ses résultats SANS
     * importer la facade `Process` (interdite sous `app/Ldap/*` par
     * `LdapNamespaceTest`) ni dépendre de la signature de `FakeProcessResult`.
     */
    protected function syntheticResult(string $output = '', string $errorOutput = '', int $exitCode = 0): ProcessResult
    {
        return Process::result(
            output: $output,
            errorOutput: $errorOutput,
            exitCode: $exitCode,
        );
    }
}
