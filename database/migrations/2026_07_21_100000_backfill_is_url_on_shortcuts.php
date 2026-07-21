<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renseigne `shortcuts.is_url`, colonne présente depuis
 * `2026_02_13_180000_add_new_fields_to_shortcuts` mais jamais alimentée par
 * l'UI locale : le type « site web » était jusqu'ici DÉDUIT à la volée par
 * `Shortcut::isUrlShortcut()` (`windows_args` commençant par `http`).
 *
 * Le backfill couvre AUSSI `windows_link` : le formulaire n'offrant pas de
 * choix de type, l'URL a souvent été saisie dans le champ « Exécutable »
 * plutôt que dans « Arguments » — ces raccourcis étaient donc classés
 * « Application » à tort.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('shortcuts')
            ->where(function ($q) {
                $q->where('windows_args', 'LIKE', 'http://%')
                    ->orWhere('windows_args', 'LIKE', 'https://%')
                    ->orWhere('windows_link', 'LIKE', 'http://%')
                    ->orWhere('windows_link', 'LIKE', 'https://%');
            })
            ->update(['is_url' => true]);
    }

    public function down(): void
    {
        // `is_url` valait `false` partout avant ce backfill (défaut de la
        // colonne, jamais écrite par l'application).
        DB::table('shortcuts')->update(['is_url' => false]);
    }
};
