<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

use App\Ipxe\Exceptions\BatPlaceholderInjectionException;
use App\Ipxe\Support\WindowsXmlPlaceholders;
use App\Models\Workstation;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Story 3.8 — D8 / AC3.1-3.6.
 *
 * Orchestrateur des 6 builders cmd batch Windows post-OOBE (port natif de
 * `legacy/modules/ipxe/Win10/action.php` blocs `$cmd_{sysprep,nosysprep,join,
 * renomme,post,wpkg}`).
 *
 * **6 méthodes publiques** :
 *  - {@see buildSysprep()}   — port legacy LOC 73-144 (sysprep + nosysprep
 *                              autologon fallback).
 *  - {@see buildNosysprep()} — port legacy LOC 151-192 (clonage rapide sans
 *                              sysprep).
 *  - {@see buildJoin()}      — port legacy LOC 358-406 (mise au domaine
 *                              post-clonage).
 *  - {@see buildRenomme()}   — port legacy LOC 317-351 (renommage au domaine).
 *  - {@see buildPost()}      — port legacy LOC 198-231 (post-install manuelle).
 *  - {@see buildWpkg()}      — port legacy LOC 268-311 (wpkg interactif).
 *
 * **Chaque builder** :
 *  1. Sanitize TOUS les inputs dynamiques via
 *     {@see WindowsXmlPlaceholders::sanitizeBatPlaceholder()} (0-trust D9).
 *  2. Render le template Blade `ipxe.windows.cmd.{step}`.
 *  3. Post-traitement CRLF strict via {@see normalizeCrlf()} (pattern 3.4
 *     `LinuxPreseedService` + 3.5 D7).
 *  4. Return string body cmd batch prêt à servir en `Content-Type: text/plain`.
 *
 * **Sécurité critique** : ce service produit des .cmd batch qui s'exécutent
 * en SYSTEM côté Windows post-reboot. Toute injection (`;calc.exe`, etc.)
 * doit être REJETÉE avant le render Blade — le caller (controller) catch
 * {@see BatPlaceholderInjectionException} et répond 200 + body vide + log
 * warning.
 *
 * **Anti-pattern** :
 *  - ❌ Ne PAS extraire chaque builder en classe dédiée (overkill — 6 méthodes
 *    ≤ 30 LOC chacune sous une seule classe).
 *  - ❌ Ne PAS modifier les templates Blade pour court-circuiter
 *    `sanitizeBatPlaceholder` — la sanitization est faite ici et SEULEMENT
 *    ici (defense in depth couche 3 après FormRequest + enum).
 *  - ❌ Ne PAS sauter le post-traitement CRLF — un body LF only fait que
 *    Windows poste silencieusement KO sur le `call %windir%\action.cmd`.
 */
final class WindowsActionCmdBuilder
{
    public function __construct(
        private readonly ViewFactory $views,
        private readonly \App\Services\ServiceCredentials $credentials,
    ) {
    }

    /**
     * Story 3.8 — AC3.1, AC4.1 — Build cmd_sysprep.
     *
     * @param  Workstation  $workstation  Poste destinataire (lecture name,
     *                                    programmed_action pour state vars).
     * @return string                     Body cmd batch CRLF strict ASCII.
     *
     * @throws BatPlaceholderInjectionException Si un input contient un char
     *         d'injection cmd.exe.
     */
    public function buildSysprep(Workstation $workstation): string
    {
        $vars = $this->commonVars($workstation);

        return $this->renderAndNormalize('ipxe.windows.cmd.sysprep', $vars);
    }

    /**
     * Story 3.8 — AC3.1, AC4.2 — Build cmd_nosysprep.
     */
    public function buildNosysprep(Workstation $workstation): string
    {
        $vars = $this->commonVars($workstation);

        return $this->renderAndNormalize('ipxe.windows.cmd.nosysprep', $vars);
    }

