<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajout du dépôt manuel d'ISO (upload chunké) à la gestion ISO Windows
 * (page admin `/admin/ipxe/iso-windows`).
 *
 * - `source` : 'url' (téléchargement curl serveur depuis Microsoft) ou
 *   'upload' (fichier déposé par l'admin via uploader chunké). Défaut 'url'
 *   pour préserver la sémantique des rows existantes (toutes issues du flux
 *   URL livré en story 3.6).
 * - `source_url` devient nullable : un dépôt manuel n'a pas d'URL source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('windows_iso_downloads', function (Blueprint $table): void {
            $table->string('source', 10)->default('url')->after('source_url');
            $table->string('source_url', 2048)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('windows_iso_downloads', function (Blueprint $table): void {
            $table->dropColumn('source');
            // Best-effort : on ne re-force pas NOT NULL (des rows upload
            // pourraient avoir source_url=null). On laisse nullable.
        });
    }
};
