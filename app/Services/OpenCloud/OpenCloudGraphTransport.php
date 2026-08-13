<?php

declare(strict_types=1);

namespace App\Services\OpenCloud;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * LE POINT DE SORTIE HTTP UNIQUE vers l'instance OpenCloud.
 *
 * Tout ce que SE5 dit à l'instance passe par ces cinq méthodes, sur le client
 * HTTP falsifiable du framework — jamais `curl` nu, jamais un processus, jamais
 * l'outil en ligne de commande du produit (qui supposerait un accès système AU
 * SERVEUR, qu'on n'a pas sur une instance distante). Un test d'architecture
 * l'épingle, et un autre épingle la LISTE de ces méthodes : une surface fermée
 * ne s'élargit pas par distraction.
 *
 * ---------------------------------------------------------------------------
 * **LES QUATRE SÉMANTIQUES MESURÉES QUE CETTE CLASSE NORMALISE** (2026-08-13) :
 *
 *  1. **Deux versions d'API COHABITENT.** `drives`, `users` et `groups` vivent en
 *     `/graph/v1.0/` ; les items, leurs permissions et les définitions de rôles
 *     vivent en `/graph/v1beta1/`. Employer la mauvaise rend `404 page not found`
 *     — une réponse qui ressemble à « la cible n'existe pas » alors qu'elle veut
 *     dire « la route n'existe pas ». Les chemins sont donc écrits en entier par
 *     les appelants, jamais recomposés ici.
 *  2. **Un `409 nameAlreadyExists` est un SUCCÈS.** « Existe déjà » se dit ainsi
 *     pour un groupe, un compte ou un octroi. C'est le piège symétrique de celui
 *     de l'autre produit (où un `200` enveloppait un refus) : ici un code d'échec
 *     enveloppe un état conforme. Le prendre pour un échec ferait rapporter rouge
 *     une zone parfaitement provisionnée, et le rejeu ne convergerait jamais.
 *     {@see call()} le normalise, sur demande explicite de l'appelant — jamais par
 *     défaut, parce que « déjà existant » n'est pas conforme pour tout le monde.
 *     **Le STATUT seul ne conclut rien** : la normalisation exige le statut ET le
 *     code applicatif. Idem pour le `404` du point 1, dont le jumeau
 *     `404 page not found` a un corps en texte brut et ne porte AUCUN code.
 *  3. **Un corps de liste a DEUX formes.** Une enveloppe `{"value":[…]}` pour les
 *     espaces, comptes, groupes et permissions ; un **TABLEAU NU** pour les
 *     définitions de rôles. {@see OpenCloudResult::entries()} les réconcilie.
 *  4bis. **Un geste manque à Graph** : créer un dossier. Il passe par le protocole
 *     d'édition distante, dont les codes se lisent autrement — d'où
 *     {@see sendRaw()}, où le verbe et la lecture de ses codes sont DÉCLARÉS par
 *     l'appelant plutôt que nommés ici (voir son docblock : la création
 *     d'arborescence distante appartient aux seuls namespaces de backend).
 *  4. **Le refus est TYPÉ.** Les erreurs portent `error.code`
 *     (`invalidRequest`, `notAllowed`, `accessDenied`, `itemNotFound`,
 *     `nameAlreadyExists`) et `error.message`. Le message d'un
 *     `role not applicable to this resource` est reconnu à part : c'est un défaut
 *     de TRADUCTION côté SE5, pas une panne côté instance.
 * ---------------------------------------------------------------------------
 *
 * **Le secret ne sort par aucun canal.** Il n'entre que dans l'en-tête
 * d'autorisation posé par {@see pending()} ; aucun message construit ici ne le
 * cite, et aucune méthode ne le rend.
 */
final class OpenCloudGraphTransport
{
    private const TIMEOUT_SECONDS = 30;

    public function __construct(private readonly OpenCloudConnectionConfig $config) {}

    /** L'URL de base, pour les messages d'affichage — jamais le secret. */
    public function baseUrl(): string
    {
        return $this->config->baseUrl;
    }

