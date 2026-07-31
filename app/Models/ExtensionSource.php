<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExtensionSourceKind;
use App\Enums\ExtensionSourceSyncStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 54.1 — Source d'extensions : d'OÙ provient une extension du registre.
 *
 * Le multi-sources (AR7) est modélisé DÈS LE SOCLE. En 54.1 une seule ligne
 * existait : la source `bundled`, dont les manifests sont embarqués dans le
 * dépôt SE5 (`resources/extensions/<id>/manifest.json`).
 *
 * **Story 56.1** active les sources DISTANTES (`ExtensionSourceKind::Remote`) :
 * une source distante porte l'URL de BASE d'un dépôt statique publiant trois
 * fichiers (`index.json`, `index.json.sig`, `source.pub`), la **clé publique
 * Ed25519 pinnée** à l'ajout (`public_key` — jamais re-téléchargée ensuite) et
 * l'état de sa dernière synchro (`sync_status`, `last_synced_at`,
 * `last_error`).
 *
 * ⚠️ `kind` = transport, `is_official` = confiance (FR4). Deux axes distincts.
 *
 * ⚠️ `url` est une URL de BASE, sans `/index.json` final : c'est le service de
 * synchro qui compose les trois chemins du dépôt (contrat de format v1).
 *
 * ⚠️ Vocabulaire : « amont » / `Upstream`, jamais « central ».
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property ExtensionSourceKind $kind
 * @property string $url
 * @property bool $is_official
 * @property bool $enabled
 * @property string $public_key
 * @property ExtensionSourceSyncStatus $sync_status
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property string $last_error
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Extension> $extensions
 */
class ExtensionSource extends Model
{
    use HasFactory;

    /** Clé naturelle de la source embarquée dans le dépôt SE5. */
    public const KEY_BUNDLED = 'bundled';

    /** Libellé canonique de la source embarquée (baseline du seeder). */
    public const NAME_BUNDLED = 'Embarquée (SambaEdu)';

    protected $table = 'extension_sources';

    /** @var list<string> */
    protected $fillable = [
        'key',
        'name',
        'kind',
        'url',
        'is_official',
        'enabled',
        'public_key',
        'sync_status',
        'last_synced_at',
        'last_error',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'kind' => ExtensionSourceKind::class,
        'is_official' => 'boolean',
        'enabled' => 'boolean',
        'sync_status' => ExtensionSourceSyncStatus::class,
        'last_synced_at' => 'datetime',
    ];

    /** Les extensions publiées par cette source. */
    public function extensions(): HasMany
    {
        return $this->hasMany(Extension::class, 'extension_source_id');
    }

    /** Source DISTANTE (le seul `kind` qui se synchronise par le réseau). */
    public function isRemote(): bool
    {
        return $this->kind === ExtensionSourceKind::Remote;
    }

    /**
     * URL de BASE normalisée du dépôt (sans `/` final) : les trois fichiers du
     * contrat de format v1 s'y composent (`/index.json`, `/index.json.sig`,
     * `/source.pub`).
     */
    public function baseUrl(): string
    {
        return rtrim((string) $this->url, '/');
    }

    /**
     * Hôte du dépôt — CE QUE L'ADMIN DOIT VOIR avant d'intégrer une extension
     * tierce (FR4/UX-DR4 : « Source non officielle : `<host>` »). Chaîne vide
     * si l'URL n'a pas d'hôte (source embarquée).
     */
    public function host(): string
    {
        $host = parse_url($this->baseUrl(), PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    /**
     * État de synchro effectif — `Ok` par défaut pour les lignes antérieures à
     * la migration 56.1 comme pour la source embarquée (qui ne se synchronise
     * jamais par le réseau).
     */
    public function syncStatus(): ExtensionSourceSyncStatus
    {
        return $this->sync_status ?? ExtensionSourceSyncStatus::Ok;
    }

    /**
     * Cette source PROPOSE-t-elle encore ses extensions `available` ?
     *
     * Règle UNIQUE de proposabilité (56.1) : une source gelée (`enabled =
     * false`) ou dont le dernier catalogue n'a pas pu être VÉRIFIÉ
     * (`sync_status = error`, signature invalide) ne propose plus rien —
     * fail-closed NFR2. Une source `unreachable` continue de proposer son
     * dernier catalogue vérifié (le registre EST le cache local, NFR7).
     *
     * ⚠️ Cette méthode vit sur le MODÈLE, pas dans un service, parce qu'elle a
     * DEUX appelants qui doivent dire exactement la même chose :
     * {@see \App\Services\Extensions\ExtensionCatalogService::find()} (ce qui
     * s'AFFICHE) et
     * {@see \App\Services\Extensions\ExtensionLifecycleService::integrate()}
     * (ce qui s'INTÈGRE). Review 56.1 #1 : tant que la règle n'existait qu'en
     * privé dans le catalogue, un appel Livewire direct à `integrate(<id>)`
     * intégrait une extension pourtant masquée — le fail-closed ne tenait qu'à
     * l'affichage. Une garantie qui n'existe que dans la vue n'est pas une
     * garantie.
     *
     * Ne concerne QUE le passage `available → integrated`. Une extension DÉJÀ
     * intégrée reste désinstallable même si sa source est gelée : rompre le
     * lien fige l'état, il ne piège pas l'admin.
     */
    public function offersAvailableExtensions(): bool
    {
        return $this->enabled && $this->syncStatus()->proposesAvailableExtensions();
    }
}
