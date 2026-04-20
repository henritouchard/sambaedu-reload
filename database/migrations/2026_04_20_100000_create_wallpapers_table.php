<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wallpapers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('path');
            $table->enum('type', ['wallpaper', 'lockscreen']);
            $table->nullableMorphs('owner');
            $table->boolean('is_default')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['type', 'owner_type', 'owner_id'], 'wallpapers_owner_type_unique');
        });

        // Index partiel pgsql-only (unsupported SQLite/MySQL via Schema::).
        // Garantit un seul défaut étab par type (post-review #E — skip si
        // driver non-pgsql pour ne pas casser tests SQLite `:memory:`).
        // Fallback applicatif : WallpaperUploadService utilise is_default=true
        // dans son WHERE updateOrCreate pour garantir unicité côté appli.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX wallpapers_default_per_type ON wallpapers (type) '
                . 'WHERE is_default = true AND owner_id IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wallpapers');
    }
};