    /**
     * Story 3.8 — AC3.1, AC3.2, AC4.3 — Build cmd_join.
     *
     * **Spécifique join** : reçoit `$role` (nouveau nom du poste post-clonage)
     * et `$ou` (LDAP OU pour le `Add-Computer -OUPath`).
     */
    public function buildJoin(Workstation $workstation, string $role = '', string $ou = ''): string
    {
        $vars = $this->commonVars($workstation);
        // AC3.4 — sanitize via la même méthode 0-trust que les autres inputs.
        $vars['role'] = WindowsXmlPlaceholders::sanitizeBatPlaceholder($role);
        $vars['ou'] = $this->sanitizeOu($ou);

        return $this->renderAndNormalize('ipxe.windows.cmd.join', $vars);
    }

    /**
     * Story 3.8 — AC3.1, AC3.2, AC4.4 — Build cmd_renomme.
     *
     * **Spécifique renomme** : reçoit `$role` (nouveau nom AD du poste).
     */
    public function buildRenomme(Workstation $workstation, string $role = ''): string
    {
        $vars = $this->commonVars($workstation);
        $vars['role'] = WindowsXmlPlaceholders::sanitizeBatPlaceholder($role);

        return $this->renderAndNormalize('ipxe.windows.cmd.renomme', $vars);
    }

    /**
     * Story 3.8 — AC3.1, AC4.5 — Build cmd_post.
     */
    public function buildPost(Workstation $workstation): string
    {
        $vars = $this->commonVars($workstation);

        return $this->renderAndNormalize('ipxe.windows.cmd.post', $vars);
    }

    /**
     * Story 3.8 — AC3.1, AC4.6 — Build cmd_wpkg.
     */
    public function buildWpkg(Workstation $workstation): string
    {
        $vars = $this->commonVars($workstation);

        return $this->renderAndNormalize('ipxe.windows.cmd.wpkg', $vars);
    }

    /**
     * Construit le dictionnaire de variables communes aux 6 templates,
     * sanitisées via `sanitizeBatPlaceholder` (D9 / AC3.4 / AC10.4).
     *
     * **Variables émises** :
     *  - `name`         — nom du poste (Workstation->name).
     *  - `uuid`         — UUID hardware (Workstation->uuid).
     *  - `id`           — id DB (debug header REM uniquement).
     *  - `cloneName`    — name + suffix random (parité legacy ligne 59).
     *  - `type`/`role`/`script`/`etape`/`ret` — state programmed_action (header
     *    REM uniquement — les valeurs effectives sont overridden par les
     *    builders spécifiques pour role/ou).
     *  - `se4installName`, `se4installPasswd`, `adminseName`, `adminsePasswd`,
     *    `domain`, `se4fsName` — configs lues via `config(...)`.
     *
     * **Note sécurité** : les configs sont lues côté serveur et passent par
     * sanitize (defense in depth) — un `.env` corrompu avec `SE4INSTALL_NAME=";
     * calc.exe"` lèverait BatPlaceholderInjectionException avant render.
     *
     * @return array<string, string>
     *
     * @throws BatPlaceholderInjectionException
     */
    private function commonVars(Workstation $workstation): array
    {
        $sanitize = static fn (mixed $v): string => WindowsXmlPlaceholders::sanitizeBatPlaceholder((string) ($v ?? ''));

        $name = $sanitize($workstation->name ?? '');
        // Note : random_int n'accepte que des entiers — le suffix résultant est
        // toujours composé de [0-9-] donc safe pour sanitize, mais on protège
        // quand même defense in depth.
        $cloneName = $name !== ''
            ? $sanitize(substr($name, 0, 6) . '-' . random_int(0, 9999))
            : '';

        $pa = is_array($workstation->programmed_action ?? null)
            ? (array) $workstation->programmed_action
            : [];

        return [
            'name' => $name,
            'uuid' => $sanitize($workstation->uuid ?? ''),
            'id' => $sanitize((string) ($workstation->id ?? '')),
            'cloneName' => $cloneName,
            'type' => $sanitize($pa['type'] ?? 'default'),
            // `role` et `etape` sont passées dans le header REM debug — les
            // builders spécifiques (join/renomme) overriden role pour le corps.
            'role' => $sanitize($pa['role'] ?? ''),
            'script' => $sanitize($pa['script'] ?? 'default'),
            'etape' => $sanitize($pa['etape'] ?? 'default'),
            'ret' => $sanitize((string) ($pa['ret'] ?? '-1')),
            // OU par défaut (vide — les builders join/renomme injectent une
            // valeur réelle).
            'ou' => '',
            // Configs SE5 — sanitize defense in depth.
            'se4installName' => $sanitize(config('sambaedu.se4install_name', '')),
            'se4installPasswd' => $sanitize($this->credentials->se4installEffectivePassword()),
            'adminseName' => $sanitize(config('sambaedu.windows.adminse_name', '')),
            'adminsePasswd' => $sanitize(config('sambaedu.windows.adminse_passwd', '')),
            'domain' => $sanitize(config('sambaedu.domain', '')),
            'se4fsName' => $sanitize(config('sambaedu.se4fs_name', '')),
        ];
    }

