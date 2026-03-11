<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration unifiée pour le nouveau schéma PostgreSQL SE4FS
 * 
 * Ce schéma remplace toutes les anciennes migrations et crée une base propre.
 * Tables créées :
 * - workstations : Postes de travail
 * - workstation_groups : Groupes/Salles de machines
 * - workstation_group_workstation : Pivot groupe ↔ poste
 * - depots : Dépôts d'applications WPKG
 * - applications : Applications WPKG
 * - app_profiles : Profils applicatifs
 * - app_profile_application : Pivot profil ↔ application
 * - app_profile_workstation_group : Pivot profil ↔ groupe
 * - app_profile_workstation : Pivot profil ↔ poste
 * - shortcuts : Raccourcis (bureau, démarrage, barre des tâches)
 * - controlhub_connection : Connexion au ControlHub
 * - controlhub_tasks : Tâches reçues du ControlHub
 * - jobs : File d'attente Laravel
 * - failed_jobs : Jobs échoués
 */
return new class extends Migration {
    public function up(): void
    {
        // =====================================================================
        // WORKSTATIONS - Postes de travail
        // =====================================================================
        Schema::create('workstations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique()->comment('Nom du poste (hostname)');
            $table->string('os', 100)->nullable()->comment('Système d\'exploitation');
            $table->string('ip', 45)->nullable()->comment('Adresse IP');
            $table->string('mac', 17)->nullable()->comment('Adresse MAC');
            $table->uuid('uuid')->nullable()->comment('UUID matériel');
            $table->string('status', 20)->default('active')->comment('active, inactive, protected');
            
            // Rapports et logs
            $table->timestamp('last_report_at')->nullable()->comment('Date du dernier rapport');
            $table->string('report_sha', 64)->nullable()->comment('Hash SHA du dernier rapport');
            $table->text('log_path')->nullable()->comment('Chemin du fichier log');
            $table->text('report_path')->nullable()->comment('Chemin du fichier rapport');
            
            // Salle physique (relation 1:N)
            $table->foreignId('physical_room_id')->nullable()
                ->comment('Salle physique où se trouve la machine');
            
            // Synchronisation AD
            $table->string('ad_dn', 512)->nullable()->comment('Distinguished Name dans AD');
            $table->string('ad_guid', 36)->nullable()->comment('objectGUID dans AD');
            
            // Gestion
            $table->boolean('managed_by_control_hub')->default(false);
            
            $table->timestamps();
            
            // Index
            $table->index('status');
            $table->index('physical_room_id');
            $table->index('ad_guid');
            $table->index('ip');
            $table->index('mac');
        });

        // =====================================================================
        // WORKSTATION_GROUPS - Groupes/Salles de machines
        // =====================================================================
        Schema::create('workstation_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique()->comment('Identifiant unique (slug)');
            $table->string('display_name', 255)->nullable()->comment('Nom d\'affichage');
            $table->text('description')->nullable();
            
            // Hiérarchie
            $table->foreignId('parent_id')->nullable()
                ->constrained('workstation_groups')
                ->onDelete('set null');
            
            // Type de groupe
            $table->boolean('is_physical_room')->default(false)
                ->comment('True = salle physique (OU AD), False = groupe logique (CN AD)');
            
            // Synchronisation AD
            $table->string('ad_dn', 512)->nullable()->comment('Distinguished Name dans AD');
            $table->string('ad_guid', 36)->nullable()->comment('objectGUID dans AD');
            
            // Statut et gestion
            $table->boolean('is_active')->default(true);
            $table->boolean('managed_by_control_hub')->default(false);
            
            $table->timestamps();
            
            // Index
            $table->index('parent_id');
            $table->index('is_physical_room');
            $table->index('is_active');
            $table->index('ad_guid');
        });

        // Ajouter la FK physical_room_id après création de workstation_groups
        Schema::table('workstations', function (Blueprint $table) {
            $table->foreign('physical_room_id')
                ->references('id')
                ->on('workstation_groups')
                ->onDelete('set null');
        });

        // =====================================================================
        // WORKSTATION_GROUP_WORKSTATION - Pivot groupe ↔ poste
        // =====================================================================
        Schema::create('workstation_group_workstation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workstation_group_id')
                ->constrained('workstation_groups')
                ->onDelete('cascade');
            $table->foreignId('workstation_id')
                ->constrained('workstations')
                ->onDelete('cascade');
            $table->timestamps();

            $table->unique(['workstation_group_id', 'workstation_id'], 'wg_ws_unique');
        });

        // =====================================================================
        // DEPOTS - Dépôts d'applications WPKG
        // =====================================================================
        Schema::create('depots', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->comment('Nom du dépôt');
            $table->string('url', 512)->comment('URL du dépôt');
            $table->boolean('is_primary')->default(false)->comment('Dépôt principal');
            $table->boolean('is_active')->default(true)->comment('Dépôt actif');
            $table->string('xml_hash', 64)->nullable()->comment('Hash du fichier XML');
            $table->timestamps();

            $table->index('is_active');
            $table->index('is_primary');
        });

        // =====================================================================
        // APPLICATIONS - Applications WPKG
        // =====================================================================
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')
                ->constrained('depots')
                ->onDelete('cascade');
            
            $table->string('app_id', 255)->comment('Identifiant technique de l\'application');
            $table->string('name', 255)->comment('Nom d\'affichage');
            $table->string('version', 100)->nullable();
            $table->string('category', 100)->nullable();
            $table->string('compatibility', 255)->nullable()->comment('Compatibilité OS');
            $table->string('branch', 50)->nullable()->comment('Branche (stable, testing, etc.)');
            
            // XML et métadonnées
            $table->text('xml')->nullable()->comment('Contenu XML de l\'application');
            $table->string('xml_url', 512)->nullable();
            $table->string('xml_sha', 64)->nullable();
            $table->string('log_url', 512)->nullable();
            
            $table->timestamps();

            $table->unique(['depot_id', 'app_id']);
            $table->index('category');
            $table->index('branch');
        });

        // =====================================================================
        // APP_PROFILES - Profils applicatifs
        // =====================================================================
        Schema::create('app_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique()->comment('Identifiant unique');
            $table->string('display_name', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('ad_guid', 36)->nullable()->comment('GUID dans AD après synchronisation');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        // =====================================================================
        // APP_PROFILE_APPLICATION - Pivot profil ↔ application
        // =====================================================================
        Schema::create('app_profile_application', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_profile_id')
                ->constrained('app_profiles')
                ->onDelete('cascade');
            $table->foreignId('application_id')
                ->constrained('applications')
                ->onDelete('cascade');
            $table->timestamps();

            $table->unique(['app_profile_id', 'application_id'], 'app_profile_app_unique');
        });

        // =====================================================================
        // APP_PROFILE_WORKSTATION_GROUP - Pivot profil ↔ groupe
        // =====================================================================
        Schema::create('app_profile_workstation_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_profile_id')
                ->constrained('app_profiles')
                ->onDelete('cascade');
            $table->foreignId('workstation_group_id')
                ->constrained('workstation_groups')
                ->onDelete('cascade');
            $table->timestamps();

            $table->unique(['app_profile_id', 'workstation_group_id'], 'app_profile_wg_unique');
        });

        // =====================================================================
        // APP_PROFILE_WORKSTATION - Pivot profil ↔ poste
        // =====================================================================
        Schema::create('app_profile_workstation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_profile_id')
                ->constrained('app_profiles')
                ->onDelete('cascade');
            $table->foreignId('workstation_id')
                ->constrained('workstations')
                ->onDelete('cascade');
            $table->timestamps();

            $table->unique(['app_profile_id', 'workstation_id'], 'app_profile_ws_unique');
        });

        // =====================================================================
        // SHORTCUTS - Raccourcis
        // =====================================================================
        Schema::create('shortcuts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique()->comment('Clé unique du raccourci');
            $table->string('name', 255)->comment('Nom d\'affichage');
            $table->string('owner', 512)->nullable()->comment('Propriétaires (groupes AD, séparés par virgules)');
            $table->string('place', 20)->default('desktop')->comment('desktop, startup, taskbar');
            $table->boolean('is_global')->default(false)->comment('Géré par ControlHub');
            
            // Configuration Windows
            $table->string('windows_link', 512)->nullable()->comment('Chemin de l\'exécutable Windows');
            $table->text('windows_args')->nullable()->comment('Arguments Windows');
            $table->string('windows_path', 512)->nullable()->comment('Répertoire de travail Windows');
            $table->string('windows_icon', 512)->nullable()->comment('Chemin de l\'icône Windows');
            
            // Configuration Linux
            $table->string('linux_link', 512)->nullable()->comment('Chemin de l\'exécutable Linux');
            $table->text('linux_args')->nullable()->comment('Arguments Linux');
            $table->string('linux_path', 512)->nullable()->comment('Répertoire de travail Linux');
            $table->string('linux_startupwmclass', 255)->nullable()->comment('StartupWMClass Linux');
            
            // Icône stockée
            $table->string('icon_path', 512)->nullable()->comment('Chemin de l\'icône uploadée');
            
            $table->timestamps();

            $table->index('place');
            $table->index('is_global');
            $table->index('owner');
        });

        // =====================================================================
        // CONTROLHUB_CONNECTION - Connexion au ControlHub
        // =====================================================================
        Schema::create('controlhub_connection', function (Blueprint $table) {
            $table->id();
            $table->string('base_url', 512)->comment('URL de base du ControlHub');
            $table->text('api_token')->comment('Token pour appeler les APIs ControlHub (chiffré)');
            $table->string('se4fs_api_token', 64)->comment('Token pour valider les appels de ControlHub vers SE4FS');
            
            // Configuration heartbeat
            $table->integer('heartbeat_interval')->default(300)->comment('Intervalle en secondes');
            $table->boolean('heartbeat_enabled')->default(true);
            $table->integer('heartbeat_failures')->default(0);
            
            // Statut
            $table->string('status', 20)->default('unknown')->comment('online, offline, error');
            $table->string('error_type', 100)->nullable();
            
            // Métadonnées
            $table->timestamp('last_handshake_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();

            $table->index('is_active');
        });

        // =====================================================================
        // CONTROLHUB_TASKS - Tâches reçues du ControlHub
        // =====================================================================
        Schema::create('controlhub_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('controlhub_task_id')->unique()->comment('ID de la tâche côté ControlHub');
            $table->string('name', 255);
            $table->string('type', 100)->comment('Type de tâche (create_shortcut, delete_shortcut, etc.)');
            $table->jsonb('payload')->nullable();
            $table->string('status', 20)->default('received')
                ->comment('received, queued, in_progress, success, failed, canceled');
            $table->jsonb('result')->nullable();
            $table->text('error_message')->nullable();
            
            // Planification
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            
            // Callback
            $table->boolean('callback_sent')->default(false);
            $table->timestamp('callback_sent_at')->nullable();
            $table->jsonb('callback_response')->nullable();
            $table->text('callback_error')->nullable();
            
            $table->timestamps();

            $table->index('status');
            $table->index('type');
            $table->index('callback_sent');
        });

        // =====================================================================
        // JOBS - File d'attente Laravel
        // =====================================================================
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->text('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->text('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->text('payload');
            $table->text('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        // =====================================================================
        // CACHE - Table de cache Laravel (optionnel)
        // =====================================================================
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        // =====================================================================
        // SESSIONS - Sessions Laravel (optionnel)
        // =====================================================================
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('controlhub_tasks');
        Schema::dropIfExists('controlhub_connection');
        Schema::dropIfExists('shortcuts');
        Schema::dropIfExists('app_profile_workstation');
        Schema::dropIfExists('app_profile_workstation_group');
        Schema::dropIfExists('app_profile_application');
        Schema::dropIfExists('app_profiles');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('depots');
        Schema::dropIfExists('workstation_group_workstation');
        
        // Supprimer la FK avant de supprimer la table
        Schema::table('workstations', function (Blueprint $table) {
            $table->dropForeign(['physical_room_id']);
        });
        
        Schema::dropIfExists('workstation_groups');
        Schema::dropIfExists('workstations');
    }
};
