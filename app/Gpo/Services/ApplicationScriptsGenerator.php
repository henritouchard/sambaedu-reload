<?php

declare(strict_types=1);

namespace App\Gpo\Services;

use App\LdapModels\MachineModel;
use App\Ldap\AdMachineManager;
use App\Repositories\UserRepository;
use App\Repositories\WorkstationRepository;
use App\Services\AppCustomization\Contracts\AppContextWriter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Orchestrateur de résolution du contexte applicatif runtime.
 *
 * Story 16.7 — AC2.1 : port natif de `get_app_scripts_info()` legacy
 * (`applications.inc.php:826-1007`), la fonction la plus volumineuse de
 * l'endpoint.
 *
 * **Périmètre** :
 *  - Résolution machine LDAP (search_machine natif via `WorkstationRepository`)
 *  - Résolution user LDAP (search_user natif via `UserRepository`)
 *  - Side effects AD au `startup` only (`check`, `registerHardware`, `setOs`)
 *  - Side effect AD au `logon` only (`listRemoteConnexion`)
 *  - Pose APCu `apps.$id` (structure iso-legacy compatible 4.7/4.8/16.3b/16.3c)
 *
 * Retourne :
 *  - `array $info` complet si succès
 *  - `[]` (array vide) si cas dégénéré iso-legacy (`Debian-gdm`/`root` au
 *    logon/logoff, action `remote-*-system`, machine introuvable, etc.)
 *
 * @legacy-port path="sambaedu/includes/applications.inc.php:826-1007 (get_app_scripts_info)"
 */
final class ApplicationScriptsGenerator
{
    /** Liste utilisateurs « système » sans script (logon/logoff iso-legacy :856). */
    private const SYSTEM_USERS = ['Debian-gdm', 'root'];

    public function __construct(
        private readonly WorkstationRepository $workstations,
        private readonly UserRepository $users,
        private readonly AdMachineManager $adMachines,
        private readonly AppContextWriter $contextWriter,
        private readonly ?ApplicationScriptsAssembler $assembler = null,
    ) {}

