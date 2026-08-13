<?php

declare(strict_types=1);

namespace App\Services\OpenCloud;

/**
 * LA SONDE DE CONNEXION : trois diagnostics, trois messages.
 *
 * « Ça ne marche pas » n'est pas un diagnostic. Les causes réelles se corrigent à
 * des endroits différents, par des personnes potentiellement différentes :
 *  - **instance injoignable** → réseau, port, ou l'instance est à l'arrêt (et la
 *    commande de déploiement est alors le geste, pas la saisie) ;
 *  - **compte inconnu ou mot de passe faux** → `401` nu, sans corps ;
 *  - **privilège insuffisant** → le compte existe, il n'est pas administrateur ;
 *    or créer un espace de projet EST une opération d'administration (mesuré :
 *    `403 notAllowed « insufficient permissions to create a space. »`).
 *
 * Les confondre ferait chercher au mauvais endroit — la panne la plus coûteuse
 * n'est pas celle qui échoue, c'est celle qui envoie ailleurs.
 *
 * ---------------------------------------------------------------------------
 * **LA SONDE N'ÉCRIT RIEN, JAMAIS.** Elle lit l'identité du compte connecté, puis
 * l'inventaire des espaces et l'annuaire — deux lectures que seul un compte
 * administrateur obtient (mesuré : un compte ordinaire rend `403 accessDenied`
 * sur l'annuaire). Fabriquer une écriture d'épreuve « pour tester » aurait été un
 * geste qui MODIFIE l'instance sous couvert de la sonder, et cet écueil a déjà
 * été payé une fois dans ce dépôt.
 *
 * Ce qu'elle ne peut PAS constater sans écrire — qu'une création d'espace
 * aboutira réellement — est DIT dans son message vert plutôt que présumé.
 * ---------------------------------------------------------------------------
 */
final class OpenCloudConnectionProbe
{
    private function __construct(
        public readonly bool $reachable,
        public readonly bool $authenticated,
        public readonly bool $administrator,
        public readonly ?OpenCloudFailure $failure,
        /** Message destiné à l'exploitant. **Jamais** le secret. */
        public readonly string $message,
        /** Code de transport, pour le journal seulement. */
        public readonly ?int $httpStatus = null,
        /** Version de l'instance, quand elle a été relue. */
        public readonly ?string $account = null,
    ) {
    }

    public static function ok(string $account): self
    {
        return new self(
            true,
            true,
            true,
            null,
            sprintf(
                'Instance joignable, compte « %s » authentifié et administrateur (inventaire des espaces et '
                . 'annuaire lisibles). Qu\'une création d\'espace aboutisse ne se constate qu\'à l\'écriture : '
                . 'la première réconciliation d\'un répertoire le dira.',
                $account,
            ),
            200,
            $account,
        );
    }

    public static function unreachable(string $detail): self
    {
        return new self(false, false, false, OpenCloudFailure::Injoignable, $detail);
    }

    /** Le compte n'a pas été authentifié : identifiant inconnu ou mot de passe faux. */
    public static function notAuthenticated(string $detail, ?int $httpStatus = null): self
    {
        return new self(true, false, false, OpenCloudFailure::Privilege, $detail, $httpStatus);
    }

    /** Le compte existe, il n'est pas administrateur de l'instance. */
    public static function notAdministrator(string $detail, ?int $httpStatus = null, ?string $account = null): self
    {
        return new self(true, true, false, OpenCloudFailure::Privilege, $detail, $httpStatus, $account);
    }

    /**
     * Refus que la sonde ne sait pas ranger dans les cas connus. Il EXISTE, et il
     * porte son relevé : c'est ce qui permet à l'exploitant de raisonner sur des
     * faits plutôt que sur une impression.
     */
    public static function rejected(string $detail, ?int $httpStatus = null): self
    {
        return new self(true, false, false, OpenCloudFailure::Refus, $detail, $httpStatus);
    }

    public function isOk(): bool
    {
        return $this->reachable && $this->authenticated && $this->administrator;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->isOk(),
            'reachable' => $this->reachable,
            'authenticated' => $this->authenticated,
            'administrator' => $this->administrator,
            'failure' => $this->failure?->value,
            'message' => $this->message,
            'http_status' => $this->httpStatus,
            'account' => $this->account,
        ];
    }
}
