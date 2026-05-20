<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 6.2 — Modèle SER pour les pilotes Windows associés aux imprimantes CUPS.
 *
 * Couche métier complémentaire à Samba (pas un remplacement). Samba via
 * `rpcclient enumdrivers` reste source de vérité runtime de la liste publiée.
 *
 * Cette table porte :
 *  - PK composite `(printer_cups_name, architecture)` — un même driver peut
 *    être rattaché à plusieurs imprimantes (1 ligne par imprimante).
 *  - Audit : `created_at`/`updated_at`/`created_by_user_id`.
 *  - Provenance : `source` (`upload-w10` / `synced` / `manual-cli`).
 *  - Drift : `orphan` (true = SER seul, Samba l'a perdu).
 *  - Métadata métier : `notes` (nom interne lisible).
 *
 * Friction Eloquent PK composite (décision DEV 2026-05-20) :
 *  - `$primaryKey = null` + `$incrementing = false` désactive les helpers
 *    Eloquent qui supposent une clé primaire scalaire (`find()`,
 *    route model binding, save()-with-existing-key). On expose un helper
 *    statique `findByKey()` et on s'appuie sur le Query Builder pour les
 *    mises à jour ciblées (`->where()->update()`).
 *  - L'INSERT/CREATE marche normalement (Eloquent compose le SQL depuis
 *    `$fillable`). Le DELETE par instance (`$model->delete()`) fonctionne
 *    aussi car il utilise les attributs courants pour construire la WHERE.
 */
class PrinterDriver extends Model
{
    use HasFactory;

    protected $table = 'printer_drivers';

    /**
     * PK composite — pas de clé primaire Eloquent scalaire.
     *
     * Conséquences : `find()` ne fonctionne pas ; `save()` sur un modèle
     * fraîchement chargé ne sait pas s'il doit INSERT ou UPDATE. On
     * gère ces cas via Query Builder ciblé.
     */
    protected $primaryKey = null;
    public $incrementing = false;

    protected $fillable = [
        'printer_cups_name',
        'architecture',
        'driver_name',
        'source',
        'orphan',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'orphan' => 'boolean',
    ];

    // ========================================================================
    // RELATIONS
    // ========================================================================

    /**
     * Imprimante CUPS portant ce driver (FK CASCADE).
     */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class, 'printer_cups_name', 'cups_name');
    }

    /**
     * Utilisateur ayant créé l'entrée SER (null si créé par `printer-drivers:sync`).
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // ========================================================================
    // SCOPES
    // ========================================================================

    /**
     * Drivers présents à la fois en SER et dans Samba (état normal).
     */
    public function scopeNonOrphan(Builder $query): Builder
    {
        return $query->where('orphan', false);
    }

    /**
     * Drivers SER orphelins (présents en SER mais absents de Samba — drift).
     */
    public function scopeOrphans(Builder $query): Builder
    {
        return $query->where('orphan', true);
    }

    /**
     * Drivers d'une architecture donnée (D5 : `x64` uniquement en 6.2).
     */
    public function scopeForArchitecture(Builder $query, string $arch): Builder
    {
        return $query->where('architecture', $arch);
    }

    /**
     * Drivers d'une provenance donnée (`upload-w10` / `synced` / `manual-cli`).
     */
    public function scopeBySource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }

    // ========================================================================
    // HELPERS (composite key)
    // ========================================================================

    /**
     * Lookup par clé composite — remplace `Model::find($id)` qui ne fonctionne
     * pas avec une PK composite Eloquent.
     */
    public static function findByKey(string $cupsName, string $architecture): ?self
    {
        return self::query()
            ->where('printer_cups_name', $cupsName)
            ->where('architecture', $architecture)
            ->first();
    }

    /**
     * Index par clé composite pour les diffs de la commande sync.
     */
    public function compositeKey(): string
    {
        return $this->printer_cups_name . '|' . $this->architecture;
    }
}
