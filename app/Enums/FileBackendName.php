<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Story 60.3 — VOCABULAIRE de la colonne `network_shares.backend` : qui est
 * l'autorité d'écriture des droits d'un partage.
 *
 * Enum FERMÉE, et c'est le point. Une valeur hors vocabulaire n'est jamais
 * ramenée à un défaut : elle fait échouer la résolution en nommant ce qui était
 * attendu ({@see \App\Exceptions\Filesystem\UnknownFileBackendException}). Un
 * repli silencieux sur `posix` écrirait des permissions POSIX pour un partage que
 * l'administrateur croit hébergé ailleurs — la pire forme du défaut que cet epic
 * combat : le signal qui n'atteint pas son destinataire.
 *
 * **Deux cases, et pas trois.** `nextcloud` et `nextcloud_delegue` arriveront PAR
 * CODE en Epic 61 (D6 : l'adaptateur est natif, par produit ; le runtime, lui,
 * est une extension) — jamais par configuration. Le squelette Nextcloud jetable
 * de cette story vit sous `tests/Integration/` et n'a, structurellement, aucune
 * valeur de colonne pour être choisi : un test l'épingle
 * ({@see \Tests\Unit\Enums\FileBackendNameTest}).
 *
 * **Ce que cette colonne achète** (décision Q-D, 2026-08-04) : POSIX est conservé
 * et deviendra retirable. Le jour venu, le retirer sera basculer cette valeur et
 * lancer une migration explicite (D9), pas réécrire le domaine.
 *
 * Cases PascalCase, valeurs snake_case (convention maison).
 */
enum FileBackendName: string
{
    /**
     * L'autorité historique : permissions POSIX + partage SMB. VALEUR LÉGITIME
     * DE COLONNE dès cette story (c'est ce que sont tous les partages existants),
     * mais SANS implémentation ici — la descente de l'exécution sous la ligne de
     * contrat est la story 60.4.
     */
    case Posix = 'posix';

    /**
     * Le backend qui n'exécute rien et le DIT. Il sert l'aperçu avant
     * application, et il est la seconde implémentation qui prouve que le contrat
     * n'est pas POSIX déguisé.
     *
     * Il ne prouve pas, à lui seul, que le contrat est bon : n'exécutant rien, il
     * satisfait n'importe quel contrat (D3). C'est le double propagateur des
     * tests et le squelette Nextcloud jetable qui portent cette preuve-là.
     */
    case Preview = 'preview';

    /** Libellé FR — aucune valeur technique brute à l'écran (iso 42.3 D1). */
    public function label(): string
    {
        return match ($this) {
            self::Posix => 'Serveur de fichiers (POSIX/SMB)',
            self::Preview => 'Aperçu (aucune exécution)',
        };
    }

    /**
     * Phrase courte destinée à l'infobulle du badge : ce que le choix de backend
     * change POUR L'UTILISATEUR (le chemin d'accès), pas comment il est écrit.
     */
    public function description(): string
    {
        return match ($this) {
            self::Posix => 'Les droits sont posés sur le serveur de fichiers et le lecteur se monte en SMB.',
            self::Preview => "Aucun droit n'est posé : ce backend sert uniquement à prévisualiser un plan.",
        };
    }

    /** `true` si la valeur brute appartient au vocabulaire fermé. */
    public static function isKnown(mixed $value): bool
    {
        return is_string($value) && self::tryFrom($value) !== null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
