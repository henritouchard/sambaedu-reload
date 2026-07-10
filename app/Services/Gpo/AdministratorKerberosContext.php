<?php

declare(strict_types=1);

namespace App\Services\Gpo;

use App\Gpo\Support\GpoActionLog;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Contexte Kerberos **Administrator** éphémère pour l'écriture SYSVOL.
 *
 * Story 38.4 (AC1/AC2) — extraction du mécanisme éprouvé (27.16) jusqu'ici
 * privé à {@see AgentBootstrapPublisher}, désormais MUTUALISÉ entre le port
 * natif d'`import_gpo` ({@see NativeGpoPublisher}) et le plan roaming
 * ({@see SysvolPolicyService}).
 *
 * Rappel du piège central (`project_sysvol_wwwadmin_no_write_rights_and_silent_success`,
 * `project_sysvol_write_needs_wwwadmin_kinit`) : le user PHP-FPM `www-admin`
 * n'a que READ sur SYSVOL ; un `smbclient put` sans ticket adéquat sort en
 * **exit 0 SANS rien écrire** (faux succès). La seule parade fiable est un
 * ticket Kerberos Administrator dédié, obtenu par `kinit` avec `admin_passwd`
 * fourni sur STDIN (jamais argv/fichier), isolé dans un `KRB5CCNAME`
 * temporaire et détruit en `finally`.
 *
 * Garde-fou archi : ce service vit sous `App\Services\Gpo` (PAS `App\Gpo`), il
 * peut donc invoquer `Process` (kinit/smbclient/kdestroy) — le test
 * `GpoNamespaceTest` ne scanne que `app/Gpo/`.
 */
class AdministratorKerberosContext
{
    /** Cache de la sonde `kinit --password-file` (Heimdal=true, MIT=false). */
    private ?bool $kinitSupportsPasswordFile = null;

    /** Handle /dev/null gardé vivant après détachement de fd 0 du TTY. */
    private $nullStdin = null;

    /** Garde : ne détacher stdin qu'une fois par instance. */
    private bool $stdinDetached = false;

    /** Mot de passe Administrator (admin_passwd), jamais logué en clair. */
    public function adminPassword(): string
    {
        return (string) config('sambaedu.admin_passwd', '');
    }

    /** Les creds Administrator sont-ils disponibles (pré-requis d'écriture) ? */
    public function hasCredentials(): bool
    {
        return $this->adminPassword() !== '';
    }

    /**
     * Exécute `$callback` sous un ticket Kerberos Administrator frais.
     *
     * Séquence : détache stdin du TTY (anti-prompt bloquant), kinit dans un
     * ccache dédié, publie `KRB5CCNAME`, exécute `$callback`, puis restaure
     * l'env et détruit le ticket (`finally`). Le callback reçoit le chemin du
     * ccache (utile pour `Process::env(['KRB5CCNAME' => …])` sur smbclient).
     *
     * @template T
     * @param  callable(string):T  $callback  Reçoit le KRB5CCNAME actif.
     * @return T
     */
    public function withTicket(GpoActionLog $log, callable $callback)
    {
        if (! $this->hasCredentials()) {
            throw new RuntimeException('admin_passwd absent — contexte Kerberos Administrator impossible.');
        }

        $this->detachStdinFromTty();

        $ccache = $this->makeTempCcachePath();
        $previousCcache = getenv('KRB5CCNAME');

        try {
            $this->kinitAdministrator($this->adminPassword(), $ccache, $log);
            putenv('KRB5CCNAME=' . $ccache);

            return $callback($ccache);
        } finally {
            if ($previousCcache === false) {
                putenv('KRB5CCNAME');
            } else {
                putenv('KRB5CCNAME=' . $previousCcache);
            }
            $this->destroyTicket($ccache);
        }
    }

    /**
     * Masque tout fragment ressemblant au mot de passe Administrator dans un
     * message destiné aux logs (défense en profondeur).
     */
    public function scrub(string $text): string
    {
        $passwd = $this->adminPassword();
        if ($passwd !== '') {
            $text = str_replace($passwd, '***', $text);
        }

        return $text;
    }

