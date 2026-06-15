<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Shortcuts\ShortcutIconBackfiller;
use Illuminate\Console\Command;

/**
 * Backfill des icônes UPLOADÉES existantes name-addressed → content-addressed
 * (Story 27.7, AC5). Appelant fin de {@see ShortcutIconBackfiller} — calque
 * la commande wallpaper backfill.
 *
 * Usage :
 *   php artisan shortcuts:backfill-icons [--legacy-dir=PATH]
 *
 * Idempotent + fail-soft : COPIE les `.ico` legacy (jamais supprime), dédup
 * par checksum, re-run no-op. Un `.ico` legacy absent → loggé `missing`, pas
 * d'échec.
 */
class ShortcutsBackfillIconsCommand extends Command
{
    protected $signature = 'shortcuts:backfill-icons {--legacy-dir= : Dossier des icônes legacy name-addressed (défaut : config shortcut_icons.legacy_path)}';

    protected $description = 'Content-adresse les icônes uploadées existantes (<name>.ico → <sha>.ico) et persiste icon_asset/icon_checksum (Story 27.7).';

    public function handle(ShortcutIconBackfiller $backfiller): int
    {
        $legacyDir = $this->option('legacy-dir');
        $legacyDir = is_string($legacyDir) && $legacyDir !== '' ? $legacyDir : null;

        $stats = $backfiller->run($legacyDir);

        $this->info(sprintf(
            'Backfill icônes raccourcis : %d asset(s) content-adressé(s), %d raccourci(s) lié(s), %d manquant(s).',
            $stats['assets'],
            $stats['linked'],
            $stats['missing'],
        ));

        return self::SUCCESS;
    }
}
