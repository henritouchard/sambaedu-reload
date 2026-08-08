<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

/**
 * Story 61.1 — LE RAPPORT : par élément pour les montages, compté pour les
 * utilisateurs, JAMAIS un booléen global.
 *
 * Un run qui a monté les stockages mais échoué sur les comptes DOIT le dire, et le
 * dire de façon lisible sans consulter les journaux. C'est la contrepartie du
 * fail-soft par utilisateur : sans compteur, « ça a marché » finit par vouloir dire
 * « ça n'a pas explosé », et c'est exactement la signature de défaut que les Epics
 * 56/57 ont rencontrée — un signal qui n'atteint pas son destinataire.
 *
 * **Les montages sont détaillés, les utilisateurs sont comptés.** Il y a deux
 * montages et il peut y avoir dix mille comptes : détailler les seconds ferait un
 * rapport que personne ne lit. Les comptes NON NOMINAUX (introuvables, échecs) sont
 * quand même listés, bornés à {@see self::MAX_LISTED_LOGINS} — parce que ceux-là
 * demandent un geste, et qu'un geste a besoin d'un nom.
 *
 * **Le rapport se sérialise en tableau et s'affiche.** Contrairement aux rapports
 * de la ligne de contrat (Epic 60), il n'a pas d'invariant de complétude à
 * protéger : ce n'est pas un état désiré confronté à un plan, c'est un journal
 * d'exécution. Il est donc mis en cache en tableau et relu par l'écran, patron
 * identique au dernier rapport de réconciliation des répertoires réseau.
 */
final class NextcloudProvisioningReport
{
    /** Au-delà, la liste nominative devient illisible et le compteur suffit. */
    public const MAX_LISTED_LOGINS = 50;

    /** @var list<array{name: string, action: string, label: string, detail: string}> */
    private array $mounts = [];

    /**
     * Au-delà, l'échantillon nominatif des profils indéterminables n'apprend plus
     * rien : si l'annuaire est muet, ce sont TOUS les comptes qui y figurent, et le
     * compteur porte l'information.
     */
    public const MAX_SAMPLED_QUOTA_LOGINS = 10;

    /** @var array<string, int> */
    private array $userCounters = [
        'crees' => 0,
        'adoptes' => 0,
        'introuvables' => 0,
        'echecs' => 0,
        'exclus' => 0,
        'quotas_indetermines' => 0,
    ];

    /** @var list<string> */
    private array $quotaUnresolved = [];

    /** @var list<array{login: string, issue: string, detail: string, candidates: list<string>}> */
    private array $userIssues = [];

    private ?NextcloudConnectionProbe $probe = null;

    private ?string $refusal = null;

    public function __construct(
        public readonly bool $dryRun = false,
        public readonly ?string $startedAt = null,
    ) {
    }

    // -- Connexion ------------------------------------------------------------

    public function recordProbe(NextcloudConnectionProbe $probe): void
    {
        $this->probe = $probe;
    }

    /**
     * Refus AMONT (configuration invalide, capacité éteinte, verrou déjà tenu) :
     * rien n'a été tenté, et le rapport le dit plutôt que d'afficher des compteurs
     * à zéro qu'on prendrait pour un succès.
     */
    public function recordRefusal(string $reason): void
    {
        $this->refusal = $reason;
    }

    public function refusal(): ?string
    {
        return $this->refusal;
    }

    // -- Montages -------------------------------------------------------------

    public function recordMount(string $name, NextcloudMountAction $action, string $detail = ''): void
    {
        $this->mounts[] = [
            'name' => $name,
            'action' => $action->value,
            'label' => $action->label(),
            'detail' => $detail,
        ];
    }

    /** @return list<array{name: string, action: string, label: string, detail: string}> */
    public function mounts(): array
    {
        return $this->mounts;
    }

    // -- Utilisateurs ---------------------------------------------------------

    public function countUserCreated(): void
    {
        $this->userCounters['crees']++;
    }

    public function countUserAdopted(): void
    {
        $this->userCounters['adoptes']++;
    }

    /**
     * Compte NC introuvable pour un utilisateur SE5 du périmètre. **Ce n'est pas
     * un échec technique** : c'est un état qui demande un geste humain, et le
     * message porte la marche à suivre — SE5 n'invente JAMAIS de mot de passe pour
     * combler le trou (un compte créé avec un aléa est un compte auquel personne
     * ne peut se connecter, et il ferait passer le compteur au vert).
     *
     * `$discardedCandidates` : les comptes que l'autocomplétion a rendus sans
     * qu'aucun soit l'homonyme du login. SE5 n'en adopte AUCUN (adopter un
     * quasi-homonyme reviendrait à écraser plus tard le mot de passe d'un compte
     * tiers), mais les nommer transforme un « introuvable » sec en une piste :
     * c'est exactement le cas d'une instance dont les identifiants ne sont pas
     * les logins.
     *
     * @param  list<string>  $discardedCandidates
     */
    public function countUserMissing(string $login, array $discardedCandidates = []): void
    {
        $this->userCounters['introuvables']++;

        $detail = 'aucun compte Nextcloud pour ce login : il se créera au prochain '
            . 'changement de mot de passe SE5, ou se déclarera côté instance (synchro LDAP / création manuelle).';

        if ($discardedCandidates !== []) {
            $detail .= ' L\'instance a proposé des comptes proches, aucun ne porte ce login — non adoptés : '
                . implode(', ', array_slice($discardedCandidates, 0, 5))
                . (count($discardedCandidates) > 5 ? ', …' : '') . '.';
        }

        // Story 61.2 — les candidats écartés voyagent aussi en STRUCTURE, pas
        // seulement dans la phrase : la modale de rattachement (AC7) pré-remplit le
        // champ avec le premier d'entre eux. Reparser le texte du détail aurait fait
        // dépendre un geste d'écriture de la ponctuation d'un message.
        $this->addIssue($login, 'introuvable', $detail, $discardedCandidates);
    }

