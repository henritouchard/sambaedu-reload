<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use App\Auth\Oidc\Services\OidcClientRegistry;
use App\Auth\Oidc\Support\OidcClaimsResolver;
use App\Exceptions\ExtensionLifecycleException;
use App\Models\Extension;
use App\Models\ExtensionAuditLog;
use App\Models\OidcClient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Story 56.4 — **LES SCOPES ACCORDÉS, VUS DEPUIS L'EXTENSION** (FR23, FR36).
 *
 * Deux gestes, et un seul sujet : ce que l'admin a réellement accordé à une
 * extension, et son retrait.
 *
 *  - {@see self::grantedScopesFor()} — ce que la fiche AFFICHE (`null` quand
 *    l'extension n'a aucun client OIDC actif : il n'y a alors rien à montrer,
 *    pas « une liste vide ») ;
 *  - {@see self::revokeScope()} — le retrait, en transaction, audité.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CE SERVICE NE COUPE PAS L'ACCÈS, IL COUPE UNE DONNÉE
 *
 *  Révoquer `groups` ne casse pas le SSO de l'extension : ses utilisateurs
 *  continuent de s'y connecter, elle n'apprend simplement plus leurs classes.
 *  Couper l'accès, c'est désinstaller (FR10). Cette distinction est ce qui
 *  permet à un admin de restreindre sans provoquer de panne — et c'est
 *  pourquoi la révocation d'un scope RÉDUIT les jetons au lieu de les refuser.
 *
 *  L'effet est IMMÉDIAT, y compris sur les jetons déjà émis : rien n'est
 *  purgé, le scope effectif est recalculé à chaque usage
 *  ({@see \App\Models\OidcClient::effectiveScopeFor()}).
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **À SENS UNIQUE.** Il n'y a pas de ré-octroi : re-consentir passe par une
 * désinstallation puis une réinstallation, comme pour toute modification du
 * contrat d'une extension installée (même doctrine que
 * `ERROR_REDIRECT_PATHS_CHANGED`, 56.3). Un bouton « ré-accorder » ferait de
 * l'écart demandés/accordés un réglage à cliquer, alors que c'est une décision
 * d'installation.
 *
 * **Patron lifecycle** (54.2/56.2) : transaction + `lockForUpdate` sur
 * l'extension, revalidation SERVEUR de l'entrée (l'input Livewire n'est jamais
 * cru), acte et trace d'audit dans la MÊME transaction, no-op ⇒ ZÉRO ligne
 * d'audit. L'acteur est passé en paramètre — le service ne lit jamais `auth()`.
 *
 * NFR15 : rien d'Eloquent ne sort d'ici, uniquement des tableaux plats.
 */
class ExtensionScopeService
{
    /** Le scope a été retiré : `granted_scopes` a réellement changé. */
    public const STATUS_REVOKED = 'revoked';

    /** Déjà retiré (ou jamais accordé) — écran périmé, no-op signalé. */
    public const STATUS_NOT_GRANTED = 'not_granted';

    /**
     * Le scope demandé n'est pas révocable : hors du catalogue fermé, ou
     * `openid` — le plancher du protocole, qui n'est jamais accordé et ne peut
     * donc pas être retiré. Refus, jamais un no-op silencieux.
     */
    public const STATUS_UNSUPPORTED = 'unsupported';

    /** L'extension n'a aucun client OIDC actif : il n'y a rien à révoquer. */
    public const STATUS_NO_CLIENT = 'no_client';

    public function __construct(
        private readonly OidcClientRegistry $registry,
    ) {
    }

    /**
     * Les scopes ACCORDÉS à une extension, ou `null` si elle n'a aucun client
     * OIDC actif.
     *
     * `null` et `[]` ne disent PAS la même chose : `null` = « cette extension
     * n'a pas de client » (une `link`, ou une `app` non installée) — la fiche
     * n'affiche alors aucun volet ; `[]` = « elle en a un, et il n'a plus
     * aucun scope » — la fiche l'affiche, parce que c'est un état que l'admin a
     * produit et doit pouvoir constater.
     *
     * Le client retenu est le plus récent des clients `enabled` de la clé
     * (patron de résolution d'`ExtensionInstallService::update()`) : les
     * fantômes d'une installation antérieure ne doivent pas décider de ce qui
     * s'affiche.
     *
     * @return list<string>|null
     */
    public function grantedScopesFor(Extension $extension): ?array
    {
        $key = (string) $extension->key;

        if ($key === '') {
            return null;
        }

        /** @var OidcClient|null $client */
        $client = OidcClient::query()
            ->where('extension_key', $key)
            ->where('enabled', true)
            ->orderByDesc('id')
            ->first();

        return $client?->grantedScopes();
    }

    /**
     * Révoque UN scope accordé à une extension.
     *
     * @param  User|null  $actor  `null` ⇒ acte CLI, journalisé sous `system`
     * @return array{changed: bool, status: string}
     *
     * @throws ExtensionLifecycleException Identifiant d'extension inconnu.
     */
    public function revokeScope(int $extensionId, string $scope, ?User $actor = null): array
    {
        $scope = trim($scope);

        return DB::transaction(function () use ($extensionId, $scope, $actor): array {
            /** @var Extension|null $extension */
            $extension = Extension::query()->lockForUpdate()->find($extensionId);

            if ($extension === null) {
                throw ExtensionLifecycleException::unknownExtension($extensionId);
            }

            // ── Revalidation SERVEUR du scope ──────────────────────────────
            //
            // L'entrée vient d'un clic Livewire : elle est traitée comme
            // hostile. Un scope hors catalogue — `openid` compris — est refusé
            // AVANT toute écriture, et sans ligne d'audit : il n'y a pas d'acte
            // à tracer.
            if (! in_array($scope, array_keys(OidcClaimsResolver::CLAIMS_BY_SCOPE), true)) {
                return ['changed' => false, 'status' => self::STATUS_UNSUPPORTED];
            }

            /** @var list<OidcClient> $clients */
            $clients = OidcClient::query()
                ->where('extension_key', (string) $extension->key)
                ->where('enabled', true)
                ->get()
                ->all();

            if ($clients === []) {
                return ['changed' => false, 'status' => self::STATUS_NO_CLIENT];
            }

            // TOUS les clients actifs de la clé sont traités, pas seulement
            // celui qu'affiche la fiche : une installation antérieure mal
            // nettoyée peut en avoir laissé un second (patron `remove()` 56.2),
            // et un fantôme qui continuerait de servir `groups` viderait la
            // révocation de son sens.
            $changed = false;

            foreach ($clients as $client) {
                $result = $this->registry->revokeScope((string) $client->client_id, $scope);
                $changed = $changed || $result['changed'];
            }

            if (! $changed) {
                // Déjà révoqué : l'écran de l'admin était périmé. C'est une
                // information, pas un acte — donc zéro ligne d'audit (patron
                // review 54.2 #2).
                return ['changed' => false, 'status' => self::STATUS_NOT_GRANTED];
            }

            // Atomicité acte ↔ trace : si l'audit échoue, la révocation est
            // annulée par le rollback. `details` = le scope, jamais un
            // `client_id`, jamais un secret.
            ExtensionAuditLog::log(
                extensionId: $extension->id,
                extensionKey: (string) $extension->key,
                extensionName: (string) $extension->name,
                action: ExtensionAuditLog::ACTION_SCOPE_REVOKE,
                actorUserId: $actor?->id,
                actorLogin: $actor?->login ?? ExtensionAuditLog::ACTOR_SYSTEM,
                details: $scope,
            );

            return ['changed' => true, 'status' => self::STATUS_REVOKED];
        });
    }
}
