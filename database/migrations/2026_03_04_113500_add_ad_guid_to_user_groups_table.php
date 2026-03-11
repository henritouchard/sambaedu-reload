<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_groups', function (Blueprint $table) {
            if (!Schema::hasColumn('user_groups', 'ad_guid')) {
                $table->string('ad_guid', 36)
                    ->nullable()
                    ->after('ad_dn')
                    ->comment('objectGUID AD (identifiant immuable)');
            }
        });

        Schema::table('user_groups', function (Blueprint $table) {
            $table->unique('ad_guid', 'user_groups_ad_guid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('user_groups', function (Blueprint $table) {
            $table->dropUnique('user_groups_ad_guid_unique');

            if (Schema::hasColumn('user_groups', 'ad_guid')) {
                $table->dropColumn('ad_guid');
            }
        });
    }
};
