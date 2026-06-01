<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

use App\Ipxe\Contracts\IpxeAuthorizes;
use App\Models\User;
use App\Services\AuthenticationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 4.10 — Auth + permission centralisée pour les endpoints iPXE sensibles.
 *
 * **Régression sécurité critique** (2026-05-28) : `IpxeService::handleAdmin()`
 * et tous les endpoints sensibles (`maintenance`, `action/{action}`,
 * `installation-linux/windows`, `clonezilla-menu`, `enrollment/*`) ne
 * vérifiaient ni le `username` ni le `password` POSTés par le firmware iPXE.
 * N'importe quel couple random ouvrait le menu admin et déclenchait
 * réinstallations / factory-reset.
 *
 * **Responsabilité unique** : 1 méthode `authorize()` qui :
 *
 *   1. Extrait `username` (clear) et `password` (base64-encoded — le
 *      template iPXE `known.blade.php` POSTe `param password ${password:base64}`).
 *   2. Décode le password (base64).
 *   3. Si les deux sont vides → `Outcome::MissingCredentials` (=
 *      pas d'auth tentée — caller décide quoi faire : 401-like).
 *   4. Sinon, appelle {@see AuthenticationService::validateAdCredentials()}
 *      qui fait le bind LDAP iso-legacy.
 *      KO → `Outcome::AuthFailed` + log warning `ipxe.<context>.auth_failed`.
 *   5. Si OK, charge le user Eloquent via `User::findByLogin()` puis vérifie
 *      `$user->can('computer.install')` (équivalent legacy `SE_COMPUTER_INSTALL`).
 *      KO → `Outcome::PermissionDenied` + log warning
 *      `ipxe.<context>.permission_denied`.
 *   6. OK → `Outcome::Allowed` + log info `ipxe.<context>.auth_success`.
 *
 * **Anti-leak** : `$password` n'est JAMAIS loggé (ni en clair, ni encodé,
 * ni tronqué). Les logs ne contiennent que `username_prefix` (3 premiers
 * chars), `ip`, `mac_prefix` (6), `uuid_prefix` (8), `permission` requise.
 *
 * **Pas de session** : `validateAdCredentials()` ne touche pas `$_SESSION` ni
 * ne dispatche d'event Auth Laravel. Un firmware iPXE n'a pas de cookie de
 * session — chaque endpoint re-poste username/password.
 */
// Story 4.10 (correctif review #12) — `final` restauré. Les tests qui
// veulent stubber l'auth implémentent l'interface {@see IpxeAuthorizes}
// au lieu d'étendre cette classe (cf. `tests/Support/IpxeAuthTestHelper`).
final class IpxeAuthService implements IpxeAuthorizes
{
    public const PERMISSION = 'computer.install';

    public function __construct(
        private readonly AuthenticationService $auth,
    ) {
    }

