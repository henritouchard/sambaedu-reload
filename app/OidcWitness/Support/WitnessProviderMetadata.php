<?php

declare(strict_types=1);

namespace App\OidcWitness\Support;

use RuntimeException;

/**
 * Story 55.3 — La découverte du fournisseur, vue d'un client honnête.
 *
 * Le témoin ne connaît qu'une chose du fournisseur : son `issuer`. Tout le reste
 * — l'URL d'autorisation, celle du token endpoint, celle du JWKS — se DÉCOUVRE
 * par HTTP à `{issuer}/.well-known/openid-configuration`, exactement comme le
 * ferait une extension tierce. Aucun chemin `/oidc/...` n'est écrit en dur ici :
 * le jour où le fournisseur bougerait (ou serait remplacé par Keycloak, NFR12),
 * le témoin suivrait sans être modifié.
 *
 * **Contrôle du contrat, pas seulement de la disponibilité** : l'`issuer`
 * ANNONCÉ par le document doit être celui qu'on est allé interroger. Un
 * document qui prétend parler pour un autre émetteur est refusé — c'est le
 * contrôle standard qui empêche un fournisseur détourné de faire accepter des
 * jetons d'ailleurs.
 *
 * **Mise en cache d'instance seulement** (mémoïsation par objet) : pas de cache
 * partagé, pas de TTL à invalider. Une page de démonstration fait deux appels ;
 * un vrai SDK (Epic 58) portera une vraie politique de cache.
 */
class WitnessProviderMetadata
{
    /** @var array<string, array<string, mixed>> */
    private array $discoveryMemo = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $jwksMemo = [];

    public function __construct(
        private readonly WitnessHttpClient $http,
    ) {
    }

    /**
     * Document de discovery de l'`issuer` donné.
     *
     * @return array<string, mixed>
     */
    public function discovery(string $issuer): array
    {
        $issuer = rtrim($issuer, '/');

        if (isset($this->discoveryMemo[$issuer])) {
            return $this->discoveryMemo[$issuer];
        }

        $document = $this->http->getJson($issuer . '/.well-known/openid-configuration');

        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $required) {
            if (! isset($document[$required]) || ! is_string($document[$required]) || $document[$required] === '') {
                throw new RuntimeException('Discovery incomplète : champ « ' . $required . ' » absent');
            }
        }

        if (rtrim((string) $document['issuer'], '/') !== $issuer) {
            throw new RuntimeException(
                'Discovery incohérente : l\'issuer annoncé ne correspond pas à celui interrogé'
            );
        }

        return $this->discoveryMemo[$issuer] = $document;
    }

    /**
     * Les clés publiées par le JWKS, telles quelles (RFC 7517). Leur conversion
     * en clés vérifiables appartient au vérificateur.
     *
     * @return list<array<string, mixed>>
     */
    public function jwks(string $jwksUri): array
    {
        if (isset($this->jwksMemo[$jwksUri])) {
            return $this->jwksMemo[$jwksUri];
        }

        $document = $this->http->getJson($jwksUri);
        $keys = $document['keys'] ?? null;

        if (! is_array($keys) || $keys === []) {
            // Fail-closed : un JWKS vide n'autorise RIEN. Le laisser passer
            // reviendrait à accepter un jeton qu'on n'a pas su vérifier.
            throw new RuntimeException('JWKS vide ou malformé');
        }

        $normalized = [];
        foreach ($keys as $key) {
            if (is_array($key)) {
                /** @var array<string, mixed> $key */
                $normalized[] = $key;
            }
        }

        return $this->jwksMemo[$jwksUri] = $normalized;
    }
}
