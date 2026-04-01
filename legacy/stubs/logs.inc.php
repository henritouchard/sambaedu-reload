<?php

/**
 * Stub logs.inc.php — remplace le legacy logs.inc.php (qui utilise mysqli).
 *
 * Redirige log_connexion() et get_machine_status() vers le modèle
 * Eloquent MachineBootLog (PostgreSQL) au lieu de la base MySQL legacy.
 *
 * Les fonctions APCu (computer_lock, etc.) sont passées telles quelles
 * depuis le fichier legacy original.
 */

use App\Models\MachineBootLog;
use App\Models\Workstation;

// ─── Constantes legacy (flags d'erreur bitmask) ─────────────────────────

// Valeurs identiques à /var/www/sambaedu/includes/config.inc.php
if (!defined('SAMBAEDU_NO_WOL'))            { define('SAMBAEDU_NO_WOL', 1); }
if (!defined('SAMBAEDU_NO_WOL_LOG'))        { define('SAMBAEDU_NO_WOL_LOG', 2); }
if (!defined('SAMBAEDU_NO_SHUTDOWN'))       { define('SAMBAEDU_NO_SHUTDOWN', 4); }
if (!defined('SAMBAEDU_NO_SHUTDOWN_LOG'))   { define('SAMBAEDU_NO_SHUTDOWN_LOG', 8); }
if (!defined('SAMBAEDU_NO_IPXE'))           { define('SAMBAEDU_NO_IPXE', 32); }
if (!defined('SAMBAEDU_NO_LOGON_LOG'))      { define('SAMBAEDU_NO_LOGON_LOG', 64); }
if (!defined('SAMBAEDU_NO_LOGOFF_LOG'))     { define('SAMBAEDU_NO_LOGOFF_LOG', 128); }
if (!defined('SAMBAEDU_WPKG_RUNNING'))      { define('SAMBAEDU_WPKG_RUNNING', 16384); }
if (!defined('SAMBAEDU_SLOW_NET_ERROR'))    { define('SAMBAEDU_SLOW_NET_ERROR', 0x20000); }

// ─── Computer lock (APCu, inchangé) ─────────────────────────────────────

if (!function_exists('computer_is_locked')) {
    function computer_is_locked($computer, $lock = [])
    {
        if (empty($lock)) {
            $lock = apcu_fetch('computer_lock');
        }
        return $lock[$computer] ?? false;
    }
}

if (!function_exists('computer_lock')) {
    function computer_lock($computer, $user, &$lock = [], $store = true)
    {
        if (empty($lock)) {
            $lock = apcu_fetch('computer_lock');
        }
        $lock[$computer] = $user;
        if ($store) {
            apcu_store('computer_lock', $lock, 600);
        }
    }
}

if (!function_exists('computer_unlock')) {
    function computer_unlock($computer, &$lock = [], $store = true)
    {
        if (empty($lock)) {
            $lock = apcu_fetch('computer_lock');
        }
        unset($lock[$computer]);
        if ($store) {
            apcu_store('computer_lock', $lock, 600);
        }
    }
}

// ─── get_machine_status — Eloquent version ──────────────────────────────

if (!function_exists('get_machine_status')) {
    function get_machine_status($config, $machine, $refresh = false, $etab = 'localhost')
    {
        $machine = strtolower($machine);

        $tab = [
            'state'     => 'off',
            'error'     => 0,
            'starttime' => 0,
            'score'     => 0,
            'speed'     => 0,
            'port'      => '',
            'switchName' => '',
            'switchIP'  => '',
            'vlan'      => '',
        ];

        $log = MachineBootLog::where('machine_name', $machine)
            ->orderByDesc('id')
            ->first();

        if ($log) {
            $tab['state']      = $log->os ?? 'off';
            $tab['error']      = $log->error_flags ?? 0;
            $tab['speed']      = $log->boot_speed ?? 0;
            $tab['starttime']  = $log->started_at ? $log->started_at->toDateTimeString() : 0;
            $tab['port']       = $log->switch_port ?? '';
            $tab['switchName'] = $log->switch_name ?? '';
            $tab['switchIP']   = $log->switch_ip ?? '';
            $tab['vlan']       = $log->vlan ?? '';

            if ($log->stopped_at) {
                $tab['state'] = 'off';
            }
        }

        return $tab;
    }
}