    /**
     * Autorise (ou refuse) un appel iPXE sensible.
     *
     * @param string $context Suffixe de log (ex. `admin`, `maintenance`,
     *                        `action`, `install_linux`, `enrollment.name`).
     *                        Utilisé pour bâtir l'event `ipxe.<context>.auth_*`.
     */
    public function authorize(Request $request, string $context): IpxeAuthOutcome
    {
        $username = trim((string) $request->input('username', ''));
        // Le template iPXE POSTe `param password ${password:base64}` —
        // le firmware encode automatiquement en base64.
        $passwordRaw = (string) $request->input('password', '');
        $password = $passwordRaw !== '' ? $this->decodePassword($passwordRaw) : '';

        $ip = (string) ($request->ip() ?? '');
        $mac = (string) $request->input('mac', '');
        $uuid = (string) $request->input('uuid', '');

        $logBase = [
            'ip' => $ip,
            'username_prefix' => $username !== '' ? substr($username, 0, 3) : '',
            'mac_prefix' => $mac !== '' ? substr($mac, 0, 6) : '',
            'uuid_prefix' => $uuid !== '' ? substr($uuid, 0, 8) : '',
        ];

        if ($username === '' || $password === '') {
            $event = 'ipxe.' . $context . '.auth_missing';
            $this->log('warning', $event, array_merge(['action_type' => $event], $logBase));

            return new IpxeAuthOutcome(
                status: IpxeAuthStatus::MissingCredentials,
                username: $username !== '' ? $username : null,
                user: null,
            );
        }

        try {
            $ok = $this->auth->validateAdCredentials($username, $password);
        } catch (Throwable $e) {
            // Erreur LDAP transitoire : on traite comme auth_failed (sûr par
            // défaut) + on log l'exception_class pour SIEM.
            $event = 'ipxe.' . $context . '.auth_failed';
            $this->log('warning', $event, array_merge([
                'action_type' => $event,
                'reason' => 'ldap_exception',
                'exception_class' => $e::class,
            ], $logBase));

            return new IpxeAuthOutcome(
                status: IpxeAuthStatus::AuthFailed,
                username: $username,
                user: null,
            );
        }

        if (! $ok) {
            $event = 'ipxe.' . $context . '.auth_failed';
            $this->log('warning', $event, array_merge(['action_type' => $event], $logBase));

            return new IpxeAuthOutcome(
                status: IpxeAuthStatus::AuthFailed,
                username: $username,
                user: null,
            );
        }

        // Auth LDAP OK → vérification permission Spatie.
        $user = User::findByLogin($username);
        if ($user === null || ! $user->can(self::PERMISSION)) {
            $event = 'ipxe.' . $context . '.permission_denied';
            $this->log('warning', $event, array_merge([
                'action_type' => $event,
                'permission' => self::PERMISSION,
                'user_known_in_pg' => $user !== null,
            ], $logBase));

            return new IpxeAuthOutcome(
                status: IpxeAuthStatus::PermissionDenied,
                username: $username,
                user: $user,
            );
        }

        $event = 'ipxe.' . $context . '.auth_success';
        $this->log('info', $event, array_merge([
            'action_type' => $event,
            'permission' => self::PERMISSION,
        ], $logBase));

        return new IpxeAuthOutcome(
            status: IpxeAuthStatus::Allowed,
            username: $username,
            user: $user,
        );
    }

    /**
     * Décodage base64 défensif. Si la valeur n'est pas du base64 valide,
     * on retourne la chaîne brute (fallback — un firmware iPXE buggué
     * pourrait POSTer la valeur en clair).
     *
     * Story 4.10 (correctif review #3) — durcissement : `base64_decode(strict=true)`
     * ne retourne `false` QUE pour les caractères hors alphabet b64. Un mot
     * de passe composé uniquement de [A-Za-z0-9+/] comme `mypassword` est
     * « décodé » en binaire aléatoire sans déclencher le fallback raw, ce
     * qui fait échouer silencieusement le bind LDAP. On exige désormais à
     * la fois un format b64 valide ET une longueur multiple de 4 avant de
     * tenter le décodage — sinon on retourne la valeur brute.
     */
    private function decodePassword(string $value): string
    {
        if (
            $value !== ''
            && preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $value) === 1
            && strlen($value) % 4 === 0
        ) {
            $decoded = base64_decode($value, true);
            if ($decoded !== false && $decoded !== '') {
                return $decoded;
            }
        }

        // Fallback : firmware non standard ou password raw.
        return $value;
    }

    /**
     * @param  'info'|'warning'|'error'  $level
     * @param  array<string,mixed>  $context
     */
    private function log(string $level, string $event, array $context): void
    {
        $channel = (string) config('ipxe.log.channel', 'ipxe');
        $logger = Log::channel($channel);

        match ($level) {
            'warning' => $logger->warning($event, $context),
            'error' => $logger->error($event, $context),
            default => $logger->info($event, $context),
        };
    }
}
