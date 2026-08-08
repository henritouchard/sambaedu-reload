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

    protected $help = <<<'HELP'
    <comment>Commande désactivée.</comment> Elle ne fait plus rien.

    Les droits d'accès à l'application ne sont plus dérivés de l'annuaire : ils vivent
    en base, portés par les rôles et les délégations. L'annuaire n'en est plus la
    source, et resynchroniser depuis lui écraserait la vérité par une copie périmée.

    Pour attribuer des droits, passez par la page de gestion des droits. Pour une
    reprise depuis un serveur SE4, c'est <info>sambaedu:migrate-rights-to-spatie</info>.
    HELP;

    public function handle(): int
    {
        $this->error('Cette commande est désactivée: les droits web ne sont plus synchronisés depuis l\'AD.');
        $this->line('Utiliser les permissions/rôles SQL (Spatie) pour gérer les droits web.');

        return self::FAILURE;
    }
}
