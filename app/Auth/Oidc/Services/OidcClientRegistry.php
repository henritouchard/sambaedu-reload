<?php

declare(strict_types=1);

namespace App\Auth\Oidc\Services;

use App\Auth\Oidc\Support\OidcErrorCodes;
use App\Models\Extension;
use App\Models\OidcClient;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Story 55.1 — Le REGISTRE DES CLIENTS CONFIDENTIELS (FR19 amorce).
 *
 * Trois opérations, et le point d'accroche de l'Epic 56 :
 *
 *  - {@see self::register()}   — déclare un client, retourne le secret CLAIR
 *    **une seule fois** ;
 *  - {@see self::authenticate()} — vérifie un couple `client_id`/secret au
 *    token endpoint ;
 *  - {@see self::revoke()}     — désactive un client (jamais de suppression).
 *
 * En 55.1, les clients sont déclarés par commande artisan. En Epic 56, c'est
 * l'installation d'une extension de type `app` qui appellera `register()` et sa
 * désinstallation qui appellera `revoke()` — d'où une API pensée pour être
 * pilotée par du code, pas par un formulaire.
 *
 * **NFR3 — le secret n'existe en clair qu'une fois.** `register()` le renvoie à
 * son appelant ; seul `hash('sha256', $secret)` est persisté. Il n'est ni
 * loggé, ni ré-affichable : un secret perdu se remplace, il ne se retrouve pas.
 *
 * **Pourquoi sha256 et pas bcrypt/argon2** : le secret est un jeton CSPRNG de
 * 256 bits généré par nous, pas un mot de passe humain. Il n'a ni entropie
 * faible, ni réutilisation inter-sites — les deux problèmes que le hachage lent
 * résout. La comparaison est faite par `hash_equals` (temps constant). C'est
 * exactement la doctrine déjà appliquée aux refresh tokens des postes
 * (`WorkstationJwtIssuer::issueRefreshToken()`), et un hachage lent sur un
 * endpoint machine appelé à chaque SSO serait un vecteur de déni de service.
 */
class OidcClientRegistry
{
    /** Longueur (en octets) du `client_id` opaque avant hexadécimalisation. */
    private const CLIENT_ID_BYTES = 16;

    /** Longueur (en octets) du secret CSPRNG. 32 octets = 256 bits. */
    private const SECRET_BYTES = 32;

    /**
     * Longueur maximale d'une `redirect_uri`, ALIGNÉE sur la colonne
     * `oidc_authorization_codes.redirect_uri` (VARCHAR 512) où elle est recopiée
     * à chaque émission de code. Élargir la colonne impose d'élargir ici.
     */
    public const MAX_REDIRECT_URI_LENGTH = 512;

