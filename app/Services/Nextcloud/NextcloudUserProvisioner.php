<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

use App\Enums\NextcloudInstanceMode;
use App\Exceptions\Nextcloud\NextcloudConfigurationException;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Story 61.1 — LES COMPTES NEXTCLOUD : création au fil de l'eau, adoption pour le
 * stock, propagation du mot de passe.
 *
 * ---------------------------------------------------------------------------
 * **POURQUOI CES COMPTES EXISTENT.** Le montage `files_external` de cette story
 * utilise les « identifiants de connexion, enregistrés en session » : Nextcloud
 * relaie à Samba les identifiants de l'utilisateur CONNECTÉ. Le montage n'est donc
 * fonctionnel que si l'utilisateur s'authentifie auprès de Nextcloud avec ses
 * identifiants AD — soit parce que l'instance a une synchro LDAP, soit parce que
 * SE5 y a créé un compte local avec le même mot de passe. Cette classe est le
 * second cas.
 * ---------------------------------------------------------------------------
 *
 * **On n'invente JAMAIS de mot de passe.** Un compte Nextcloud créé avec un aléa
 * est un compte auquel personne ne peut se connecter : le montage lui montrerait
 * une erreur d'authentification SMB, et le compteur du rapport serait au vert.
 * C'est la signature de défaut des Epics 56/57 — un signal qui n'atteint pas son
 * destinataire. Pour le stock existant, un compte absent est donc **rapporté**
 * (compteur `introuvables`, avec la marche à suivre), jamais fabriqué. Le mot de
 * passe n'est en main qu'à deux moments : la création SE5 et le changement de mot
 * de passe — et ce sont exactement les deux crochets de cette classe.
 *
 * **Le cache d'identité (`users.nextcloud_user_id`) est un CACHE.** L'identité
 * Nextcloud n'est pas nécessairement le login SE5 ; SE4 la cachait dans
 * l'attribut AD `Id NC` (`cloud.inc.php:702`), ce que SE5 ne peut pas reprendre —
 * l'AD est un artefact compilé depuis Postgres, pas un lieu d'écriture d'état
 * applicatif. La colonne est nullable, reconstructible, hors `$fillable`, et la
 * vérité reste chez Nextcloud.
 *
 * **Limite CONNUE, à dire plutôt qu'à taire** : sur une instance à synchro LDAP,
 * la création au fil de l'eau (AC5) émet un `POST cloud/users` avec `userid =
 * login`. Si la synchro attribue à ce même utilisateur un identifiant DIFFÉRENT
 * (mappage sur un GUID), la création n'est pas rejetée en `102` et un second
 * compte, local, peut apparaître. Le cas ne s'est pas présenté au cadrage (les
 * instances d'établissement SE4 mappent l'identifiant sur le login) et la story
 * prescrit cet ordre ; il est nommé ici pour être reconnu s'il survient, pas
 * contourné par une abstraction spéculative. Le balayage du stock, lui, ne
 * masque plus ce cas : depuis la revue, l'adoption ne retient QUE l'homonyme
 * ({@see resolveRemote()}), et un identifiant divergent est rapporté comme
 * candidat écarté au lieu d'être adopté à tort.
 */
final class NextcloudUserProvisioner
{
    /**
     * Identifiants rendus par l'autocomplétion sans qu'aucun soit l'homonyme du
     * login cherché. Ils ne sont JAMAIS adoptés (voir {@see resolveRemote()}) —
     * mais les taire ferait d'un « introuvable » un silence, alors que
     * l'instance, elle, a bien répondu quelque chose. Ils remontent au rapport
     * (AC8) pour que l'exploitant sache où regarder.
     *
     * @var list<string>
     */
    private array $discardedCandidates = [];

    /**
     * DISJONCTEUR DE LOT (revue 61.1 #5). Une réinitialisation en masse propage
     * le mot de passe utilisateur par utilisateur, **dans le cycle d'une requête
     * HTTP**. Chaque tentative vers une instance injoignable coûte le délai
     * complet du client (15 s) : trente élèves feraient sept minutes et demie
     * d'attente, au-delà de tout `max_execution_time` et de tout mandataire.
     *
     * Le fail-soft protège chaque itération ; il ne protège pas le BUDGET DE
     * TEMPS global. Ce drapeau le protège : à la première défaillance
     * d'INFRASTRUCTURE (instance injoignable), on cesse d'essayer pour le reste
     * du lot, et {@see flushBatchSkips()} émet UN avertissement disant combien de
     * comptes n'ont pas été propagés — jamais un avertissement par utilisateur.
     *
     * **Un refus propre à UN compte n'ouvre PAS le disjoncteur** (compte LDAP non
     * modifiable, compte absent, privilège sur ce compte) : ce sont des états
     * normaux, propres à un utilisateur, et les suivants doivent être tentés.
     */
    private bool $batchCircuitOpen = false;

    private int $batchSkipped = 0;

    private ?string $batchCircuitReason = null;

    public function __construct(private readonly NextcloudClientFactory $factory)
    {
    }

    /**
     * AC5 — CRÉATION AU FIL DE L'EAU, appelée depuis le flux de création SE5.
     *
     * **Fail-soft, et compté dans le journal** : l'échec de Nextcloud ne bloque
     * jamais la création SE5 (iso `configureUserCloud`). Mais il n'est pas muet —
     * un avertissement nommé part au journal, avec le login et la cause.
     *
     * Capacité éteinte ou configuration incomplète ⇒ **aucun appel émis**, aucun
     * journal : ce n'est pas une panne, c'est une instance qui n'utilise pas
     * Nextcloud.
     */
    public function ensureAccountAtCreation(string $login, string $password): void
    {
        try {
            $client = $this->factory->make();
        } catch (NextcloudConfigurationException $e) {
            $this->traceDelegatedSkip('nextcloud.user.create.delegated_mode', $login, $e);

            return;
        }

        try {
            $result = $client->createUser($login, $password);
        } catch (\Throwable $e) {
            Log::warning('nextcloud.user.create.error', ['login' => $login, 'error' => $e->getMessage()]);

            return;
        }

        if ($result->isFailure()) {
            Log::warning('nextcloud.user.create.failed', [
                'login' => $login,
                'failure' => $result->failure?->value,
                'message' => $result->message,
            ]);

            return;
        }

        // L'identifiant est celui que NOUS avons envoyé — première étape de la
        // résolution ordonnée (AC6), et la seule qui ne coûte aucun appel.
        $held = $this->cacheIdentity($login, $login);

        if ($held !== null) {
            // Deux logins SE5 distincts ne peuvent pas être homonymes du même
            // compte : si ça arrive, la colonne a été écrite ailleurs à tort, et
            // l'écraser mettrait la prochaine propagation de mot de passe sur le
            // compte d'un tiers. On ne l'écrase pas, et on le DIT.
            Log::warning('nextcloud.identity.cache.conflict', [
                'login' => $login,
                'nextcloud_user_id' => $login,
                'held_by' => $held,
            ]);
        }

        Log::info($result->alreadyConforming ? 'nextcloud.user.adopted' : 'nextcloud.user.created', [
            'login' => $login,
        ]);
    }

    /**
     * AC5/AC6 — ADOPTION du stock existant : on résout, on cache, on ne crée pas.
     *
     * Rend `true` si l'utilisateur est (désormais) rattaché à une identité
     * Nextcloud connue.
     */
    public function adopt(
        User $user,
        NextcloudAdminClient $client,
        NextcloudProvisioningReport $report,
        bool $dryRun,
    ): void {
        $login = (string) $user->login;

        if ((string) ($user->nextcloud_user_id ?? '') !== '') {
            // Déjà résolu : AUCUN appel. C'est l'invariant testé de l'AC6 — le
            // cache n'aurait aucune valeur si on le rechargeait à chaque passage.
            $report->countUserAdopted();

            return;
        }

        // La simulation LIT (une lecture n'est pas une écriture, et un `--dry-run`
        // qui ne dirait rien du stock serait sans valeur) mais ne PERSISTE rien :
        // le cache n'est écrit que hors simulation.
        $this->discardedCandidates = [];
        $resolved = $this->resolveRemote($client, $login);

        if ($resolved instanceof NextcloudResult) {
            $report->countUserFailed($login, $resolved->message);

            return;
        }

        if ($resolved === null) {
            if ($this->discardedCandidates !== []) {
                Log::info('nextcloud.identity.candidates_discarded', [
                    'login' => $login,
                    'candidates' => $this->discardedCandidates,
                ]);
            }

            $report->countUserMissing($login, $this->discardedCandidates);

            return;
        }

        if (! $dryRun) {
            $held = $this->cacheIdentity($login, $resolved);

            if ($held !== null) {
                // **Compté et rapporté, JAMAIS une exception** : un balayage de
                // rentrée ne s'interrompt pas parce qu'un compte est ambigu — mais
                // il ne l'écrit pas non plus, et l'exploitant lit lequel.
                Log::warning('nextcloud.identity.cache.conflict', [
                    'login' => $login,
                    'nextcloud_user_id' => $resolved,
                    'held_by' => $held,
                ]);

                $report->countUserFailed($login, sprintf(
                    'Identité Nextcloud « %s » déjà rattachée à l\'utilisateur SE5 « %s » : rien n\'a été '
                    . 'écrit. Deux comptes SE5 pointant la même identité feraient qu\'un changement de mot '
                    . 'de passe de l\'un écraserait le compte de l\'autre.',
                    $resolved,
                    $held,
                ));

                return;
            }
        }

        $report->countUserAdopted();
    }

    /**
     * AC7 — PROPAGATION DU MOT DE PASSE, sous DOUBLE condition : la capacité est
     * active ET la colonne d'identité est remplie.
     *
     * La seconde condition n'est pas une optimisation : sans identité connue, on ne
     * sait pas QUEL compte mettre à jour, et deviner (« ce doit être le login »)
     * écrirait à l'aveugle sur une instance dont on ignore la convention.
     *
     * **Fail-soft mais VISIBLE** : jamais un blocage du changement AD, jamais un
     * silence total. Une instance à synchro LDAP refuse ou ignore ce `PUT` — les
     * deux sont tolérés et journalisés en `debug`, pas en erreur : ce n'est pas une
     * panne, c'est une instance dont les mots de passe viennent d'ailleurs.
     */
    public function propagatePassword(string $login, string $newPassword): void
    {
        if ($this->batchCircuitOpen) {
            // Le disjoncteur est ouvert : on ne retente RIEN pour ce lot. Le
            // décompte part en un seul avertissement à la fin.
            $this->batchSkipped++;

            return;
        }

        try {
            $client = $this->factory->make();
        } catch (NextcloudConfigurationException $e) {
            $this->traceDelegatedSkip('nextcloud.user.password.delegated_mode', $login, $e);

            return;
        }

        $nextcloudId = $this->cachedIdentity($login);
        if ($nextcloudId === null) {
            return;
        }

        try {
            $result = $client->setUserPassword($nextcloudId, $newPassword);
        } catch (\Throwable $e) {
            Log::warning('nextcloud.user.password.error', ['login' => $login, 'error' => $e->getMessage()]);

            return;
        }

        if ($result->failure === NextcloudFailure::Injoignable) {
            // Défaillance d'INFRASTRUCTURE : elle vaudra pour tous les suivants.
            $this->batchCircuitOpen = true;
            $this->batchCircuitReason = $result->message;
            $this->batchSkipped++;

            return;
        }

        if ($result->successful) {
            Log::info('nextcloud.user.password.propagated', ['login' => $login]);

            return;
        }

        if (
            $result->failure === NextcloudFailure::Refus
            || $result->failure === NextcloudFailure::Absent
            || $result->failure === NextcloudFailure::Privilege
        ) {
            // Le cas « instance à synchro LDAP » : le compte n'est pas modifiable
            // par cette API. Ce n'est pas une panne de SE5.
            //
            // **Pourquoi le PRIVILÈGE est ici, et seulement ici.** Un compte LDAP
            // qui refuse la modification répond 403 / OCS 997, que le client
            // classe en `Privilege`. Dans CE contexte précis — un `PUT` de mot de
            // passe sur un compte dont on sait déjà qu'il existe — un 403/997
            // signifie « ce compte n'est pas modifiable par cette API » bien plus
            // souvent que « le compte de service n'est plus admin » ; et à chaque
            // réinitialisation en masse (des centaines de comptes à la rentrée),
            // le compter comme une panne noierait le journal sous un
            // avertissement par utilisateur pour un état parfaitement normal.
            //
            // Le second cas — un privilège réellement cassé — n'est pas perdu
            // pour autant : la sonde de connexion (AC1) et le provisionnement
            // (AC8/AC9) le diagnostiquent, eux, en échec net et nommé. Ce sont
            // les endroits où un privilège cassé DOIT crier ; la classification
            // générale du client n'est pas touchée.
            Log::debug('nextcloud.user.password.not_applicable', [
                'login' => $login,
                'failure' => $result->failure?->value,
                'message' => $result->message,
            ]);

            return;
        }

        Log::warning('nextcloud.user.password.failed', [
            'login' => $login,
            'failure' => $result->failure?->value,
            'message' => $result->message,
        ]);
    }

    /**
     * Clôt un LOT de propagations : émet **un seul** avertissement disant combien
     * de comptes n'ont pas été propagés, et pourquoi, puis referme le
     * disjoncteur.
     *
     * À appeler après la boucle d'une réinitialisation en masse — et aussi après
     * une propagation unitaire, sans quoi une instance injoignable rendrait le
     * chemin unitaire MUET, ce que l'AC7 interdit (« fail-soft mais visible »).
     *
     * Rend le nombre de comptes non propagés, pour que l'appelant puisse en faire
     * autre chose qu'un journal s'il le souhaite.
     */
    public function flushBatchSkips(): int
    {
        $skipped = $this->batchSkipped;

        if ($skipped > 0) {
            Log::warning('nextcloud.user.password.batch_skipped', [
                'skipped' => $skipped,
                'reason' => $this->batchCircuitReason,
            ]);
        }

        $this->batchSkipped = 0;
        $this->batchCircuitOpen = false;
        $this->batchCircuitReason = null;

        return $skipped;
    }

    /**
     * AC6 — le cache, lu par le chemin legacy (`configureUserCloud`) quand il est
     * rempli. Rend `null` quand rien n'est caché : l'appelant garde alors son
     * comportement d'origine.
     */
    public function cachedIdentity(string $login): ?string
    {
        try {
            $value = User::query()->where('login', $login)->value('nextcloud_user_id');
        } catch (\Throwable) {
            // Base indisponible : le cache est un confort, pas une dépendance.
            return null;
        }

        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * Résolution ordonnée AC6, côté distant : sonde directe puis autocomplétion.
     *
     * Rend :
     *  - `string` — l'identifiant Nextcloud résolu ;
     *  - `null` — **introuvable**, et c'est silencieux côté API (mesure du spike
     *    60.0 : zéro résultat, pas d'erreur) — jamais silencieux côté SE5 ;
     *  - {@see NextcloudResult} — un échec NET (privilège, instance injoignable),
     *    qui n'est pas la même chose qu'une absence et ne doit pas être compté
     *    comme telle.
     */
    private function resolveRemote(NextcloudAdminClient $client, string $login): string|NextcloudResult|null
    {
        $direct = $client->getUser($login);

        if ($direct->successful) {
            $id = $direct->value('id');

            return is_string($id) && $id !== '' ? $id : $login;
        }

        if ($direct->failure !== NextcloudFailure::Absent && $direct->failure !== NextcloudFailure::Refus) {
            // Privilège, instance injoignable, réponse illisible : ce n'est pas une
            // absence, et rejouer l'autocomplétion donnerait le même refus.
            return $direct;
        }

        $matches = $client->autocompleteUser($login);

        if ($matches->isFailure()) {
            return $matches;
        }

        /** @var list<array{id: string, source: string}> $found */
        $found = $matches->value('matches', []);

        foreach ($found as $entry) {
            if (mb_strtolower($entry['id']) === mb_strtolower($login)) {
                return $entry['id'];
            }
        }

        // ---------------------------------------------------------------------
        // **ON N'ADOPTE QUE L'HOMONYME.** L'autocomplétion est une recherche
        // FLOUE (sous-chaîne sur l'identifiant, le nom affiché, l'adresse) : un
        // candidat unique n'est pas une preuve d'identité, c'est le seul compte
        // dont le nom RESSEMBLE. Adopter `p.durand-martin` pour `p.durand`
        // écrirait cette identité dans le cache (`users.nextcloud_user_id`),
        // puis {@see propagatePassword()} ÉCRASERAIT le mot de passe du compte
        // d'une autre personne au prochain changement AD — silencieusement, et
        // journalisé comme un succès.
        //
        // La règle « plus d'un : on ne devine pas » s'étend donc au cas « un
        // seul, mais qui n'est pas lui » : un candidat non homonyme n'est pas
        // une identité, c'est un INTROUVABLE, et l'appelant le compte comme tel.
        //
        // Ce qu'on renonce ainsi à couvrir automatiquement : l'instance dont les
        // identifiants ne sont PAS les logins (mappage sur un GUID). Ce cas
        // demandera un geste explicite ou une corroboration (courriel, nom
        // affiché exact) — hors périmètre de cette story ; les candidats écartés
        // sont nommés dans le rapport pour que le geste ait de quoi s'appuyer.
        // ---------------------------------------------------------------------
        $this->discardedCandidates = array_values(array_map(
            static fn (array $entry): string => $entry['id'],
            $found,
        ));

        return null;
    }

    /**
     * Story 61.2 — LE MODE DÉLÉGUÉ COUPE CES CROCHETS, ET LE DIT EN `debug`.
     *
     * La fabrique rend `null` pour trois raisons : capacité éteinte, configuration
     * incomplète, ou **mode délégué**. Les deux premières sont déjà couvertes par
     * le silence de 61.1 (une instance qui n'utilise pas Nextcloud ne doit pas voir
     * ses créations d'utilisateurs bavarder). La troisième mérite une trace, mais
     * une trace SEULEMENT.
     *
     * **Pourquoi `debug` et pas `warning`.** Capacité active + mode délégué est un
     * état CONFIGURÉ et LÉGITIME : la gestion des comptes est une opération
     * d'administration, et le mode déclaré ne la porte pas. Un avertissement par
     * création d'utilisateur — ou par élève lors d'une réinitialisation en masse à
     * la rentrée — serait exactement la pollution que le finding #3 de la revue
     * 61.1 a déjà fait corriger. Le refus CRIE là où l'administrateur agit
     * (commande en code 2 nommant le mode, bouton désactivé avec son motif), pas
     * dans le flux de vie des utilisateurs.
     *
     * ---------------------------------------------------------------------------
     * **CORRECTION DE REVUE (61.2 #5) — LE MODE EST PORTÉ PAR LE REFUS, PLUS RELU.**
     * Cette trace relisait `files.policy` — un `SELECT` de plus, sans cache, **par
     * compte sauté**, alors que la configuration venait tout juste d'être lue par
     * {@see NextcloudConnectionConfig::current()} quelques lignes plus haut. Sur un
     * import de rentrée, c'était un doublement des requêtes pour produire une ligne
     * de `debug`.
     *
     * Le refus lui-même transporte désormais le mode déclaré
     * ({@see NextcloudConfigurationException::$declaredMode}) : la trace ne relit
     * plus rien du tout, et le tri reste EXACT — seul un refus de MODE porte un mode
     * (la capacité éteinte et la configuration incomplète n'en portent pas, et ne
     * tracent donc rien, comme avant).
     * ---------------------------------------------------------------------------
     */
    private function traceDelegatedSkip(string $event, string $login, NextcloudConfigurationException $refusal): void
    {
        if ($refusal->declaredMode !== NextcloudInstanceMode::Delegue) {
            return;
        }

        Log::debug($event, [
            'login' => $login,
            'mode' => NextcloudInstanceMode::Delegue->value,
            'reason' => 'la gestion des comptes est une opération d\'administration ; le mode délégué ne la porte pas',
        ]);
    }

    /**
     * Écriture du cache. `saveQuietly` : ce n'est pas un changement d'état métier,
     * aucun observateur n'a de raison de s'en émouvoir.
     *
     * ---------------------------------------------------------------------------
     * **CORRECTION DE REVUE (61.2 #2) — LA GARDE D'UNICITÉ EST À TOUS LES POINTS
     * D'ÉCRITURE, pas seulement au geste manuel.** Une identité Nextcloud portée par
     * deux logins SE5 fait que la propagation de mot de passe de l'un écrase le
     * compte de l'autre. Le rattachement explicite s'en garde
     * ({@see NextcloudIdentityLinker}) ; la résolution automatique doit s'en garder
     * au même titre — deux logins distincts ne peuvent normalement pas être
     * homonymes du même compte, mais la garde ne coûte rien et ferme la CLASSE
     * entière de défauts plutôt qu'un de ses chemins.
     * ---------------------------------------------------------------------------
     *
     * Rend le login SE5 qui détient déjà cette identité — auquel cas **rien n'est
     * écrit** — ou `null` quand l'écriture a pu se faire (ou n'avait pas lieu
     * d'être). **Jamais d'exception** : un balayage de rentrée ne s'interrompt pas
     * sur un compte ambigu, il le rapporte.
     */
    private function cacheIdentity(string $login, string $nextcloudUserId): ?string
    {
        try {
            $user = User::query()->where('login', $login)->first();
            if ($user === null) {
                return null;
            }

            if ((string) ($user->nextcloud_user_id ?? '') === $nextcloudUserId) {
                return null;
            }

            $holder = User::query()
                ->where('nextcloud_user_id', $nextcloudUserId)
                ->where('login', '!=', $login)
                ->value('login');

            if (is_string($holder) && $holder !== '') {
                return $holder;
            }

            // Hors `$fillable` par conception : l'affectation est NOMINATIVE, ce
            // qui interdit qu'un formulaire l'écrive par assignation en masse.
            $user->nextcloud_user_id = $nextcloudUserId;
            $user->saveQuietly();
        } catch (\Throwable $e) {
            Log::warning('nextcloud.identity.cache.failed', ['login' => $login, 'error' => $e->getMessage()]);
        }

        return null;
    }
}