    /** L'identifiant du compte d'administration, pour les messages. */
    public function adminUser(): string
    {
        return $this->config->adminUser;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $path, string $operation, array $query = []): OpenCloudResult
    {
        return $this->call('GET', $path, null, $operation, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(string $path, array $payload, string $operation, ?string $conformingMessage = null): OpenCloudResult
    {
        return $this->call('POST', $path, $payload, $operation, [], $conformingMessage);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function patch(string $path, array $payload, string $operation): OpenCloudResult
    {
        return $this->call('PATCH', $path, $payload, $operation);
    }

    /**
     * Retire une ressource. **Un `404` est normalisé en CONFORME** quand
     * l'appelant le demande : mesuré, le retrait d'un octroi déjà absent rend
     * `404 itemNotFound`, et « il n'y avait rien à retirer » est l'état voulu.
     */
    public function delete(string $path, string $operation, bool $absentIsConforming = true): OpenCloudResult
    {
        $result = $this->call('DELETE', $path, null, $operation);

        if ($absentIsConforming && $result->isAbsent()) {
            return OpenCloudResult::conforming(
                sprintf('%s : rien à retirer, déjà absent.', ucfirst($operation)),
                [],
                $result->httpStatus,
            );
        }

        return $result;
    }

    /**
     * L'appel dont la réponse se lit AU CODE DE TRANSPORT, et lui seul.
     *
     * ---------------------------------------------------------------------------
     * **POURQUOI CE PASSAGE EST GÉNÉRIQUE PLUTÔT QUE NOMMÉ.** Un geste manque à
     * l'API Graph : créer un dossier. Il se fait par le protocole d'édition
     * distante, dont les codes ne veulent pas dire ce qu'ils ont l'air de dire —
     * `405` signifie « le dossier existe déjà » et `409` « le parent manque ».
     *
     * Ce transport pouvait porter ce geste sous son nom ; il ne le fait pas, et
     * c'est délibéré. Une garde d'architecture veut que la création
     * d'arborescence distante appartienne aux SEULS namespaces de backend — ce
     * répertoire-ci n'en est pas un, il ne fait que sortir en HTTP. Le verbe est
     * donc DÉCLARÉ par l'appelant, avec la lecture qu'il attend de ses codes, et
     * le nom du geste reste là où il doit vivre : sous la ligne de contrat, chez
     * le seul écrivain légitime des zones.
     * ---------------------------------------------------------------------------
     *
     * @param  list<int>  $conforming  codes qui signifient « c'était déjà fait »
     * @param  list<int>  $absent  codes qui signifient « la cible manque »
     */
    public function sendRaw(
        string $method,
        string $path,
        string $operation,
        array $conforming = [],
        array $absent = [],
        string $conformingMessage = '',
        string $absentMessage = '',
    ): OpenCloudResult {
        try {
            $response = $this->pending()->send($method, $this->config->url($path));
        } catch (ConnectionException $e) {
            return OpenCloudResult::failed(
                OpenCloudFailure::Injoignable,
                sprintf('%s : instance injoignable (%s).', ucfirst($operation), $this->shortReason($e)),
            );
        }

        $status = $response->status();

        if ($response->successful()) {
            return OpenCloudResult::ok([], $status);
        }

        if (in_array($status, $conforming, true)) {
            return OpenCloudResult::conforming(
                $conformingMessage !== '' ? $conformingMessage : sprintf('%s : déjà conforme.', ucfirst($operation)),
                [],
                $status,
            );
        }

        if (in_array($status, $absent, true)) {
            return OpenCloudResult::failed(
                OpenCloudFailure::Absent,
                $absentMessage !== '' ? $absentMessage : sprintf('%s : la cible manque.', ucfirst($operation)),
                $status,
            );
        }

        if ($status === 401 || $status === 403) {
            return OpenCloudResult::failed(
                OpenCloudFailure::Privilege,
                $this->privilegeMessage($operation),
                $status,
            );
        }

        return OpenCloudResult::failed(
            OpenCloudFailure::Refus,
            sprintf('%s : refusée par l\'instance (HTTP %d).', ucfirst($operation), $status),
            $status,
        );
    }

    // =========================================================================
    // Interne
    // =========================================================================

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>  $query
     */
    private function call(
        string $method,
        string $path,
        ?array $payload,
        string $operation,
        array $query = [],
        ?string $conformingMessage = null,
    ): OpenCloudResult {
        $url = $this->config->url($path);
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        try {
            $request = $this->pending();
            $response = match (strtoupper($method)) {
                'GET' => $request->get($url),
                'POST' => $request->post($url, $payload ?? []),
                'PATCH' => $request->patch($url, $payload ?? []),
                'DELETE' => $request->delete($url),
                default => $request->send(strtoupper($method), $url),
            };
        } catch (ConnectionException $e) {
            return OpenCloudResult::failed(
                OpenCloudFailure::Injoignable,
                sprintf('%s : instance injoignable (%s).', ucfirst($operation), $this->shortReason($e)),
            );
        }

        return $this->interpret($response, $operation, $conformingMessage);
    }

    /**
     * **LA SEULE LECTURE DE RÉPONSE DE CE CLIENT.**
     *
     * Le verdict vient du CORPS quand il porte une erreur typée, du code de
     * transport sinon — et « déjà existant » n'est jamais un échec.
     */
    private function interpret(Response $response, string $operation, ?string $conformingMessage): OpenCloudResult
    {
        $status = $response->status();
        $body = $response->json();

        $error = is_array($body) ? ($body['error'] ?? null) : null;
        $code = is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : null;
        $detail = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : '';

        if ($response->successful()) {
            if (is_array($body) && array_is_list($body)) {
                // Le TABLEAU NU mesuré sur les définitions de rôles.
                return OpenCloudResult::ok([], $status, '', $body);
            }

            return OpenCloudResult::ok(is_array($body) ? $body : [], $status);
        }

        // « Existe déjà » : un code d'échec qui enveloppe un état CONFORME.
        //
        // **LE STATUT NE SUFFIT PAS, ET LE CODE APPLICATIF NON PLUS : IL FAUT LES
        // DEUX.** Un `409` seul ne veut pas dire « déjà là » — il veut dire
        // « conflit », et la modification d'un octroi (`PATCH …/permissions/{id}`)
        // peut en rendre un pour une raison qui n'a rien de conforme. Le prendre
        // pour un succès ferait rapporter « appliqué » sur un octroi resté ce
        // qu'il était, sans relecture. Le catalogue de codes est étroit et mesuré
        // (`nameAlreadyExists`) : on l'exige.
        if ($status === 409 && $code === 'nameAlreadyExists') {
            return OpenCloudResult::conforming(
                $conformingMessage ?? sprintf('%s : déjà conforme.', ucfirst($operation)),
                [],
                $status,
            );
        }

        if ($status === 401 || $status === 403) {
            return OpenCloudResult::failed(
                OpenCloudFailure::Privilege,
                $this->privilegeMessage($operation, $detail),
                $status,
                $code,
            );
        }

        // « La cible n'existe pas » — et c'est le sens le plus DANGEREUX des deux,
        // parce qu'il est couplé à {@see delete()} où l'absence vaut conforme.
        //
        // **UN `404 page not found` N'EST PAS UN `404 itemNotFound`.** Le premier
        // dit « la route n'existe pas » et son corps est du TEXTE BRUT : `json()`
        // rend `null`, donc `$code` est `null`. Si le statut décidait seul, une
        // route de suppression erronée rendrait « déjà absent », et la révocation
        // conclurait « aucun octroi n'était en place » sur des accès intacts. Le
        // code applicatif est donc EXIGÉ en plus du statut.
        if ($status === 404 && $code === 'itemNotFound') {
            return OpenCloudResult::failed(
                OpenCloudFailure::Absent,
                sprintf(
                    '%s : la cible n\'existe pas côté instance%s.',
                    ucfirst($operation),
                    $detail === '' ? '' : ' — ' . $detail,
                ),
                $status,
                $code,
            );
        }

        // Le rôle de la MAUVAISE famille : un défaut de traduction chez NOUS.
        if (stripos($detail, 'role not applicable') !== false
            || stripos($detail, 'available_role') !== false) {
            return OpenCloudResult::failed(
                OpenCloudFailure::RoleInapplicable,
                sprintf(
                    '%s : le rôle demandé n\'est pas applicable à cette ressource — la racine d\'un espace '
                    . 'et un sous-dossier acceptent deux familles de rôles disjointes%s.',
                    ucfirst($operation),
                    $detail === '' ? '' : ' — ' . $detail,
                ),
                $status,
                $code,
            );
        }

        return OpenCloudResult::failed(
            OpenCloudFailure::Refus,
            sprintf(
                '%s : refusée par l\'instance%s.',
                ucfirst($operation),
                $detail === '' ? sprintf(' (HTTP %d)', $status) : ' — ' . $detail,
            ),
            $status,
            $code,
        );
    }

    private function privilegeMessage(string $operation, string $detail = ''): string
    {
        return sprintf(
            '%s : refusée — le compte « %s » n\'a pas le privilège requis (administrateur de l\'instance)%s.',
            ucfirst($operation),
            $this->config->adminUser,
            $detail === '' ? '' : ' — ' . $detail,
        );
    }

    private function pending(): PendingRequest
    {
        // AUTHENTIFICATION BASIQUE : mesuré comme le seul canal serveur→serveur
        // du produit. Aucun jeton, aucune expiration, rien à renouveler.
        return Http::withBasicAuth($this->config->adminUser, $this->config->adminPassword())
            ->withHeaders(['Accept' => 'application/json'])
            ->withOptions(['verify' => $this->config->verifyTls])
            ->timeout(self::TIMEOUT_SECONDS);
    }

    private function shortReason(ConnectionException $e): string
    {
        $message = trim($e->getMessage());

        return $message === ''
            ? 'échec de connexion'
            : (string) preg_replace('/\s+/', ' ', mb_substr($message, 0, 120));
    }
}
