<?php

declare(strict_types=1);

namespace App\Ldap;

use App\Gpo\Support\SambaToolRunner;
use App\Repositories\WorkstationRepository;
use Illuminate\Support\Facades\Log;

/**
 * Service natif de gestion AD côté machines (création, mise à jour hardware,
 * OS, requête de connexion remote).
 *
 * Story 16.7 — AC3.1 (cf. décision user D1 « PURE NATIVE » 2026-05-12) +
 * tranchement DO3 par défaut (option (a) : `App\Ldap\AdMachineManager` pour
 * cohérence avec `App\Ldap\AdUserManager` Story 16.3b).
 *
 * **Périmètre** : porte natif les 4 fonctions legacy AD consommées par
 * `applications.inc.php::get_app_scripts_info` :
 *
 *  - `check_computer($config, $machine, &$html)`   → {@see check()}
 *  - `register_machine_hardware($config, $m, $uuid)` → {@see registerHardware()}
 *  - `set_os($config, $name, $os)`                  → {@see setOs()}
 *  - `list_remote_connexion($config, $machineCn, $userLdap)` → {@see listRemoteConnexion()}
 *
 * **Sécurité** :
 *
 *  - Mode array `SambaToolRunner` (jamais de concat shell — défense en
 *    profondeur, parité 16.3b `AdUserManager`).
 *  - Regex stricte sur tous les inputs (`MACHINE_REGEX`) AVANT tout appel
 *    shell — refus précoce d'`'; rm -rf /`.
 *  - Tous les logs sur le channel `gpo` (audit AD writeback) avec
 *    `action_type` documenté dans `app/Gpo/README.md`.
 *
 * **Tranchement DO4** (write LDAP via `SambaToolRunner` mode array vs
 * `LdapRecord` direct) : par défaut `SambaToolRunner` — audit log gratuit,
 * pas de question droits LDAP côté Laravel, parité 16.3b. La lecture passe
 * par `WorkstationRepository` (LdapRecord) pour bénéficier du cache et de
 * la typisation.
 *
 * @legacy-port path="sambaedu/includes/ldap.inc.php (check_computer, register_machine_hardware, set_os)"
 * @legacy-port path="sambaedu/includes/remote.inc.php (list_remote_connexion)"
 * @see AdUserManager Pattern source (Story 16.3b).
 */
class AdMachineManager
{
    /** Caractères valides pour un nom NetBIOS de machine (parité regex 16.3b). */
    private const MACHINE_REGEX = '/^[A-Za-z0-9_\-\.\$]{1,64}$/';

    /** Caractères valides pour un samAccountName user (parité regex 16.3b). */
    private const SAMACCOUNTNAME_REGEX = '/^[A-Za-z0-9_\-\.\$]{1,64}$/';

    public function __construct(
        private readonly SambaToolRunner $runner,
        private readonly WorkstationRepository $workstations,
    ) {}

