<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Commande historique désactivée.
 *
 * Les droits web ne doivent plus être synchronisés depuis l'AD.
 */
class SyncRightsCommand extends Command
{
    protected $signature = 'sambaedu:sync-rights 
                            {login? : Login spécifique à synchroniser (tous si omis)}
                            {--dry-run : Affiche les changements sans les appliquer}';

    protected $description = 'Commande désactivée (droits web gérés uniquement en SQL/Spatie)';

    public function handle(): int
    {
        $this->error('Cette commande est désactivée: les droits web ne sont plus synchronisés depuis l\'AD.');
        $this->line('Utiliser les permissions/rôles SQL (Spatie) pour gérer les droits web.');

        return self::FAILURE;
    }
}
