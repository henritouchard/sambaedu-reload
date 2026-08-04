<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Posix;

/**
 * Story 60.4 — la NEUTRALISATION d'une sortie d'erreur système avant qu'elle
 * n'entre dans un rapport.
 *
 * **Pourquoi cette classe existe.** Le contrat exige qu'un échec NOMME sa cause,
 * et la seule cause utile vient du système. Mais `detail` est aussi le seul texte
 * libre d'un rapport, et une sortie d'erreur brute contient exactement ce que la
 * ligne de coupe interdit de faire remonter : un chemin absolu, un nom de
 * commande, un mode de permission, un nom de groupe d'annuaire.
 *
 * Sans elle, la neutralité des rapports serait vraie sur le chemin heureux (où
 * aucun geste n'échoue, donc aucun texte système ne remonte) et fausse partout
 * ailleurs — c'est-à-dire précisément dans les cas pour lesquels le `detail`
 * existe. C'est la signature de défaut que cet epic rencontre à chaque story ;
 * elle se ferme ici, au point exact où le texte franchit la ligne.
 *
 * Le remplacement garde la PHRASE (« argument invalide », « permission refusée »,
 * « aucun espace disponible ») et jette le VOCABULAIRE. C'est ce qui reste
 * actionnable pour un administrateur sans lui apprendre le dialecte du backend.
 *
 * La couverture est vérifiée par un test qui fait échouer de vrais gestes avec de
 * vraies sorties d'erreur et confronte le rapport produit à la liste canonique de
 * marqueurs interdits — pas par une seconde liste qui divergerait de la première.
 */
final class PosixDiagnostic
{
    /** Longueur retenue : une cause, pas un déversoir. */
    private const MAX_WIDTH = 300;

    /**
     * Substitutions, dans l'ordre. La première capture les chemins absolus (avant
     * que les autres motifs ne les découpent), la dernière les modes de
     * permission isolés.
     *
     * @var array<string, string>
     */
    private const SUBSTITUTIONS = [
        // Nom du groupe d'administration, échappé ou non — il porte une espace,
        // il apparaît donc sous deux formes dans les sorties système.
        '/domain(?:\\\\040|\s|_)+admins/i' => '<groupe d\'administration>',
        // Chemins absolus.
        '#(?<![\w])/[A-Za-z0-9_.\-]+(?:/[A-Za-z0-9_.\-]+)*#' => '<chemin>',
        // Entrées de liste d'accès, avec ou sans miroir d'héritage.
        '/\b(?:default:)?(?:user|group|mask|other)::?[A-Za-z0-9_.\\\\-]*:?[rwx-]*/i' => '<entrée de droits>',
        // Noms de commandes et élévation de privilège.
        '/\b(?:setfacl|getfacl|chmod|chown|chgrp|mkdir|mv|getent|sudo)\b/i' => '<commande système>',
        // Modes de permission isolés.
        '/\b(?:rwx|rw-|r-x|--x|rx)\b/i' => '<droits>',
        // Racines gérées citées sans barre oblique de tête.
        '/\bPartages\b/i' => '<racine gérée>',
        '/\bClasses\b/i' => '<racine gérée>',
    ];

    /**
     * Rend une cause LISIBLE et NEUTRE à partir d'une sortie d'erreur système.
     *
     * Une sortie vide n'est pas un silence à masquer : elle se dit.
     */
    public static function neutralize(string $raw): string
    {
        $text = trim($raw);
        if ($text === '') {
            return 'le système n\'a produit aucune sortie d\'erreur.';
        }

        foreach (self::SUBSTITUTIONS as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        if ($text === '') {
            return 'le système n\'a produit aucune cause exploitable.';
        }

        return mb_strimwidth($text, 0, self::MAX_WIDTH, '…');
    }
}
