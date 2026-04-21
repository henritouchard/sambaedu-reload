<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration — table `app_customizations` (story 4.8).
 *
 * Pattern polymorphe hérité de `wallpapers` (4.7) : `customizable_type` /
 * `customizable_id` nullable pour représenter le scope "global établissement"
 * via `(NULL, NULL, is_default=true)`.
 *
 * Clé composite `(app_kind, customizable_type, customizable_id)` pour lookup
 * par scope.
 *
 * Index partiel pgsql-only `app_customizations_default_per_kind` garantissant
 * un unique enregistrement default par AppKind (wrappé `pgsql` seulement —
 * fallback SQLite `:memory:` + applicatif dans le service via
 * updateOrCreate sur `(app_kind, NULL, NULL)`).
 */
return new class extends Migration {
    public function up(): void
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        Schema::create('app_customizations', function (Blueprint $table) use ($isPgsql): void {
            $table->id();
            $table->string('app_kind', 32);
            $table->nullableMorphs('customizable');
            if ($isPgsql) {
                $table->jsonb('policies_json');
            } else {
                $table->json('policies_json');
            }
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['app_kind', 'customizable_type', 'customizable_id'],
                'app_customizations_scope_index',
            );
        });

        // Index partiel pgsql-only : un seul default global par AppKind.
        // Sur SQLite :memory: / MySQL, la contrainte est garantie en applicatif
        // par AppCustomizationService::savePolicies via updateOrCreate sur
        // (app_kind, NULL, NULL).
        if ($isPgsql) {
            DB::statement(
                'CREATE UNIQUE INDEX app_customizations_default_per_kind '
                . 'ON app_customizations (app_kind) '
                . 'WHERE is_default = true AND customizable_id IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_customizations');
    }
};
