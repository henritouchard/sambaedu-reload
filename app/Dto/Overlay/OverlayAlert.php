<?php

declare(strict_types=1);

namespace App\Dto\Overlay;

/**
 * DTO immuable d'une alerte du payload overlay.
 *
 * Représente une ligne affichable par l'overlay (Rainmeter / Conky), quelle
 * que soit sa provenance :
 *  - `source = "derived"` : recalculée à chaque poll (multi-session, quota) ;
 *  - `source = "posted"`  : signal stocké (`overlay_signals`) posté par le système.
 *
 * L'overlay ne consomme que `severity` / `title` / `text` (+ extras) ; il
 * ignore la source. Cf. spike `spike-wallpaper-overlay-tools-2026-06-09.md`.
 */
final readonly class OverlayAlert
{
    public const SOURCE_DERIVED = 'derived';
    public const SOURCE_POSTED = 'posted';

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    /**
     * @param  string  $id        identifiant stable de l'alerte (ex. `quota`, `sig-42`).
     * @param  string  $source    `derived` | `posted`.
     * @param  string  $kind      type métier (`session`, `quota`, `remote_control`, `notice`).
     * @param  string  $severity  `info` | `warning` | `critical`.
     * @param  string  $title     titre court.
     * @param  string  $text      message détaillé.
     * @param  string|null  $expiresAt  ISO-8601 ou null (alertes dérivées : null).
     * @param  array<string,mixed>  $meta  extras typés (machines[], partitions[]) — fusionnés à plat.
     */
    public function __construct(
        public string $id,
        public string $source,
        public string $kind,
        public string $severity,
        public string $title,
        public string $text,
        public ?string $expiresAt = null,
        public array $meta = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $base = [
            'id' => $this->id,
            'source' => $this->source,
            'kind' => $this->kind,
            'severity' => $this->severity,
            'title' => $this->title,
            'text' => $this->text,
        ];

        if ($this->expiresAt !== null) {
            $base['expires_at'] = $this->expiresAt;
        }

        // Extras typés (machines / partitions) à plat → parsable Rainmeter & jq.
        // `$base` en second = autoritaire : `meta` ne peut PAS écraser une clé
        // du contrat (id/source/severity/…) — review finding A.
        return array_merge($this->meta, $base);
    }
}
