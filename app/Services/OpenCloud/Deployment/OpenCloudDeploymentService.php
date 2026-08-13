<?php

declare(strict_types=1);

namespace App\Services\OpenCloud\Deployment;

use App\Services\FilePolicyService;
use App\Services\OpenCloud\OpenCloudConnectionConfig;
use App\Services\ServiceCredentials;

/**
 * LE PILOTE DU DÉPLOIEMENT : idempotent, non destructeur, et il n'active RIEN.
 *
 * ---------------------------------------------------------------------------
 * **CE QU'IL FAIT, ET DANS QUEL ORDRE.**
 *
 *  1. il s'assure d'un secret d'administration — **généré** s'il n'existe pas,
 *     rangé dans le magasin chiffré, jamais affiché, jamais journalisé ;
 *  2. il passe le relais au seam privilégié, qui compose, initialise si besoin,
 *     démarre et SONDE ;
 *  3. il **pré-remplit** les réglages de connexion (adresse et identifiant
 *     d'administration) — deux valeurs non secrètes, pour que l'administrateur
 *     n'ait pas à les recopier ;
 *  4. il **n'active JAMAIS la capacité**. L'instance déployée ne devient pas une
 *     autorité d'écriture parce qu'elle existe : activer la capacité, confirmer
 *     la connexion, puis choisir cette autorité à la création d'un répertoire
 *     sont trois gestes explicites, dans cet ordre. C'est la même doctrine que
 *     partout dans ce produit — rien ne se met à écrire tout seul.
 * ---------------------------------------------------------------------------
 *
 * **CE QU'IL NE FAIT PAS, ET NE PEUT PAS FAIRE.** Il ne supprime rien : ni
 * conteneur, ni volume, ni donnée. Le seam n'a aucun verbe pour cela, et aucun
 * chemin de ce fichier ne compose une option de suppression. Un redéploiement sur
 * une instance peuplée CONVERGE ; il ne repart pas de zéro. Il ne provisionne
 * aucun objet du domaine non plus : ni compte, ni groupe, ni partage.
 *
 * **Il ne crée AUCUN objet du système d'extensions**, ne touche aucune unité de
 * service, et n'ouvre aucun canal d'installation : le déploiement d'une instance
 * et l'installation d'une extension ne sont pas le même livrable, et la
 * séparation obtenue le 2026-08-08 est le gain qu'on ne rend pas.
 */
final class OpenCloudDeploymentService
{
    /** Longueur du secret d'administration généré. */
    private const SECRET_BYTES = 24;

    public function __construct(
        private readonly OpenCloudHelperRunner $runner,
        private readonly ServiceCredentials $credentials,
    ) {
    }

