<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bibliothèque de wallpapers — découple le fichier image de son assignation.
 *
 * Une ligne = UN fichier image physique (dédupliqué par `checksum`), stocké
 * sous `config('wallpapers.library_path')` (storage/, sauvegardable). Les
 * lignes `wallpapers` deviennent des assignations (owner → asset_id) qui
 * référencent ces assets — un même asset peut être assigné à plusieurs
 * parcs/users sans dupliquer le fichier.
 *
 * Remplace le couplage implicite par convention de nom `<type>@<key>.jpg`
 * (qui produisait des « wallpapers fantômes » résolus par nom de fichier).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wallpaper_assets', function (Blueprint $table): void {
            $table->id();
            // Nom du fichier dans la bibliothèque (content-addressé : <checksum>.<ext>).
            $table->string('filename')->unique();
            // Nom d'origine à l'upload (affichage UI).
            $table->string('original_name')->nullable();
            // sha256 du contenu — clé de déduplication.
            $table->string('checksum', 64)->unique();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallpaper_assets');
    }
};