    public function countUserFailed(string $login, string $detail): void
    {
        $this->userCounters['echecs']++;
        $this->addIssue($login, 'echec', $detail);
    }

    /**
     * Correction de revue 61.3 #1 — LE PLAFOND QU'ON N'A PAS ÉCRIT, ET POURQUOI.
     *
     * Le profil de quota d'un compte se résout par l'ANNUAIRE. Quand l'annuaire ne
     * répond pas — ou ne connaît pas ce compte — le profil est INDÉTERMINABLE, et
     * SE5 n'écrit alors AUCUN plafond : appliquer le profil de repli reviendrait à
     * poser un plafond d'élève à un enseignant, sans erreur ni journal.
     *
     * **Ce n'est ni un échec ni une panne** (le compte est adopté, son montage
     * fonctionne, le code de sortie ne change pas) : c'est un CONSTAT, du même genre
     * que les comptes introuvables — un état que l'exploitant doit connaître parce
     * qu'un plafond qu'il croit posé ne l'est pas.
     *
     * L'échantillon nominatif vit à part de {@see userIssues()} À DESSEIN : une
     * panne d'annuaire concerne TOUS les comptes, et laisser ce cas consommer le
     * budget de la liste ferait disparaître les « introuvables », qui eux demandent
     * un geste par personne.
     */
    public function countQuotaProfileUnresolved(string $login): void
    {
        $this->userCounters['quotas_indetermines']++;

        if (count($this->quotaUnresolved) < self::MAX_SAMPLED_QUOTA_LOGINS) {
            $this->quotaUnresolved[] = $login;
        }
    }

    /** @return list<string> échantillon borné, jamais la population entière */
    public function quotaUnresolvedLogins(): array
    {
        return $this->quotaUnresolved;
    }

    /** Comptes hors périmètre (identité fédérée). */
    public function countUserExcluded(): void
    {
        $this->userCounters['exclus']++;
    }

    /**
     * Dénombrement des hors-périmètre en un bloc. Les détailler n'apprendrait
     * rien — aucun geste n'est attendu sur eux — mais les TAIRE laisserait croire
     * que le total du rapport couvre toute la population.
     */
    public function recordExcludedCount(int $count): void
    {
        $this->userCounters['exclus'] = max(0, $count);
    }

    /** @return array<string, int> */
    public function userCounters(): array
    {
        return $this->userCounters;
    }

    /** @return list<array{login: string, issue: string, detail: string}> */
    public function userIssues(): array
    {
        return $this->userIssues;
    }

    // -- Verdict --------------------------------------------------------------

    public function hasFailures(): bool
    {
        if ($this->userCounters['echecs'] > 0) {
            return true;
        }

        foreach ($this->mounts as $mount) {
            if ($mount['action'] === NextcloudMountAction::Echec->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Code de sortie de la commande :
     *  - `2` — configuration invalide, capacité éteinte ou instance inatteignable :
     *    **rien n'a été tenté**, ce n'est pas un échec partiel ;
     *  - `1` — au moins un élément en échec, les autres ont pu aboutir ;
     *  - `0` — convergé.
     */
    public function exitCode(): int
    {
        if ($this->refusal !== null) {
            return 2;
        }

        if ($this->probe !== null && ! $this->probe->isOk()) {
            return 2;
        }

        return $this->hasFailures() ? 1 : 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'started_at' => $this->startedAt,
            'refusal' => $this->refusal,
            'connection' => $this->probe?->toArray(),
            'mounts' => $this->mounts,
            'users' => $this->userCounters,
            'user_issues' => $this->userIssues,
            'quota_unresolved' => $this->quotaUnresolved,
            'exit_code' => $this->exitCode(),
        ];
    }

    /**
     * @param  list<string>  $candidates  Identifiants proposés par l'instance et NON adoptés.
     */
    private function addIssue(string $login, string $issue, string $detail, array $candidates = []): void
    {
        if (count($this->userIssues) >= self::MAX_LISTED_LOGINS) {
            return;
        }

        $this->userIssues[] = [
            'login' => $login,
            'issue' => $issue,
            'detail' => $detail,
            'candidates' => array_values($candidates),
        ];
    }
}
