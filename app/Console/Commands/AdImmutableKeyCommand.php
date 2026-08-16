<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\LdapModels\LdapUser;
use App\Services\Ad\AdImmutableKeyOutcome;
use App\Services\Ad\AdImmutableKeyService;
use Illuminate\Console\Command;
use Throwable;

/**
 * LA POSE DE LA CLÉ IMMUABLE, EN COMMANDE.
 *
 * Doctrine du dépôt : une opération d'exploitation est une COMMANDE, jamais une
 * procédure à rejouer à la main.
 *
 * **Pourquoi elle n'est pas un confort.** Un compte sans clé n'a pas d'identité pour
 * le plan de fichiers cloud : il ne peut ni s'authentifier sur une instance
 * configurée sur cette clé, ni recevoir d'octroi nominatif. Un parc existant n'a
 * AUCUNE clé — la pose automatique ne vaut que pour les comptes créés après.
 * **Ce rattrapage est donc un PRÉREQUIS de bascule, pas un nettoyage ultérieur.**
 *
 * Trois propriétés portent la sûreté de cette commande :
 *
 *  - **elle SÉLECTIONNE explicitement** les trois attributs dont elle a besoin. Ce
 *    n'est pas une optimisation : c'est ce qui rend l'idempotence vraie (sans
 *    l'attribut porteur dans la sélection, il serait relu `null` et réécrit à chaque
 *    passage) *et* ce qui évite de charger tout l'annuaire en mémoire — sur l'outil
 *    dont l'unique raison d'être est le gros parc existant ;
 *  - **elle traite par PAGES sans tout accumuler** — un annuaire tronque en silence
 *    au-delà de sa limite de taille, et une troncature ferait rendre « tout est
 *    conforme » à une commande qui n'aurait vu qu'une partie du parc ;
 *  - **elle n'écrase RIEN sans le montrer**. Une valeur divergente non vide est
 *    listée et comptée à part, jamais écrasée sans `--force`.
 */
