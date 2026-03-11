<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration pour le système de gestion des droits SambaEdu 4.6
 * 
 * Tables créées :
 * - users : Utilisateurs (cache SQL des utilisateurs AD, future source de vérité)
 * - user_groups : Groupes d'utilisateurs (classes, équipes, etc.)
 * - user_group_user : Pivot user_group ↔ user
 * - delegations : Délégations de droits scopées par WorkstationGroup physique
 */
return new class extends Migration {
    public function up(): void
    {
        // =====================================================================
        // USERS - Utilisateurs
        // =====================================================================
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('login', 255)->unique()->comment('samAccountName AD');
            $table->string('password', 255)->nullable()->comment('Hash bcrypt (NULL = auth via AD)');
            $table->string('fullname', 255)->nullable();
            $table->string('firstname', 255)->nullable();
            $table->string('lastname', 255)->nullable();
            $table->string('email', 255)->nullable();
            $table->text('dn')->nullable()->comment('DN complet dans l\'AD (sync proxy)');
            $table->string('role', 50)->default('autre')->comment('eleve, prof, admin, autre');
            $table->boolean('is_active')->default(true);

            // Colonnes de sync AD (transitoires, à supprimer quand SQL = source de vérité)
            $table->jsonb('ad_groups')->nullable()->comment('Groupes AD (memberOf)');
            $table->jsonb('ad_right_profiles')->nullable()->comment('Profils de droits AD');
            $table->unsignedInteger('ad_rights_bitmask')->default(0)->comment('Bitmask legacy calculé');
            $table->timestamp('ad_synced_at')->nullable()->comment('Dernière sync depuis AD');

            $table->rememberToken();
            $table->timestamps();

            // Index
            $table->index('role');
            $table->index('is_active');
        });

        // =====================================================================
        // USER_GROUPS - Groupes d'utilisateurs
        // =====================================================================
        Schema::create('user_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->unique()->comment('Nom du groupe');
            $table->string('display_name', 255)->nullable();
            $table->string('type', 50)->comment('class, team, admin, custom');
            $table->text('ad_dn')->nullable()->comment('DN dans l\'AD (sync proxy)');
            $table->timestamps();

            // Index
            $table->index('type');
        });

        // =====================================================================
        // USER_GROUP_USER - Pivot user_group ↔ user
        // =====================================================================
        Schema::create('user_group_user', function (Blueprint $table) {
            $table->foreignId('user_group_id')
                ->constrained('user_groups')
                ->onDelete('cascade');
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');

            $table->primary(['user_group_id', 'user_id']);
        });

        // =====================================================================
        // DELEGATIONS - Délégations de droits scopées par WorkstationGroup
        // =====================================================================
        Schema::create('delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');
            $table->foreignId('workstation_group_id')
                ->constrained('workstation_groups')
                ->onDelete('cascade')
                ->comment('FK vers workstation_groups (physiques)');
            $table->unsignedBigInteger('permission_id')
                ->comment('FK vers spatie permissions');
            $table->boolean('is_negative')->default(false)
                ->comment('Délégation d\'exclusion (no_)');
            $table->foreignId('granted_by')->nullable()
                ->constrained('users')
                ->onDelete('set null')
                ->comment('Utilisateur ayant accordé la délégation');
            $table->timestamp('expires_at')->nullable()
                ->comment('Expiration optionnelle');
            $table->timestamps();

            // FK vers spatie permissions
            $table->foreign('permission_id')
                ->references('id')
                ->on('permissions')
                ->onDelete('cascade');

            // Contrainte d'unicité
            $table->unique(
                ['user_id', 'workstation_group_id', 'permission_id', 'is_negative'],
                'delegations_unique'
            );

            // Index
            $table->index('workstation_group_id');
            $table->index('permission_id');
            $table->index('is_negative');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations');
        Schema::dropIfExists('user_group_user');
        Schema::dropIfExists('user_groups');
        Schema::dropIfExists('users');
    }
};
