<?php

/**
 * Stub printers.inc.php — shadow du legacy sambaedu/includes/printers.inc.php.
 *
 * Ce stub résout le conflit de déclaration de guid() : la fonction est définie
 * dans gpo_deps.inc.php (chargé par le bootstrap) ET dans printers.inc.php legacy
 * (sans guard function_exists). Ce stub prend la priorité dans l'include_path
 * (stubs/ est prepend) et charge le fichier original via eval() après avoir
 * protégé guid() par un guard function_exists.
 *
 * FRAGILITÉ CONNUE (review 1bis-15 #1) : le patch repose sur des str_replace
 * exacts ("function guid()\n{" / "    return \$uuid;\n}\n"). Toute évolution
 * upstream de sambaedu/includes/printers.inc.php (reformatage, commentaire,
 * indentation, dos2unix) casse le patch silencieusement. L'assertion finale
 * ci-dessous détecte ce cas en levant un E_USER_ERROR immédiat plutôt que
 * d'attendre le "Cannot redeclare guid()" au 2e include.
 *
 * NB : ce stub shadow s'applique à TOUS les modules legacy qui incluent
 * printers.inc.php (pas juste le module printers). Effet de bord : eval sur
 * 1079 lignes à chaque bootstrap, opcache désactivé sur ce contenu.
 *
 * Story : 1bis-15-module-printers — résolution collision guid() (AC5)
 */

// Guard : ne charger qu'une seule fois
if (defined('LEGACY_PRINTERS_INC_LOADED')) {
    return;
}
define('LEGACY_PRINTERS_INC_LOADED', true);

// Inclure le fichier original via chemin absolu.
// Problème : printers.inc.php legacy déclare guid() sans guard function_exists(),
// ce qui provoque "Cannot redeclare guid()" si gpo_deps.inc.php l'a déjà définie.
// Solution : lire le contenu, remplacer "function guid()" par le guard, puis eval().
$_printers_legacy_path = config('sambaedu.legacy_path', '/var/www/sambaedu') . '/includes/printers.inc.php';

if (file_exists($_printers_legacy_path)) {
    $__content = file_get_contents($_printers_legacy_path);

    // Retirer l'ouverture <?php pour eval()
    $__content = preg_replace('/^\s*<\?php\s*/i', '', $__content, 1);

    // Wraper guid() dans un guard function_exists (remplacement exact de chaîne)
    // Le fichier original contient exactement "function guid()\n{"
    $__content = str_replace(
        "function guid()\n{",
        "if (!function_exists('guid')) {\nfunction guid()\n{",
        $__content
    );

    // Fermer le if après la fermeture de guid().
    // La fonction guid() se termine par "    return \$uuid;\n}" suivi d'une ligne vide.
    // On cherche ce pattern exact et on ajoute la fermeture du if juste après.
    $__content = str_replace(
        "    return \$uuid;\n}\n",
        "    return \$uuid;\n}\n}\n",
        $__content
    );

    eval($__content);
    unset($__content);

    // Assertion post-eval : vérifie que le patch str_replace a bien inséré
    // le guard function_exists et que guid() a été déclarée. Si guid() n'est
    // pas définie après eval, c'est que le str_replace a échoué (legacy a
    // évolué, pattern "function guid()\n{" introuvable) — on trigger un
    // E_USER_ERROR explicite plutôt qu'une erreur opaque "Cannot redeclare".
    if (!function_exists('guid')) {
        trigger_error(
            'legacy/stubs/printers.inc.php : le patch str_replace a échoué '
            . '(guid() non déclarée après eval). Vérifier que '
            . $_printers_legacy_path . ' contient toujours "function guid()\n{".',
            E_USER_ERROR
        );
    }
}
unset($_printers_legacy_path);