final class AdImmutableKeyCommand extends Command
{
    protected $signature = 'ad:immutable-key
        {login? : Login d\'un compte précis ; sans argument, tout l\'annuaire}
        {--dry-run : Montre ce qui serait écrit, sans rien écrire}
        {--force : Écrase les valeurs divergentes non vides (à ne jouer qu\'après les avoir vues)}';

    protected $description = "Pose la clé immuable d'identité (objectGUID en texte) sur les comptes de l'annuaire";

    protected $help = <<<'HELP'
    Pose sur chaque compte d'annuaire une clé d'identité IMMUABLE, dérivée de son
    objectGUID et rendue en texte.

      <info>php artisan ad:immutable-key --dry-run</info>    voir ce qui serait fait
      <info>php artisan ad:immutable-key</info>              tout l'annuaire
      <info>php artisan ad:immutable-key jdupont</info>      un seul compte

    <comment>À quoi ça sert.</comment> Les plans de fichiers cloud stockent leurs octrois et la
    propriété des espaces sous l'identifiant de compte qu'ils calculent depuis
    l'annuaire. Si cet identifiant est le login, un renommage — qui arrive —
    orpheline SILENCIEUSEMENT tous les octrois nominatifs et l'espace personnel.
    Avec cette clé, un renommage devient un non-événement.

    <comment>Prérequis de bascule.</comment> Un parc existant n'a aucune clé. Tant que cette commande
    n'a pas été jouée, les comptes concernés ne peuvent pas s'authentifier sur une
    instance configurée sur cette clé. À jouer AVANT de basculer l'instance, pas
    après.

    <comment>Rien n'est écrasé sans être montré.</comment> Un compte dont l'attribut porte DÉJÀ une
    autre valeur est listé et compté à part, et n'est PAS écrit. L'inventaire du code
    garantit que cet attribut est libre de NOTRE fait ; il ne dit rien d'un annuaire
    qu'un outil tiers a touché. Après les avoir vues, <comment>--force</comment> les écrase.

    L'attribut porteur et l'activation de la pose automatique sont dans
    <info>config/ad_identity.php</info>.

    <comment>Codes de retour.</comment> <info>0</info> n'est rendu que si le parc est EFFECTIVEMENT en règle —
    c'est-à-dire tout conforme, ou tout posé par cette exécution. En simulation,
    <info>0</info> signifie donc « rien à faire », et tout reste à faire rend <info>1</info> : sans quoi
    un enchaînement du genre <info>ad:immutable-key --dry-run && basculer</info> basculerait
    un parc dont aucun compte n'a de clé.
    <info>1</info> couvre aussi : annuaire injoignable, compte inconnu, valeurs divergentes,
    objectGUID inexploitable, écriture en échec.
    HELP;

    public function handle(AdImmutableKeyService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $login = $this->argument('login');
        $single = is_string($login) && $login !== '';

        $this->line(sprintf(
            'Attribut porteur : <info>%s</info>%s%s',
            $service->attribute(),
            $dryRun ? '   <comment>(simulation — rien ne sera écrit)</comment>' : '',
            $force ? '   <comment>(--force : les valeurs divergentes SERONT écrasées)</comment>' : '',
        ));

        $tally = [];
        foreach (AdImmutableKeyOutcome::cases() as $case) {
            $tally[$case->value] = 0;
        }

        /** @var list<array{string, string}> $divergent */
        $divergent = [];
        $seen = 0;

        $handle = function (LdapUser $entry) use ($service, $dryRun, $force, &$tally, &$divergent, &$seen): void {
            $seen++;
            $outcome = $service->ensure($entry, $dryRun, $force);
            $tally[$outcome->value]++;

            if ($outcome === AdImmutableKeyOutcome::Divergent) {
                $divergent[] = [$service->label($entry), (string) $service->currentFor($entry)];

                return;
            }

            if ($outcome === AdImmutableKeyOutcome::Unresolved || $outcome === AdImmutableKeyOutcome::Failed) {
                $this->warn(sprintf(
                    '  %s — %s',
                    $service->label($entry),
                    $outcome === AdImmutableKeyOutcome::Unresolved
                        ? 'aucun objectGUID exploitable, rien tenté'
                        : 'écriture refusée par l\'annuaire',
                ));
            }
        };

        try {
            $single ? $this->eachOfOne($service, $login, $handle) : $this->eachOfAll($service, $handle);
        } catch (Throwable $e) {
            $this->error(sprintf('Annuaire injoignable : %s', $e->getMessage()));

            return self::FAILURE;
        }

        if ($seen === 0) {
            $this->error($single
                ? sprintf('Aucun compte d\'annuaire « %s ».', $login)
                : 'Aucun compte trouvé dans l\'annuaire.');

            return self::FAILURE;
        }

        if ($divergent !== []) {
            $this->newLine();
            $this->warn(sprintf(
                '%d compte(s) portent DÉJÀ une autre valeur dans « %s » — rien n\'a été écrit dessus :',
                count($divergent),
                $service->attribute(),
            ));
            $this->table(['compte', 'valeur actuelle'], $divergent);
            $this->line('Vérifiez d\'où elles viennent avant de rejouer avec <comment>--force</comment>.');
        }

        $this->newLine();
        $this->table(
            ['verdict', 'comptes'],
            [
                ['déjà conforme', $tally[AdImmutableKeyOutcome::Conforme->value]],
                [$dryRun ? 'à écrire' : 'clé posée', $tally[AdImmutableKeyOutcome::Written->value]],
                ['valeur divergente (non écrasée)', $tally[AdImmutableKeyOutcome::Divergent->value]],
                ['sans objectGUID', $tally[AdImmutableKeyOutcome::Unresolved->value]],
                ['écriture en échec', $tally[AdImmutableKeyOutcome::Failed->value]],
            ],
        );

        // `0` ne se rend que si le parc est EFFECTIVEMENT en règle. En simulation,
        // « à écrire » n'est PAS un succès : c'est très exactement ce qui reste à
        // faire, et le taire ferait passer un `--dry-run && basculer` pour un feu vert.
        $remaining = $tally[AdImmutableKeyOutcome::Divergent->value]
            + $tally[AdImmutableKeyOutcome::Unresolved->value]
            + $tally[AdImmutableKeyOutcome::Failed->value]
            + ($dryRun ? $tally[AdImmutableKeyOutcome::Written->value] : 0);

        if ($remaining > 0) {
            $this->error(sprintf(
                '%d compte(s) sans clé conforme. Ces comptes ne pourront pas s\'authentifier sur une instance configurée sur cette clé.',
                $remaining,
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  callable(LdapUser): void  $handle
     */
    private function eachOfOne(AdImmutableKeyService $service, string $login, callable $handle): void
    {
        $entry = LdapUser::query()
            ->select($service->selectFor())
            ->where('samaccountname', '=', $login)
            ->first();

        if ($entry instanceof LdapUser) {
            $handle($entry);
        }
    }

    /**
     * Tout l'annuaire, PAGE PAR PAGE.
     *
     * `chunk()` (et non `get()` ni `paginate()`) pour deux raisons distinctes :
     * `get()` se ferait TRONQUER en silence par la limite de taille du serveur, et
     * `paginate()` accumulerait tout le parc en mémoire avant la première écriture.
     *
     * @param  callable(LdapUser): void  $handle
     */
    private function eachOfAll(AdImmutableKeyService $service, callable $handle): void
    {
        LdapUser::query()
            ->select($service->selectFor())
            ->chunk(500, function ($page) use ($handle): void {
                foreach ($page as $entry) {
                    if ($entry instanceof LdapUser) {
                        $handle($entry);
                    }
                }
            });
    }
}