// ─── log_connexion — Eloquent version ───────────────────────────────────

if (!function_exists('log_connexion')) {
    function log_connexion($config, $user, $machine, $os, $action, $ip = "", $error = 0, $speed = 0, $clear = false, $etab = 'localhost')
    {
        if ($user == "nobody") {
            return false;
        }

        $machine = strtolower($machine);
        $status = get_machine_status($config, $machine, true);

        if (empty($speed)) {
            $speed = $_POST['speed'] ?? $status['speed'] ?? 0;
        }
        $port       = $_POST['port'] ?? $status['port'] ?? '';
        $switchName = $_POST['switchName'] ?? $status['switchName'] ?? '';
        $switchIP   = $_POST['switchIP'] ?? $status['switchIP'] ?? '';
        $vlan       = $_POST['vlan'] ?? $status['vlan'] ?? '';

        if ($clear) {
            $error = $status['error'] & ~$error;
        } else {
            $error = $status['error'] | $error;
        }

        // Résoudre le workstation_id si possible
        $ws = Workstation::where('name', $machine)->first();
        $wsId = $ws->id ?? null;

        switch ($action) {
            case 'repair_startup':
            case 'startup':
                $error &= ~(SAMBAEDU_NO_WOL | SAMBAEDU_WPKG_RUNNING | SAMBAEDU_SLOW_NET_ERROR);

                $existing = MachineBootLog::where('machine_name', $machine)
                    ->whereNull('stopped_at')
                    ->orderByDesc('id')
                    ->first();

                if (!$existing || $status['state'] === 'off') {
                    // Nouveau boot
                    MachineBootLog::create([
                        'workstation_id' => $wsId,
                        'machine_name'   => $machine,
                        'os'             => $os,
                        'action'         => $action,
                        'initiated_by'   => $user ?: null,
                        'started_at'     => now(),
                        'error_flags'    => 0,
                        'boot_speed'     => $speed,
                        'switch_port'    => $port,
                        'switch_name'    => $switchName,
                        'switch_ip'      => $switchIP,
                        'vlan'           => $vlan,
                    ]);
                } else {
                    // Update existant (wol → ipxe, etc.)
                    $score = (300 - (time() - strtotime($status['starttime']))) * $speed;
                    if ($score <= 0) { $score = 0; }

                    $update = [
                        'os'          => $os,
                        'error_flags' => $error,
                        'started_at'  => now(),
                    ];

                    if ($status['state'] === 'wol') {
                        $update['wol_score'] = $score;
                    } elseif ($status['state'] === 'ipxe') {
                        $update['ipxe_score'] = $score;
                    }

                    $existing->update($update);
                }
                break;

            case 'shutdown':
                $error &= ~(SAMBAEDU_WPKG_RUNNING | SAMBAEDU_SLOW_NET_ERROR);
                $existing = MachineBootLog::where('machine_name', $machine)
                    ->whereNull('stopped_at')
                    ->orderByDesc('id')
                    ->first();

                if ($existing) {
                    $existing->update([
                        'stopped_at'  => now(),
                        'error_flags' => $error,
                    ]);
                }
                break;

            default:
                // logon, ping, etc. — update error/os
                $existing = MachineBootLog::where('machine_name', $machine)
                    ->whereNull('stopped_at')
                    ->orderByDesc('id')
                    ->first();

                if ($existing) {
                    $existing->update([
                        'os'          => $os,
                        'error_flags' => $error,
                        'boot_speed'  => $speed,
                    ]);
                }
                break;
        }

        return true;
    }
}
