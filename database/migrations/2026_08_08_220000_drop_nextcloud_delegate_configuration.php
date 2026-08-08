<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recadrage du 2026-08-08 — LE MODE « INSTANCE NON ADMINISTRÉE » EST SUPPRIMÉ.
 *
 * ---------------------------------------------------------------------------
 * **POURQUOI.** Mesuré contre une instance réelle, un compte Nextcloud ORDINAIRE
 * refuse de créer un dossier d'équipe (OCS 403 dans un corps HTTP 200), refuse de
 * créer un groupe, et son partage visant un groupe échoue. Sans dossier d'équipe,
 * pas de clôture — donc pas de cloisonnement, qui est le problème que tout le plan
 * de fichiers existe pour résoudre. SE5 exige désormais un compte administrateur,
 * et le mode délégué livré par la story 61.2 a été retiré du code.
 *
 * **CE QUE CETTE MIGRATION NETTOIE, ET POURQUOI ELLE EXISTE.** Le code ne lit plus
 * ni le credential porteur ni les deux clés de réglage : les laisser ne casserait
 * rien. Mais l'app password du compte porteur est un SECRET vivant dans
 * `service_credentials` — une donnée morte que plus personne ne fera tourner ni ne
 * révoquera. On l'efface plutôt que de l'oublier là.
 *
 * **IRRÉVERSIBLE, et c'est assumé** : `down()` ne restaure rien. Un secret effacé ne
 * se reconstitue pas, et le mode qui le consommait n'existe plus — le « rétablir »
 * demanderait de reculer sur la décision, pas de rejouer une migration.
 * ---------------------------------------------------------------------------
 */
return new class extends Migration
{
    /** Nom du credential du compte porteur (ex-`NextcloudDelegateConfig::CREDENTIAL_NAME`). */
    private const DELEGATE_CREDENTIAL = 'nextcloud_delegue';

    /** Clé du réglage global de politique de fichiers. */
    private const POLICY_KEY = 'files.policy';

    /** Les deux clés que le payload ne porte plus. */
    private const DROPPED_KEYS = ['nextcloud_mode', 'nextcloud_delegue_user'];

    public function up(): void
    {
        if (Schema::hasTable('service_credentials')) {
            DB::table('service_credentials')->where('name', self::DELEGATE_CREDENTIAL)->delete();
        }

        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $row = DB::table('system_settings')->where('key', self::POLICY_KEY)->first();

        if ($row === null) {
            return;
        }

        $payload = json_decode((string) $row->value, true);

        if (! is_array($payload)) {
            return;
        }

        $cleaned = array_diff_key($payload, array_flip(self::DROPPED_KEYS));

        if ($cleaned === $payload) {
            return;
        }

        DB::table('system_settings')
            ->where('key', self::POLICY_KEY)
            ->update(['value' => json_encode($cleaned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    public function down(): void
    {
        // Rien à défaire : un secret effacé ne se reconstitue pas, et le mode qui
        // le lisait n'existe plus.
    }
};