    /**
     * Résout le contexte runtime à partir des inputs HTTP normalisés.
     *
     * @param  array{
     *   machine: string,
     *   action: string,
     *   application: string,
     *   os: string,
     *   uuid: string,
     *   interpreter: string,
     *   speed: int,
     *   user: string,
     *   id: string,
     *   userprofile: string,
     * }  $input
     * @return array<string,mixed>  Contexte iso-legacy ou `[]` si cas dégénéré.
     */
    public function resolveInfo(array $input): array
    {
        $machineRaw = strtolower($input['machine'] ?? '');
        // ltsp : strip préfixe `l-` (iso-legacy :854).
        $machine = preg_replace('/^l-/', '', $machineRaw) ?? $machineRaw;
        $action = (string) ($input['action'] ?? '');
        $user = (string) ($input['user'] ?? '');
        $id = (string) ($input['id'] ?? '');
        $os = (string) ($input['os'] ?? 'windows');
        $application = (string) ($input['application'] ?? '');
        $uuid = trim(strtolower((string) ($input['uuid'] ?? '')));
        $interpreter = (string) ($input['interpreter'] ?? '');
        $speed = (int) ($input['speed'] ?? 0);
        $speed = intdiv($speed, 1_000_000);
        $userprofile = (string) ($input['userprofile'] ?? '');

        if ($interpreter === '') {
            $interpreter = $os === 'linux' ? 'bash' : 'cmd';
        }

        // Cas dégénérés iso-legacy AC1.5.
        if ($id === '') {
            if (in_array($action, ['logon', 'logoff'], true) && in_array($user, self::SYSTEM_USERS, true)) {
                return [];
            }
            if (preg_match('/remote-(.*)-system/', $action) === 1) {
                return [];
            }
            if (in_array($action, ['logon-system', 'logoff-system'], true) && $machine === '') {
                Log::channel('daily')->warning('[ApplicationScriptsGenerator] missing machine for system action', [
                    'action' => $action,
                    'user' => $user,
                ]);
                return [];
            }
            // Calcul id md5 iso-legacy ligne 878.
            $id = md5(strtolower($user) . strtolower($machine) . $action . $application);
        }

        // Si APCu déjà peuplé, on retourne tel quel (économie LDAP — pattern legacy :880).
        // Story 16.11 Q1.a — si le payload mis en cache antérieurement n'avait
        // pas d'uuid (versions pré-16.11), on le ré-écrit avec l'uuid courant
        // pour que `RequireBootstrapToken::checkMismatch()` puisse valider.
        $cached = $this->fetchCached($id);
        if ($cached !== null) {
            if (! array_key_exists('uuid', $cached) && $uuid !== '') {
                $cached['uuid'] = $uuid;
                // Best-effort : repousse en APCu pour que les requêtes suivantes
                // bénéficient de l'uuid (sinon, perte de l'info à chaque hit).
                $this->contextWriter->write($id, $cached, 1800);
            }
            $cached['interpreter'] = $interpreter;
            $cached['speed'] = $speed;
            $cached['uuid'] = $uuid;
            $cached['userprofile'] = $userprofile;
            return $cached;
        }

        // Parse action `remote-<x>-<context>` (iso-legacy :882-885).
        preg_match('/^((remote)-)?([a-z]*)(-(system|server|once))?$/U', $action, $m);
        $context = $m[5] ?? '';
        $actionPure = $m[3] ?? '';
        $remote = ! empty($m[2]);

        // Side effect AD au startup only (3 appels) — AC1.3.
        if ($actionPure === 'startup' && $machine !== '') {
            $this->adMachines->check($machine);
        }

        // Recherche machine.
        $machineLdap = $machine !== '' ? $this->workstations->findByName($machine) : null;
        if ($machineLdap === null || $machine === '') {
            Log::channel('daily')->warning('[ApplicationScriptsGenerator] unknown machine', [
                'machine' => $machine,
                'user' => $user,
                'action' => $action,
            ]);
            return [];
        }
        $machineData = $this->machineToArray($machineLdap);

        // Iso-legacy : `register_machine_hardware` et `set_os` sont appelés
        // par `log_application_scripts` à la fin d'exécution (footer curl
        // `ret=0`), pas au démarrage. Cf. review #1 — corrigé en déléguant
        // ces side effects à `ApplicationLoggerService::logScripts`. Les
        // données `uuid`/`os` sont transmises via le contexte `$info`.

        // Recherche user (cas : connexion système = machine, sinon user réel).
        $userIsMachine = $machine !== '' && preg_match('/' . preg_quote($machine, '/') . '/i', $user) === 1;
        $userLdap = null;
        if ($userIsMachine) {
            $userData = $machineData;
        } elseif ($user !== '') {
            $userLdap = $this->users->findByLogin($user);
            $userData = $userLdap !== null ? $this->userToArray($userLdap) : ['cn' => $user];
            // Iso-legacy `:909` — création du home dir au premier logon (parité
            // `mkhome.sh`, review #16). Skip testing pour éviter shell-out CI.
            if ($userLdap !== null && ! app()->environment('testing')) {
                $this->runMkhome($user);
            }
        } else {
            $userData = ['cn' => ''];
        }

        // Calculs parcs/salle/groupes.
        $parcs = $this->extractParcs($machineLdap);
        $salle = (string) ($machineData['salle'] ?? '');

        $groups = $groupsE = [];
        $listU = $listUe = [];
        if (! $userIsMachine && $userLdap !== null) {
            foreach ($this->extractUserGroups($userLdap) as $group) {
                $groups[] = $group['cn'];
                $groupsE[] = $group['sam'];
            }
            $listU = array_values(array_unique(array_merge([$userData['cn']], $groups)));
            $listUe = array_values(array_unique(array_merge([$userData['cn']], $groupsE)));

            // Side effect AD au logon only — AC1.4.
            if ($actionPure === 'logon') {
                $remoteType = $this->adMachines->listRemoteConnexion($machineData['cn'], (string) $userData['cn']);
                if ($remoteType === 'rdp') {
                    $listU[] = 'remote_user';
                    $listUe[] = 'remote_user';
                }
            }
        } elseif (! $userIsMachine) {
            $userData['cn'] = 'nobody';
        }

        $listM = array_values(array_unique(array_merge([$machineData['cn']], [$salle], $parcs)));
        $list = array_values(array_unique(array_merge($listU, $listM)));

        // Liste apps installées poste (réutilise Story 15.2).
        $listeApplications = $this->resolveInstalledApplications($machineData['cn'], $os, $actionPure);

        $info = [
            'id' => $id,
            'action' => $actionPure,
            'remote' => $remote,
            'context' => $context,
            'application' => $application,
            'user' => $userData,
            'machine' => $machineData,
            'salle' => $salle,
            'list' => $list,
            'list_u' => $listU,
            'list_ue' => $listUe,
            'list_m' => $listM,
            'liste_applications' => $listeApplications,
            'admin' => 0, // Posé ci-dessous après injection des parcs (parité legacy `:936`).
            'os' => $os,
            'time' => time(),
            'parcs' => $parcs,
            // Story 16.11 Q1.a — `uuid` doit être posé AVANT `contextWriter->write()`
            // pour que `RequireBootstrapToken::checkMismatch()` (validator
            // durci `LegacyBootstrapTokenValidator::payloadMatchesUuid`) puisse
            // valider le couple token↔uuid lors de l'enroll auto-bootstrap.
            // Sans cette ligne, 100% des enrolls 16.11 échouent en
            // `bootstrap_token.invalid` car la clé `uuid` n'est jamais peuplée
            // dans le payload APCu côté lecteur.
            'uuid' => $uuid,
        ];

        // Story 16.7 post-review #4 (2026-05-13) — parité legacy `:936` :
        // `$info['admin'] = 1` ssi user a `have_right(SE_COMPUTER_ADMIN)` ou
        // `have_delegation($machine, SE_COMPUTER_ADMIN, $user)`. Le contexte
        // `parcs` doit être présent pour la résolution scopée — d'où le
        // calcul après assignation `$info`. Sécurité : pas d'élévation pour
        // les connexions « système » (user == machine) ni pour les sessions
        // sans user résolu (`userl['cn'] = 'nobody'` legacy `:955`).
        if (! $userIsMachine && $userLdap !== null) {
            $info['admin'] = $this->resolveAdminFlag($info);
        }

        // Pose APCu (TTL 1800s iso-legacy :998).
        // Story 16.11 Q1.a — `uuid` est désormais inclus dans `$info` ci-dessus
        // (avant ce `write()`) pour permettre au validator bootstrap-token de
        // valider le couple token↔uuid. Conséquence : la clé `uuid` est
        // maintenant **persistée** en APCu (pas seulement passthrough).
        $this->contextWriter->write($id, $info, 1800);

        // Champs non cachables (iso-legacy :1000-1004). `uuid` reste posé
        // ici pour parité legacy (re-écrit avec la valeur courante non
        // normalisée par le caller, alors que la version cachée a déjà été
        // normalisée `strtolower` ligne 76).
        $info['interpreter'] = $interpreter;
        $info['speed'] = $speed;
        $info['userprofile'] = $userprofile;

        return $info;
    }

