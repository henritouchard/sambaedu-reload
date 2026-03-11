<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Wireable;

/**
 * Modèle Eloquent pour les rapports d'installation WPKG
 * 
 * Utilise la table legacy 'poste_app' avec son schéma existant.
 * Représente le statut d'installation d'une application sur un poste.
 * 
 * @property int $id_poste ID du poste
 * @property int $id_app ID de l'application
 * @property string $id_nom_app Identifiant technique de l'application
 * @property string $revision_poste_app Version installée
 * @property string $statut_poste_app Statut (Installed, Not installed, etc.)
 * @property int $reboot_poste_app Redémarrage nécessaire (1) ou non (0)
 */
class Report extends Model implements Wireable
{
    /**
     * La table associée au modèle (table legacy)
     */
    protected $table = 'poste_app';

    /**
     * Indique si le modèle a une clé primaire auto-incrémentée
     * Cette table utilise une clé composite (id_poste, id_app)
     */
    public $incrementing = false;

    /**
     * Indique si les timestamps sont gérés automatiquement
     */
    public $timestamps = false;

    /**
     * Les attributs qui peuvent être assignés en masse
     */
    protected $fillable = [
        'id_poste',
        'id_app',
        'id_nom_app',
        'revision_poste_app',
        'statut_poste_app',
        'reboot_poste_app',
    ];

    /**
     * Les attributs qui doivent être castés
     */
    protected $casts = [
        'id_poste' => 'integer',
        'id_app' => 'integer',
        'reboot_poste_app' => 'integer',
    ];

    /**
     * Relation avec le poste
     */
    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class, 'id_poste', 'id_poste');
    }

    /**
     * Relation avec l'application
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'id_app', 'id_app');
    }

    /**
     * Scope pour les applications installées
     */
    public function scopeInstalled(Builder $query): Builder
    {
        return $query->where('statut_poste_app', 'Installed');
    }

    /**
     * Scope pour les applications non installées
     */
    public function scopeNotInstalled(Builder $query): Builder
    {
        return $query->where('statut_poste_app', 'Not installed');
    }

    /**
     * Scope pour les applications nécessitant un redémarrage
     */
    public function scopeNeedsReboot(Builder $query): Builder
    {
        return $query->where('reboot_poste_app', 1);
    }

    /**
     * Vérifie si l'application est installée
     */
    public function isInstalled(): bool
    {
        return $this->statut_poste_app === 'Installed';
    }

    /**
     * Vérifie si un redémarrage est nécessaire
     */
    public function needsReboot(): bool
    {
        return $this->reboot_poste_app === 1;
    }

    /**
     * Retourne le label du statut
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->statut_poste_app) {
            'Installed' => 'Installé',
            'Not installed' => 'Non installé',
            'Installing' => 'En cours',
            'Failed' => 'Échec',
            default => $this->statut_poste_app ?? 'Inconnu',
        };
    }

    /**
     * Sérialise le modèle pour Livewire
     */
    public function toLivewire(): array
    {
        return [
            'id_poste' => $this->id_poste,
            'id_app' => $this->id_app,
        ];
    }

    /**
     * Désérialise depuis Livewire
     */
    public static function fromLivewire($value): static
    {
        return static::where('id_poste', $value['id_poste'])
            ->where('id_app', $value['id_app'])
            ->firstOrFail();
    }
}
