<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle pour les raccourcis pré-compilés.
 *
 * Chaque entrée représente un raccourci compilé pour une cible spécifique
 * (workstation, workstation_group, ad_user, ad_user_group) et un OS/action.
 *
 * Les fichiers binaires (.lnk, .desktop) sont stockés sur le filesystem
 * dans /etc/sambaedu/applications/shortcuts/compiled/.
 *
 * @property int $id
 * @property int $shortcut_id
 * @property string $target_type
 * @property string $target_identifier
 * @property string $os
 * @property string $action
 * @property string|null $script_fragment
 * @property string|null $compiled_path Chemin vers le fichier pré-compilé sur disque
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class CompiledShortcut extends Model
{
    protected $table = 'compiled_shortcuts';

    protected $fillable = [
        'shortcut_id',
        'target_type',
        'target_identifier',
        'os',
        'action',
        'script_fragment',
        'compiled_path',
    ];

    public function shortcut(): BelongsTo
    {
        return $this->belongsTo(Shortcut::class);
    }

    /**
     * Vérifie si le fichier compilé existe sur le filesystem.
     */
    public function compiledFileExists(): bool
    {
        return $this->compiled_path && file_exists($this->compiled_path);
    }
}
