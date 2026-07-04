<?php

declare(strict_types=1);

namespace App\Jobs\ControlHub;

use App\Services\ControlHub\ArtifactPullService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Story 39.4 — Canal ④ : job ASYNCHRONE de pull d'un binaire imposé par le contrat amont
 * (controlHub). Dispatché APRÈS le commit de l'ingestion (jamais un téléchargement synchrone dans
 * la requête HTTP d'ingestion 39.1 — le pull ne doit jamais bloquer/dégrader le canal ①).
 *
 * Thin wrapper `ShouldQueue` (patron structurel {@see \App\Ipxe\Iso\Jobs\DownloadWindowsIsoJob} :
 * `tries=1`, un échec est terminal) — toute la logique (téléchargement via `Http`, vérification
 * sha256 serveur, précédence locale, matérialisation content-addressée / par-clé) vit dans
 * {@see \App\Services\ControlHub\ArtifactPullService} (testabilité).
 *
 * ⚠️ L'URL signée voyage EN ARGUMENT du job (jamais en colonne DB — AC5 : les URL sont régénérées à
 * chaque émission ; l'identité stable est le checksum). Le téléchargement passe par
 * `Illuminate\Support\Facades\Http` (artefacts petits — wallpapers/outils, pas d'ISO multi-Go →
 * pas de `Process`/`curl` shell).
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » ; vocabulaire « amont » / `ControlHub*`.
 */
class PullContractArtifactJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Un échec de pull est terminal (le retry vit dans la ré-émission du contrat — URL fraîche). */
    public int $tries = 1;

    public function __construct(
        public readonly int $itemId,
        public readonly string $type,
        public readonly string $key,
        public readonly string $url,
        public readonly string $checksum,
        public readonly ?string $filename = null,
        public readonly ?int $size = null,
    ) {
    }

    public function handle(ArtifactPullService $service): void
    {
        $service->pull(
            $this->itemId,
            $this->type,
            $this->key,
            $this->url,
            $this->checksum,
            $this->filename,
            $this->size,
        );
    }
}
