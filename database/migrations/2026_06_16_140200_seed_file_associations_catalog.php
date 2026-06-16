<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.3bis — Set initial du catalogue d'associations (D-Henri n°4 :
 * reproduction de l'existant legacy). IDEMPOTENT : `updateOrInsert` par `key`
 * (rejouable, zéro doublon). Le catalogue grossit ensuite par DATA / via le
 * {@see \Database\Seeders\FileAssociationSeeder} (qui parse `default.xml` quand
 * il est lisible sur la VM) — zéro release agent.
 *
 * Source de cette baseline FIGÉE : les associations legacy connues servies par
 * `gpo/associations_out.php` (cf. fixture `tests/Fixtures/Gpo/legacy-associations-out.json`
 * et `default.xml` sous `/usr/share/sambaedu/applications/associations/`). On
 * fige ici un set minimal sûr (extensions + protocoles web), suffisant pour qu'à
 * la bascule les défauts d'associations soient déjà en base — zéro régression.
 * Le seeder rejouable enrichit/remplace depuis `default.xml` réel quand présent.
 *
 * « Désactiver une association » = cesser de la gérer (item ABSENT) ; jamais de
 * reset OFF explicite (contrat §8 — type/clé absent = non géré).
 */
return new class extends Migration
{
    /**
     * Baseline iso-legacy (FirefoxHTML/FirefoxURL + visionneuse photos), reproduit
     * la réponse type de `associations_out.php`. ProgIds = ceux émis par les
     * packages WPKG Firefox (cf. PackagesXmlAssociationsReader). La `key` n'est PAS
     * stockée ici : elle est dérivée par identité `(identifier, progid)` via
     * {@see \App\Models\FileAssociation::catalogKey} — la MÊME clé que celle du
     * {@see \Database\Seeders\FileAssociationSeeder} (baseline ET parse default.xml),
     * pour qu'une paire identique upsert au lieu de dupliquer le catalogue sur VM.
     *
     * **Extension WPKG-aware (D-Henri n°7).** Chaque ligne est TAGUÉE par `source` :
     *   - Firefox (`.html/.htm/http/https → Firefox*`) = `wpkg`, `wpkg_package='firefox'`
     *     (le `<package id>` représentatif — = `Application::app_id` ; applicable
     *     seulement si Firefox est déployé sur le parc) ;
     *   - `.jpg → WindowsPhotoViewer` = `native` (built-in Windows) ;
     *   - `.txt → txtfile` = `native` (Notepad, le cas de Henri) — toujours applicable.
     * Cohérent avec le {@see \Database\Seeders\FileAssociationSeeder} (mêmes tags
     * dans sa baseline figée).
     *
     * @return list<array{label:string,description:string,identifier:string,assoc_type:string,progid:string,source:string,wpkg_package:?string}>
     */
    private function catalogRows(): array
    {
        return [
            ['label' => 'Pages HTML → Firefox', 'description' => 'Ouvre les fichiers .html avec Mozilla Firefox (association legacy par défaut).', 'identifier' => '.html', 'assoc_type' => 'file', 'progid' => 'FirefoxHTML', 'source' => 'wpkg', 'wpkg_package' => 'firefox'],
            ['label' => 'Pages HTM → Firefox', 'description' => 'Ouvre les fichiers .htm avec Mozilla Firefox (association legacy par défaut).', 'identifier' => '.htm', 'assoc_type' => 'file', 'progid' => 'FirefoxHTML', 'source' => 'wpkg', 'wpkg_package' => 'firefox'],
            ['label' => 'Protocole HTTP → Firefox', 'description' => 'Ouvre les liens http:// avec Mozilla Firefox (association legacy par défaut).', 'identifier' => 'http', 'assoc_type' => 'protocol', 'progid' => 'FirefoxURL', 'source' => 'wpkg', 'wpkg_package' => 'firefox'],
            ['label' => 'Protocole HTTPS → Firefox', 'description' => 'Ouvre les liens https:// avec Mozilla Firefox (association legacy par défaut).', 'identifier' => 'https', 'assoc_type' => 'protocol', 'progid' => 'FirefoxURL', 'source' => 'wpkg', 'wpkg_package' => 'firefox'],
            ['label' => 'Images JPG → Visionneuse de photos', 'description' => 'Ouvre les fichiers .jpg avec la visionneuse de photos Windows (association legacy par défaut).', 'identifier' => '.jpg', 'assoc_type' => 'file', 'progid' => 'WindowsPhotoViewer', 'source' => 'native', 'wpkg_package' => null],
            ['label' => 'Fichiers texte → Bloc-notes', 'description' => 'Ouvre les fichiers .txt avec le Bloc-notes Windows (built-in, toujours disponible).', 'identifier' => '.txt', 'assoc_type' => 'file', 'progid' => 'txtfile', 'source' => 'native', 'wpkg_package' => null],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('file_associations')) {
            return;
        }

        $now = now();

        foreach ($this->catalogRows() as $row) {
            DB::table('file_associations')->updateOrInsert(
                ['key' => \App\Models\FileAssociation::catalogKey($row['identifier'], $row['progid'])],
                array_merge($row, [
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]),
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('file_associations')) {
            return;
        }

        // Clés dérivées du MÊME catalogue que up() (pas de liste en dur : aucune
        // divergence si le set évolue). FK `file_association_id` du pivot en
        // cascadeOnDelete : supprimer ces associations retire AUSSI leurs
        // assignations de parc. Acceptable — zéro prod (mémoire
        // zero_prod_publish_is_test), aucune donnée à préserver.
        $keys = array_map(
            static fn (array $row): string => \App\Models\FileAssociation::catalogKey($row['identifier'], $row['progid']),
            $this->catalogRows(),
        );

        DB::table('file_associations')->whereIn('key', $keys)->delete();
    }
};
