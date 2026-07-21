<?php

declare(strict_types=1);

namespace App\Gpo\Support;

/**
 * Résolveur stateless : mappe le `displayName` d'une GPO vers les sections
 * UI natives déjà refondues (Story 16.3a).
 *
 * Classe **stateless** — méthodes statiques pures, aucune dépendance externe.
 * Pas de container Laravel, pas d'I/O, pas d'accès données.
 *
 * Usage :
 * ```php
 * $matches = NativeSectionResolver::resolve($gpo->displayName);
 * // ['profils-itinerants' => ['label' => ..., 'url' => ..., 'icon' => ...], ...]
 *
 * if (NativeSectionResolver::hasMatch($displayName)) { ... }
 *
 * $url = NativeSectionResolver::buildUrl('wallpapers', '{GUID}');
 * // '/app/parc-settings/wallpapers?from_gpo=%7BGUID%7D'
 * ```
 */
final class NativeSectionResolver
{
    /**
     * Mapping heuristique : clé section → patterns (lowercase contains) + URL + libellé + icône.
     *
     * Extrait de `NATIVE_SECTIONS_HEURISTICS` de la SFC détail (Story 16.2, AC2.4 / D9).
     *
     * @var array<string, array{patterns: list<string>, url: string, label: string, icon: string}>
     */
    private const MAPPING = [
        'profils-itinerants' => [
            'patterns' => ['redirections', 'roaming', 'profil', 'no_roam'],
            'url' => '/admin/settings/files?tab=roaming',
            'label' => 'Gérer les profils itinérants nativement',
            'icon' => 'fa-users-gear',
        ],
        'wallpapers' => [
            'patterns' => ['wallpaper', 'fond-ecran', 'fond_ecran', 'lockscreen'],
            'url' => '/app/parc-settings/wallpapers',
            'label' => 'Gérer les fonds d\'écran',
            'icon' => 'fa-image',
        ],
        'app-customizations' => [
            'patterns' => ['firefox', 'thunderbird', 'app-custom', 'applications'],
            'url' => '/app/parc-settings/app-customizations',
            'label' => 'Personnaliser les applications',
            'icon' => 'fa-puzzle-piece',
        ],
        'shortcuts' => [
            'patterns' => ['shortcut', 'raccourci'],
            'url' => '/app/parc-settings?tab=shortcuts',
            'label' => 'Gérer les raccourcis',
            'icon' => 'fa-link',
        ],
        // Story 16.3c — UI admin native Wine (apps Windows sur postes Linux).
        // Decision SM D10. Pattern `wine` substring match — cohérence avec les
        // entrées existantes (firefox/thunderbird matchent aussi substring).
        // Risque marginal de faux positif sur GPO `wineries` (très peu probable
        // sur un parc SE4FS) — accepté.
        'wine' => [
            'patterns' => ['wine'],
            'url' => '/admin/settings/gpo/wine',
            'label' => 'Gérer les apps Wine (Linux/Windows)',
            'icon' => 'fa-wine-glass',
        ],
    ];

    /**
     * Résout les sections natives qui correspondent au displayName donné.
     *
     * Matching case-insensitive (substring) sur les patterns de chaque section.
     * Retourne un tableau vide si `$displayName` est vide ou qu'aucun pattern ne matche.
     *
     * @param  string  $displayName  Nom de la GPO (peut être vide — retourne [] proprement).
     * @return array<string, array{patterns: list<string>, url: string, label: string, icon: string}>
     *                               Clé = identifiant section, valeur = mapping complet.
     */
    public static function resolve(string $displayName): array
    {
        if ($displayName === '') {
            return [];
        }

        $lower = strtolower($displayName);
        $matches = [];

        foreach (self::MAPPING as $key => $section) {
            foreach ($section['patterns'] as $pattern) {
                if (str_contains($lower, $pattern)) {
                    $matches[$key] = $section;
                    break; // un seul pattern suffit par section
                }
            }
        }

        return $matches;
    }

    /**
     * Indique si au moins une section native matche le displayName.
     *
     * Helper sémantique — équivalent à `count(resolve($displayName)) > 0`.
     *
     * @param  string  $displayName  Nom de la GPO.
     */
    public static function hasMatch(string $displayName): bool
    {
        return count(self::resolve($displayName)) > 0;
    }

    /**
     * Construit l'URL de navigation vers une section native, avec ou sans
     * paramètre `?from_gpo={guid}` pour le breadcrumb de retour.
     *
     * Si l'URL de base contient déjà un `?` (cas `profils-itinerants` →
     * `/admin/settings/files?tab=roaming`), le paramètre est ajouté avec `&`
     * et non `?`.
     *
     * Si `$fromGpoGuid` est null ou vide, retourne l'URL sans paramètre.
     *
     * @param  string       $sectionKey   Clé de section (ex. `wallpapers`, `profils-itinerants`).
     * @param  string|null  $fromGpoGuid  GUID de la GPO source (ex. `{AAAA...EEEE}`).
     * @return string URL complète (absolue depuis la racine).
     * @throws \InvalidArgumentException Si la clé de section n'existe pas dans MAPPING.
     */
    public static function buildUrl(string $sectionKey, ?string $fromGpoGuid = null): string
    {
        if (!array_key_exists($sectionKey, self::MAPPING)) {
            throw new \InvalidArgumentException(
                "Section inconnue : « {$sectionKey} ». Clés valides : " . implode(', ', array_keys(self::MAPPING))
            );
        }

        $baseUrl = self::MAPPING[$sectionKey]['url'];

        if ($fromGpoGuid === null || $fromGpoGuid === '') {
            return $baseUrl;
        }

        // Détecter si l'URL de base contient déjà un paramètre de requête.
        // Ex. '/admin/settings/files?tab=roaming' → utiliser '&'.
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . 'from_gpo=' . rawurlencode($fromGpoGuid);
    }
}