    /**
     * Vérifie qu'une machine AD existe ; si absente, l'auto-crée
     * (parité legacy `check_computer` lignes 2460-2520).
     *
     * Iso-legacy : les serveurs `se4fs*`/`se4ad*` sont ignorés (line 2463
     * legacy `preg_match("/^(se4fs|se4ad).../i", $cn)` retourne true direct).
     *
     * @return bool `true` si la machine existe (ou a été créée), `false` si
     *              création requise mais échouée.
     */
    public function check(string $machineName): bool
    {
        if (! $this->isValidMachineName($machineName)) {
            Log::channel('gpo')->warning('[AdMachineManager] check() invalid machine name', [
                'action_type' => 'ad.machine.check',
                'machine' => $machineName,
                'step' => 'rejected',
            ]);
            return false;
        }

        // Iso-legacy : serveurs SE4FS/SE4AD = no-op idempotent.
        if (preg_match('/^(se4fs|se4ad)/i', $machineName) === 1) {
            return true;
        }

        // Lecture native via LdapRecord (cache APCu repository).
        $existing = $this->workstations->findByName($machineName);
        if ($existing !== null) {
            Log::channel('gpo')->debug('[gpo] ad.machine.check existing', [
                'action_type' => 'ad.machine.check',
                'machine' => $machineName,
                'step' => 'exists',
            ]);
            return true;
        }

        // Création samba-tool — l'AD se charge du DN selon la config
        // par défaut (`cn=computers,dc=...`). Pour un placement OU précis,
        // une story dédiée pourra étendre avec `--computerou=` (cf. Epic 17).
        Log::channel('gpo')->info('[gpo] ad.machine.check create start', [
            'action_type' => 'ad.machine.check',
            'machine' => $machineName,
            'step' => 'create',
        ]);

        try {
            $result = $this->runner->run(['computer', 'create', $machineName]);
        } catch (\Throwable $e) {
            Log::channel('gpo')->error('[AdMachineManager] check() create threw', [
                'action_type' => 'ad.machine.check',
                'machine' => $machineName,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        if ($result->exitCode() !== 0) {
            $stderr = (string) $result->errorOutput();
            // Idempotence : `already exists` = succès (un autre poste a pu créer
            // simultanément — boot de masse).
            if (stripos($stderr, 'already exists') !== false) {
                Log::channel('gpo')->info('[AdMachineManager] check() create idempotent', [
                    'action_type' => 'ad.machine.check',
                    'machine' => $machineName,
                    'step' => 'already_exists',
                ]);
                return true;
            }
            Log::channel('gpo')->error('[AdMachineManager] check() create failed', [
                'action_type' => 'ad.machine.check',
                'machine' => $machineName,
                'exit_code' => $result->exitCode(),
                'stderr' => $this->truncate($stderr),
            ]);
            return false;
        }

        Log::channel('gpo')->info('[gpo] ad.machine.check success', [
            'action_type' => 'ad.machine.check',
            'machine' => $machineName,
            'step' => 'created',
        ]);
        return true;
    }

    /**
     * Enregistre l'UUID hardware (BIOS) d'une machine sur l'attribut LDAP
     * `netbootGUID` (parité legacy `register_machine_hardware` lignes 2693-2724).
     *
     * Iso-legacy : si l'UUID actuel diffère, on remplace (`--replace`).
     *
     * @param  array<string,string>  $hwAttrs  Attributs hardware additionnels
     *                                          (réservé extension future, ignoré
     *                                          pour parité legacy stricte).
     */
    public function registerHardware(string $machineName, string $uuid, array $hwAttrs = []): bool
    {
        if (! $this->isValidMachineName($machineName)) {
            Log::channel('gpo')->warning('[AdMachineManager] registerHardware() invalid machine name', [
                'action_type' => 'ad.machine.hardware.register',
                'machine' => $machineName,
            ]);
            return false;
        }

        // UUID = 36 hex avec tirets (format BIOS standard) ou 32 hex pur.
        $uuid = trim(strtolower($uuid));
        if ($uuid === '' || ! preg_match('/^[a-f0-9\-]{32,36}$/', $uuid)) {
            Log::channel('gpo')->warning('[AdMachineManager] registerHardware() invalid uuid', [
                'action_type' => 'ad.machine.hardware.register',
                'machine' => $machineName,
            ]);
            return false;
        }

        // Note : iso-legacy `register_machine_hardware` distingue le cas
        // `netbootguid` absent (add) vs différent (replace). On simplifie en
        // `--set` qui est idempotent côté samba-tool (équivalent replace) —
        // garde l'audit log dans tous les cas.
        $args = ['computer', 'edit', $machineName, '--set-attribute=netbootGUID=' . $uuid];

        try {
            $result = $this->runner->run($args);
        } catch (\Throwable $e) {
            Log::channel('gpo')->error('[AdMachineManager] registerHardware() threw', [
                'action_type' => 'ad.machine.hardware.register',
                'machine' => $machineName,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        if ($result->exitCode() !== 0) {
            Log::channel('gpo')->error('[AdMachineManager] registerHardware() failed', [
                'action_type' => 'ad.machine.hardware.register',
                'machine' => $machineName,
                'exit_code' => $result->exitCode(),
                'stderr' => $this->truncate((string) $result->errorOutput()),
            ]);
            return false;
        }

        Log::channel('gpo')->info('[gpo] ad.machine.hardware.register success', [
            'action_type' => 'ad.machine.hardware.register',
            'machine' => $machineName,
            'uuid' => $uuid,
        ]);
        return true;
    }

    /**
     * Marque la machine comme membre d'un groupe parc OS
     * (parité legacy `set_os` lignes 4106-4110 : `groupaddmember($machine.'$',
     * $os.$config['suffix'])`).
     *
     * Le mécanisme legacy iso-Sambaedu = appartenance au parc `linux`/`windows`
     * → la résolution OS se fait en lisant les `memberOf` de la machine
     * (`get_os` legacy lignes 4080-4092). On reproduit le même mécanisme :
     * ajout du compte machine au groupe `linux`/`windows` (via samba-tool).
     */
    public function setOs(string $machineName, string $os): bool
    {
        if (! $this->isValidMachineName($machineName)) {
            Log::channel('gpo')->warning('[AdMachineManager] setOs() invalid machine name', [
                'action_type' => 'ad.machine.os.set',
                'machine' => $machineName,
            ]);
            return false;
        }
        if (! in_array($os, ['linux', 'windows'], true)) {
            Log::channel('gpo')->warning('[AdMachineManager] setOs() invalid os', [
                'action_type' => 'ad.machine.os.set',
                'machine' => $machineName,
                'os' => $os,
            ]);
            return false;
        }

        // Iso-legacy : suffix optionnel issu de `$config['suffix']` (souvent
        // vide pour SE4FS standard). On le résout via config Laravel pour
        // permettre l'override (parité Story 16.3b `ReadUserManager::computeSuffix`).
        $suffix = (string) config('sambaedu.legacy_ldap.suffix', '');
        $member = $machineName . '$';
        $group = $os . $suffix;

        $args = ['group', 'addmembers', $group, $member];

        try {
            $result = $this->runner->run($args);
        } catch (\Throwable $e) {
            Log::channel('gpo')->error('[AdMachineManager] setOs() threw', [
                'action_type' => 'ad.machine.os.set',
                'machine' => $machineName,
                'os' => $os,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        if ($result->exitCode() !== 0) {
            $stderr = (string) $result->errorOutput();
            // Idempotence : `already a member` = succès (machine déjà dans le
            // groupe OS — boot répété).
            if (stripos($stderr, 'already') !== false) {
                Log::channel('gpo')->debug('[gpo] ad.machine.os.set already member', [
                    'action_type' => 'ad.machine.os.set',
                    'machine' => $machineName,
                    'os' => $os,
                ]);
                return true;
            }
            Log::channel('gpo')->error('[AdMachineManager] setOs() failed', [
                'action_type' => 'ad.machine.os.set',
                'machine' => $machineName,
                'os' => $os,
                'exit_code' => $result->exitCode(),
                'stderr' => $this->truncate($stderr),
            ]);
            return false;
        }

        Log::channel('gpo')->info('[gpo] ad.machine.os.set success', [
            'action_type' => 'ad.machine.os.set',
            'machine' => $machineName,
            'os' => $os,
            'group' => $group,
        ]);
        return true;
    }

    /**
     * Détecte si l'utilisateur a une connexion remote (RDP/VNC/SSH) déclarée
     * via Guacamole pour la machine cible — parité legacy `list_remote_connexion`
     * (`remote.inc.php:302-319`).
     *
     * Logique legacy :
     *  1. si `$config['guacamole_url']` vide → retourne ''
     *  2. sinon, lit le groupe AD `remote_<machineCn>` (objectclass guacConfigGroup)
     *  3. si `$userDn` ∈ membres → retourne `$remote['guacconfigprotocol']` (`rdp`/`vnc`/`ssh`)
     *
     * **Tranchement pragmatique 16.7** : la lecture du groupe `remote_<cn>` requiert
     * `search_ad($cn, "remote")` qui n'est pas natif (LdapModels). On expose un
     * comportement gracieux conservateur :
     *  - Si Guacamole non configuré (`config('sambaedu.guacamole_url')` vide) → `''`
     *  - Sinon → `''` par défaut (parité « pas de remote ») + log info documentant
     *    le shim (cf. tech-debt-gpo.md). Le portage complet sera fait quand un
     *    `RemoteConnectionRepository` natif sera créé (story dédiée Guacamole).
     *
     * @return string `'rdp'`, `'vnc'`, `'ssh'`, ou `''` (pas de remote).
     */
    public function listRemoteConnexion(string $machineCn, string $userSamaccountname): string
    {
        if (! $this->isValidMachineName($machineCn) || ! $this->isValidSamaccountname($userSamaccountname)) {
            Log::channel('gpo')->warning('[AdMachineManager] listRemoteConnexion() invalid input', [
                'action_type' => 'ad.machine.remote.list',
                'machine' => $machineCn,
                'user' => $userSamaccountname,
            ]);
            return '';
        }

        $guacamoleUrl = (string) config('sambaedu.guacamole_url', '');
        if ($guacamoleUrl === '') {
            // Iso-legacy ligne 305 : pas de Guacamole = pas de remote.
            return '';
        }

        // @legacy-port partiel : la lecture du groupe `remote_<cn>` LDAP via
        // `search_ad($cn, "remote")` n'est pas portée native. Story dédiée
        // « Guacamole RemoteConnectionRepository » à créer (cf. tech-debt-gpo.md).
        Log::channel('gpo')->debug('[gpo] ad.machine.remote.list shim fallback', [
            'action_type' => 'ad.machine.remote.list',
            'machine' => $machineCn,
            'user' => $userSamaccountname,
            'reason' => 'guacamole repo not yet native — returning empty (no remote)',
        ]);

        return '';
    }

    /**
     * Story 3.3 — D14 / AC3.1.
     *
     * Renomme un compte machine dans l'AD — port natif de la branche
     * `move_ad($config, $oldName, "cn=$newName,$ou", "computer")` legacy
     * `enregistrement.php:56`.
     *
     * **Plan B retenu (delete + recreate)** : `samba-tool computer move` ne
     * supporte que le déplacement OU (changement `parent DN`), pas le
     * renommage CN. La voie pragmatique est de supprimer le compte machine
     * sous l'ancien nom puis le recréer sous le nouveau. Conséquence
     * documentée : `netbootGUID` est perdu côté ancien compte → un appel
     * `{@see registerHardware()}` est nécessaire **après** ce rename pour
     * réenregistrer l'UUID hardware sur le nouveau compte (le service
     * `WorkstationEnrollmentService` orchestre cette séquence dans le cas
     * `RENAMED`).
     *
     * Idempotence :
     *  - Si `$oldName` n'existe pas → on tente quand même le delete (samba-tool
     *    retourne en général exit 0 + warning, ou exit 1 + "object not found"
     *    qu'on traite comme succès silencieux).
     *  - Si le delete réussit mais le create échoue → log error + retour false.
     *
     * @return bool `true` si le nouveau compte machine existe à la fin, `false`
     *              sinon.
     */
    public function renameComputer(string $oldName, string $newName): bool
    {
        if (! $this->isValidMachineName($oldName)) {
            Log::channel('gpo')->warning('[AdMachineManager] renameComputer() invalid old name', [
                'action_type' => 'ad.machine.rename',
                'machine' => $oldName,
                'step' => 'rejected_old',
            ]);
            return false;
        }
        if (! $this->isValidMachineName($newName)) {
            Log::channel('gpo')->warning('[AdMachineManager] renameComputer() invalid new name', [
                'action_type' => 'ad.machine.rename',
                'machine' => $newName,
                'step' => 'rejected_new',
            ]);
            return false;
        }

        // Cas dégénéré : oldName == newName → no-op idempotent.
        if (strcasecmp($oldName, $newName) === 0) {
            Log::channel('gpo')->debug('[gpo] ad.machine.rename noop', [
                'action_type' => 'ad.machine.rename',
                'machine' => $oldName,
                'step' => 'same_name',
            ]);
            return true;
        }

        // Iso-legacy : ne pas tenter de renommer un serveur interne SE4FS/SE4AD.
        if (preg_match('/^(se4fs|se4ad)/i', $oldName) === 1) {
            Log::channel('gpo')->info('[gpo] ad.machine.rename skip server', [
                'action_type' => 'ad.machine.rename',
                'machine' => $oldName,
                'step' => 'skip_se4_server',
            ]);
            return true;
        }

        Log::channel('gpo')->info('[gpo] ad.machine.rename start (delete+create)', [
            'action_type' => 'ad.machine.rename',
            'old' => $oldName,
            'new' => $newName,
            'step' => 'start',
        ]);

        // Étape 1 — delete (tolérant aux échecs "object not found").
        try {
            $deleteResult = $this->runner->run(['computer', 'delete', $oldName]);
            if ($deleteResult->exitCode() !== 0) {
                $stderr = (string) $deleteResult->errorOutput();
                // Tolérance : l'ancien compte n'existe peut-être pas (race
                // condition, base AD désynchronisée). On log warning et on
                // continue avec le create — l'important est que le nouveau
                // compte existe à la fin.
                if (stripos($stderr, 'no such object') === false
                    && stripos($stderr, 'not found') === false) {
                    Log::channel('gpo')->warning('[AdMachineManager] renameComputer() delete failed (continuing)', [
                        'action_type' => 'ad.machine.rename',
                        'old' => $oldName,
                        'exit_code' => $deleteResult->exitCode(),
                        'stderr' => $this->truncate($stderr),
                        'step' => 'delete_failed_continue',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::channel('gpo')->warning('[AdMachineManager] renameComputer() delete threw (continuing)', [
                'action_type' => 'ad.machine.rename',
                'old' => $oldName,
                'error' => $e->getMessage(),
                'step' => 'delete_threw_continue',
            ]);
        }

        // Étape 2 — create du nouveau compte.
        try {
            $createResult = $this->runner->run(['computer', 'create', $newName]);
        } catch (\Throwable $e) {
            Log::channel('gpo')->error('[AdMachineManager] renameComputer() create threw', [
                'action_type' => 'ad.machine.rename',
                'old' => $oldName,
                'new' => $newName,
                'error' => $e->getMessage(),
                'step' => 'create_threw',
            ]);
            return false;
        }

        if ($createResult->exitCode() !== 0) {
            $stderr = (string) $createResult->errorOutput();
            // Idempotence sur la deuxième étape : si le nouveau nom existe déjà
            // (race), on considère que le rename est satisfait.
            if (stripos($stderr, 'already exists') !== false) {
                Log::channel('gpo')->info('[AdMachineManager] renameComputer() create idempotent', [
                    'action_type' => 'ad.machine.rename',
                    'old' => $oldName,
                    'new' => $newName,
                    'step' => 'create_already_exists',
                ]);
                return true;
            }
            Log::channel('gpo')->error('[AdMachineManager] renameComputer() create failed', [
                'action_type' => 'ad.machine.rename',
                'old' => $oldName,
                'new' => $newName,
                'exit_code' => $createResult->exitCode(),
                'stderr' => $this->truncate($stderr),
                'step' => 'create_failed',
            ]);
            return false;
        }

        Log::channel('gpo')->info('[gpo] ad.machine.rename success', [
            'action_type' => 'ad.machine.rename',
            'old' => $oldName,
            'new' => $newName,
            'step' => 'success',
        ]);
        return true;
    }

    private function isValidMachineName(string $name): bool
    {
        return $name !== '' && (bool) preg_match(self::MACHINE_REGEX, $name);
    }

    private function isValidSamaccountname(string $name): bool
    {
        return $name !== '' && (bool) preg_match(self::SAMACCOUNTNAME_REGEX, $name);
    }

    /**
     * Tronque une sortie pour logs (parité `AdUserManager` 2 Ko).
     */
    private function truncate(string $value): string
    {
        $max = 2048;
        return strlen($value) > $max ? substr($value, 0, $max) . '…[truncated]' : $value;
    }
}