    /**
     * Story 16.7 post-review #4 — pose `$info['admin']` (1/0) via le pendant
     * natif d'Epic 7 (`PermissionService::canOnWorkstationGroup` + permission
     * Spatie `computer.elevate`). Délégation à
     * {@see ApplicationScriptsAssembler::resolveLocalAdminRight()} pour
     * mutualiser la logique de résolution avec `localAdminScripts`.
     *
     * Best-effort : toute exception (ex. tests sans Spatie seedé) retourne `0`
     * — l'admin n'est jamais accordé en cas d'incertitude.
     *
     * @param  array<string,mixed>  $info
     */
    private function resolveAdminFlag(array $info): int
    {
        try {
            $assembler = $this->assembler ?? app(ApplicationScriptsAssembler::class);
            return $assembler->resolveLocalAdminRight($info) ? 1 : 0;
        } catch (\Throwable $e) {
            Log::channel('daily')->debug('[ApplicationScriptsGenerator] resolveAdminFlag failed', [
                'user' => $info['user']['cn'] ?? '?',
                'machine' => $info['machine']['cn'] ?? '?',
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Exécution `mkhome.sh` parité legacy (review #16). Best-effort —
     * si le script est absent ou échoue, on log warning et on continue.
     */
    private function runMkhome(string $user): void
    {
        $script = '/usr/share/sambaedu/shares/share.avail/mkhome.sh';
        if (! is_file($script) || ! is_executable($script)) {
            return;
        }
        try {
            $result = Process::timeout(10)->run([$script, $user]);
            if (! $result->successful()) {
                Log::channel('gpo')->warning('[ApplicationScriptsGenerator] mkhome.sh failed', [
                    'user' => $user,
                    'exit_code' => $result->exitCode(),
                    'stderr' => $result->errorOutput(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('gpo')->warning('[ApplicationScriptsGenerator] mkhome.sh threw', [
                'user' => $user,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Lecture APCu côté générateur (best-effort).
     *
     * @return array<string,mixed>|null
     */
    private function fetchCached(string $id): ?array
    {
        if (! function_exists('apcu_fetch') || ! function_exists('apcu_enabled') || ! apcu_enabled()) {
            return null;
        }
        $success = false;
        $payload = apcu_fetch('apps.' . $id, $success);
        if ($success !== true || ! is_array($payload)) {
            return null;
        }
        return $payload;
    }

    /**
     * Convertit un `MachineModel` LDAP en array iso-legacy.
     *
     * @return array<string,mixed>
     */
    private function machineToArray(MachineModel $m): array
    {
        $cn = $m->getMachineName();
        $memberOf = [];
        try {
            $rawMemberOf = $m->getAttribute('memberof') ?: [];
            if (is_array($rawMemberOf)) {
                $memberOf = $rawMemberOf;
            }
        } catch (\Throwable) {
            $memberOf = [];
        }
        $dn = (string) ($m->getDn() ?: '');

        // Calcul salle = parent direct du DN machine (port iso-legacy
        // `ldap_dn2cn(ldap_dn2oudn($dn))`). On prend le 2e composant du DN
        // éclaté et on extrait la valeur après `=` — sans filtrer sur `OU=`
        // afin de gérer aussi les machines sous `CN=Computers` (review #13).
        $salle = '';
        if ($dn !== '') {
            $parts = preg_split('/,\s*/', $dn) ?: [];
            if (isset($parts[1]) && preg_match('/^[A-Za-z]+=(.+)$/', $parts[1], $mm) === 1) {
                $salle = $mm[1];
            }
        }

        // Détection OS via memberOf (iso-legacy `get_os`).
        $osGroups = [];
        foreach ($memberOf as $dnGroup) {
            $dnLower = strtolower((string) $dnGroup);
            if (str_contains($dnLower, 'cn=linux,')) {
                $osGroups[] = 'linux';
            } elseif (str_contains($dnLower, 'cn=windows,')) {
                $osGroups[] = 'windows';
            }
        }

        return [
            'cn' => $cn,
            'dn' => $dn,
            'memberof' => $memberOf,
            'iphostnumber' => $m->getIpAddress() ?? '',
            'netbootguid' => (string) ($m->getAttribute('netbootguid')[0] ?? ''),
            'salle' => $salle,
            'os_groups' => $osGroups,
        ];
    }

    /**
     * Convertit un user (Eloquent ou LdapUser) en array iso-legacy.
     *
     * @return array<string,mixed>
     */
    private function userToArray(mixed $userLdap): array
    {
        if (is_object($userLdap)) {
            $cn = method_exists($userLdap, 'getLogin') ? (string) $userLdap->getLogin() : '';
            if ($cn === '' && method_exists($userLdap, 'getAttribute')) {
                $cn = (string) ($userLdap->getAttribute('cn')[0] ?? '');
            }
            if ($cn === '' && property_exists($userLdap, 'login')) {
                $cn = (string) $userLdap->login;
            }
            $dn = '';
            if (method_exists($userLdap, 'getDn')) {
                $dn = (string) $userLdap->getDn();
            }
            return [
                'cn' => $cn,
                'dn' => $dn,
            ];
        }
        return ['cn' => '', 'dn' => ''];
    }

    /**
     * Extrait la liste des parcs (groupes machine hors salle).
     *
     * @return list<string>
     */
    private function extractParcs(MachineModel $m): array
    {
        $parcs = [];
        try {
            $memberOf = (array) ($m->getAttribute('memberof') ?: []);
        } catch (\Throwable) {
            return [];
        }
        foreach ($memberOf as $dn) {
            if (preg_match('/^CN=([^,]+),/i', (string) $dn, $mm) === 1) {
                $parcs[] = $mm[1];
            }
        }
        return array_values(array_unique($parcs));
    }

    /**
     * Extrait les groupes user (cn + samAccountName-équivalent).
     *
     * @return list<array{cn: string, sam: string}>
     */
    private function extractUserGroups(mixed $userLdap): array
    {
        $groups = [];
        if (! is_object($userLdap) || ! method_exists($userLdap, 'getAttribute')) {
            return [];
        }
        $memberOf = (array) ($userLdap->getAttribute('memberof') ?: []);
        foreach ($memberOf as $dn) {
            $dn = (string) $dn;
            $cn = '';
            if (preg_match('/^CN=([^,]+),/i', $dn, $mm) === 1) {
                $cn = $mm[1];
            }
            $groups[] = ['cn' => $cn, 'sam' => $cn]; // sam ≈ cn faute de `ldap_dn2sam` natif.
        }
        return $groups;
    }

    /**
     * Résout la liste des applications installées sur le poste (Story 15.2).
     *
     * Fallback gracieux : si le service `WorkstationPackagesResolver` n'est
     * pas bindé (test env), retourne `[]` + `['edge']` pour windows.
     *
     * @return list<string>
     */
    private function resolveInstalledApplications(string $machineCn, string $os, string $action): array
    {
        if ($action === '' || $machineCn === '') {
            return [];
        }
        $apps = [];
        $resolverClass = '\\App\\Wpkg\\Deployment\\Services\\WorkstationPackagesResolver';
        if (class_exists($resolverClass)) {
            try {
                $resolver = app($resolverClass);
                if (method_exists($resolver, 'resolve')) {
                    $collection = $resolver->resolve($machineCn);
                    if (is_iterable($collection)) {
                        foreach ($collection as $name) {
                            $apps[] = strtolower((string) $name);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::channel('daily')->debug('[ApplicationScriptsGenerator] WorkstationPackagesResolver unavailable', [
                    'machine' => $machineCn,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        if ($os === 'windows') {
            $apps[] = 'edge';
        }
        return array_values(array_unique($apps));
    }
}
