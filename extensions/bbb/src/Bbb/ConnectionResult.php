<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Bbb;

/**
 * Story 57.1 — Le résultat d'un test de connexion, prêt à afficher.
 *
 * ⚠️ Le message ne contient JAMAIS le secret testé, ni l'URL signée (qui porte
 * le `checksum`), ni la réponse brute du serveur.
 */
final class ConnectionResult
{
    private function __construct(
        public readonly ConnectionStatus $status,
        public readonly string $message,
        public readonly int $meetingCount = 0,
    ) {
    }

    public static function ok(int $meetingCount): self
    {
        return new self(
            ConnectionStatus::Ok,
            $meetingCount === 0
                ? 'Connexion réussie : URL et secret acceptés, aucune réunion en cours.'
                : sprintf('Connexion réussie : URL et secret acceptés, %d réunion(s) en cours.', $meetingCount),
            $meetingCount,
        );
    }

    public static function unreachable(): self
    {
        return new self(
            ConnectionStatus::Unreachable,
            'Serveur injoignable : aucune réponse dans le délai imparti. Vérifiez l\'URL, le nom d\'hôte et le certificat TLS.',
        );
    }

    public static function invalidSecret(): self
    {
        return new self(
            ConnectionStatus::InvalidSecret,
            'Secret invalide : le serveur a rejeté la signature de la requête (checksumError).',
        );
    }

    public static function invalidResponse(string $detail = ''): self
    {
        return new self(
            ConnectionStatus::InvalidResponse,
            'Réponse inattendue : l\'adresse répond, mais ce n\'est pas une API BigBlueButton.'
                . ($detail !== '' ? ' (' . $detail . ')' : ''),
        );
    }

    public function isOk(): bool
    {
        return $this->status === ConnectionStatus::Ok;
    }

    /** Classe d'alerte de la charte, pour l'affichage. */
    public function alertVariant(): string
    {
        return $this->isOk() ? 'success' : 'error';
    }
}
