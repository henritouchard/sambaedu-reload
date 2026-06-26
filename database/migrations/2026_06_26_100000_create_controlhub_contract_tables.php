<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Epic 28 — Story 28.1 : Modèle et persistance du contrat amont (controlHub).
 *
 * Crée les 5 tables du contrat amont reçu depuis controlHub :
 *
 *  - `controlhub_contracts`               — contrat porteur de l'état du lien (active|severed).
 *  - `controlhub_contract_items`          — items imposés {type, clé, valeur, état enforcement, cible instance|label}.
 *  - `controlhub_contract_labels`         — labels {nom, mode libre|réservé}.
 *  - `controlhub_contract_imposed_groups` — groupes imposés {nom, label associé}.
 *  - `controlhub_contract_catalog_apps`   — catalogue applicatif faisant autorité.
 *
 * Portée de cette story : CRÉATION PURE (schéma + modèles). Aucun seeder, aucune ligne par défaut (NFR3).
 * L'ingestion idempotente = Story 28.2 ; le branchement StateCompiler = Story 28.3.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans les noms de table, de colonne ou de contrainte.
 * Préfixe de table imposé : `controlhub_contract_*`. [Source: prd-contrat-manage-se5.md#R3 ; décision Henri 2026-06-26]
 *
 * Les clés naturelles uniques (NFR4) préparent l'upsert idempotent de la Story 28.2.
 * ⚠️ Noms de contrainte courts (< 63 car. PG) passés en 2e argument de unique().
 *
 * Style : cf. 2026_06_18_100000_create_capabilities_table.php (garde hasTable, FK cascade, commentaires).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('controlhub_contracts')) {
            return;
        }

        // ── 1. Contrat racine ───────────────────────────────────────────────────
        Schema::create('controlhub_contracts', function (Blueprint $table): void {
            $table->id();

            // Identifiant neutre de l'autorité amont émettrice.
            // nullable : on peut stocker un contrat local avant d'avoir reçu la référence.
            // unique : une seule ligne par autorité connue (upsert 28.2).
            // ⚠️ JAMAIS le mot « central » — vocabulaire = « authority_ref ».
            $table->string('authority_ref')->nullable()->unique()
                ->comment('Identifiant neutre de l\'autorité amont émettrice — jamais « central »');

            // État du lien : active (opérationnel) | severed (rompu/révoqué).
            // Valeur string castée en ControlHubLinkState côté modèle.
            $table->string('link_state')->default('active')
                ->comment('État du lien : active | severed (ControlHubLinkState)');

            // Horodatage de la dernière réception du contrat (null avant toute réception).
            $table->timestamp('received_at')->nullable()
                ->comment('Horodatage de la dernière réception du contrat depuis l\'autorité amont');

            $table->timestamps();
        });

        // ── 2. Items imposés ────────────────────────────────────────────────────
        Schema::create('controlhub_contract_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('controlhub_contract_id')
                ->constrained('controlhub_contracts')
                ->cascadeOnDelete()
                ->comment('FK vers le contrat parent (cascade suppression)');

            // Vocabulaire d'entité amont : applications, wallpapers, capabilities, shortcuts, agent_tools…
            $table->string('type')
                ->comment('Vocabulaire d\'entité amont : applications, wallpapers, capabilities, shortcuts, agent_tools…');

            // Clé de l'item imposé (ex. identifiant de la capacité, clé de raccourci…).
            $table->string('key')
                ->comment('Clé de l\'item imposé');

            // Valeur portée par l'item — sémantique selon `type` (peut être null = absence).
            $table->text('value')->nullable()
                ->comment('Valeur de l\'item ; sémantique selon type ; null = absence explicite');

            // État d'enforcement : locked | permissive | absent (ControlHubEnforcementState).
            $table->string('enforcement_state')
                ->comment('État d\'enforcement : locked | permissive | absent (ControlHubEnforcementState)');

            // Cible : instance (toute la flotte) | label (postes du label désigné).
            $table->string('target_type')->default('instance')
                ->comment('Cible : instance | label (ControlHubContractTarget)');

            // Nom du label ciblé — renseigné uniquement si target_type = label.
            // ⚠️ NOT NULL DEFAULT '' (PAS nullable) : la chaîne vide '' = « pas de label / cible instance ».
            //    Indispensable à NFR4 — en PG comme en SQLite, NULL est DISTINCT dans un index unique,
            //    donc deux items 'instance' identiques avec target_label=NULL ne collisionneraient JAMAIS
            //    (trou d'idempotence sur le cas dominant). '' rend la clé naturelle effective.
            //    L'ingestion 28.2 normalisera null → '' avant écriture. [Review 28.1 finding #1]
            $table->string('target_label')->default('')
                ->comment('Nom du label ciblé si target_type=label ; \'\' = cible instance (NOT NULL pour NFR4)');

            $table->timestamps();

            // Clé naturelle idempotente (NFR4) — préparation upsert Story 28.2.
            // Nom court (< 63 car. PG).
            $table->unique(
                ['controlhub_contract_id', 'type', 'key', 'target_type', 'target_label'],
                'chc_item_natural_key',
            );
        });

        // ── 3. Labels ───────────────────────────────────────────────────────────
        Schema::create('controlhub_contract_labels', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('controlhub_contract_id')
                ->constrained('controlhub_contracts')
                ->cascadeOnDelete()
                ->comment('FK vers le contrat parent (cascade suppression)');

            // Nom du label (ex. « salle-info », « nomade »).
            $table->string('name')
                ->comment('Nom du label imposé par l\'autorité amont');

            // Mode : free (utilisable librement) | reserved (réservé à l'autorité amont).
            $table->string('mode')
                ->comment('Mode du label : free | reserved (ControlHubLabelMode)');

            $table->timestamps();

            // Clé naturelle : un label par nom par contrat.
            $table->unique(
                ['controlhub_contract_id', 'name'],
                'chc_label_unique',
            );
        });

        // ── 4. Groupes imposés ──────────────────────────────────────────────────
        Schema::create('controlhub_contract_imposed_groups', function (Blueprint $table): void {
            $table->id();

            // Nom de FK court explicite : le nom auto-généré
            // (controlhub_contract_imposed_groups_controlhub_contract_id_foreign) dépasse 63 car.
            // et serait tronqué silencieusement par PG. [Review 28.1 finding #3]
            $table->foreignId('controlhub_contract_id')
                ->constrained('controlhub_contracts', indexName: 'chc_imposed_group_contract_fk')
                ->cascadeOnDelete()
                ->comment('FK vers le contrat parent (cascade suppression)');

            // Nom du WorkstationGroup à garantir (slug, correspond à workstation_groups.name).
            $table->string('name')
                ->comment('Nom du workstationGroup à garantir (correspond à workstation_groups.name)');

            // Nom du label réservé porté par ce groupe (nullable — pas toujours lié à un label réservé).
            // ⚠️ PAS de FK dure vers controlhub_contract_labels : rattachement par nom côté logique amont.
            //    Le mapping groupe↔label local est différé → Epic 30 (Stories 30.x).
            $table->string('label_name')->nullable()
                ->comment('Nom du label réservé associé à ce groupe imposé — rattachement logique, pas FK (Epic 30)');

            $table->timestamps();

            // Clé naturelle : un groupe par nom par contrat.
            $table->unique(
                ['controlhub_contract_id', 'name'],
                'chc_imposed_group_unique',
            );
        });

        // ── 5. Catalogue applicatif ─────────────────────────────────────────────
        Schema::create('controlhub_contract_catalog_apps', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('controlhub_contract_id')
                ->constrained('controlhub_contracts')
                ->cascadeOnDelete()
                ->comment('FK vers le contrat parent (cascade suppression)');

            // Identifiant de l'application faisant autorité (correspond à applications.app_id).
            $table->string('app_key')
                ->comment('Identifiant de l\'app faisant autorité (correspond à applications.app_id)');

            // Nom d'affichage optionnel reçu de l'autorité amont.
            $table->string('display_name')->nullable()
                ->comment('Nom d\'affichage reçu de l\'autorité amont (informatif, nullable)');

            $table->timestamps();

            // Clé naturelle : une app par app_key par contrat.
            $table->unique(
                ['controlhub_contract_id', 'app_key'],
                'chc_catalog_app_unique',
            );
        });
    }

    public function down(): void
    {
        // Ordre inverse pour respecter les FK (enfants avant parent).
        Schema::dropIfExists('controlhub_contract_catalog_apps');
        Schema::dropIfExists('controlhub_contract_imposed_groups');
        Schema::dropIfExists('controlhub_contract_labels');
        Schema::dropIfExists('controlhub_contract_items');
        Schema::dropIfExists('controlhub_contracts');
    }
};