    /**
     * Host SYSVOL — `se4ad_name` (FQDN du DC), JAMAIS une IP (échec
     * SASL/canonicalisation côté smbclient kerberos, `project_ipxe_boot500_sasl_nocanon`).
     */
    public function sysvolHost(): string
    {
        $name = (string) config('sambaedu.se4ad_name', '');
        if ($name !== '') {
            return $name;
        }

        try {
            return app(\App\Config\SambaEduConfig::class)->ldap()->getHosts()[0] ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    // -----------------------------------------------------------------------
    // Internes — kinit / ccache (ports iso AgentBootstrapPublisher 27.16).
    // -----------------------------------------------------------------------

    /**
     * `kinit Administrator` dans le ccache dédié. Le mot de passe est fourni
     * via STDIN (jamais argv/fichier). Sonde la capacité `--password-file`
     * (Heimdal) une fois. Un timeout borne le Process.
     */
    private function kinitAdministrator(string $adminPasswd, string $ccache, GpoActionLog $log): void
    {
        $principal = $this->administratorPrincipal();
        $log->step('kinit Administrator (ccache dédié)', ['principal' => $principal]);

        $command = ['kinit'];
        if ($this->kinitSupportsPasswordFile()) {
            $command[] = '--password-file=STDIN';
        }
        $command[] = $principal;

        $result = Process::env(['KRB5CCNAME' => $ccache])
            ->input($adminPasswd)
            ->timeout(30)
            ->run($command);

        if (! $result->successful()) {
            throw new RuntimeException(sprintf(
                'kinit Administrator échoué (exit=%d) — impossible d\'établir le contexte d\'écriture SYSVOL. stderr: %s',
                $result->exitCode() ?? -1,
                $this->scrub($result->errorOutput()),
            ));
        }

        // Durcir les perms du ccache (ticket lisible par le seul user courant).
        $ccachePath = preg_replace('/^FILE:/', '', $ccache) ?? $ccache;
        if (is_file($ccachePath)) {
            @chmod($ccachePath, 0600);
        }
    }

    /** Sonde si `kinit` supporte `--password-file` (Heimdal) ou non (MIT). Mémoïsé. */
    private function kinitSupportsPasswordFile(): bool
    {
        if ($this->kinitSupportsPasswordFile !== null) {
            return $this->kinitSupportsPasswordFile;
        }

        $help = Process::timeout(5)->run(['kinit', '--help']);
        $text = $help->output() . $help->errorOutput();

        return $this->kinitSupportsPasswordFile = str_contains($text, '--password-file');
    }

    /** Principal Kerberos Administrator : `Administrator@REALM`. */
    private function administratorPrincipal(): string
    {
        $adminName = (string) config('sambaedu.ldap_admin_name', 'Administrator');
        $realm = strtoupper((string) config('sambaedu.domain', ''));

        return $realm !== '' ? $adminName . '@' . $realm : $adminName;
    }

    private function makeTempCcachePath(): string
    {
        return 'FILE:' . sys_get_temp_dir() . '/krb5cc_se_sysvol_' . bin2hex(random_bytes(6));
    }

    /** Détruit le ticket du ccache dédié (best effort). */
    private function destroyTicket(string $ccache): void
    {
        try {
            Process::env(['KRB5CCNAME' => $ccache])->run(['kdestroy', '-c', $ccache]);
        } catch (\Throwable) {
            // best effort — un ccache fichier résiduel est inerte (purge OS /tmp).
        }
        $path = preg_replace('/^FILE:/', '', $ccache) ?? $ccache;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Réattache fd 0 (STDIN) à /dev/null pour que les `Process` smbclient/kinit
     * n'héritent jamais d'un TTY → jamais de prompt mot de passe bloquant.
     * Idempotent, CLI uniquement.
     */
    private function detachStdinFromTty(): void
    {
        if ($this->stdinDetached || PHP_SAPI !== 'cli') {
            return;
        }
        $this->stdinDetached = true;

        if (defined('STDIN') && is_resource(STDIN)) {
            @fclose(STDIN);
        }
        $this->nullStdin = @fopen('/dev/null', 'r');
    }
}
