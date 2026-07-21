<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 36.5 (AC8) — capacité de PREUVE + CATALOGUE du mécanisme HORS-REGISTRE
 * `app_profile` : `roaming_app_profile`. La demande fondatrice « retrouver ses
 * signets Firefox sur n'importe quel poste » se projette sur la redirection du
 * profil applicatif vers le home réseau (report du mécanisme SE4
 * `Roaming→Server`, `applications.inc.php:538` — lien de dossier, accès direct
 * serveur SANS copie).
 *
 * SOCLE (pas d'override par maille) : le mécanisme est de portée SESSION et
 * s'applique à TOUTE session user dès que la capacité est ACTIVE et que la
 * politique de fichiers monte le home K: (gate FilePolicyService['home'], AC7) —
 * iso le jeu fixe K:/H: des lecteurs. `value_type=toggle`/`default_value=on`
 * sont cosmétiques : le provider {@see \App\Services\Agent\Providers\AppProfileCapabilityProvider}
 * ne consomme QUE `is_active` (aucune lecture d'assignation/override).
 *
 * CATALOGUE (catalogue-first) = spec JSON de la projection : Firefox +
 * Thunderbird (décision Henri 2026-07-21 — ces deux-là suffisent, le mécanisme
 * reste générique : une 3ᵉ app = une entrée, pas du code). Chaque entrée porte
 * `app`, `link` (relatif au profil Windows), `server` (relatif au home réseau),
 * `profile_name`, `install_hash` (section Firefox `[Install<hash>]` — valeurs SE4
 * des chemins d'install standards) et `cache_local` (dossier de cache épinglé
 * LOCAL sous %LOCALAPPDATA%, AC5 — report du `AppData\Local\cacheFirefox` SE4).
 *
 * Nom de profil `managed.default` : NEUF, STABLE, NON versionné, HORS radical
 * `sambaedu` (AC4, piège n°1) — jamais matché par la garde
 * `referencesSambaeduProfile()` du mécanisme `legacy_cleanup` (38.3). Le
 * garde-fou d'authoring {@see \App\Services\Agent\Providers\AppProfileAuthoringGuard}
 * le VÉRIFIE (radical interdit refusé à la persistance).
 *
 * Pattern iso 36.1/36.2 : `updateOrInsert` par `key` puis par
 * `(capability_id, os, mechanism)`, idempotent, garde `hasTable`, `down()` par
 * suppression de la `key` (FK cascade → projection + assignments). Description
 * ≤ 255 (contrainte varchar PG — sinon migrate /vm casse en 22001, invisible en
 * SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('capabilities') || ! Schema::hasTable('capability_projections')) {
            return;
        }

        $now = now();

        DB::table('capabilities')->updateOrInsert(
            ['key' => 'roaming_app_profile'],
            [
                'label' => 'Profil applicatif itinérant',
                'description' => 'Redirige le profil Firefox/Thunderbird (signets, barre personnelle, '
                    .'préférences) vers le home réseau : suivi inter-postes, accès direct serveur '
                    .'sans copie. Dépend du montage du home K: (politique de fichiers).',
                'category' => 'Bureau',
                'value_type' => 'toggle',
                'options' => null,
                'default_value' => 'on',
                // Dépendance rendue explicite (AC7) : sans le home K: monté, le
                // mécanisme n'émet AUCUN item (rediriger vers une cible non montée
                // n'a pas de sens). Réglé sur /admin/settings/files.
                'warning' => 'Dépend du montage du home réseau K: (politique de gestion des fichiers, '
                    ."/admin/settings/files) : si le home est désactivé, la redirection n'a aucun effet. "
                    .'Le cache est épinglé en local ; les bases (signets, préférences) vivent sur le home réseau.',
                'applies_to_os' => json_encode(['windows'], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'overrides_locked' => false,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $capabilityId = DB::table('capabilities')->where('key', 'roaming_app_profile')->value('id');
        if ($capabilityId === null) {
            return;
        }

        // CATALOGUE Firefox + Thunderbird. `profile_name` = dernier segment de
        // `link` (= `Path=` relatif de profiles.ini). `install_hash` = valeurs SE4
        // des chemins d'install standards (sections `[Install<hash>]`).
        $apps = [
            [
                'app' => 'firefox',
                'link' => 'AppData\\Roaming\\Mozilla\\Firefox\\managed.default',
                'server' => '.mozilla\\firefox\\managed.default',
                'profile_name' => 'managed.default',
                'install_hash' => '308046B0AF4A39CB',
                'cache_local' => 'cacheFirefox',
            ],
            [
                'app' => 'thunderbird',
                'link' => 'AppData\\Roaming\\Thunderbird\\managed.default',
                'server' => '.thunderbird\\Profiles\\managed.default',
                'profile_name' => 'managed.default',
                'install_hash' => 'D78BF5DD33499EC2',
                'cache_local' => 'cacheThunderbird',
            ],
        ];

        DB::table('capability_projections')->updateOrInsert(
            [
                'capability_id' => $capabilityId,
                'os' => 'windows',
                'mechanism' => 'app_profile',
            ],
            [
                'spec' => json_encode(['apps' => $apps], JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('capabilities')) {
            return;
        }

        // FK cascadeOnDelete : supprimer la capacité retire projection + overrides.
        DB::table('capabilities')->where('key', 'roaming_app_profile')->delete();
    }
};
