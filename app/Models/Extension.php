<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 54.1 — Une extension du registre local SE5.
 *
 * **Le `manifest` complet est la source de vérité de la fiche** : scopes,
 * dépendances, `entry_url` et `visibility.roles` s'y lisent. Les colonnes
 * `name`/`version`/`publisher`/`icon`/`description`/`type` sont des
 * dénormalisations destinées à la LISTE (afficher la bibliothèque sans décoder
 * N documents JSON).
 *
 * **`status` est affiché, jamais muté en 54.1** : la synchro de la source
 * bundled ({@see \App\Services\Extensions\ExtensionCatalogService::syncBundled()})
 * ne l'écrit jamais, sinon un rechargement de catalogue dé-intégrerait
 * silencieusement une extension. Les transitions arrivent en Story 54.2.
 *
 * ⚠️ Aucune de ces colonnes n'est alimentée par la sync amont (controlHub) :
 * le registre d'extensions est isolé PAR CONSTRUCTION (NFR14).
 *
 * @property int $id
 * @property int $extension_source_id
 * @property string $key
 * @property string $name
 * @property string $version
 * @property string $publisher
 * @property string $icon
 * @property string $description
 * @property ExtensionType $type
 * @property ExtensionStatus $status
 * @property array<string, mixed> $manifest
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read ExtensionSource|null $source
 */
class Extension extends Model
{
    use HasFactory;

    protected $table = 'extensions';

    /**
     * ⚠️ `status` est VOLONTAIREMENT absent : rien dans cette story ne doit
     * pouvoir le muter par assignation de masse. Story 54.2 l'ajoutera (ou
     * mutera la propriété explicitement).
     *
     * @var list<string>
     */
    protected $fillable = [
        'extension_source_id',
        'key',
        'name',
        'version',
        'publisher',
        'icon',
        'description',
        'type',
        'manifest',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'type' => ExtensionType::class,
        'status' => ExtensionStatus::class,
        'manifest' => 'array',
    ];

    /** La source d'où provient cette extension. */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ExtensionSource::class, 'extension_source_id');
    }

    /** URL d'entrée déclarée par le manifest (cible de la tuile pour un `link`). */
    public function entryUrl(): string
    {
        return (string) ($this->manifest['entry_url'] ?? '');
    }

    /**
     * Scopes DEMANDÉS par le manifest — information admin (FR3) en 54.1 :
     * affichés, jamais consommés (les scopes effectifs relèvent des Epics 55/56).
     *
     * @return list<string>
     */
    public function requestedScopes(): array
    {
        return $this->stringList('scopes');
    }

    /**
     * Dépendances déclarées par le manifest (autres extensions requises).
     *
     * @return list<string>
     */
    public function dependencies(): array
    {
        return $this->stringList('dependencies');
    }

    /**
     * Rôles MÉTIER (`admin`/`prof`/`eleve`) auxquels la tuile est destinée.
     * 54.1 STOCKE cette donnée ; c'est la Story 54.3 (lanceur) qui la RÉSOUT.
     * Ce ne sont jamais des `SambaPermission` (AR8).
     *
     * @return list<string>
     */
    public function visibilityRoles(): array
    {
        $visibility = $this->manifest['visibility'] ?? null;
        if (! is_array($visibility) || ! isset($visibility['roles']) || ! is_array($visibility['roles'])) {
            return [];
        }

        return array_values(array_map(static fn ($role): string => (string) $role, $visibility['roles']));
    }

    /**
     * Liste de chaînes d'une clé racine du manifest (absente ⇒ `[]`).
     *
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        $value = $this->manifest[$key] ?? null;
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($item): string => (string) $item, $value));
    }
}