    /**
     * Déclare un client confidentiel.
     *
     * @param  list<string>  $redirectUris  Liste STRICTE — égalité exacte à l'usage.
     * @param  string|null   $extensionKey  Clé d'une extension du registre (facultatif).
     * @return array{client: OidcClient, client_id: string, client_secret: string}
     *
     * @throws InvalidArgumentException Si le nom, la liste d'URI ou l'extension sont invalides.
     */
    public function register(string $name, array $redirectUris, ?string $extensionKey = null): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Le nom du client est obligatoire.');
        }

        $normalizedUris = $this->validateRedirectUris($redirectUris);

        $extension = null;
        if ($extensionKey !== null && $extensionKey !== '') {
            $extension = Extension::query()->where('key', $extensionKey)->first();
            if ($extension === null) {
                throw new InvalidArgumentException(
                    'Extension inconnue du registre : « ' . $extensionKey . ' ». '
                    . 'Vérifier `php artisan db:seed --class=BundledExtensionSeeder` ou la clé fournie.'
                );
            }
        }

        $clientId = bin2hex(random_bytes(self::CLIENT_ID_BYTES));
        $secret = bin2hex(random_bytes(self::SECRET_BYTES));

        $client = OidcClient::query()->create([
            'extension_id' => $extension?->id,
            'extension_key' => $extension?->key ?? '',
            'name' => $name,
            'client_id' => $clientId,
            'client_secret_hash' => hash('sha256', $secret),
            'redirect_uris' => $normalizedUris,
            'enabled' => true,
        ]);

        // ⚠️ Ni le secret ni son hash ne sont loggés (NFR3).
        Log::channel('oidc')->info('[OidcClientRegistry] oidc.client.registered', [
            'action_type' => 'oidc.client.registered',
            'client_id' => $clientId,
            'name' => $name,
            'extension_key' => $client->extension_key,
            'redirect_uris_count' => count($normalizedUris),
        ]);

        return [
            'client' => $client,
            'client_id' => $clientId,
            // Le CLAIR, rendu UNE SEULE FOIS — il n'est stocké nulle part.
            'client_secret' => $secret,
        ];
    }

    /**
     * Résout un client ACTIF. Un client révoqué est introuvable ici — c'est ce
     * qui rend la révocation effective pour tous les flux (autorisation ET
     * échange), sans avoir à purger ses codes et jetons.
     */
    public function findEnabledByClientId(string $clientId): ?OidcClient
    {
        if ($clientId === '') {
            return null;
        }

        return OidcClient::query()
            ->where('client_id', $clientId)
            ->where('enabled', true)
            ->first();
    }

    /**
     * Authentifie un client confidentiel au token endpoint.
     *
     * Renvoie `null` en cas d'échec, QUELLE QUE SOIT la cause (client inconnu,
     * révoqué, secret faux) : le contrôleur répond un unique `invalid_client`,
     * le détail va au journal.
     */
    public function authenticate(string $clientId, string $secret): ?OidcClient
    {
        $client = $this->findEnabledByClientId($clientId);

        if ($client === null) {
            $this->logAuthFailure($clientId, OidcErrorCodes::CLIENT_UNKNOWN);

            return null;
        }

        if ($secret === '' || ! hash_equals($client->client_secret_hash, hash('sha256', $secret))) {
            $this->logAuthFailure($clientId, OidcErrorCodes::CLIENT_AUTH_FAILED);

            return null;
        }

        return $client;
    }

    /**
     * Révoque (désactive) un client. IDEMPOTENT : un client déjà révoqué est un
     * no-op signalé, pas une erreur — la commande doit être rejouable.
     *
     * @return array{found: bool, changed: bool}
     */
    public function revoke(string $clientId): array
    {
        $client = OidcClient::query()->where('client_id', $clientId)->first();

        if ($client === null) {
            return ['found' => false, 'changed' => false];
        }

        if (! $client->enabled) {
            return ['found' => true, 'changed' => false];
        }

        $client->enabled = false;
        $client->save();

        Log::channel('oidc')->warning('[OidcClientRegistry] oidc.client.revoked', [
            'action_type' => 'oidc.client.revoked',
            'client_id' => $clientId,
            'extension_key' => $client->extension_key,
        ]);

        return ['found' => true, 'changed' => true];
    }

    /**
     * Valide et normalise une liste d'URI de redirection.
     *
     * **Schémas bornés** (précédent : correctif `entry_url` de la review 54.3) :
     * seules une URL absolue `http(s)://…` et un chemin absolu de l'instance
     * (`/callback`) sont acceptés. Sont refusés `javascript:`, `data:`, et les
     * URL protocol-relative `//hôte` — qui, écrites dans un `Location:`,
     * enverraient l'utilisateur (et le code d'autorisation) chez un tiers.
     *
     * @param  list<string>  $redirectUris
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public function validateRedirectUris(array $redirectUris): array
    {
        $normalized = [];

        foreach ($redirectUris as $uri) {
            $uri = trim((string) $uri);

            if ($uri === '') {
                continue;
            }

            if (str_starts_with($uri, '//')) {
                throw new InvalidArgumentException(
                    'URI de redirection refusée (protocol-relative) : ' . $uri
                );
            }

            // Borne ALIGNÉE sur `oidc_authorization_codes.redirect_uri`
            // (VARCHAR 512) : l'URI validée y est recopiée à chaque émission de
            // code. Sans ce contrôle, un client accepté ici échouerait à CHAQUE
            // flux d'autorisation sur une `QueryException` PostgreSQL (500 hors
            // journal `oidc`), alors que SQLite — driver des tests — ne signale
            // jamais le dépassement. Refus à l'enregistrement = échec bruyant
            // au bon moment, pour la bonne personne.
            if (mb_strlen($uri) > self::MAX_REDIRECT_URI_LENGTH) {
                throw new InvalidArgumentException(
                    'URI de redirection refusée (longueur ' . mb_strlen($uri) . ' > '
                    . self::MAX_REDIRECT_URI_LENGTH . ' caractères) : ' . mb_substr($uri, 0, 80) . '…'
                );
            }

            if (str_starts_with($uri, '/')) {
                // Chemin absolu de l'instance — légitime pour une extension
                // servie par SE5 lui-même.
                $normalized[] = $uri;

                continue;
            }

            $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));
            if ($scheme !== 'http' && $scheme !== 'https') {
                throw new InvalidArgumentException(
                    'URI de redirection refusée (schéma non autorisé, attendu http/https '
                    . 'ou chemin absolu) : ' . $uri
                );
            }

            if (parse_url($uri, PHP_URL_HOST) === null) {
                throw new InvalidArgumentException('URI de redirection refusée (hôte manquant) : ' . $uri);
            }

            $normalized[] = $uri;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException(
                'Au moins une URI de redirection est obligatoire : sans elle, aucun flux '
                . 'd\'autorisation ne peut aboutir.'
            );
        }

        return array_values(array_unique($normalized));
    }

    private function logAuthFailure(string $clientId, string $code): void
    {
        Log::channel('oidc')->warning('[OidcClientRegistry] oidc.token.rejected', [
            'action_type' => 'oidc.token.rejected',
            'code' => $code,
            // `client_id` est un identifiant PUBLIC — jamais le secret ni son hash.
            'client_id' => $clientId,
        ]);
    }
}
