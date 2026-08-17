<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Config\SambaEduConfig;
use App\Exceptions\Nextcloud\NextcloudConfigurationException;
use App\Models\User;
use App\Services\Nextcloud\NextcloudAdminClient;
use App\Services\Nextcloud\NextcloudClientFactory;
use App\Services\Nextcloud\NextcloudFailure;
use App\Services\Nextcloud\NextcloudLdapSyncSettings;
use Illuminate\Console\Command;

/**
 * RATTACHER L'INSTANCE NEXTCLOUD À L'ANNUAIRE, PAR UNE COMMANDE.
 *
 * ---------------------------------------------------------------------------
 * **POURQUOI CETTE COMMANDE EXISTE.** Les comptes Nextcloud du stock existant ne
 * peuvent pas venir de SE5 : le provisionnement refuse par conception d'inventer
 * un mot de passe ({@see \App\Services\Nextcloud\NextcloudUserProvisioner}), et le
 * mot de passe n'est en main qu'à la création d'un compte SE5 ou à son changement.
 * Un compte du stock est donc *rapporté* introuvable, jamais fabriqué. Le seul
 * chemin qui reste est que l'instance lise l'annuaire elle-même.
 *
 * Ce réglage se faisait à la main, écran par écran, instance par instance. La
 * doctrine du dépôt est qu'une opération multi-instance est une COMMANDE : une
 * procédure à rejouer n'est pas un mécanisme, elle diverge dès la deuxième
 * instance et personne ne sait plus laquelle est à jour.
 * ---------------------------------------------------------------------------
 *
 * **AUCUNE VALEUR N'EST SAISIE.** Tout est dérivé de `sambaedu.conf` (URL, port,
 * DN de base, compte de lecture, RDN des utilisateurs) et de la connexion Nextcloud
 * déjà enregistrée. Il n'y a rien à retaper, donc rien à retaper de travers.
 *
 * **100 % HTTP.** Pas de `docker exec`, pas d'appel à l'outil d'administration en
 * ligne de commande de l'instance : cette commande parle à l'API, comme tout le
 * reste du canal Nextcloud. C'est ce qui la rend falsifiable par `Http::fake()`,
 * donc testable sans réseau — et c'est aussi ce qui la fait marcher quand
 * l'instance n'est pas sur la même machine, ce qui est le cas général.
 *
 * **CE QU'ELLE NE FAIT PAS : LES GROUPES.** Motivé dans
 * {@see NextcloudLdapSyncSettings} — un groupe visible dans l'instance est un
 * groupe sur lequel on peut accrocher un partage, donc un second plan de
 * permissions sur une zone que Samba arbitre déjà.
 *
 * **Codes de sortie :**
 *  - `0` — l'instance lit l'annuaire, et la vérification l'a constaté (ou il n'y
 *    avait personne à chercher) ;
 *  - `1` — la configuration est posée mais la VÉRIFICATION A ÉCHOUÉ : l'instance
 *    ne trouve pas une personne qui existe pourtant dans l'annuaire. C'est le cas
 *    du certificat non accepté ({@see self::CERTIFICATE_HINT}) ;
 *  - `2` — rien n'a été écrit : configuration incomplète de notre côté, capacité
 *    Nextcloud éteinte, instance injoignable, privilège insuffisant, ou
 *    configuration divergente sans `--force`.
 */
class NextcloudConfigureLdapCommand extends Command
{
    /**
     * L'app de l'instance qui porte la synchro d'annuaire. Elle est livrée avec
     * Nextcloud mais DÉSACTIVÉE par défaut — d'où l'activation par l'API, qui
     * évite d'avoir à entrer dans le conteneur.
     */
    private const LDAP_APP = 'user_ldap';

    /**
     * Combien d'identifiants de configuration on sonde avant de conclure qu'il n'y
     * en a pas. L'API n'expose aucune liste (mesuré : `405` sur la collection), le
     * sondage est donc la seule voie. Dix est large : une instance qui aurait dix
     * configurations d'annuaire a un problème que cette commande ne résoudra pas.
     */
    private const CONFIG_PROBE_LIMIT = 10;

