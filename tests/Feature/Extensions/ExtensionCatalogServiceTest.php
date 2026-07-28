<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions;

use App\Enums\ExtensionSourceKind;
use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Models\Extension;
use App\Models\ExtensionSource;
use App\Services\Extensions\ExtensionCatalogService;
use Database\Seeders\BundledExtensionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 54.1 (AC1/AC2) — Synchro de la source EMBARQUÉE et lecture du catalogue.
 *
 * La découverte des manifests est pointée sur un répertoire TEMPORAIRE
 * (`config('extensions.bundled_path')`, patron `agent.tools_embedded_path`) :
 * les cas d'erreur sont ainsi testés sans dépendre du contenu réel du dépôt. Un
 * test dédié vérifie en revanche que le manifest RÉEL du dépôt
 * (`resources/extensions/doc/manifest.json`) est valide et chargé.
 *
 * Tests HÔTE (php8.4 + pdo_sqlite), `RefreshDatabase`.
 */
class ExtensionCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $bundledPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bundledPath = sys_get_temp_dir().'/se5-extensions-'.uniqid('', true);
        mkdir($this->bundledPath, 0o777, true);
        config(['extensions.bundled_path' => $this->bundledPath]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->bundledPath);
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function service(): ExtensionCatalogService
    {
        return app(ExtensionCatalogService::class);
    }

    /** Écrit `<bundled_path>/<dir>/manifest.json`. */
    private function writeManifest(string $dir, array|string $manifest): void
    {
        $folder = $this->bundledPath.'/'.$dir;
        if (! is_dir($folder)) {
            mkdir($folder, 0o777, true);
        }
        file_put_contents(
            $folder.'/manifest.json',
            is_string($manifest) ? $manifest : json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /** Supprime le dossier `<bundled_path>/<dir>` (manifest disparu). */
    private function removeManifestDir(string $dir): void
    {
        $this->removeDirectory($this->bundledPath.'/'.$dir);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path.'/'.$entry;
            is_dir($full) ? $this->removeDirectory($full) : @unlink($full);
        }
        @rmdir($path);
    }

    private function manifest(string $id, array $overrides = []): array
    {
        return array_merge([
            'manifest_version' => 1,
            'id' => $id,
            'type' => 'link',
            'name' => ucfirst($id),
            'version' => '1.0.0',
            'entry_url' => '/'.$id,
            'icon' => 'fa-solid fa-puzzle-piece',
            'publisher' => 'SambaEdu',
            'description' => 'Extension '.$id.'.',
            'scopes' => [],
            'dependencies' => [],
            'visibility' => ['roles' => ['admin']],
        ], $overrides);
    }

    // ── AC1 — chargement de la source embarquée ───────────────────────────

    #[Test]
    public function sync_creates_the_bundled_source_and_loads_its_manifests(): void
    {
        $this->writeManifest('doc', $this->manifest('doc', ['name' => 'Documentation', 'entry_url' => '/doc']));

        $stats = $this->service()->syncBundled();

        $source = ExtensionSource::where('key', ExtensionSource::KEY_BUNDLED)->first();
        self::assertNotNull($source, 'la source embarquée est créée');
        self::assertSame(ExtensionSourceKind::Bundled, $source->kind);
        self::assertSame('', $source->url, 'jamais NULL sur une colonne de clé (piège #3)');

        self::assertSame(1, $stats['loaded']);
        self::assertSame(1, $stats['created']);

        $extension = Extension::where('key', 'doc')->first();
        self::assertNotNull($extension);
        self::assertSame('Documentation', $extension->name);
        self::assertSame(ExtensionType::Link, $extension->type);
        self::assertSame(ExtensionStatus::Available, $extension->fresh()->status);
        self::assertSame('/doc', $extension->entryUrl());
        self::assertSame($source->id, $extension->extension_source_id);
    }

    #[Test]
    public function the_real_repository_manifest_is_valid_and_loads(): void
    {
        // On repointe sur le VRAI dossier du dépôt : le manifest livré doit être
        // conforme au contrat v1 (sinon la tuile Documentation est morte-née).
        config(['extensions.bundled_path' => base_path('resources/extensions')]);

        $stats = $this->service()->syncBundled();

        self::assertSame(0, $stats['skipped'], 'aucun manifest du dépôt ne doit être rejeté');
        $doc = Extension::where('key', 'doc')->first();
        self::assertNotNull($doc, 'la tuile Documentation est chargée');
        self::assertSame('/doc', $doc->entryUrl());
        self::assertSame(ExtensionType::Link, $doc->type);
        // `administratif` ajouté en review 54.3 (#5) : cette population, écrite
        // telle quelle par la sync, ouvrait sinon un lanceur SYSTÉMATIQUEMENT
        // vide — la documentation est le contre-exemple parfait d'une
        // application réservée aux enseignants et aux élèves.
        self::assertSame(['admin', 'prof', 'eleve', 'administratif'], $doc->visibilityRoles());
    }

    #[Test]
    public function an_empty_discovery_directory_is_not_an_error(): void
    {
        $stats = $this->service()->syncBundled();

        self::assertSame(0, $stats['loaded']);
        self::assertSame(0, $stats['skipped']);
        self::assertNotNull(ExtensionSource::where('key', ExtensionSource::KEY_BUNDLED)->first());
    }

    // ── AC2 — un manifest fautif n'en casse aucun autre ───────────────────

    #[Test]
    public function an_invalid_manifest_is_skipped_without_breaking_the_others(): void
    {
        $this->writeManifest('doc', $this->manifest('doc'));
        // Champ obligatoire manquant.
        $this->writeManifest('broken', $this->manifest('broken', ['name' => '']));
        // Type inconnu.
        $this->writeManifest('weird', $this->manifest('weird', ['type' => 'widget']));
        // Version non supportée.
        $this->writeManifest('future', $this->manifest('future', ['manifest_version' => 99]));
        // JSON illisible.
        $this->writeManifest('corrupt', '{ this is not json ');
        $this->writeManifest('other', $this->manifest('other'));

        $stats = $this->service()->syncBundled();

        self::assertSame(2, $stats['loaded'], 'les deux manifests valides sont chargés');
        self::assertSame(4, $stats['skipped'], 'les quatre fautifs sont ignorés');

        self::assertEqualsCanonicalizing(
            ['doc', 'other'],
            Extension::query()->pluck('key')->all(),
        );
    }

    // ── AC2 — idempotence, `status` préservé ──────────────────────────────

    #[Test]
    public function replaying_the_sync_creates_no_duplicate_and_writes_nothing(): void
    {
        $this->writeManifest('doc', $this->manifest('doc'));
        $this->service()->syncBundled();

        $before = Extension::where('key', 'doc')->firstOrFail();
        $updatedAtBefore = (string) $before->updated_at;

        $stats = $this->service()->syncBundled();

        self::assertSame(1, Extension::query()->count(), 'aucun doublon');
        self::assertSame(0, $stats['created']);
        self::assertSame(0, $stats['updated'], 'rien de sale ⇒ aucune écriture');
        self::assertSame($updatedAtBefore, (string) $before->fresh()->updated_at);
    }

    #[Test]
    public function replaying_the_sync_never_resets_the_status_of_an_integrated_extension(): void
    {
        $this->writeManifest('doc', $this->manifest('doc'));
        $this->service()->syncBundled();

        $extension = Extension::where('key', 'doc')->firstOrFail();
        $extension->status = ExtensionStatus::Integrated;   // ce que fera la 54.2
        $extension->save();

        // Le manifest change (nouvelle version publiée) → la ligne est mise à
        // jour… mais JAMAIS sa colonne `status`.
        $this->writeManifest('doc', $this->manifest('doc', ['version' => '2.0.0', 'name' => 'Doc v2']));
        $stats = $this->service()->syncBundled();

        self::assertSame(1, $stats['updated']);
        $fresh = $extension->fresh();
        self::assertSame('2.0.0', $fresh->version);
        self::assertSame('Doc v2', $fresh->name);
        self::assertSame(ExtensionStatus::Integrated, $fresh->status, 'status jamais écrit par la synchro');
    }

    #[Test]
    public function an_updated_manifest_refreshes_the_denormalized_columns(): void
    {
        $this->writeManifest('doc', $this->manifest('doc'));
        $this->service()->syncBundled();

        $this->writeManifest('doc', $this->manifest('doc', [
            'version' => '1.1.0',
            'publisher' => 'Autre éditeur',
            'description' => 'Nouvelle description.',
            'scopes' => ['profile'],
        ]));
        $stats = $this->service()->syncBundled();

        self::assertSame(1, $stats['updated']);
        $fresh = Extension::where('key', 'doc')->firstOrFail();
        self::assertSame('1.1.0', $fresh->version);
        self::assertSame('Autre éditeur', $fresh->publisher);
        self::assertSame(['profile'], $fresh->requestedScopes());
    }

    // ── AC2 — prune borné ─────────────────────────────────────────────────

    #[Test]
    public function a_disappeared_manifest_prunes_only_the_available_bundled_row(): void
    {
        $this->writeManifest('doc', $this->manifest('doc'));
        $this->writeManifest('gone', $this->manifest('gone'));
        $this->service()->syncBundled();
        self::assertSame(2, Extension::query()->count());

        $this->removeManifestDir('gone');
        $stats = $this->service()->syncBundled();

        self::assertSame(1, $stats['pruned']);
        self::assertNull(Extension::where('key', 'gone')->first());
        self::assertNotNull(Extension::where('key', 'doc')->first());
    }

    #[Test]
    public function a_disappeared_manifest_never_removes_an_integrated_extension(): void
    {
        $this->writeManifest('doc', $this->manifest('doc'));
        $this->service()->syncBundled();

        $extension = Extension::where('key', 'doc')->firstOrFail();
        $extension->status = ExtensionStatus::Integrated;
        $extension->save();

        $this->removeManifestDir('doc');
        $stats = $this->service()->syncBundled();

        self::assertSame(0, $stats['pruned']);
        self::assertNotNull(
            Extension::where('key', 'doc')->first(),
            'une extension intégrée n\'est jamais dé-intégrée silencieusement',
        );
    }

    // ── Correctif de review 54.1 — racine introuvable ≠ catalogue vide ────
    //
    // Sans cette garde, `discoverBundledManifestPaths()` renvoyait `[]` pour une
    // racine ABSENTE comme pour une racine VIDE : le prune ne voyait alors aucune
    // clé « vue » et supprimait TOUT le catalogue embarqué `available`. Un
    // `EXTENSIONS_BUNDLED_PATH` mal résolu, une config mise en cache avec un
    // chemin d'une autre machine ou un déploiement incomplet suffisaient à vider
    // la bibliothèque — le sinistre déjà vécu par ce projet sur le catalogue
    // applicatif local, rejoué sur le registre d'extensions.

    #[Test]
    public function a_missing_bundled_root_preserves_the_catalog_instead_of_wiping_it(): void
    {
        $this->writeManifest('doc', $this->manifest('doc'));
        $this->writeManifest('other', $this->manifest('other'));
        $this->service()->syncBundled();
        self::assertSame(2, Extension::query()->count());

        // Accident de déploiement : la racine des manifests n'existe plus.
        config(['extensions.bundled_path' => $this->bundledPath.'-inexistant']);

        $stats = $this->service()->syncBundled();

        self::assertSame(0, $stats['pruned'], 'racine introuvable ⇒ AUCUN prune');
        self::assertSame(0, $stats['loaded']);
        self::assertSame(2, Extension::query()->count(), 'le catalogue embarqué est PRÉSERVÉ');
    }

    #[Test]
    public function an_existing_but_empty_bundled_root_still_prunes(): void
    {
        // Contre-épreuve : la garde ne doit PAS neutraliser le prune légitime.
        // Racine présente et réellement vidée de ses manifests = observation
        // valide ⇒ les lignes `available` disparues sont bien supprimées.
        $this->writeManifest('doc', $this->manifest('doc'));
        $this->service()->syncBundled();
        self::assertSame(1, Extension::query()->count());

        $this->removeManifestDir('doc');
        self::assertDirectoryExists($this->bundledPath);

        $stats = $this->service()->syncBundled();

        self::assertSame(1, $stats['pruned']);
        self::assertSame(0, Extension::query()->count());
    }

    #[Test]
    public function the_prune_is_bounded_to_the_bundled_source(): void
    {
        // Une extension d'une AUTRE source (anticipation Epic 56) ne doit pas
        // être emportée par la synchro de la source embarquée.
        $remoteSource = ExtensionSource::factory()->remote()->create();
        $remote = Extension::factory()->create([
            'extension_source_id' => $remoteSource->id,
            'key' => 'remote_ext',
        ]);

        $this->writeManifest('doc', $this->manifest('doc'));
        $stats = $this->service()->syncBundled();

        self::assertSame(0, $stats['pruned']);
        self::assertNotNull($remote->fresh(), 'les extensions des autres sources sont intouchées');
    }

    #[Test]
    public function two_sources_may_publish_the_same_key(): void
    {
        // La clé naturelle est `(source, key)` — pas `key` seule.
        $remoteSource = ExtensionSource::factory()->remote()->create();
        Extension::factory()->create([
            'extension_source_id' => $remoteSource->id,
            'key' => 'doc',
        ]);

        $this->writeManifest('doc', $this->manifest('doc'));
        $this->service()->syncBundled();

        self::assertSame(2, Extension::where('key', 'doc')->count());
    }

    // ── Seeder ────────────────────────────────────────────────────────────

    #[Test]
    public function the_seeder_is_idempotent_and_returns_counters(): void
    {
        $this->writeManifest('doc', $this->manifest('doc'));

        $first = (new BundledExtensionSeeder())->run();
        $second = (new BundledExtensionSeeder())->run();

        self::assertSame(1, $first['created']);
        self::assertSame(0, $second['created']);
        self::assertSame(0, $second['updated']);
        self::assertSame(1, ExtensionSource::query()->count(), 'la source n\'est jamais dupliquée');
        self::assertSame(1, Extension::query()->count());
    }

    // ── Lecture (pages admin) ─────────────────────────────────────────────

    #[Test]
    public function library_returns_flat_rows_with_labels_and_source(): void
    {
        $this->writeManifest('doc', $this->manifest('doc', ['name' => 'Documentation']));
        $this->service()->syncBundled();

        $rows = $this->service()->library();

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('Documentation', $row['name']);
        self::assertSame('link', $row['type']);
        self::assertSame('Lien', $row['type_label']);
        self::assertSame('available', $row['status']);
        self::assertSame('Disponible', $row['status_label']);
        self::assertSame(ExtensionSource::NAME_BUNDLED, $row['source_name']);
        self::assertIsInt($row['id']);
    }

    #[Test]
    public function find_returns_the_manifest_driven_detail(): void
    {
        $this->writeManifest('bbb', $this->manifest('bbb', [
            'type' => 'app',
            'scopes' => ['profile', 'groups'],
            'dependencies' => ['doc'],
            'visibility' => ['roles' => ['admin', 'prof']],
        ]));
        $this->service()->syncBundled();

        $id = (int) Extension::where('key', 'bbb')->value('id');
        $detail = $this->service()->find($id);

        self::assertNotNull($detail);
        self::assertSame('/bbb', $detail['entry_url']);
        self::assertSame(['profile', 'groups'], $detail['scopes']);
        self::assertSame(['doc'], $detail['dependencies']);
        self::assertSame(['admin', 'prof'], $detail['visibility_roles']);
        self::assertSame('Application', $detail['type_label']);
        self::assertSame('Embarquée', $detail['source_kind_label']);
        self::assertTrue($detail['source_is_official']);
        self::assertSame(1, $detail['manifest_version']);
    }

    #[Test]
    public function find_returns_null_for_an_unknown_id(): void
    {
        self::assertNull($this->service()->find(999_999));
    }
}
