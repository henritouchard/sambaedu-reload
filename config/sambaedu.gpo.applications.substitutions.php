<?php

declare(strict_types=1);

/**
 * Whitelist des substitutions `###_KEY_###` autorisées dans les templates
 * de scripts applications.
 *
 * Story 16.7 — décision user D3 (2026-05-12) : CONFIG STATIQUE.
 *
 * Sécurité (audit F3 audit-gpo-legacy adressé) :
 *
 *  - **Whitelist immuable** : seules les clés explicitement listées peuvent
 *    être substituées dans un template `.windows`/`.linux`/`scripts.json`.
 *  - **Aucun input user** (`machine`, `user`, `action`, `uuid`…) n'est
 *    injectable comme clé : la map est strictement statique et lue par
 *    `ApplicationScriptsAssembler::applySubstitutions()`.
 *  - Les placeholders hors whitelist restent **inchangés** dans la sortie
 *    (warning log channel `daily`) → ne casse pas iso-bytes legacy car le
 *    legacy `write_param()` (cf. `traitement_data.inc.php`) ne substituait
 *    que les clés de config présentes.
 *
 * **Référence legacy** : iso `traitement_data.inc.php::write_param()` qui
 * faisait `str_replace("###_" . $key . "_###", $value, $line)` pour chaque
 * `$key` de `$config` — on remplace cet accès dynamique full-config par une
 * whitelist explicite.
 *
 * **Conventions** :
 *  - Les valeurs sont **lazy-résolues** via closures pour permettre le test
 *    et éviter les effets de bord à la lecture du fichier.
 *  - Une clé qui résout à `null` est **ignorée** (no-op au runtime).
 *
 * @legacy-port path="sambaedu/includes/traitement_data.inc.php (write_param)"
 * @see \App\Gpo\Services\ApplicationScriptsAssembler::applySubstitutions
 */
return [
    /**
     * Liste des clés autorisées + résolveur. Chaque résolveur retourne une
     * `string` (valeur de substitution) ou `null` (clé ignorée).
     *
     * @var array<string, callable():?string>
     */
    'whitelist' => [
        // Identifiant DNS du serveur SE4FS (utilisé dans curl URL des
        // scripts cmd/bash, cf. legacy `applications.inc.php:399,405,423`).
        'SE4FS_NAME' => static fn (): ?string => config('sambaedu.se4fs_name')
            ?? env('SE4FS_NAME')
            ?: null,

        // Domaine DNS de l'établissement (suffixe utilisé dans
        // `applications.inc.php:405,426`).
        'DOMAIN' => static fn (): ?string => config('sambaedu.domain')
            ?? env('SE4FS_DOMAIN')
            ?: null,

        // Identifiant établissement (UAI / RNE) — utilisé dans header `cmd`
        // `applications.inc.php:368` (`SET TAG=...`).
        'UAI' => static fn (): ?string => config('sambaedu.uai')
            ?? env('SE4FS_UAI')
            ?: null,

        // Chemin partage NETLOGON (déploiement scripts/exécutables Windows).
        'NETLOGON_PATH' => static fn (): ?string => config('sambaedu.netlogon_path')
            ?? env('NETLOGON_PATH', '/var/lib/samba/sysvol'),

        // URL/base du dépôt WPKG (consommée par `wpkg_scripts` côté legacy).
        'WPKG_URL' => static fn (): ?string => (string) config('sambaedu.wpkg.base_url', ''),

        // Domaine Samba (NetBIOS) — utilisé dans `local_admin_scripts`
        // pour `net localgroup administrateurs <DOMAIN>\<user>`.
        'SAMBA_DOMAIN' => static fn (): ?string => config('sambaedu.samba_domain')
            ?? env('SAMBA_DOMAIN')
            ?: null,

        // Dossier temporaire serveur (parité legacy `sys_get_temp_dir()`).
        'TMP_DIR' => static fn (): string => '/tmp',

        // Nom UI du dossier "Mes Documents" (legacy `applications.inc.php:291`).
        'CLOUD_PERSO_NAME' => static fn (): ?string => config('sambaedu.cloud_perso_name', 'Mes Documents'),
    ],
];
