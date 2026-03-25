<?php

/**
 * Stub config.inc.php — remplace le legacy config.inc.php
 * quand un module est exécuté via LegacyEmbedService.
 *
 * Empêche le chargement du vrai config.inc.php legacy (qui redefinirait
 * get_config() et d'autres fonctions déjà dans nos shims).
 *
 * Délègue à notre config bridge (legacy/config.inc.php) via require_once.
 */

// S'assurer que notre bridge est chargé
require_once __DIR__ . '/../config.inc.php';

// ─── Fonctions du legacy config.inc.php nécessaires aux modules ─────────

if (!function_exists('header_authorize')) {
    /**
     * Stub : remplace l'auth gate + etab selector du legacy.
     * En mode embed, l'utilisateur est déjà authentifié via Laravel.
     */
    function header_authorize(&$config): string
    {
        // Bridge Laravel auth → legacy session
        if (function_exists('auth') && auth()->check()) {
            $user = auth()->user();
            $config['login'] = $user->login ?? '';
            $_SESSION['login'] = $config['login'];
            $_SESSION['level'] = 0; // niveau admin (accès complet)
            $_SESSION['etab'] = $config['etab_ou'] ?? '';
            $_SESSION['etab_ou'] = $config['etab_ou'] ?? '';
        }
        return ''; // Pas de HTML chrome
    }
}

if (!function_exists('open_session')) {
    function open_session($login = '', $passwd = ''): bool
    {
        return true; // Session déjà gérée par Laravel
    }
}

if (!function_exists('close_session')) {
    function close_session(): void
    {
        // No-op en mode embed
    }
}
