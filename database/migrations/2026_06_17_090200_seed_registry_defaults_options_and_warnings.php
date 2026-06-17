<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.3ter — migration de DONNÉES (idempotente) : posture par défaut SÛRE
 * (D6) + warnings au déclenchement (D7) + `options` des réglages à choix fermé.
 *
 * IMPORTANT (D1 — diffusion Broadcast) : `registry_settings.value` est désormais
 * la valeur par défaut APPLIQUÉE À TOUTE LA FLOTTE. La posture seedée doit donc
 * être la posture SÛRE, pas « la valeur qu'on voudrait sur un labo » :
 *   - `disable_uac` (EnableLUA) : défaut 0→1 (UAC ACTIVÉ). Diffuser 0 partout =
 *     trou de sécurité + casse menu Démarrer/Paramètres Win10/11 + redémarrage
 *     requis. « Désactiver l'UAC » devient un OVERRIDE de parc délibéré.
 *   - `show_file_extensions` (HideFileExt) : défaut 0 (afficher les extensions —
 *     inchangé, inoffensif).
 *   - `show_hidden_files` (Hidden) : défaut 1 (afficher les fichiers cachés —
 *     inchangé, choix admin acceptable flotte-large).
 *
 * Idempotent : `where('key', …)->update()` ciblé, rejouable. `down()` réversible
 * (remet EnableLUA=0, vide options/warning).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('registry_settings')) {
            return;
        }

        $hasOptions = Schema::hasColumn('registry_settings', 'options');
        $hasWarning = Schema::hasColumn('registry_settings', 'warning');
        $now = now();

        $afficherMasquer = json_encode([
            ['value' => '0', 'label' => 'Afficher'],
            ['value' => '1', 'label' => 'Masquer'],
        ], JSON_UNESCAPED_UNICODE);

        $afficherFichiers = json_encode([
            ['value' => '1', 'label' => 'Afficher'],
            ['value' => '0', 'label' => 'Ne pas afficher'],
        ], JSON_UNESCAPED_UNICODE);

        $activeDesactive = json_encode([
            ['value' => '1', 'label' => 'Activé'],
            ['value' => '0', 'label' => 'Désactivé'],
        ], JSON_UNESCAPED_UNICODE);

        $uacWarning = 'Désactive l\'UAC (contrôle de compte d\'utilisateur) : '
            . 'trou de sécurité (tout processus admin s\'exécute élevé sans invite), '
            . 'casse le menu Démarrer / Paramètres sur Windows 10/11 (applications UWP), '
            . 'et nécessite un redémarrage du poste.';

        // HideFileExt — options Afficher (0) / Masquer (1). Défaut value=0 conservé.
        $this->updateRow('show_file_extensions', [
            'options' => $hasOptions ? $afficherMasquer : null,
        ], $hasOptions, $hasWarning, $now);

        // Hidden — options Afficher (1) / Ne pas afficher (0). Défaut value=1 conservé.
        $this->updateRow('show_hidden_files', [
            'options' => $hasOptions ? $afficherFichiers : null,
        ], $hasOptions, $hasWarning, $now);

        // EnableLUA — D6 : défaut bascule 0→1 (posture sûre). D7 : warning.
        // options Activé (1) / Désactivé (0).
        $this->updateRow('disable_uac', [
            'value' => '1',
            'options' => $hasOptions ? $activeDesactive : null,
            'warning' => $hasWarning ? $uacWarning : null,
        ], $hasOptions, $hasWarning, $now);
    }

    public function down(): void
    {
        if (! Schema::hasTable('registry_settings')) {
            return;
        }

        $hasOptions = Schema::hasColumn('registry_settings', 'options');
        $hasWarning = Schema::hasColumn('registry_settings', 'warning');
        $now = now();

        // EnableLUA — restaure la posture 27.3 (value=0), vide options/warning.
        $this->updateRow('disable_uac', [
            'value' => '0',
            'options' => null,
            'warning' => null,
        ], $hasOptions, $hasWarning, $now);

        foreach (['show_file_extensions', 'show_hidden_files'] as $key) {
            $this->updateRow($key, [
                'options' => null,
            ], $hasOptions, $hasWarning, $now);
        }
    }

    /**
     * Update idempotent ciblé par `key` : ne touche que les colonnes existantes
     * (gardes Schema::hasColumn → no-op si la migration de colonnes n'est pas
     * jouée). Ne crée jamais de ligne (les réglages sont seedés en 27.3).
     *
     * @param  array<string,mixed>  $values
     */
    private function updateRow(string $key, array $values, bool $hasOptions, bool $hasWarning, mixed $now): void
    {
        if (! $hasOptions) {
            unset($values['options']);
        }
        if (! $hasWarning) {
            unset($values['warning']);
        }
        if ($values === []) {
            return;
        }

        DB::table('registry_settings')
            ->where('key', $key)
            ->update(array_merge($values, ['updated_at' => $now]));
    }
};
