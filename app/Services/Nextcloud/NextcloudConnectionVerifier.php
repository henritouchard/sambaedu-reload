<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

use App\Exceptions\Nextcloud\NextcloudConfigurationException;
use App\Services\FilePolicyService;
use App\Services\ServiceCredentials;
use Illuminate\Support\Facades\Cache;

/**
 * Story 61.2 — LA VÉRIFICATION DE CONFIGURATION EST FAIL-CLOSED.
 *
 * ---------------------------------------------------------------------------
 * **RECADRAGE DU 2026-08-08 — IL N'Y A PLUS QU'UNE QUESTION.** Ce service
 * s'appelait `NextcloudModeGuard` et sondait le MODE VISÉ parmi deux positions
 * (instance administrée / compte porteur délégué). La mesure contre une instance
 * réelle a tranché : un compte ordinaire ne peut créer ni Team folder, ni groupe,
 * ni partage de groupe — donc pas de clôture, donc pas de cloisonnement. Le mode
 * délégué a été supprimé, et avec lui la notion de mode.
 *
 * Ce qui SURVIT, parce que c'est ça qui avait de la valeur :
 *  1. le **fail-closed** — une configuration que le compte ne peut pas honorer est
 *     refusée AVEC SON MOTIF, jamais acceptée puis dégradée en silence ; il ne se
 *     formule plus que d'une façon : « ce compte est-il administrateur de
 *     l'instance ? », et {@see NextcloudConnectionProbe} y répond déjà ;
 *  2. la sonde porte sur les valeurs que l'administrateur vient de SAISIR et qui ne
 *     sont **pas encore persistées** — sonder l'état persisté ne dirait rien de la
 *     cible qu'on s'apprête à enregistrer ;
 *  3. la **persistance du diagnostic**, et avec elle l'état « déclaré mais NON
 *     VÉRIFIÉ depuis le dernier changement de secret ».
 * ---------------------------------------------------------------------------
 *
 * **La sonde ne s'exécute QUE quand ce qui définit la connexion change** —
 * l'appelant en décide, et l'écran l'applique. Le point de sauvegarde de l'écran
 * est global à l'onglet : sonder à chaque enregistrement ferait d'une panne
 * d'instance un verrou sur des réglages qui ne la concernent pas (le répertoire
 * personnel, les partages, l'hôte SMB).
 *
 * **Aucune écriture d'épreuve, jamais** : la sonde est en lecture seule, et ce
 * qu'elle ne peut pas constater sans écrire est DIT dans son message vert.
 */
final class NextcloudConnectionVerifier
{
    /**
     * Dernier diagnostic de connexion, PERSISTÉ (correction de revue 61.2 #3).
     *
     * ---------------------------------------------------------------------------
     * **POURQUOI CE DIAGNOSTIC SURVIT À LA PAGE.** L'écran doit pouvoir dire
     * « cette configuration est déclarée mais NON VÉRIFIÉE depuis le dernier
     * changement de secret ». Une propriété Livewire ne le tiendrait pas : au
     * prochain montage elle repart vide, et l'écran redevient muet sur une
     * configuration qui n'est plus confirmée — c'est-à-dire qu'il laisse croire
     * qu'elle l'est. Un « non vérifié » qui s'efface tout seul est pire qu'absent.
     *
     * Le domicile est le MÊME mécanisme que le dernier rapport de provisionnement
     * ({@see NextcloudProvisioningService::REPORT_CACHE_KEY}) : un diagnostic
     * d'exploitation reconstructible à tout moment par « Tester la connexion »,
     * jamais une autorité. Le perdre ne coûte qu'un clic ; le voir mentir coûterait
     * une configuration réputée bonne qui ne l'est pas.
     *
     * La CLÉ est inchangée depuis 61.2 : le diagnostic déjà en cache sur une
     * instance en service reste lisible après le retrait des modes.
     * ---------------------------------------------------------------------------
     */
    public const DIAGNOSTIC_CACHE_KEY = 'nextcloud:mode:last-diagnostic';

    /** Trente jours : l'état de vérification ne se périme pas en une session. */
    public const DIAGNOSTIC_CACHE_DAYS = 30;

    public function __construct(private readonly ServiceCredentials $credentials)
    {
    }

    /**
     * Retient le diagnostic affiché — ou l'oublie (`null`) quand il ne vaut plus
     * rien (secret retiré, capacité éteinte).
     *
     * @param  array<string, mixed>|null  $diagnostic
     */
    public function rememberDiagnostic(?array $diagnostic): void
    {
        if ($diagnostic === null) {
            Cache::forget(self::DIAGNOSTIC_CACHE_KEY);

            return;
        }

        Cache::put(self::DIAGNOSTIC_CACHE_KEY, $diagnostic, now()->addDays(self::DIAGNOSTIC_CACHE_DAYS));
    }

    /** @return array<string, mixed>|null */
    public function lastDiagnostic(): ?array
    {
        $data = Cache::get(self::DIAGNOSTIC_CACHE_KEY);

        return is_array($data) ? $data : null;
    }

    /**
     * Sonde la configuration avec les valeurs fournies (celles de l'écran) ou, à
     * défaut, celles persistées. Le secret n'est **jamais** fourni par l'appelant :
     * il est lu du stock chiffré, ici, et nulle part ailleurs.
     *
     * @param  string|null  $baseUrl  URL saisie (défaut : persistée)
     * @param  bool|null  $verifyTls  Vérification TLS saisie (défaut : persistée)
     * @param  string|null  $adminUser  Identifiant admin saisi (défaut : persisté)
     */
    public function verify(
        ?string $baseUrl = null,
        ?bool $verifyTls = null,
        ?string $adminUser = null,
    ): NextcloudConnectionProbe {
        $policy = FilePolicyService::globalConfig();

        $baseUrl ??= (string) $policy['nextcloud_server_url'];
        $verifyTls ??= (bool) $policy['nextcloud_verify_tls'];
        $adminUser ??= (string) $policy['nextcloud_admin_user'];

        try {
            $config = NextcloudConnectionConfig::fromValues(
                $baseUrl,
                $adminUser,
                (string) ($this->credentials->password(NextcloudConnectionConfig::CREDENTIAL_NAME) ?? ''),
                '',
                $verifyTls,
            );
        } catch (NextcloudConfigurationException $e) {
            // Configuration incomplète : **aucun appel n'est émis**, et le refus
            // nomme ce qui manque plutôt que de faire croire à une panne.
            return NextcloudConnectionProbe::unreachable($e->getMessage());
        }

        return (new NextcloudAdminClient($config))->probe();
    }
}
