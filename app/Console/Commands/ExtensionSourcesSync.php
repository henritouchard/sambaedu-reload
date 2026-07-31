<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ExtensionSourceSyncStatus;
use App\Exceptions\ExtensionSourceException;
use App\Models\ExtensionSource;
use App\Services\Extensions\RemoteCatalogSyncService;
use Illuminate\Console\Command;

/**
 * Story 56.1 (AC7, AR1) — `php artisan ext:sources:sync {key?}`.
 *
 * Synchronise une source distante (par sa clé) ou TOUTES les sources distantes
 * actives. **Même moteur que le bouton « Actualiser » de la page des sources**
 * ({@see RemoteCatalogSyncService}) : il n'existe pas de second chemin de
 * synchro, donc pas de comportement qui diverge entre l'UI et la planification
 * (doctrine AR1).
 *
 * Rejouable sans risque : la synchro est idempotente (invariant 54.1 #3), et
 * une source injoignable ou un catalogue refusé ne suppriment RIEN — ils
 * marquent seulement le statut de la source.
 *
 * Codes retour : `0` si toutes les sources traitées sont `ok`, `1` si au moins
 * une est `unreachable` ou `error` — de quoi faire remonter un dépôt qui
 * décroche dans une supervision, sans jamais empêcher SE5 de fonctionner
 * (NFR7 : le dernier catalogue vérifié reste en place).
 */
class ExtensionSourcesSync extends Command
{
    /** @var string */
    protected $signature = 'ext:sources:sync
        {key? : Clé de la source à synchroniser (par défaut : toutes les sources distantes actives)}';

    /** @var string */
    protected $description = "Synchronise le catalogue des sources d'extensions distantes (signature Ed25519 vérifiée avant tout usage).";

    public function handle(RemoteCatalogSyncService $sync): int
    {
        $key = $this->argument('key');

        $results = $key === null
            ? $sync->syncAll()
            : $this->syncOne($sync, (string) $key);

        if ($results === null) {
            return self::FAILURE;
        }

        if ($results === []) {
            $this->line('Aucune source distante active — rien à synchroniser.');

            return self::SUCCESS;
        }

        $this->renderTable($results);

        $failed = array_filter(
            $results,
            static fn (array $row): bool => $row['status'] !== ExtensionSourceSyncStatus::Ok->value,
        );

        if ($failed !== []) {
            $this->warn(count($failed).' source(s) en échec — le dernier catalogue vérifié reste en place (aucune suppression).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>|null  `null` ⇒ refus (déjà expliqué à l'opérateur)
     */
    private function syncOne(RemoteCatalogSyncService $sync, string $key): ?array
    {
        $source = ExtensionSource::query()->where('key', $key)->first();

        if ($source === null) {
            $this->error("Source « {$key} » introuvable. Sources connues : "
                .(ExtensionSource::query()->orderBy('key')->pluck('key')->implode(', ') ?: '(aucune)'));

            return null;
        }

        if (! $source->enabled) {
            // Désactiver, c'est GELER : la commande ne contourne pas la
            // décision de l'admin.
            $this->error("Source « {$key} » désactivée : réactivez-la avant de la synchroniser.");

            return null;
        }

        try {
            return [$sync->sync($source)];
        } catch (ExtensionSourceException $e) {
            $this->error($e->getMessage());

            return null;
        }
    }

    /** @param list<array<string, mixed>> $results */
    private function renderTable(array $results): void
    {
        $this->table(
            ['Source', 'Statut', 'Chargées', 'Créées', 'MàJ', 'Ignorées', 'Retirées', 'Détail'],
            array_map(static function (array $row): array {
                $status = ExtensionSourceSyncStatus::tryFrom((string) $row['status']);

                return [
                    (string) $row['source'],
                    $status?->label() ?? (string) $row['status'],
                    (string) $row['loaded'],
                    (string) $row['created'],
                    (string) $row['updated'],
                    (string) $row['skipped'],
                    (string) $row['pruned'],
                    (string) $row['error'],
                ];
            }, $results),
        );
    }
}
