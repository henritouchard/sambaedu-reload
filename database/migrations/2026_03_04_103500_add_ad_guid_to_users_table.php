<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'ad_guid')) {
                $table->string('ad_guid', 36)->nullable()->after('dn')->comment('objectGUID AD (identifiant immuable)');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('ad_guid', 'users_ad_guid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_ad_guid_unique');

            if (Schema::hasColumn('users', 'ad_guid')) {
                $table->dropColumn('ad_guid');
            }
        });
    }
};
