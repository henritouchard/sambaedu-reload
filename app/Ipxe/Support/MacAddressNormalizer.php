<?php

declare(strict_types=1);

namespace App\Ipxe\Support;

/**
 * Story 3.1 — D4 / AC2.2.
 *
 * Normalise une adresse MAC vers le format canonique
 * `xx:xx:xx:xx:xx:xx` (12 hex chars en lowercase + 5 séparateurs `:`),
 * en acceptant les variantes legacy :
 *
 *  - `aa:bb:cc:dd:ee:ff` (canonique)
 *  - `AA-BB-CC-DD-EE-FF` (Windows ipconfig + iPXE `${net0/mac}` parfois)
 *  - `aabbccddeeff` (sans séparateur — `chain` paramètre brut)
 *  - mixte case toléré (lowercase final imposé)
 *
 * Retourne `null` si le format n'est pas reconnu (caractères non hex, taille
 * mauvaise, etc.). **Pas** d'exception jetée — le caller (`WorkstationLocator`)
 * traite `null` comme « MAC inutilisable, tenter UUID ou retomber sur menu
 * default ».
 *
 * Décision DO-2 : on retourne `?string` plutôt que jeter, parité legacy
 * tolérant — `boot.php:22` accepte n'importe quoi en entrée et fallback
 * sur boot disk si rien ne matche.
 */
final class MacAddressNormalizer
{
    /**
     * Tente de normaliser une chaîne MAC vers le format canonique
     * `xx:xx:xx:xx:xx:xx` lowercase.
     *
     * @param  string  $raw  Adresse MAC brute (peut contenir `:`, `-`, ou
     *                       aucun séparateur).
     * @return string|null   Adresse normalisée ou `null` si invalide.
     */
    public static function normalize(string $raw): ?string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        // Retire tous les séparateurs courants : `:`, `-`, espaces.
        $hex = preg_replace('/[:\-\s]/', '', $trimmed);
        if ($hex === null) {
            return null;
        }

        // 12 hex chars exactement attendus.
        if (preg_match('/^[0-9A-Fa-f]{12}$/', $hex) !== 1) {
            return null;
        }

        $lower = strtolower($hex);

        return implode(':', str_split($lower, 2));
    }
}