    /**
     * Combien de comptes on cherche avant de conclure. **Il en faut PLUSIEURS** :
     * le premier compte d'annuaire venu peut porter le même nom qu'un compte local
     * de l'instance (mesuré : `admin`), et l'instance répond alors par son compte
     * local — ce qui ne prouve rien du tout. On continue jusqu'à en trouver un que
     * l'annuaire sert vraiment.
     */
    private const PROBE_CANDIDATES = 5;

    /**
     * Le diagnostic qu'on ne peut pas obtenir de l'API, et qu'il faut donc dire
     * d'avance. Un annuaire Samba présente un certificat auto-signé ; sans
     * `--trust-self-signed`, la liaison échoue — et elle échoue en SILENCE côté
     * API : l'écriture réussit, la configuration se dit active, et aucune personne
     * n'apparaît.
     */
    private const CERTIFICATE_HINT = 'Un annuaire Samba présente un certificat auto-signé, que rien ne peut '
        .'vérifier. Si la liaison échoue, rejouez avec --trust-self-signed.';

    protected $signature = 'nextcloud:configure-ldap
        {--dry-run : Affiche la configuration qui serait posée, sans rien écrire}
        {--force : Réécrit une configuration existante qui diverge de celle calculée}
        {--trust-self-signed : Accepte un certificat d\'annuaire que rien ne peut vérifier — nécessaire face à un AD Samba}
        {--probe= : Login à chercher pour vérifier la liaison, au lieu d\'en prendre un de l\'annuaire}';

    protected $description = 'Règle la synchro d\'annuaire de l\'instance Nextcloud depuis la configuration SambaEdu';

    protected $help = <<<'HELP'
    <comment>Ce que fait cette commande.</comment> Elle active l'app de synchro d'annuaire de
    l'instance Nextcloud, y pose une configuration dérivée de <info>sambaedu.conf</info>, et
    vérifie que l'instance voit bien une personne réelle de l'annuaire.

    Après quoi les comptes du stock EXISTENT côté Nextcloud, ce que
    <info>nextcloud:provision</info> ne peut pas faire : il refuse d'inventer un mot de passe,
    donc il rapporte les comptes absents au lieu de les fabriquer.

    <comment>Rien à saisir.</comment> URL, port, DN de base, compte de lecture et conteneur des
    utilisateurs viennent de <info>sambaedu.conf</info> ; l'accès admin à l'instance vient de la
    connexion Nextcloud déjà enregistrée dans Administration › Fichiers.

    <comment>Le certificat.</comment> Un AD Samba présente un certificat auto-signé. La liaison
    échoue alors, et elle échoue en silence : l'écriture réussit, la configuration se
    dit active, et aucune personne n'apparaît. <info>--trust-self-signed</info> accepte ce
    certificat. Ce n'est PAS un défaut : le chemin legacy désactivait la vérification
    TLS en dur dans le code, ce qui rendait la faiblesse invisible.

    <comment>Ce qu'elle ne défait pas.</comment> Elle restreint la recherche au conteneur des
    utilisateurs, ce qui évite d'embarquer les comptes de service et l'homonyme du
    compte admin de l'instance. Mais c'est PRÉVENTIF : si une configuration plus large
    a déjà tourné, les comptes qu'elle a rattachés RESTENT connus de l'instance, en
    « reliquats ». Ils se traitent côté instance, pas ici.

    <comment>Les groupes ne sont pas synchronisés</comment>, et leurs réglages sont remis à vide.
    Un groupe visible dans l'instance est un groupe sur lequel n'importe qui peut
    accrocher un partage Nextcloud — un second plan de permissions sur une zone que
    Samba arbitre déjà. L'authentification n'en a aucun besoin.

    <comment>Idempotente.</comment> Rejouée sur une configuration identique, elle l'annonce et
    n'écrit rien. Si la configuration en place DIFFÈRE, elle affiche les écarts et
    refuse sans <info>--force</info>.

    <comment>La vérification.</comment> Après l'écriture, la commande cherche des personnes dans
    l'instance : par défaut les premiers comptes d'annuaire actifs de SE5, jusqu'à en
    trouver un que l'annuaire sert VRAIMENT — un compte peut porter le même nom qu'un
    compte local de l'instance, qui répond alors sans rien prouver. <info>--probe=</info> en
    désigne un seul. Aucun trouvé, la commande sort en <info>1</info> : la configuration est
    posée, mais elle ne sert à rien.

    <comment>Exemples</comment>
      <info>php artisan nextcloud:configure-ldap --dry-run</info>
      <info>php artisan nextcloud:configure-ldap --trust-self-signed</info>
      <info>php artisan nextcloud:configure-ldap --trust-self-signed --force</info>
    HELP;

    public function handle(NextcloudClientFactory $factory, SambaEduConfig $sambaedu): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // ① NOTRE côté. Poser une configuration incomplète activerait une synchro
        //    qui ne peut pas se lier, et l'instance annoncerait « active » sur une
        //    liaison morte.
        $ldap = $sambaedu->ldap();
        $missing = NextcloudLdapSyncSettings::missingFrom($ldap);

        if ($missing !== []) {
            $this->error('REFUS : la configuration SambaEdu ne suffit pas à régler la synchro d\'annuaire.');
            foreach ($missing as $item) {
                $this->line('  Manque : '.$item);
            }
            $this->line('Complétez /etc/sambaedu/sambaedu.conf, puis rejouez.');

            return 2;
        }

        // ② LE côté instance. Capacité éteinte ou connexion incomplète : aucun
        //    appel n'est émis, et le refus nomme ce qui manque.
        try {
            $client = $factory->make();
        } catch (NextcloudConfigurationException $e) {
            $this->error('REFUS : '.$e->getMessage());
            $this->line('Réglez la connexion dans Administration › Fichiers, onglet Emplacements et cloud.');

            return 2;
        }

        $settings = NextcloudLdapSyncSettings::for($ldap, (bool) $this->option('trust-self-signed'));

        $this->renderIntent($settings);

        if (! (bool) $this->option('trust-self-signed')) {
            $this->warn(self::CERTIFICATE_HINT);
        }

        // ③ L'app doit être active AVANT toute lecture de configuration : tant
        //    qu'elle est éteinte, ses routes n'existent pas et répondent `404`.
        //    En simulation on ne l'active pas, donc on ne peut rien relire non
        //    plus — l'aperçu s'arrête ici, et il le dit.
        if ($dryRun) {
            $this->comment('SIMULATION — aucune écriture n\'a été émise.');
            $this->line(
                'L\'état de l\'instance n\'est pas relu : la lecture d\'une configuration exige que l\'app '
                .'de synchro soit active, ce qui est déjà une écriture.'
            );

            return 0;
        }

        $enabled = $client->enableApp(self::LDAP_APP);
        if ($enabled->isFailure()) {
            $this->error($enabled->message);

            return 2;
        }

        // ④ Une configuration existe-t-elle déjà, et laquelle ?
        $existing = $this->findExistingConfig($client, $settings);
        if ($existing === false) {
            return 2;
        }

        if ($existing !== null && $existing['conforming']) {
            $this->info(sprintf(
                'Déjà conforme : la configuration « %s » de l\'instance dit déjà cela. Rien à écrire.',
                $existing['id'],
            ));

            return $this->verify($client, 0);
        }

        if ($existing !== null && ! (bool) $this->option('force')) {
            $this->error(sprintf(
                'REFUS : la configuration « %s » de l\'instance diffère de celle calculée — '
                .'elle n\'est pas réécrite sans --force.',
                $existing['id'],
            ));
            $this->renderDivergences($settings, $existing['keys']);

            return 2;
        }

        $configId = $existing['id'] ?? null;

        if ($configId === null) {
            $created = $client->createLdapConfig();
            if ($created->isFailure()) {
                $this->error($created->message);

                return 2;
            }

            $configId = is_string($created->data['configID'] ?? null) ? $created->data['configID'] : null;

            if ($configId === null) {
                $this->error(
                    'L\'instance a accepté la création mais n\'a pas rendu d\'identifiant de configuration : '
                    .'on ne sait pas où écrire, et on n\'écrit pas au hasard.'
                );

                return 2;
            }

            $this->line('Configuration créée sur l\'instance : '.$configId);
        }

        $written = $client->writeLdapConfig($configId, $settings->keysForWriting());
        if ($written->isFailure()) {
            $this->error($written->message);

            return 2;
        }

        $this->info(sprintf('Configuration « %s » posée : l\'instance lit désormais l\'annuaire.', $configId));

        return $this->verify($client, 0);
    }

    /**
     * Ce qu'on va poser, dit AVANT de le poser. Le mot de passe du compte de
     * lecture n'y est pas : il n'est pas dans la carte affichable.
     */
    private function renderIntent(NextcloudLdapSyncSettings $settings): void
    {
        $shown = [
            'serveur' => $settings->keys['ldapHost'].':'.$settings->keys['ldapPort'],
            'compte de lecture' => $settings->keys['ldapAgentName'],
            'DN de base' => $settings->keys['ldapBase'],
            'conteneur des personnes' => $settings->keys['ldapBaseUsers'],
            'identifiant interne' => $settings->keys['ldapExpertUsernameAttr'],
            'certificat non vérifiable' => $settings->keys['turnOffCertCheck'] === '1' ? 'accepté' : 'refusé',
            'synchro des groupes' => 'aucune (réglages remis à vide)',
        ];

        $this->table(
            ['réglage', 'valeur'],
            array_map(static fn (string $k, string $v): array => [$k, $v], array_keys($shown), $shown),
        );
    }

    /** @param  array<string, mixed>  $remote */
    private function renderDivergences(NextcloudLdapSyncSettings $settings, array $remote): void
    {
        $this->table(
            ['clé', 'sur l\'instance', 'calculé'],
            array_map(
                static fn (array $d): array => [$d['cle'], $d['actuel'], $d['voulu']],
                $settings->divergences($remote),
            ),
        );
        $this->line('Rejouez avec <info>--force</info> pour écraser, ou corrigez sambaedu.conf.');
    }

    /**
     * La configuration existante, `null` s'il n'y en a aucune, `false` si
     * l'instance a répondu un échec qui n'est PAS une absence — auquel cas on
     * n'écrit rien : on ne sait pas ce qu'il y a en face.
     *
     * @return array{id: string, keys: array<string, mixed>, conforming: bool}|null|false
     */
    private function findExistingConfig(
        NextcloudAdminClient $client,
        NextcloudLdapSyncSettings $settings,
    ): array|null|false {
        $firstNonConforming = null;

        for ($index = 1; $index <= self::CONFIG_PROBE_LIMIT; $index++) {
            $configId = sprintf('s%02d', $index);
            $read = $client->readLdapConfig($configId);

            if ($read->failure === NextcloudFailure::Absent) {
                // La borne du sondage : les identifiants sont attribués en suite,
                // le premier trou est donc la fin.
                break;
            }

            if ($read->isFailure()) {
                $this->error($read->message);
                $this->line('Rien n\'a été écrit : on ne réécrit pas une instance dont on ne sait pas lire l\'état.');

                return false;
            }

            if ($settings->matches($read->data)) {
                return ['id' => $configId, 'keys' => $read->data, 'conforming' => true];
            }

            // On retient la PREMIÈRE divergente, et on continue : une instance
            // peut porter une configuration conforme après une non conforme, et
            // s'arrêter à la première ferait réécrire pour rien.
            $firstNonConforming ??= ['id' => $configId, 'keys' => $read->data, 'conforming' => false];
        }

        return $firstNonConforming;
    }

    /**
     * LA LIAISON FONCTIONNE-T-ELLE VRAIMENT ?
     *
     * L'écriture qui réussit ne prouve rien : l'instance ne valide pas à
     * l'écriture (mesuré), et une liaison refusée est SILENCIEUSE. On cherche donc
     * une personne réelle. Trouvée, la synchro voit l'annuaire ; introuvable, la
     * configuration est posée pour rien — et le dire vaut mieux qu'un « OK » qui
     * n'a rien constaté.
     *
     * **Pourquoi la sonde directe et non la recherche** : mesuré le 2026-08-17,
     * `?search=` avec un login pointé rend zéro résultat là où la sonde par
     * identifiant rend le compte. La sonde est aussi la première étape de la
     * résolution d'identité du provisionnement — même chemin, même résultat.
     */
    private function verify(NextcloudAdminClient $client, int $successCode): int
    {
        $candidates = $this->probeLogins();

        if ($candidates === []) {
            $this->comment(
                'Vérification non faite : aucun compte d\'annuaire actif dans SE5 à chercher. '
                .'Désignez-en un avec --probe=<login>.'
            );

            return $successCode;
        }

        $localOnly = null;
        $lastFailure = null;

        foreach ($candidates as $login) {
            $result = $client->getUser($login);

            if (! $result->successful) {
                $lastFailure = [$login, $result->message];

                continue;
            }

            $backend = is_string($result->data['backend'] ?? null) ? $result->data['backend'] : '?';

            if (strtoupper($backend) === 'LDAP') {
                $this->info(sprintf('Vérifié : l\'instance connaît « %s » par l\'annuaire.', $login));

                return $successCode;
            }

            // Le compte est là mais il vient d'ailleurs : la synchro n'y est pour
            // rien, et croire l'avoir vérifiée serait le pire des deux mondes. On
            // continue avec le candidat suivant — celui-ci ne prouve rien, mais il
            // n'infirme rien non plus.
            $localOnly ??= [$login, $backend];
        }

        if ($localOnly !== null) {
            $this->warn(sprintf(
                'LIAISON NON PROUVÉE : les comptes cherchés existent déjà LOCALEMENT sur l\'instance '
                .'(« %s » est un compte %s), la synchro n\'y est donc pour rien. Désignez avec '
                .'--probe= un login qui n\'a pas de compte local.',
                $localOnly[0],
                $localOnly[1],
            ));

            return $successCode;
        }

        $this->error(sprintf(
            'LA CONFIGURATION EST POSÉE MAIS NE SERT À RIEN : l\'instance ne connaît aucun des %d '
            .'comptes cherchés, « %s » compris, qui existent pourtant dans l\'annuaire.',
            count($candidates),
            $lastFailure[0] ?? '?',
        ));
        $this->line('  '.($lastFailure[1] ?? ''));

        if (! (bool) $this->option('trust-self-signed')) {
            $this->line(self::CERTIFICATE_HINT);
        }

        return 1;
    }

    /**
     * Les logins à chercher : celui qu'on désigne, sinon les premiers comptes
     * d'annuaire actifs de SE5.
     *
     * **Ils sont pris dans SE5, pas dans l'annuaire** : c'est la population dont on
     * veut qu'elle puisse se connecter, et une personne absente de SE5 n'est pas un
     * cas d'usage de ce produit.
     *
     * @return list<string>
     */
    private function probeLogins(): array
    {
        $designated = trim((string) $this->option('probe'));

        if ($designated !== '') {
            return [$designated];
        }

        return User::query()
            ->where('source', 'ad')
            ->where('is_active', true)
            ->orderBy('login')
            ->limit(self::PROBE_CANDIDATES)
            ->pluck('login')
            ->filter(static fn (mixed $login): bool => is_string($login) && $login !== '')
            ->values()
            ->all();
    }
}
