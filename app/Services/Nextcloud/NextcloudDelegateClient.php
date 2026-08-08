<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Story 61.2 — LE POINT DE SORTIE HTTP DU MODE DÉLÉGUÉ. **EN LECTURE SEULE.**
 *
 * ---------------------------------------------------------------------------
 * **NIVEAU DE PRIVILÈGE : UN COMPTE ORDINAIRE, ET RIEN DE PLUS.** C'est la
 * définition du mode : le compte porteur n'est pas administrateur, il n'a ni les
 * montages globaux ni la gestion des comptes. Mesuré le 2026-08-08 sur `nc-spike`
 * (Nextcloud 34.0.2) avec un compte ordinaire : les deux endpoints
 * d'administration répondent `403`.
 *
 * **CE CLIENT N'ÉCRIT RIEN, ET C'EST UNE FRONTIÈRE DE STORY.** 61.2 déclare et
 * administre le mode ; elle n'exécute aucun plan. Les écritures du délégué — créer
 * l'arborescence, émettre un octroi — appartiennent au backend de 61.3. Les ajouter
 * ici produirait du code mort ET affaiblirait la garde d'architecture qui interdit
 * à ce namespace de toucher aux partages. Ce que ces écritures font réellement est
 * MESURÉ par le test d'intégration de l'AC9, en HTTP nu côté test, jamais par une
 * méthode de production.
 * ---------------------------------------------------------------------------
 *
 * **Deux méthodes, deux besoins de la story, toutes deux en lecture :**
 *  1. {@see probe()} — la sonde du mode (AC3) : le porteur s'authentifie-t-il, et
 *     le partage est-il activé sur l'instance ?
 *  2. {@see findUserByExactId()} — la vérification d'identité du rattachement
 *     explicite (AC7) en mode délégué : l'autocomplétion, avec correspondance
 *     EXACTE sur l'identifiant. Le précédent SE4 (`cloud.inc.php:989`) prouve
 *     qu'elle fonctionne en compte ordinaire.
 *
 * **L'auth est celle du PORTEUR, et elle ne peut pas être autre chose** : ce client
 * ne se construit que sur {@see NextcloudDelegateConfig}, qui ne sait lire que le
 * credential du porteur. Le croisement avec l'auth admin n'est pas interdit par une
 * convention — il est impossible par typage.
 */
final class NextcloudDelegateClient
{
    /**
     * Délai d'attente par appel — identique au client d'administration : ces appels
     * sont dans le chemin d'un écran, et une instance qui met une minute à répondre
     * est en panne.
     */
    private const TIMEOUT_SECONDS = 15;

    /**
     * Code WebDAV « Multi-Status » — la réponse NORMALE d'un `PROPFIND` abouti.
     * **Mesuré** : ce n'est pas `200`, et attendre `200` ferait échouer la sonde sur
     * une instance parfaitement saine.
     */
    private const DAV_MULTI_STATUS = 207;

    /**
     * Clé de l'application de partage dans la charge utile des capacités OCS, et
     * drapeau de son API.
     *
     * ⚠️ C'est une LECTURE de capacité, jamais une route d'API de partage : le
     * mode délégué n'émet aucun octroi en 61.2 (frontière 61.3), et la garde
     * d'architecture porte sur les routes `apps/…` du partage, pas sur ce nom
     * d'application dans un inventaire de capacités.
     */
    private const SHARING_CAPABILITY = 'files_sharing';

    private const SHARING_API_FLAG = 'api_enabled';

    public function __construct(private readonly NextcloudDelegateConfig $config)
    {
    }

