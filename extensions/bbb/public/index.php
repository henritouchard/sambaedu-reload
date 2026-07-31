<?php

declare(strict_types=1);

/**
 * Story 57.1 — **FRONT CONTROLLER + SCRIPT ROUTEUR de `php -S`.**
 *
 * L'unité systemd démarre :
 *
 * ```
 * /usr/bin/php -S 127.0.0.1:${SE5_EXT_PORT} \
 *     -t /usr/share/sambaedu-ext-bbb/public \
 *        /usr/share/sambaedu-ext-bbb/public/index.php
 * ```
 *
 * Le dernier argument fait de CE fichier un **script routeur** : toute requête
 * le traverse, et c'est lui qui décide. `return false` rend la main au serveur
 * intégré, qui sert alors le fichier statique demandé (les feuilles de style et
 * le script de thème sous `public/assets/`) sans passer par PHP.
 *
 * ⚠️ Le chemin reçu ici est NU : le proxy Apache a retiré `/ext/bbb`. Toute URL
 * ré-émise doit au contraire porter le préfixe — c'est le rôle exclusif de
 * `Url::to()`.
 */

use SambaEdu\ExtBbb\App;
use SambaEdu\ExtBbb\Http\Request;

// ── Script routeur : les fichiers statiques existants sont servis tels quels ──
if (PHP_SAPI === 'cli-server') {
    $requested = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $candidate = __DIR__ . '/' . ltrim(is_string($requested) ? $requested : '', '/');

    if ($requested !== '/' && is_file($candidate) && str_starts_with(realpath($candidate) ?: '', __DIR__)) {
        return false;
    }
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (! is_file($autoload)) {
    // Le paquet Debian EMBARQUE son `vendor/` : l'absence signale une
    // installation incomplète, pas une dépendance à installer sur la cible.
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Extension Visioconférences — installation incomplète (dépendances absentes).\n";

    return;
}

require $autoload;

App::bootstrap()->handle(Request::capture())->send();
