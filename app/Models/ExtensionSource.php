<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExtensionSourceKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 54.1 — Source d'extensions : d'OÙ provient une extension du registre.
 *
 * Le multi-sources (AR7) est modélisé DÈS LE SOCLE. En 54.1 une seule ligne
 * existe : la source `bundled`, dont les manifests sont embarqués dans le dépôt
 * SE5 (`resources/extensions/<id>/manifest.json`). Les sources DISTANTES
 * (`ExtensionSourceKind::Remote`), leur UI d'ajout et la vérification de
 * signature relèvent de l'Epic 56 : les colonnes existent, le comportement non.
 *
 * ⚠️ `kind` = transport, `is_official` = confiance (FR4). Deux axes distincts.
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
    ];

    /** @var array<string, string> */
    protected $casts = [
        'kind' => ExtensionSourceKind::class,
        'is_official' => 'boolean',
        'enabled' => 'boolean',
    ];

    /** Les extensions publiées par cette source. */
    public function extensions(): HasMany
    {
        return $this->hasMany(Extension::class, 'extension_source_id');
    }
}