    /**
     * AC3 — les trois diagnostics du mode délégué, par des LECTURES uniquement.
     *
     * Deux appels, dans cet ordre, parce que chacun isole UNE cause :
     *  1. `PROPFIND` de profondeur 0 sur la racine WebDAV du porteur — l'instance
     *     répond-elle, et ce compte est-il accepté ? (`207` attendu, mesuré) ;
     *  2. les capacités OCS — le partage est-il activé sur l'instance ? Sans lui,
     *     aucun octroi ne sera possible et le mode est déclaré pour rien.
     */
    public function probe(): NextcloudDelegateProbe
    {
        try {
            $dav = $this->pending()
                ->withHeaders(['Depth' => '0'])
                ->send('PROPFIND', $this->config->davRoot());
        } catch (ConnectionException $e) {
            return NextcloudDelegateProbe::unreachable(sprintf(
                'Instance Nextcloud injoignable à l\'adresse %s (%s).',
                $this->config->baseUrl,
                $this->shortReason($e),
            ));
        }

        $status = $dav->status();

        if ($status === 401 || $status === 403) {
            return NextcloudDelegateProbe::credentialsRefused(
                sprintf(
                    'Le compte porteur « %s » a été refusé par l\'instance : app password révoqué ou '
                    . 'incorrect, ou compte désactivé.',
                    $this->config->delegateUser,
                ),
                $status,
            );
        }

        if ($status === 404) {
            return NextcloudDelegateProbe::credentialsRefused(
                sprintf(
                    'L\'instance ne connaît aucun espace de fichiers pour « %s » : vérifiez l\'identifiant '
                    . 'du compte porteur (c\'est son identifiant Nextcloud, pas son nom affiché).',
                    $this->config->delegateUser,
                ),
                404,
            );
        }

        if ($status !== self::DAV_MULTI_STATUS) {
            return NextcloudDelegateProbe::rejected(
                sprintf(
                    'L\'instance a refusé la lecture de l\'espace du compte porteur (HTTP %d ; une réponse '
                    . 'aboutie est un « 207 Multi-Status »).',
                    $status,
                ),
                $status,
            );
        }

        try {
            $capabilities = $this->pending()->get($this->config->url('ocs/v1.php/cloud/capabilities') . '?format=json');
        } catch (ConnectionException $e) {
            return NextcloudDelegateProbe::unreachable(sprintf(
                'Instance Nextcloud injoignable à l\'adresse %s (%s).',
                $this->config->baseUrl,
                $this->shortReason($e),
            ));
        }

        if (! $capabilities->successful()) {
            return NextcloudDelegateProbe::rejected(
                sprintf('L\'instance a refusé la lecture de ses capacités (HTTP %d).', $capabilities->status()),
                $capabilities->status(),
            );
        }

        if (! $this->sharingApiEnabled($capabilities)) {
            return NextcloudDelegateProbe::sharingDisabled(
                'Le partage est désactivé sur l\'instance : le mode délégué n\'a d\'autre moyen d\'octroyer '
                . 'un accès que le partage, et aucun ne pourra être émis. Réactivez-le dans les paramètres '
                . 'd\'administration de Nextcloud.',
                $capabilities->status(),
            );
        }

        return NextcloudDelegateProbe::ok($this->config->delegateUser);
    }

