<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\OpenCloud\OpenCloudIdentityLinker;
use Illuminate\Console\Command;

/**
 * LE RATTACHEMENT D'IDENTITÉ OPENCLOUD, EN COMMANDE.
 *
 * Doctrine du dépôt : les opérations d'exploitation sont des COMMANDES, jamais des
 * procédures manuelles à rejouer.
 *
 * **Pourquoi cette commande est indispensable et non un confort.** Le backend
 * traduit un sujet de plan en compte distant par le cache d'identité, et par rien
 * d'autre : sans un geste pour le remplir, tout octroi NOMINATIF (le dossier
 * personnel d'un élève) échouerait en nommant une remédiation qui n'existerait
 * pas. Un message qui renvoie vers un geste absent est exactement le défaut que
 * cet epic combat.
 *
 * **Trois gestes, et un seul écrit :**
 *  - sans option — dit l'identité actuellement en cache (lecture pure, aucun
 *    appel) ;
 *  - `--set=<id>` — rattache, **après confirmation à distance**, et refuse en
 *    NOMMANT le login qui détiendrait déjà cette identité ;
 *  - `--clear` — détache. Le cache redevient nul ; **rien n'est supprimé côté
 *    instance**.
 */
final class OpenCloudIdentityCommand extends Command
{
    protected $signature = 'opencloud:identity
        {login : Login SE5 de l\'utilisateur}
        {--set= : Identité OpenCloud à rattacher (identifiant interne ou identifiant de connexion, confirmée auprès de l\'instance avant écriture)}
        {--clear : Retire le rattachement (le cache seulement — rien n\'est supprimé côté instance)}';

    protected $description = "Rattache un utilisateur SE5 à son identité OpenCloud, ou l'en détache";

    protected $help = <<<'HELP'
    Rattache un utilisateur SE5 à son compte OpenCloud, consulte ce rattachement, ou
    le retire.

      <info>php artisan opencloud:identity jdupont</info>                    ce qui est rattaché
      <info>php artisan opencloud:identity jdupont --set=jdupont</info>      rattacher
      <info>php artisan opencloud:identity jdupont --clear</info>            détacher

    Sans option, la commande LIT et n'écrit rien — elle n'émet même aucun appel.

    <comment>--set</comment> accepte l'identifiant interne du compte OU son identifiant de connexion,
    et confirme l'un comme l'autre auprès de l'instance AVANT d'écrire : c'est
    toujours la valeur RENDUE par l'instance qui est enregistrée. Une identité déjà
    portée par un autre utilisateur SE5 est refusée, en le nommant — une identité
    n'appartient qu'à une seule personne, faute de quoi le dossier personnel d'un
    élève pourrait s'ouvrir chez un tiers.

    <comment>--clear</comment> ne retire que le rattachement côté SE5. <comment>Rien n'est supprimé côté
    instance</comment> — ni compte, ni fichier, ni partage.

    À quoi sert ce rattachement : sans lui, un droit accordé nominativement à cette
    personne ne peut pas être écrit, et la réconciliation du répertoire concerné
    échoue en la nommant.

    Codes de retour : <info>0</info> rattaché ou déjà conforme · <info>1</info> refusé (identité non
    confirmée, déjà portée, utilisateur inconnu, instance injoignable)
    HELP;

    public function handle(OpenCloudIdentityLinker $linker): int
    {
        $login = (string) $this->argument('login');
        $user = User::query()->where('login', $login)->first();

        if (! $user instanceof User) {
            $this->error(sprintf('Aucun utilisateur SE5 « %s ».', $login));

            return self::FAILURE;
        }

        if ((bool) $this->option('clear')) {
            $result = $linker->unlink($user);
            $this->line($result->message);

            return $result->isFailure() ? self::FAILURE : self::SUCCESS;
        }

        $set = trim((string) ($this->option('set') ?? ''));

        if ($set === '') {
            $current = $linker->current($user);
            $this->line($current === null
                ? sprintf('« %s » n\'est rattaché à aucune identité OpenCloud.', $login)
                : sprintf('« %s » est rattaché à l\'identité OpenCloud « %s ».', $login, $current));

            return self::SUCCESS;
        }

        $result = $linker->link($user, $set);

        $result->isFailure() ? $this->error($result->message) : $this->info($result->message);

        return $result->isFailure() ? self::FAILURE : self::SUCCESS;
    }
}
