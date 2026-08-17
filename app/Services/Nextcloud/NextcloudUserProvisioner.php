<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

use App\Exceptions\Nextcloud\NextcloudConfigurationException;
use App\Models\QuotaRule;
use App\Models\User;
use App\Services\Filesystem\XfsQuotaService;
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
 * **ET SEUL LE PREMIER DES DEUX CRÉE.** {@see propagatePassword()} exige une
 * identité DÉJÀ connue et sort sans rien faire quand la colonne est vide : un
 * changement de mot de passe ne comble donc AUCUN trou du stock. C'est une
 * propriété volontaire — sans identité connue, on ne sait pas quel compte mettre à
 * jour, et deviner écrirait à l'aveugle. Mais elle a été mal dite pendant
 * longtemps : le rapport annonçait à l'exploitant que le compte « se créera au
 * prochain changement de mot de passe », ce qui était faux et sans terme
 * (rectifié le 2026-08-17). Le seul chemin pour le stock est que l'instance lise
 * l'annuaire — `php artisan nextcloud:configure-ldap`.
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

    /**
     * SE5 a-t-il seulement UNE opinion sur le plafond du répertoire personnel ?
     * Mémoïsé pour le balayage entier : c'est une propriété de l'INSTANCE, pas de
     * l'utilisateur, et la relire par compte ferait une requête par personne pour
     * une réponse qui ne change pas.
     */
    private ?bool $governsHomeQuota = null;

    public function __construct(
        private readonly NextcloudClientFactory $factory,
        private ?XfsQuotaService $quotas = null,
    ) {
    }

    /**
     * Le service de quotas, tenu POUR TOUT LE BALAYAGE — c'est lui qui porte la
     * mémoïsation de la résolution d'annuaire ; en résoudre un neuf par utilisateur
     * la rendrait inopérante.
     */
    private function quotas(): XfsQuotaService
    {
        return $this->quotas ??= app(XfsQuotaService::class);
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
        } catch (NextcloudConfigurationException) {
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
            // Déjà résolu : AUCUN appel de RÉSOLUTION. C'est l'invariant testé de
            // l'AC6 — le cache n'aurait aucune valeur si on le rechargeait à chaque
            // passage. Le plafond, lui, est un état à CONVERGER, pas une identité à
            // retrouver : il se relit et se corrige à chaque balayage.
            $this->convergeQuota($user, (string) $user->nextcloud_user_id, $client, $report, $dryRun);
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

        $this->convergeQuota($user, $resolved, $client, $report, $dryRun);

        $report->countUserAdopted();
    }

    /**
     * Story 61.3 (AC5) — LE PLAFOND D'UNE PERSONNE, convergé au balayage.
     *
     * **La frontière D8, côté personnes.** Ce plafond budgète un COMPTE sur
     * l'instance ; il n'a rien à voir avec le plafond d'une ZONE, que le backend de
     * fichiers pose sur un dossier d'équipe. Les rattacher au même endroit ferait
     * écrire un quota d'utilisateur par une recette de partage — la violation exacte
     * que D8 nomme. Un test d'architecture tient la frontière des deux côtés.
     *
     * **On COMPARE SUR LE RELU** (piège transversal de l'epic) : la valeur envoyée
     * ne prouve rien, l'instance peut la normaliser ou l'ignorer. On ne réécrit que
     * si le relu diffère, ce qui rend le balayage idempotent — sans quoi chaque
     * passage réécrirait le plafond de chaque compte.
     *
     * **Fail-soft et SILENCIEUX sur un refus légitime.** Une instance à synchro
     * annuaire refuse la modification de ses comptes : c'est un état normal, pas une
     * panne, et il se journalise en `debug` — jamais un avertissement par
     * utilisateur (règle héritée de la revue 61.1, sans quoi une rentrée noierait le
     * journal).
     */
    private function convergeQuota(
        User $user,
        string $nextcloudUserId,
        NextcloudAdminClient $client,
        NextcloudProvisioningReport $report,
        bool $dryRun,
    ): void {
        $wanted = $this->effectiveQuotaValue($user, $report);
        if ($wanted === null) {
            return;
        }

        $read = $client->getUser($nextcloudUserId);
        if ($read->isFailure()) {
            Log::debug('nextcloud.user.quota.unreadable', ['login' => (string) $user->login]);

            return;
        }

        $quota = $read->value('quota', []);
        $current = is_array($quota) ? ($quota['quota'] ?? null) : null;

        if (self::quotaMatches($current, $wanted)) {
            return;
        }

        // Story 63.4, correction de revue — **L'ÉCRASEMENT SE COMPTE.** Le relu
        // diffère : ce balayage change ce plafond, y compris s'il avait été réglé à
        // la main dans l'instance. C'est ici, et pas plus loin, parce que la
        // simulation doit annoncer ce que le vrai passage ferait.
        $report->countQuotaChanged();

        if ($dryRun) {
            Log::info('nextcloud.user.quota.would_change', [
                'login' => (string) $user->login,
                'wanted' => $wanted,
            ]);

            return;
        }

        $result = $client->setUserQuota($nextcloudUserId, $wanted);

        if ($result->isFailure()) {
            Log::debug('nextcloud.user.quota.refused', [
                'login' => (string) $user->login,
                'failure' => $result->failure?->value,
            ]);
        }
    }

    /**
     * Le plafond EFFECTIF d'un compte, dans la forme que l'instance attend, ou
     * `null` si SE5 n'a rien à en dire — **ou s'il ne peut PAS savoir ce qu'il en
     * dit** (voir {@see resolveEffectiveQuota()}).
     */
    private function effectiveQuotaValue(User $user, NextcloudProvisioningReport $report): ?string
    {
        $login = (string) $user->login;
        if ($login === '') {
            return null;
        }

        $effective = $this->resolveEffectiveQuota($login, $report);

        // **AUCUNE RÈGLE ⇒ AUCUNE OPINION ⇒ AUCUN GESTE** (drift STRICT). SE5 ne
        // gouverne le plafond d'un compte que s'il en a une règle : sans règle,
        // écrire « sans limite » ÉCRASERAIT un plafond posé à la main sur
        // l'instance. Ce que le produit ne décrit pas, il ne le réconcilie pas — et
        // c'est aussi ce qui garde le balayage muet sur les instances qui ne
        // configurent aucun quota (le cas courant aujourd'hui).
        if ($effective === null || ($effective['source'] ?? 'none') === 'none') {
            return null;
        }

        if (($effective['is_unlimited'] ?? true) === true) {
            // « Sans limite » est une valeur, pas une absence : l'écrire est ce qui
            // permet à un plafond RETIRÉ d'une règle SE5 de l'être aussi côté
            // instance — là, SE5 a bien une opinion, et elle est « pas de limite ».
            return 'none';
        }

        $hard = (int) ($effective['quota_hard_mb'] ?? 0);

        return $hard > 0 ? (string) ($hard * 1024 * 1024) : 'none';
    }

    /**
     * Correction de revue 61.3 #1, **transposée aux GROUPES par la story 63.4** —
     * ce qui ne se résout pas ne se devine pas, et son absence se dit.
     *
     * ---------------------------------------------------------------------------
     * **CE QUI ÉTAIT FAUX.** Le plafond se choisissait d'après un profil déduit de
     * `users.role` — une colonne qui ne garde rien dans ce produit — avec un repli
     * muet. Un enseignant dont le rôle n'était pas renseigné recevait donc le
     * plafond le plus bas : pas d'erreur, pas de journal, rien. Et les groupes
     * passés étaient TOUJOURS `[]`, ce qui rendait toute règle
     * `QuotaRule::TYPE_GROUP` inatteignable pour un compte de l'instance.
     *
     * **LE PROFIL LUI-MÊME A DISPARU** (story 63.4) : le plafond par défaut est un
     * réglage d'INSTANCE, identique pour tout compte qu'aucune règle nominative ni
     * règle de groupe ne couvre. Ce qu'on demande encore à l'annuaire, ce sont donc
     * les GROUPES, et rien d'autre.
     *
     * **CE QUI EST VRAI MAINTENANT** — dans cet ordre, et l'ordre est celui du
     * COÛT autant que celui de la sûreté :
     *
     *  0. **SE5 gouverne-t-il seulement ce plafond ?** Aucune règle active sur la
     *     partition ⇒ aucune opinion possible ⇒ on rend `null` sans le moindre
     *     aller-retour d'annuaire. C'est le cas courant aujourd'hui, et c'est ce qui
     *     garde un balayage d'établissement à ZÉRO appel d'annuaire (une seule
     *     requête SQL, mémoïsée pour tout le balayage).
     *  1. **Une règle NOMINATIVE prime sur tout** et ne demande pas l'annuaire :
     *     les groupes n'entrent pas dans son calcul. La résoudre sans interroger
     *     l'annuaire évite de refuser un plafond parfaitement déterminé parce que
     *     l'annuaire, lui, ne répondait pas.
     *  2. Sinon, **l'annuaire**, une fois, pour les groupes.
     *
     * **UN ANNUAIRE MUET N'EST PAS UN COMPTE SANS GROUPE.** On n'écrit rien, et on
     * le COMPTE au rapport. Retomber sur le défaut d'instance rétrécirait
     * silencieusement le plafond d'un compte couvert par une règle de groupe plus
     * large : un plafond faux est pire qu'un plafond absent — absent, il se voit ;
     * faux, il s'applique.
     *
     * @return array{source: string, source_name: string|null, quota_soft_mb: int, quota_hard_mb: int, is_unlimited: bool}|null
     */
    private function resolveEffectiveQuota(string $login, NextcloudProvisioningReport $report): ?array
    {
        if (! $this->governsHomeQuota()) {
            return null;
        }

        $quotas = $this->quotas();

        if ($this->hasNominativeRule($login)) {
            // Les groupes ne sont pas consultés sur ce chemin : la règle nominative
            // est le PREMIER étage de `getEffectiveQuota()`. On le VÉRIFIE plutôt
            // que de le supposer — si la règle a disparu entre-temps, on repasse par
            // l'annuaire au lieu d'écrire un plafond qu'aucune règle ne porte plus.
            $effective = $quotas->getEffectiveQuota($login, QuotaRule::PARTITION_HOME, []);

            if (($effective['source'] ?? null) === 'user') {
                return $effective;
            }
        }

        $identity = $quotas->resolveDirectoryIdentity($login);

        if ($identity === null) {
            $report->countQuotaIdentityUnresolved($login);

            return null;
        }

        return $quotas->getEffectiveQuota(
            $login,
            QuotaRule::PARTITION_HOME,
            $identity['groups'],
        );
    }

    /**
     * Existe-t-il la moindre règle de quota active sur la partition des répertoires
     * personnels ? **UNE seule requête pour tout le balayage** — et quand la réponse
     * est non, aucun compte ne coûte d'aller-retour d'annuaire.
     */
    private function governsHomeQuota(): bool
    {
        if ($this->governsHomeQuota !== null) {
            return $this->governsHomeQuota;
        }

        try {
            $this->governsHomeQuota = QuotaRule::active()
                ->forPartition(QuotaRule::PARTITION_HOME)
                ->exists();
        } catch (\Throwable $e) {
            // Base indisponible : on ne sait pas ce que SE5 veut, donc on n'écrit
            // rien. Même direction que le profil indéterminable.
            Log::debug('nextcloud.user.quota.policy_unreadable', ['error' => $e->getMessage()]);
            $this->governsHomeQuota = false;
        }

        return $this->governsHomeQuota;
    }

    /** Une règle de quota NOMINATIVE sur ce login — celle qui prime sur tout. */
    private function hasNominativeRule(string $login): bool
    {
        try {
            return QuotaRule::active()
                ->forPartition(QuotaRule::PARTITION_HOME)
                ->where('type', QuotaRule::TYPE_USER)
                ->where('target', $login)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Le relu correspond-il au voulu ? « Illimité » a plusieurs écritures. */
    private static function quotaMatches(mixed $current, string $wanted): bool
    {
        if ($wanted === 'none') {
            return $current === null
                || $current === 'none'
                || (is_numeric($current) && (int) $current < 0);
        }

        return is_numeric($current) && (string) (int) $current === $wanted;
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
        } catch (NextcloudConfigurationException) {
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
