<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

/**
 * Story 61.1 — LA SONDE DE CONNEXION : trois diagnostics, trois messages.
 *
 * « Ça ne marche pas » n'est pas un diagnostic. Les trois causes réelles se
 * corrigent à trois endroits différents, par trois personnes potentiellement
 * différentes :
 *  - **instance injoignable** → réseau, DNS, TLS, ou l'instance est à l'arrêt ;
 *  - **privilège insuffisant** → le compte fourni existe peut-être, mais il n'est
 *    pas administrateur de l'instance ; or les montages globaux et la gestion des
 *    comptes SONT des opérations d'administration (cadrage 61.2) ;
 *  - **app `files_external` absente** → `occ app:enable files_external` sur
 *    l'instance ; rien de ce que SE5 peut faire à distance.
 *
 * Les confondre ferait chercher au mauvais endroit — la panne la plus coûteuse
 * n'est pas celle qui échoue, c'est celle qui envoie ailleurs.
 *
 * ---------------------------------------------------------------------------
 * **LE QUATRIÈME DIAGNOSTIC N'EST PAS ICI, ET C'EST DÉLIBÉRÉ.** La mesure du
 * 2026-08-08 a fait apparaître une quatrième cause : le backend SMB indisponible
 * sur l'hôte de l'instance (`smbclient` / `php-smbclient` absent, et détection
 * mise en cache — un redémarrage du service est nécessaire après installation).
 * Elle ne se constate qu'à l'ÉCRITURE : l'instance répond `422` au `POST` d'un
 * montage, alors que la lecture de la liste répond `200`. Aucune lecture connue
 * ne la révèle.
 *
 * La sonde ne l'invente donc pas : elle ne l'affirme ni dans un sens ni dans
 * l'autre, et le dit. Le diagnostic existe en tant que
 * {@see NextcloudFailure::BackendIndisponible}, il est produit par le premier
 * montage tenté, et son message porte la remédiation complète. Fabriquer ici une
 * écriture d'épreuve « pour tester » aurait été un geste qui MODIFIE l'instance
 * sous couvert de la sonder — précisément ce que le dépôt s'est déjà fait
 * reprocher une fois.
 * ---------------------------------------------------------------------------
 */
final class NextcloudConnectionProbe
{
    private function __construct(
        public readonly bool $reachable,
        public readonly bool $administrator,
        public readonly bool $externalStorageEnabled,
        public readonly ?NextcloudFailure $failure,
        /** Message destiné à l'exploitant. **Jamais** le secret (AC1). */
        public readonly string $message,
        /** Code de transport, pour le journal seulement. */
        public readonly ?int $httpStatus = null,
    ) {
    }

    public static function ok(
        string $message = 'Instance joignable, compte administrateur, app « Stockage externe » active. '
            . 'La disponibilité du backend SMB sur l\'instance (paquet smbclient) ne se constate qu\'au '
            . 'premier montage : lancez le provisionnement pour la vérifier.',
    ): self {
        return new self(true, true, true, null, $message);
    }

    public static function unreachable(string $detail): self
    {
        return new self(false, false, false, NextcloudFailure::Injoignable, $detail);
    }

    public static function notAdministrator(string $detail, ?int $httpStatus = null): self
    {
        return new self(true, false, false, NextcloudFailure::Privilege, $detail, $httpStatus);
    }

    public static function appMissing(string $detail, ?int $httpStatus = null): self
    {
        return new self(true, true, false, NextcloudFailure::Absent, $detail, $httpStatus);
    }

    /**
     * Refus que la sonde ne sait pas ranger dans les trois cas connus. Il EXISTE,
     * et il porte son relevé : c'est ce qui permet à la règle d'arrêt de l'AC10 de
     * s'appliquer sur des faits plutôt que sur une impression.
     */
    public static function rejected(string $detail, ?int $httpStatus = null): self
    {
        return new self(true, false, false, NextcloudFailure::Refus, $detail, $httpStatus);
    }

    public function isOk(): bool
    {
        return $this->reachable && $this->administrator && $this->externalStorageEnabled;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->isOk(),
            'reachable' => $this->reachable,
            'administrator' => $this->administrator,
            'external_storage_enabled' => $this->externalStorageEnabled,
            'failure' => $this->failure?->value,
            'message' => $this->message,
            'http_status' => $this->httpStatus,
        ];
    }
}