    /**
     * AC7 — vérification d'identité en mode délégué : l'autocomplétion, avec
     * correspondance **EXACTE** sur l'identifiant.
     *
     * **Pourquoi exacte et pas « unique ».** L'autocomplétion est une recherche
     * FLOUE (sous-chaîne sur l'identifiant, le nom affiché, l'adresse). La revue de
     * 61.1 a fermé le scénario `p.durand` / `p.durand-martin` : un candidat unique
     * n'est pas une preuve d'identité, et une identité non confirmée écrite en base
     * rouvre l'écrasement du mot de passe d'un tiers. Le rattachement de l'AC7 est
     * un geste d'admin VÉRIFIÉ, jamais une devinette — la même règle s'applique
     * qu'elle vienne d'une machine ou d'un humain.
     *
     * La comparaison ignore la casse mais rend l'identifiant **tel que l'instance
     * l'écrit** : c'est elle qui fait autorité sur l'orthographe de ses comptes.
     *
     * @return NextcloudResult `data['id']` = l'identifiant confirmé ; échec `Absent` si aucun exact.
     */
    public function findUserByExactId(string $login): NextcloudResult
    {
        $operation = sprintf('vérification de l\'identité Nextcloud « %s »', $login);

        try {
            $response = $this->pending()->get(
                $this->config->url('ocs/v2.php/core/autocomplete/get') . '?' . http_build_query([
                    'format' => 'json',
                    'search' => $login,
                    'itemType' => ' ',
                    'itemId' => ' ',
                    'shareTypes' => [0],
                ]),
            );
        } catch (ConnectionException $e) {
            return NextcloudResult::failed(
                NextcloudFailure::Injoignable,
                sprintf('%s : instance injoignable (%s).', ucfirst($operation), $this->shortReason($e)),
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return NextcloudResult::failed(
                NextcloudFailure::Privilege,
                sprintf(
                    '%s : refusée — le compte porteur « %s » n\'a pas été accepté par l\'instance.',
                    ucfirst($operation),
                    $this->config->delegateUser,
                ),
                $response->status(),
            );
        }

        $body = $response->json();
        $entries = is_array($body) ? ($body['ocs']['data'] ?? null) : null;

        if (! is_array($entries)) {
            return NextcloudResult::failed(
                NextcloudFailure::Illisible,
                sprintf('%s : réponse OCS illisible (enveloppe absente).', ucfirst($operation)),
                $response->status(),
            );
        }

        foreach ($entries as $entry) {
            if (! is_array($entry) || ($entry['source'] ?? null) !== 'users') {
                continue;
            }

            $id = $entry['id'] ?? null;

            if (is_string($id) && mb_strtolower($id) === mb_strtolower(trim($login))) {
                return NextcloudResult::ok(['id' => $id], $response->status());
            }
        }

        // L'absence est SILENCIEUSE côté API (mesure du spike 60.0 : zéro résultat,
        // pas d'erreur). Elle ne l'est jamais côté SE5.
        return NextcloudResult::failed(
            NextcloudFailure::Absent,
            sprintf(
                '%s : aucun compte de l\'instance ne porte exactement cet identifiant (la recherche est '
                . 'floue, seul l\'identifiant exact est accepté).',
                ucfirst($operation),
            ),
            $response->status(),
        );
    }

    // =========================================================================
    // Interne
    // =========================================================================

    private function sharingApiEnabled(Response $response): bool
    {
        $body = $response->json();
        $capabilities = is_array($body) ? ($body['ocs']['data']['capabilities'] ?? null) : null;

        if (! is_array($capabilities)) {
            // Enveloppe illisible : on ne PRÉSUME pas que le partage est actif.
            // Présumer le vert est la façon la plus discrète de déclarer un mode
            // que l'instance ne tiendra pas.
            return false;
        }

        $sharing = $capabilities[self::SHARING_CAPABILITY] ?? null;

        return is_array($sharing) && ($sharing[self::SHARING_API_FLAG] ?? false) === true;
    }

    /**
     * La requête de base : auth basic du PORTEUR, en-tête OCS, vérification TLS
     * conforme au réglage, délai borné, aucun retry.
     */
    private function pending(): PendingRequest
    {
        return Http::withBasicAuth($this->config->delegateUser, $this->config->delegatePassword())
            ->withHeaders([
                'OCS-APIRequest' => 'true',
                'Accept' => 'application/json',
            ])
            ->withOptions(['verify' => $this->config->verifyTls])
            ->timeout(self::TIMEOUT_SECONDS);
    }

    /**
     * Raison COURTE d'un échec de transport : on ne recopie pas le message complet
     * du client HTTP, qui contient l'URL — parfois avec des identifiants dedans.
     */
    private function shortReason(ConnectionException $e): string
    {
        $message = $e->getMessage();

        return $message === ''
            ? 'échec de connexion'
            : (string) preg_replace('/\s+/', ' ', mb_substr($message, 0, 120));
    }
}
