<?php

/**
 * Stub partages.inc.php — shadow du legacy sambaedu/includes/partages.inc.php.
 *
 * Ce stub résout le conflit de déclaration de roaming_profiles_stats() :
 * la fonction est définie dans gpo_deps.inc.php (chargé par le bootstrap)
 * ET dans partages.inc.php legacy (sans guard function_exists).
 * Ce stub prend la priorité dans l'include_path (stubs/ est prepend) et
 * charge le fichier original via eval() après avoir protégé roaming_profiles_stats()
 * par un guard function_exists.
 *
 * FRAGILITÉ CONNUE (review 1bis-15 #2) : le patch utilise une regex complexe
 * qui dépend du contenu interne de roaming_profiles_stats() (présence de
 * array_multisort(...SORT_DESC) juste avant le return). Tout refactor upstream
 * de cette fonction casse le patch silencieusement. L'assertion finale ci-dessous
 * détecte ce cas en levant un E_USER_ERROR immédiat.
 *
 * NB : ce stub shadow s'applique à TOUS les modules legacy qui incluent
 * partages.inc.php (gpo, user, annu, etc.). Effet de bord : eval sur 673 lignes
 * à chaque bootstrap, opcache désactivé sur ce contenu.
 *
 * Story : 1bis-15-module-printers — résolution collision roaming_profiles_stats() (AC5)
 * (également utile pour story 1bis-14-module-partages si elle est un jour activée)
 */

// Guard : ne charger qu'une seule fois
if (defined('LEGACY_PARTAGES_INC_LOADED')) {
    return;
}
define('LEGACY_PARTAGES_INC_LOADED', true);

// Inclure le fichier original via chemin absolu.
// Problème : partages.inc.php legacy déclare roaming_profiles_stats() sans guard,
// ce qui provoque "Cannot redeclare" si gpo_deps.inc.php l'a déjà définie.
// Solution : lire le contenu, wraper roaming_profiles_stats() dans un guard, eval().
$_partages_legacy_path = config('sambaedu.legacy_path', '/var/www/sambaedu') . '/includes/partages.inc.php';

if (file_exists($_partages_legacy_path)) {
    $__content = file_get_contents($_partages_legacy_path);

    // Retirer l'ouverture <?php pour eval()
    $__content = preg_replace('/^\s*<\?php\s*/i', '', $__content, 1);

    // Wraper roaming_profiles_stats() dans un guard function_exists
    // Le fichier original contient "function roaming_profiles_stats()\n{"
    $__content = str_replace(
        "function roaming_profiles_stats()\n{",
        "if (!function_exists('roaming_profiles_stats')) {\nfunction roaming_profiles_stats()\n{",
        $__content
    );

    // Fermer le if après la fermeture de roaming_profiles_stats().
    // La fonction se termine par "    return \$res;\n}" suivi de "\n\n"
    // On cherche le pattern exact de fermeture de cette fonction.
    // La fonction retourne $res — chercher la fermeture après array_multisort + return $res
    $__content = preg_replace(
        '/(array_multisort\(\$res.*?SORT_DESC\);)\s*\n(\s*return \$res;\n\})/s',
        "$1\n$2\n}",
        $__content
    );

    eval($__content);
    unset($__content);

    // Assertion post-eval : vérifie que le patch regex a bien inséré le guard
    // et que roaming_profiles_stats() a été déclarée. Si la regex a échoué
    // (fonction refactorée upstream, array_multisort déplacé, etc.), la fonction
    // reste non déclarée et on trigger un E_USER_ERROR explicite plutôt qu'une
    // erreur opaque "Cannot redeclare" à la prochaine inclusion.
    if (!function_exists('roaming_profiles_stats')) {
        trigger_error(
            'legacy/stubs/partages.inc.php : le patch preg_replace a échoué '
            . '(roaming_profiles_stats() non déclarée après eval). Vérifier que '
            . $_partages_legacy_path . ' contient toujours le pattern '
            . 'array_multisort($res ... SORT_DESC); return $res; dans la fonction.',
            E_USER_ERROR
        );
    }
}
unset($_partages_legacy_path);
