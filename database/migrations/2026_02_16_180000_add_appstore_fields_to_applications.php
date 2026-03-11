<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('status')->default('available')->after('log_url');
            $table->string('installed_version')->nullable()->after('status');
            $table->string('installer_url')->nullable()->after('installed_version');
            $table->string('installer_sha256')->nullable()->after('installer_url');
            $table->string('installer_filename')->nullable()->after('installer_sha256');
            $table->bigInteger('installer_size')->nullable()->after('installer_filename');
            $table->string('local_xml_path')->nullable()->after('installer_size');
            $table->string('local_installer_path')->nullable()->after('local_xml_path');
            $table->text('description')->nullable()->after('local_installer_path');
            $table->string('icon_url')->nullable()->after('description');
            $table->string('author')->nullable()->after('icon_url');
            $table->timestamp('installed_at')->nullable()->after('author');
            $table->timestamp('last_checked_at')->nullable()->after('installed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'installed_version',
                'installer_url',
                'installer_sha256',
                'installer_filename',
                'installer_size',
                'local_xml_path',
                'local_installer_path',
                'description',
                'icon_url',
                'author',
                'installed_at',
                'last_checked_at',
            ]);
        });
    }
};