    /**
     * Sanitize un OU LDAP — relax la règle générale `sanitizeBatPlaceholder`
     * pour permettre les caractères LDAP DN légitimes (`,`, `=`).
     *
     * **Whitelist OU** : `[A-Za-z0-9_\-.,= ]` (lettres, chiffres, underscore,
     * tiret, point, virgule, égal, espace). Tout autre char → rejet via
     * {@see BatPlaceholderInjectionException}.
     *
     * **Exemples valides** :
     *  - `OU=techno,OU=computers,DC=localdev,DC=fr` ✓
     *  - `OU=salle 1,OU=postes,DC=ecole,DC=fr` ✓
     *
     * **Exemples rejetés** :
     *  - `OU=evil;rm -rf,DC=fr` ✗ (`;` injection)
     *  - `OU=test\nfoo,DC=fr` ✗ (`\n` newline injection)
     *
     * @throws BatPlaceholderInjectionException
     */
    private function sanitizeOu(string $raw): string
    {
        $stripped = preg_replace('/^\s+|\s+$/u', '', $raw);
        if ($stripped === null || $stripped === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z0-9_\-.,= ]+$/u', $stripped) !== 1) {
            throw new BatPlaceholderInjectionException(
                'OU LDAP contient des chars d\'injection cmd.exe interdits (whitelist OU = [A-Za-z0-9_\-.,= ]).'
            );
        }

        return $stripped;
    }

    /**
     * Render le template Blade + post-traitement CRLF strict (D6 / AC3.3).
     *
     * **Post-traitement** : double `str_replace` qui normalize d'abord tous
     * les CRLF en LF, puis re-convertit LF en CRLF (pattern 3.4
     * LinuxPreseedService + 3.5 D7). Garantit un body homogène CRLF même si
     * le template Blade contient des LF mixed avec CRLF.
     *
     * @param  string  $view  Nom dotted du template Blade.
     * @param  array<string, mixed>  $vars  Variables à interpoler.
     */
    private function renderAndNormalize(string $view, array $vars): string
    {
        $rendered = $this->views->make($view, $vars)->render();

        return $this->normalizeCrlf($rendered);
    }

    /**
     * Normalize les line endings vers CRLF strict (D6 / AC3.3).
     *
     * @internal Public uniquement pour tests Unit (assert que le passage par
     *           cette méthode produit du CRLF strict).
     */
    public static function normalizeCrlf(string $body): string
    {
        // 1. Normalize CRLF → LF (gère les CRLF, CR seuls, LF seuls).
        $lf = str_replace(["\r\n", "\r"], "\n", $body);

        // 2. Reconvert LF → CRLF.
        return str_replace("\n", "\r\n", $lf);
    }
}
