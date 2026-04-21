<?php

declare(strict_types=1);

namespace App\Services\AppCustomization\Contracts;

/**
 * Contrat d'adapter de politique applicative (story 4.8).
 *
 * Chaque AppKind est servi par une implémentation dédiée qui reproduit
 * exactement la logique de `sambaedu/includes/firefox.inc.php` (fonctions
 * `ff_import_policy`, `tb_import_policy`, `ff_export_policy`).
 *
 * Ordre d'appel typique par `AppCustomizationService::resolvePoliciesForMachine()` :
 *   1. `getTemplate()` — template système (level 1)
 *   2. `applyAuto()`   — injection config proxy/DNS/popups (level 2)
 *   3. `mergeOverrides()` — merge récursif entre niveaux (level 3..6)
 *   4. `exportToFs()` — écriture FS rollback (`/etc/sambaedu/applications/{kind}/*.json`)
 *   5. `validatePolicies()` — whitelist UI admin (appelée par `savePolicies`)
 *   6. `renderFormComponent()` — nom du composant Livewire SFC à rendre
 */
interface AppPolicyAdapter
{
    /**
     * Charge le template système `/usr/share/sambaedu/applications/{kind}/default.json`.
     * Fallback dev `storage/app/app-customizations/{kind}/template.json` toléré.
     *
     * @return array<string,mixed>
     */
    public function getTemplate(): array;

    /**
     * Applique la logique auto : proxy, DNS, popups, préférences système.
     * Reproduit `ff_import_policy` L20-63 (Firefox) ou `tb_import_policy` L213-234
     * (Thunderbird). Ne modifie pas le tableau d'entrée.
     *
     * @param  array<string,mixed>  $template
     * @param  array<string,mixed>  $systemConfig
     * @return array<string,mixed>
     */
    public function applyAuto(array $template, array $systemConfig): array;

    /**
     * Fusion récursive d'overrides sur une base (wrapper `array_replace_recursive`).
     *
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    public function mergeOverrides(array $base, array $overrides): array;

    /**
     * Nom du composant Livewire SFC dédié pour éditer les policies de cet AppKind.
     */
    public function renderFormComponent(): string;

    /**
     * Écrit les policies dans le filesystem (JSON_PRETTY_PRINT).
     * Écriture atomique (tmp + rename) pour éviter lectures partielles côté client.
     *
     * @param  array<string,mixed>  $policies
     * @return bool  true si succès
     */
    public function exportToFs(array $policies, string $path): bool;

    /**
     * Valide les policies en entrée : whitelist stricte des clés `policies.*`
     * éditables via l'UI (Homepage, Bookmarks, ExtensionSettings pour Firefox ;
     * Proxy pour Thunderbird). Retourne un array d'erreurs (vide si OK).
     *
     * Les clés non-whitelistées sont supprimées silencieusement (pas d'erreur
     * de validation — c'est le rôle d'`applyAuto` d'injecter les clés système).
     *
     * @param  array<string,mixed>  $policies
     * @return array<string,string>  map champ → message d'erreur (vide si valide)
     */
    public function validatePolicies(array $policies): array;

    /**
     * Supprime silencieusement les clés hors whitelist avant persistence.
     * Appelé par `AppCustomizationService::savePolicies` avant `validatePolicies`.
     *
     * @param  array<string,mixed>  $policies
     * @return array<string,mixed>
     */
    public function stripNonWhitelistedOverrides(array $policies): array;
}
