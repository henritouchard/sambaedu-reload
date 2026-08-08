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

    /** @var array<string, int> */
    private array $userCounters = [
        'crees' => 0,
        'adoptes' => 0,
        'introuvables' => 0,
        'echecs' => 0,
        'exclus' => 0,
    ];

    /** @var list<array{login: string, issue: string, detail: string}> */
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

        $this->addIssue($login, 'introuvable', $detail);
    }

    public function countUserFailed(string $login, string $detail): void
    {
        $this->userCounters['echecs']++;
        $this->addIssue($login, 'echec', $detail);
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
            'exit_code' => $this->exitCode(),
        ];
    }

    private function addIssue(string $login, string $issue, string $detail): void
    {
        if (count($this->userIssues) >= self::MAX_LISTED_LOGINS) {
            return;
        }

        $this->userIssues[] = ['login' => $login, 'issue' => $issue, 'detail' => $detail];
    }
}
