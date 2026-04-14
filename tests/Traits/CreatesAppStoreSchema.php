<?php

namespace Tests\Traits;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crée le schéma minimum requis par les tests AppStore (depots, applications,
 * depot_applications, installation_logs) sans exécuter les migrations réelles
 * (qui utilisent des features Postgres comme jsonb ou `COMMENT ON TABLE`).
 */
trait CreatesAppStoreSchema
{
    protected function createAppStoreSchema(): void
    {
        if (! Schema::hasTable('depots')) {
            Schema::create('depots', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255);
                $table->string('url', 512);
                $table->boolean('is_primary')->default(false);
                $table->boolean('is_active')->default(true);
                $table->string('xml_hash', 64)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('applications')) {
            Schema::create('applications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('depot_id')->nullable();
                $table->uuid('controlhub_id')->nullable();
                $table->timestamp('controlhub_version')->nullable();
                $table->boolean('managed_by_control_hub')->default(false);
                $table->string('app_id', 255);
                $table->string('name', 255);
                $table->string('version', 100)->nullable();
                $table->string('category', 100)->nullable();
                $table->string('compatibility', 255)->nullable();
                $table->string('branch', 50)->nullable();
                $table->string('status', 50)->nullable();
                $table->string('installed_version', 100)->nullable();
                $table->timestamp('installed_at')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->string('local_xml_path', 512)->nullable();
                $table->string('local_installer_path', 512)->nullable();
                $table->string('installer_url', 512)->nullable();
                $table->string('installer_filename', 255)->nullable();
                $table->bigInteger('installer_size')->nullable();
                $table->text('xml')->nullable();
                $table->string('xml_url', 512)->nullable();
                $table->string('xml_sha', 128)->nullable();
                $table->string('log_url', 512)->nullable();
                $table->text('description')->nullable();
                $table->string('icon_url', 512)->nullable();
                $table->string('author', 255)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('depot_applications')) {
            Schema::create('depot_applications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('depot_id');
                $table->string('app_id');
                $table->string('name');
                $table->string('version')->nullable();
                $table->string('category')->nullable();
                $table->string('compatibility')->nullable();
                $table->string('branch')->nullable()->default('stable');
                $table->text('xml')->nullable();
                $table->string('xml_url')->nullable();
                $table->string('xml_sha', 128)->nullable();
                $table->string('log_url')->nullable();
                $table->text('description')->nullable();
                $table->string('author')->nullable();
                $table->string('icon_url')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamps();

                $table->unique(['depot_id', 'app_id', 'branch'], 'depot_app_branch_unique');
            });
        }

        if (! Schema::hasTable('installation_logs')) {
            Schema::create('installation_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('application_id');
                $table->string('status')->default('pending');
                $table->string('version')->nullable();
                $table->text('message')->nullable();
                $table->integer('progress')->default(0);
                $table->bigInteger('downloaded_bytes')->default(0);
                $table->bigInteger('total_bytes')->default(0);
                $table->string('sha256_computed')->nullable();
                $table->boolean('sha256_verified')->default(false);
                $table->string('initiated_by')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function dropAppStoreSchema(): void
    {
        Schema::dropIfExists('installation_logs');
        Schema::dropIfExists('depot_applications');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('depots');
    }
}
