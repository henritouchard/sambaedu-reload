<?php

declare(strict_types=1);

namespace App\Services\OpenCloud\Deployment;

/**
 * L'ÉTAT LISIBLE rendu par un déploiement ou une consultation d'état.
 *
 * Il porte des FAITS (conteneur attendu / présent, état, port, sonde de santé,
 * adresse, volumes) et jamais une phrase agrégée : c'est l'exploitant qui lit,
 * et « ça a marché » est précisément la réponse qui ne lui apprend rien.
 *
 * **Aucun secret n'entre ici**, par construction : ce rapport ne contient que ce
 * que le helper imprime, et le helper n'imprime jamais le mot de passe qu'il
 * reçoit sur son entrée standard. Un test l'épingle.
 */
final class DeploymentReport
{
    /**
     * @param  array<string, string>  $facts  paires clé/valeur imprimées par le seam
     * @param  list<string>  $steps  ce qui SERAIT fait (mode sans écriture) ou ce qui l'a été
     */
    private function __construct(
        public readonly DeploymentOutcome $outcome,
        public readonly string $message,
        public readonly array $facts = [],
        public readonly array $steps = [],
    ) {
    }

    /**
     * @param  array<string, string>  $facts
     * @param  list<string>  $steps
     */
    public static function of(DeploymentOutcome $outcome, string $message, array $facts = [], array $steps = []): self
    {
        return new self($outcome, $message, $facts, $steps);
    }

    /** @param list<string> $steps */
    public static function failed(string $message, array $steps = []): self
    {
        return new self(DeploymentOutcome::Failed, $message, [], $steps);
    }

    public function fact(string $key, ?string $default = null): ?string
    {
        return $this->facts[$key] ?? $default;
    }

    public function isFailure(): bool
    {
        return $this->outcome->isFailure();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'message' => $this->message,
            'facts' => $this->facts,
            'steps' => $this->steps,
        ];
    }
}
