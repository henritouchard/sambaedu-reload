<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration pour le système de quotas disque
 * 
 * Tables créées :
 * - quota_rules : Règles de quotas (par utilisateur, groupe ou politique par défaut)
 * - quota_audit_logs : Historique des modifications de quotas
 */
return new class extends Migration {
    public function up(): void
    {
        // =====================================================================
        // QUOTA_RULES - Règles de quotas
        // =====================================================================
        Schema::create('quota_rules', function (Blueprint $table) {
            $table->id();
            
            // Type de règle : user, group, default_eleve, default_prof, default_admin
            $table->string('type', 20)->comment('user, group, default_eleve, default_prof, default_admin');
            
            // Cible : nom d'utilisateur ou de groupe AD (null pour les politiques par défaut)
            $table->string('target', 255)->nullable()->comment('Nom utilisateur ou groupe AD');
            
            // Partition concernée
            $table->string('partition', 50)->comment('/home ou /var/sambaedu');
            
            // Quotas en Mo (0 = illimité)
            $table->unsignedInteger('quota_soft_mb')->default(0)->comment('Quota soft en Mo (0 = illimité)');
            $table->unsignedInteger('quota_hard_mb')->default(0)->comment('Quota hard en Mo (0 = illimité)');
            
            // Statut
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            // Index et contraintes
            $table->unique(['type', 'target', 'partition'], 'quota_rules_unique');
            $table->index('type');
            $table->index('target');
            $table->index('partition');
            $table->index('is_active');
        });

        // =====================================================================
        // QUOTA_AUDIT_LOGS - Historique des modifications
        // =====================================================================
        Schema::create('quota_audit_logs', function (Blueprint $table) {
            $table->id();
            
            // Référence à la règle (nullable car la règle peut être supprimée)
            $table->foreignId('quota_rule_id')->nullable()
                ->constrained('quota_rules')
                ->onDelete('set null');
            
            // Action effectuée
            $table->string('action', 20)->comment('create, update, delete, apply');
            
            // Qui a fait la modification
            $table->string('performed_by', 255)->comment('Utilisateur ayant effectué l\'action');
            
            // Cible de la modification (copie pour historique si règle supprimée)
            $table->string('target_type', 20)->comment('user, group, default_eleve, default_prof, default_admin');
            $table->string('target_name', 255)->nullable()->comment('Nom utilisateur ou groupe');
            $table->string('partition', 50)->comment('/home ou /var/sambaedu');
            
            // Valeurs avant/après
            $table->json('old_values')->nullable()->comment('Valeurs avant modification');
            $table->json('new_values')->nullable()->comment('Valeurs après modification');
            
            // Résultat de l'application sur le filesystem
            $table->boolean('fs_applied')->default(false)->comment('Quota appliqué sur le filesystem');
            $table->text('fs_error')->nullable()->comment('Erreur lors de l\'application');
            
            $table->timestamp('created_at')->useCurrent();
            
            // Index
            $table->index('quota_rule_id');
            $table->index('action');
            $table->index('performed_by');
            $table->index('target_name');
            $table->index('created_at');
        });

        // =====================================================================
        // QUOTA_SETTINGS - Paramètres globaux des quotas
        // =====================================================================
        Schema::create('quota_settings', function (Blueprint $table) {
            $table->id();
            
            // Partition concernée
            $table->string('partition', 50)->unique()->comment('/home ou /var/sambaedu');
            
            // Période de grâce en jours
            $table->unsignedSmallInteger('grace_period_days')->default(7)->comment('Période de grâce en jours');
            
            // Dépassement temporaire autorisé par défaut (en %)
            $table->unsignedSmallInteger('default_overage_percent')->default(20)->comment('Dépassement autorisé par défaut (%)');
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quota_audit_logs');
        Schema::dropIfExists('quota_rules');
        Schema::dropIfExists('quota_settings');
    }
};
