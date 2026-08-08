<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

/**
 * Story 61.1 — LE RÉSULTAT TYPÉ D'UN APPEL, ET SES TROIS ISSUES.
 *
 * Trois issues, jamais deux :
 *  1. **abouti** — l'appel a fait ce qu'on lui demandait ;
 *  2. **déjà conforme** — l'objet existait (statuscode OCS `102`, mesuré au spike
 *     60.0) : c'est un ÉTAT, pas une erreur, et l'appelant qui rejoue
 *     `nextcloud:provision` doit pouvoir compter dessus sans try/catch ;
 *  3. **échec**, avec sa cause nommée ({@see NextcloudFailure}).
 *
 * **Pourquoi un objet et pas une exception.** L'échec sur UN utilisateur ne doit
 * bloquer ni les autres, ni la création SE5 (fail-soft compté). Une exception
 * aurait imposé un `try/catch` à chaque boucle, et le premier oubli aurait fait
 * échouer un lot entier pour un compte absent. Le fail-CLOSED, lui, est ailleurs :
 * il porte sur la CONFIGURATION, avant la première écriture.
 *
 * **Aucun secret n'entre ici.** Le message est construit par le client à partir de
 * l'opération et de la cause ; ni l'en-tête d'autorisation, ni le mot de passe
 * transmis n'y figurent, et un test l'épingle.
 */
final class NextcloudResult
{
    /**
     * @param  array<string, mixed>  $data  Charge utile OCS/JSON déjà décodée (`ocs.data` pour OCS).
     */
    private function __construct(
        public readonly bool $successful,
        public readonly bool $alreadyConforming,
        public readonly ?NextcloudFailure $failure,
        public readonly string $message,
        public readonly array $data,
        public readonly ?int $httpStatus,
        public readonly ?int $ocsStatusCode,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function ok(array $data = [], ?int $httpStatus = null, ?int $ocsStatusCode = null, string $message = ''): self
    {
        return new self(true, false, null, $message, $data, $httpStatus, $ocsStatusCode);
    }

    /**
     * L'objet existait déjà. **Succès**, et l'appelant le sait par le drapeau
     * dédié : compter « adopté » plutôt que « créé » est une information
     * d'exploitation, pas une nuance cosmétique.
     *
     * @param  array<string, mixed>  $data
     */
    public static function conforming(string $message, array $data = [], ?int $httpStatus = null, ?int $ocsStatusCode = null): self
    {
        return new self(true, true, null, $message, $data, $httpStatus, $ocsStatusCode);
    }

    public static function failed(
        NextcloudFailure $failure,
        string $message,
        ?int $httpStatus = null,
        ?int $ocsStatusCode = null,
    ): self {
        return new self(false, false, $failure, $message, [], $httpStatus, $ocsStatusCode);
    }

    public function isFailure(): bool
    {
        return ! $this->successful;
    }

    /** L'échec est-il un refus de privilège (AC9) ? */
    public function isPrivilegeFailure(): bool
    {
        return $this->failure === NextcloudFailure::Privilege;
    }

    /**
     * Valeur de la charge utile, chemin pointé simple (`id`, `ocs.data.id` étant
     * déjà déplié par le client).
     */
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
            'ocs_status_code' => $this->ocsStatusCode,
        ];
    }
}
