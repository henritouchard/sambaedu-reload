<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Nextcloud\NextcloudIdentityLinker;
use Illuminate\Console\Command;

/**
 * Story 61.2 — LE RATTACHEMENT D'IDENTITÉ, EN COMMANDE.
 *
 * Doctrine du dépôt : les opérations d'exploitation sont des COMMANDES, jamais des
 * procédures manuelles à rejouer. La modale de `/admin/settings/files` et cette
 * commande exécutent le MÊME service — le bouton n'est pas un second chemin.
 *
 * **Trois gestes, et un seul écrit :**
 *  - sans option — dit l'identité actuellement mise en cache (lecture pure) ;
 *  - `--set=<id>` — rattache, **après vérification à distance** : une identité que
 *    l'instance ne confirme pas n'est jamais écrite (règle de sécurité de la
 *    correction #2 de la revue 61.1) ;
 *  - `--clear` — détache. Le cache redevient nul ; **rien n'est supprimé côté
 *    Nextcloud** (D9 : jamais de suppression implicite).
 *
 * **Codes de sortie :** `0` rattaché ou déjà conforme, `1` refusé (identité non
 * confirmée, utilisateur inconnu, instance injoignable), `2` usage invalide.
 *
 * **Rejouable** : `--set` d'une valeur déjà en place est un no-op qui n'émet même
 * pas d'appel ; `--clear` d'un utilisateur non rattaché aussi.
 */
class NextcloudIdentityCommand extends Command
{
    protected $signature = 'nextcloud:identity
        {login : Login SE5 de l\'utilisateur}
        {--set= : Identifiant Nextcloud à rattacher (vérifié auprès de l\'instance avant écriture)}
        {--clear : Retire le rattachement (le cache seulement — rien n\'est supprimé côté Nextcloud)}';

    protected $description = 'Rattache explicitement un utilisateur SE5 à une identité Nextcloud, ou l\'en détache';

    public function handle(NextcloudIdentityLinker $linker): int
    {
        $login = trim((string) $this->argument('login'));
        $set = trim((string) ($this->option('set') ?? ''));
        $clear = (bool) $this->option('clear');

        if ($set !== '' && $clear) {
            $this->error('--set et --clear s\'excluent : choisissez lequel des deux.');

            return 2;
        }

        if ($login === '') {
            $this->error('Le login SE5 est requis.');

            return 2;
        }

        if ($set === '' && ! $clear) {
            $current = $linker->current($login);

            $this->line($current === null
                ? sprintf('« %s » n\'est rattaché à aucune identité Nextcloud.', $login)
                : sprintf('« %s » → identité Nextcloud « %s ».', $login, $current));

            return 0;
        }

        $result = $clear ? $linker->clear($login) : $linker->link($login, $set);

        if ($result->isFailure()) {
            $this->error($result->message);

            return 1;
        }

        $result->alreadyConforming
            ? $this->comment($result->message)
            : $this->info($result->message);

        return 0;
    }
}
