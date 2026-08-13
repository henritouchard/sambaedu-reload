<?php

declare(strict_types=1);

namespace App\Services\OpenCloud;

/**
 * LE RÉSULTAT TYPÉ D'UN APPEL, ET SES TROIS ISSUES.
 *
 * Trois issues, jamais deux :
 *  1. **abouti** — l'appel a fait ce qu'on lui demandait ;
 *  2. **déjà conforme** — l'objet existait. Mesuré le 2026-08-13 : l'instance le
 *     dit par un **`409 nameAlreadyExists`** (groupe, compte, octroi) ou par un
 *     **`405`** (dossier déjà présent), c'est-à-dire par des codes qui RESSEMBLENT
 *     à des échecs. C'est un ÉTAT, pas une erreur, et l'appelant qui rejoue doit
 *     pouvoir compter dessus sans `try/catch` ;
 *  3. **échec**, avec sa cause nommée ({@see OpenCloudFailure}).
 *
 * **Pourquoi un objet et pas une exception.** L'échec sur UN nœud ne doit bloquer
 * ni les autres nœuds, ni le rapport. Une exception aurait imposé un `try/catch`
 * à chaque boucle, et le premier oubli aurait fait échouer une zone entière pour
 * un octroi. Le fail-CLOSED, lui, est ailleurs : il porte sur la CONFIGURATION,
 * avant la première écriture.
 *
 * **Aucun secret n'entre ici.** Le message est construit par le client à partir de
 * l'opération et de la cause ; ni l'en-tête d'autorisation, ni le mot de passe
 * transmis n'y figurent, et un test l'épingle.
 */
final class OpenCloudResult
{
    /**
     * @param  array<string, mixed>  $data  charge utile JSON déjà décodée
     * @param  list<mixed>  $collection  corps rendu en TABLEAU NU (mesuré sur les
     *                                   définitions de rôles), sinon vide
     */
    private function __construct(
        public readonly bool $successful,
        public readonly bool $alreadyConforming,
        public readonly ?OpenCloudFailure $failure,
        public readonly string $message,
        public readonly array $data,
        public readonly array $collection,
        public readonly ?int $httpStatus,
        public readonly ?string $errorCode,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<mixed>  $collection
     */
    public static function ok(
        array $data = [],
        ?int $httpStatus = null,
        string $message = '',
        array $collection = [],
    ): self {
        return new self(true, false, null, $message, $data, $collection, $httpStatus, null);
    }

    /**
     * L'objet existait déjà. **Succès**, et l'appelant le sait par le drapeau
     * dédié : compter « adopté » plutôt que « créé » est une information
     * d'exploitation, pas une nuance cosmétique.
     *
     * @param  array<string, mixed>  $data
     */
    public static function conforming(string $message, array $data = [], ?int $httpStatus = null): self
    {
        return new self(true, true, null, $message, $data, [], $httpStatus, null);
    }

    public static function failed(
        OpenCloudFailure $failure,
        string $message,
        ?int $httpStatus = null,
        ?string $errorCode = null,
    ): self {
        return new self(false, false, $failure, $message, [], [], $httpStatus, $errorCode);
    }

    public function isFailure(): bool
    {
        return ! $this->successful;
    }

    /** L'échec est-il un refus de privilège ? */
    public function isPrivilegeFailure(): bool
    {
        return $this->failure === OpenCloudFailure::Privilege;
    }

    /** L'échec est-il une cible absente ? (`404` = « il n'y a rien ici ») */
    public function isAbsent(): bool
    {
        return $this->failure === OpenCloudFailure::Absent;
    }

    /**
     * Les entrées d'une réponse de liste, quelle que soit la forme rendue.
     *
     * **Deux formes coexistent, mesurées** : une enveloppe `{"value":[…]}` pour les
     * espaces, les comptes, les groupes et les permissions ; un **TABLEAU NU** pour
     * les définitions de rôles. Normaliser ICI évite que chaque appelant redécouvre
     * la différence — et qu'un seul d'entre eux l'oublie.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        $raw = $this->collection !== [] ? $this->collection : ($this->data['value'] ?? null);

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, 'is_array'));
    }

    public function value(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Forme journalisable. **Sans secret par construction** : elle ne rend que ce
     * que ce résultat porte, et ce résultat ne porte jamais d'identifiants.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'successful' => $this->successful,
            'already_conforming' => $this->alreadyConforming,
            'failure' => $this->failure?->value,
            'message' => $this->message,
            'http_status' => $this->httpStatus,
            'error_code' => $this->errorCode,
        ];
    }
}