    /**
     * Fait converger l'instance vers l'état voulu.
     *
     * @param  int  $port  port d'écoute LOCAL
     * @param  string  $publicUrl  URL PUBLIQUE de l'instance (celle que le frontal expose)
     * @param  bool  $dryRun  mode sans écriture : montre ce qui SERAIT fait
     */
    public function deploy(int $port, string $publicUrl, bool $dryRun = false): DeploymentReport
    {
        $publicUrl = rtrim(trim($publicUrl), '/');

        if ($publicUrl === '') {
            return DeploymentReport::failed(
                'Aucune URL publique fournie : le déploiement ne devine pas l\'adresse sous laquelle '
                . 'l\'instance sera exposée par le frontal.',
            );
        }

        // L'URL publique doit être en https, MÊME derrière un frontal qui termine
        // le TLS : mesuré le 2026-08-13, le service d'identité du produit refuse
        // de démarrer sinon, et le conteneur meurt APRÈS avoir à moitié amorcé son
        // annuaire interne — un état dont l'instance ne se relève pas.
        if (! str_starts_with($publicUrl, 'https://')) {
            return DeploymentReport::failed(sprintf(
                'URL publique « %s » refusée : elle doit commencer par https://, même quand la terminaison '
                . 'TLS est assurée par le frontal. Le service d\'identité de l\'instance refuse de démarrer '
                . 'sinon, et un premier démarrage manqué laisse un annuaire interne inutilisable.',
                $publicUrl,
            ));
        }

        if ($dryRun) {
            $existing = $this->status();

            return DeploymentReport::of(
                DeploymentOutcome::Conforming,
                'Mode sans écriture : rien n\'a été exécuté.',
                $existing->facts,
                $this->plannedSteps($port, $publicUrl, $existing->fact('initialised') === 'true'),
            );
        }

        // Le secret est GÉNÉRÉ une fois, rangé chiffré, et jamais affiché.
        //
        // **ON NE GÉNÈRE QUE CE QUE L'INSTANCE VA RÉELLEMENT CONSOMMER.** Le seam
        // ne pose le mot de passe qu'à la PREMIÈRE initialisation ; ensuite il
        // l'ignore, parce que ré-initialiser réécrirait les secrets internes de
        // l'instance. En générer un nouveau sur une instance DÉJÀ initialisée
        // rangerait donc un mot de passe qui n'ouvre rien : l'authentification
        // rendrait `401` nu, et il n'existe aucun chemin de reprise (pas de verbe
        // de changement de mot de passe, et ré-initialiser est interdit à raison).
        // Le cas arrive pour de vrai : restauration de base sans la table des
        // secrets, ou oubli volontaire du secret suivi d'un redéploiement. La
        // seule réponse honnête est un REFUS NOMMÉ.
        $secret = $this->credentials->password(OpenCloudConnectionConfig::CREDENTIAL_NAME);

        if ($secret === null || $secret === '') {
            $existing = $this->status();

            if ($existing->isFailure()) {
                return $existing;
            }

            if ($existing->fact('initialised') === 'true') {
                return DeploymentReport::failed(
                    'Aucun mot de passe d\'administration n\'est enregistré alors que l\'instance est DÉJÀ '
                    . 'initialisée. En générer un nouveau rangerait un secret que l\'instance n\'accepterait '
                    . 'pas : elle ne lit celui-ci qu\'à sa toute première initialisation, et la ré-initialiser '
                    . 'réécrirait ses secrets internes et la rendrait inutilisable sur ses propres données. '
                    . 'Renseignez le mot de passe existant du compte d\'administration dans '
                    . 'Administration › Fichiers, puis relancez le déploiement.',
                    $existing->facts === [] ? [] : ['état de l\'instance : déjà initialisée'],
                );
            }

            $secret = $this->generateSecret();
            $this->credentials->put(OpenCloudConnectionConfig::CREDENTIAL_NAME, $secret);
        }

        $run = $this->runner->run(['deploy', (string) $port, $publicUrl], $secret . "\n");
        unset($secret);

        if ($run['exitCode'] !== 0) {
            return DeploymentReport::failed($this->reason($run, 'le déploiement de l\'instance a été refusé'));
        }

        $facts = $this->parse($run['stdout']);

        // PRÉ-REMPLISSAGE, jamais activation : deux valeurs non secrètes posées
        // pour éviter une recopie, et la capacité reste EXACTEMENT là où elle
        // était.
        $this->prefillConnection($publicUrl);

        $outcome = ($facts['outcome'] ?? '') === 'conforming'
            ? DeploymentOutcome::Conforming
            : DeploymentOutcome::Deployed;

        $message = $outcome === DeploymentOutcome::Conforming
            ? 'Instance déjà conforme : aucun conteneur recréé, aucune donnée touchée.'
            : 'Instance déployée et joignable. La capacité « Accès OpenCloud » reste ÉTEINTE : '
                . 'activez-la sur Administration › Fichiers pour qu\'elle puisse servir un répertoire.';

        // La REPRISE DE PROPRIÉTÉ se dit : une instance installée avant le
        // durcissement avait ses volumes sur un compte ordinaire de la machine,
        // donc son fichier de configuration — et les secrets internes qu'il
        // porte — lisibles par lui. Le déploiement vient de les reprendre au
        // compte système dédié, et l'exploitant doit le savoir.
        if (($facts['ownership_reclaimed'] ?? '') === 'true') {
            $message .= sprintf(
                ' Les volumes de l\'instance appartenaient à un autre compte de la machine : leur '
                . 'propriété a été REPRISE par le compte système « %s ». Les secrets internes de '
                . 'l\'instance n\'étaient pas à l\'abri d\'un utilisateur local jusqu\'ici ; s\'ils ont '
                . 'pu être lus, seule une réinstallation sur volume vierge les renouvelle.',
                $facts['run_user'] ?? 'dédié',
            );
        }

        return DeploymentReport::of($outcome, $message, $facts);
    }

    /** L'état lisible de l'instance : conteneur attendu/présent, santé, adresse. */
    public function status(): DeploymentReport
    {
        $run = $this->runner->run(['status']);

        if ($run['exitCode'] !== 0) {
            return DeploymentReport::failed($this->reason($run, 'l\'état de l\'instance n\'a pas pu être lu'));
        }

        $facts = $this->parse($run['stdout']);

        return DeploymentReport::of(
            DeploymentOutcome::Conforming,
            ($facts['present'] ?? 'false') === 'true'
                ? sprintf('Conteneur « %s » présent (%s).', $facts['expected'] ?? '?', $facts['state'] ?? '?')
                : 'Aucune instance OpenCloud sur cette machine.',
            $facts,
        );
    }

