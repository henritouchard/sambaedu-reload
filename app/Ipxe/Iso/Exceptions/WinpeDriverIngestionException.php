<?php

declare(strict_types=1);

namespace App\Ipxe\Iso\Exceptions;

use RuntimeException;

/**
 * Story 3.10 — Levée par {@see \App\Ipxe\Iso\Services\WinpeDriverIngestor}
 * quand l'ingestion d'une archive de pilotes échoue : extension inconnue
 * (ni `.exe` ni `.zip`), binaire d'extraction absent (`innoextract` / `unzip`),
 * archive sans aucun `.inf`, ou famille au nom invalide.
 *
 * Exception métier réutilisable par les DEUX canaux d'ingestion (D3) — la
 * commande artisan {@see \App\Console\Commands\IngestWinpeDriversCommand} (exit
 * non-zéro + message) ET le composant Livewire `iso-windows` (toast d'erreur) —
 * sans aucune duplication de logique.
 */
class WinpeDriverIngestionException extends RuntimeException
{
}
