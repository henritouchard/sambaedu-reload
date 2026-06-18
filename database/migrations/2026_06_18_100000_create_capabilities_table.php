<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Epic 27 — Repensée « registre » en gestion de CAPACITÉS/options données aux
 * postes (décision 2026-06-17, option 2 « capability-first »).
 *
 * Une `capability` est une **intention métier OS-agnostique** que l'admin
 * manipule (« Afficher les extensions de fichiers », « MAJ Windows gérées »,
 * « Bureau à distance »…). La manière dont elle se MATÉRIALISE sur un poste
 * (clé de registre, règle de pare-feu, membership de groupe…) est portée par les
 * {@see capability_projections} (mécanisme, caché à l'admin). L'override de
 * VALEUR par maille vit dans {@see capability_assignments}.
 *
 * Invariant central conservé de 27.3 : le `key`/`id` de la capacité ne fuite
 * JAMAIS au payload du contrat. Le compilateur expanse capacité → items
 * `{type, semantics, payload, hash}` ; le `StateCompiler` et le handler agent
 * restent INCHANGÉS (le rewrite est borné à l'authoring/compilation).
 *
 * Remplace `registry_settings` comme source d'autorité (le registre devient une
 * projection parmi d'autres). La migration de données des 3 réglages existants
 * vers des capacités se fait dans un seeder dédié.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('capabilities')) {
            return;
        }

        Schema::create('capabilities', function (Blueprint $table) {
            $table->id();

            // Identité de CATALOGUE — JAMAIS émise au payload (invariant central).
            $table->string('key')->unique()->comment('Clé technique unique de la capacité — jamais émise au payload contrat');
            $table->string('label')->comment('Libellé affichable UI (intention métier)');
            $table->string('description')->nullable()->comment('Aide courte UI');
            $table->string('category')->nullable()->comment('Regroupement UI (ex. Sécurité, Bureau, Mises à jour)');

            // Modèle de valeur — pilote l'UI/validation, PAS le compilateur (qui
            // ne fait que mapper une valeur effective → données via la projection).
            $table->string('value_type', 16)->default('toggle')->comment('toggle | enum | scalar');
            $table->json('options')->nullable()->comment('Choix fermés [{value,label}] (value_type=enum) ; null = toggle/saisie libre');

            // Défaut DIFFUSÉ (Broadcast, 27.3ter) : valeur effective de la capacité
            // appliquée à toute la flotte sauf override de maille.
            $table->text('default_value')->comment('Valeur par défaut diffusée (Broadcast) — texte ; sémantique selon value_type');

            // Métadonnées d'intention (vivent sur la capacité, pas la projection).
            $table->text('warning')->nullable()->comment('Message d\'implications à confirmer au déclenchement (null = pas d\'encart)');
            $table->json('applies_to_os')->comment('OS où la capacité a du sens, ex. ["windows"] — doit rester cohérent avec les projections');

            $table->boolean('is_active')->default(true)->comment('Capacité proposée/diffusée dans le catalogue');
            // 27.3ter — gelé = plus de NOUVEAUX overrides (la diffusion du défaut
            // ne change pas ; les overrides existants restent éditables).
            $table->boolean('overrides_locked')->default(false)->comment('Gèle l\'ajout de nouveaux overrides (diffusion inchangée)');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capabilities');
    }
};