    /**
     * ARRÊTE le conteneur. **Ne supprime rien** : ni le conteneur, ni ses volumes,
     * ni ses données. C'est une pause d'exploitation, jamais une désinstallation —
     * et le seam n'a de toute façon aucun verbe pour détruire.
     */
    public function stop(): DeploymentReport
    {
        $run = $this->runner->run(['stop']);

        if ($run['exitCode'] !== 0) {
            return DeploymentReport::failed($this->reason($run, 'l\'arrêt de l\'instance a été refusé'));
        }

        $facts = $this->parse($run['stdout']);

        return DeploymentReport::of(
            ($facts['outcome'] ?? '') === 'conforming' ? DeploymentOutcome::Conforming : DeploymentOutcome::Deployed,
            'Instance arrêtée. Les volumes et les données sont intacts.',
            $facts,
        );
    }

    /** @return list<string> */
    public function logs(int $lines = 100): array
    {
        $run = $this->runner->run(['logs', (string) $lines]);

        return $run['exitCode'] === 0 ? $run['stdout'] : $run['stderr'];
    }

    // =========================================================================
    // Interne
    // =========================================================================

    /**
     * Pré-remplit l'adresse et l'identifiant d'administration, **sans jamais
     * toucher à la capacité ni aux réglages de l'autre produit**.
     *
     * Un réglage déjà saisi par l'administrateur n'est pas écrasé : c'est une
     * commodité de premier déploiement, pas une reprise en main.
     */
    private function prefillConnection(string $publicUrl): void
    {
        $policy = FilePolicyService::globalConfig();

        FilePolicyService::setGlobal(
            $policy['home'],
            $policy['shares'],
            $policy['nextcloud'],
            $policy['nextcloud_server_url'],
            $policy['nextcloud_admin_user'],
            $policy['nextcloud_smb_host'],
            $policy['nextcloud_verify_tls'],
            // LA CAPACITÉ RESTE CE QU'ELLE EST. On repasse sa valeur courante :
            // le déploiement n'allume rien.
            $policy['opencloud'],
            $policy['opencloud_server_url'] === '' ? $publicUrl : $policy['opencloud_server_url'],
            $policy['opencloud_admin_user'] === '' ? 'admin' : $policy['opencloud_admin_user'],
            $policy['opencloud_verify_tls'],
        );
    }

    /** @return list<string> */
    private function plannedSteps(int $port, string $publicUrl, bool $alreadyInitialised): array
    {
        $steps = [
            sprintf('générer et ranger le mot de passe d\'administration (chiffré) si absent'),
            sprintf('écrire la composition et son fichier d\'environnement (0600 root:root)'),
        ];

        $steps[] = $alreadyInitialised
            ? 'NE PAS ré-initialiser l\'instance (configuration déjà présente — la ré-initialiser réécrirait '
                . 'ses secrets internes et la rendrait inutilisable sur ses propres données)'
            : 'initialiser l\'instance (première fois)';

        $steps[] = sprintf('faire converger le conteneur et le publier sur 127.0.0.1:%d', $port);
        $steps[] = 'sonder la santé de l\'instance et échouer NOMMÉMENT si elle ne répond pas';
        $steps[] = sprintf('pré-remplir l\'adresse « %s » et l\'identifiant d\'administration', $publicUrl);
        $steps[] = 'NE PAS activer la capacité « Accès OpenCloud » (geste explicite de l\'administrateur)';
        $steps[] = 'NE supprimer aucun conteneur, aucun volume, aucune donnée (aucun verbe ne le permet)';

        return $steps;
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, string>
     */
    private function parse(array $lines): array
    {
        $facts = [];
        foreach ($lines as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key !== '') {
                $facts[$key] = trim($value);
            }
        }

        return $facts;
    }

    /**
     * @param  array{stdout: list<string>, stderr: list<string>, exitCode: int}  $run
     */
    private function reason(array $run, string $prefix): string
    {
        $detail = trim(implode(' ', $run['stderr'] !== [] ? $run['stderr'] : $run['stdout']));

        if ($detail === '') {
            $detail = 'cause non détaillée par le seam privilégié (le helper est-il installé et autorisé '
                . 'dans /etc/sudoers.d ?).';
        }

        return ucfirst($prefix) . ' : ' . $detail;
    }

    private function generateSecret(): string
    {
        // Base64 URL-safe : lisible partout, sans caractère qu'un fichier
        // d'environnement ou un shell aurait à échapper.
        return rtrim(strtr(base64_encode(random_bytes(self::SECRET_BYTES)), '+/', '-_'), '=');
    }
}
