<?php

declare(strict_types=1);

namespace App\Services\Nextcloud;

use App\Enums\NextcloudInstanceMode;
use App\Exceptions\Nextcloud\NextcloudConfigurationException;
use App\Services\FilePolicyService;
use App\Services\ServiceCredentials;
use Illuminate\Support\Facades\Cache;

/**
 * Story 61.2 — LA SÉLECTION DE MODE EST FAIL-CLOSED.
 *
 * ---------------------------------------------------------------------------
 * **LA CONTRAINTE CŒUR DE LA STORY** : « une position que le compte configuré ne
 * peut pas honorer doit être refusée à la sélection avec le motif, jamais acceptée
 * puis silencieusement dégradée ». Ce service est l'endroit où ça se joue : il
 * SONDE le mode VISÉ, avec les valeurs que l'administrateur vient de saisir et qui
 * ne sont **pas encore persistées**, et rend le verdict qui autorise — ou non — la
 * persistance.
 *
 * Sonder l'état persisté ne servirait à rien : au moment du choix, le mode visé
 * n'est justement pas celui qui est enregistré.
 * ---------------------------------------------------------------------------
 *
 * **La sonde-garde ne s'exécute QUE quand le mode ou l'identifiant du mode
 * changent** — l'appelant en décide, et l'écran l'applique. Le point de sauvegarde
 * de l'écran est global à l'onglet : sonder à chaque enregistrement ferait d'une
 * panne d'instance un verrou sur des réglages qui ne la concernent pas (le
 * répertoire personnel, les partages, l'hôte SMB). Le fail-closed porte sur la
 * DÉCLARATION DE MODE, pas sur l'édition de réglages orthogonaux.
 *
 * **Les deux sondes sont celles des deux modes**, pas une sonde générique : le mode
 * administré est vérifié par la sonde de 61.1 (l'instance répond, le compte est
 * admin, l'app de stockage externe est là) ; le mode délégué par celle de l'AC3
 * (l'instance répond, le porteur s'authentifie, le partage est activé). Les
 * confondre reviendrait à valider un mode avec les critères de l'autre.
 *
 * **Aucune écriture d'épreuve, jamais** : les deux sondes sont en lecture seule, et
 * ce qu'elles ne peuvent pas constater sans écrire est DIT dans leur message vert.
 */
final class NextcloudModeGuard
{
    /**
     * Dernier diagnostic de connexion, PERSISTÉ (correction de revue 61.2 #3).
     *
     * ---------------------------------------------------------------------------
     * **POURQUOI CE DIAGNOSTIC SURVIT À LA PAGE.** L'écran doit pouvoir dire
     * « ce mode est déclaré mais NON VÉRIFIÉ depuis le dernier changement de
     * secret ». Une propriété Livewire ne le tiendrait pas : au prochain montage
     * elle repart vide, et l'écran redevient muet sur une position qui n'est plus
     * confirmée — c'est-à-dire qu'il laisse croire qu'elle l'est. Un « non vérifié »
     * qui s'efface tout seul est pire qu'absent.
     *
     * Le domicile est le MÊME mécanisme que le dernier rapport de provisionnement
     * ({@see NextcloudProvisioningService::REPORT_CACHE_KEY}) : un diagnostic
     * d'exploitation reconstructible à tout moment par « Tester la connexion »,
     * jamais une autorité. Le perdre ne coûte qu'un clic ; le voir mentir coûterait
     * une configuration réputée bonne qui ne l'est pas.
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

    /** Le mode actuellement déclaré. */
    public function currentMode(): NextcloudInstanceMode
    {
        return FilePolicyService::nextcloudMode();
    }

    /**
     * Sonde le mode VISÉ avec les valeurs fournies (celles de l'écran) ou, à
     * défaut, celles persistées. Les secrets ne sont **jamais** fournis par
     * l'appelant : ils sont lus du stock chiffré, ici, et nulle part ailleurs.
     *
     * @param  string|null  $baseUrl  URL saisie (défaut : persistée)
     * @param  bool|null  $verifyTls  Vérification TLS saisie (défaut : persistée)
     * @param  string|null  $adminUser  Identifiant admin saisi (défaut : persisté)
     * @param  string|null  $delegateUser  Identifiant porteur saisi (défaut : persisté)
     */
    public function verify(
        NextcloudInstanceMode $target,
        ?string $baseUrl = null,
        ?bool $verifyTls = null,
        ?string $adminUser = null,
        ?string $delegateUser = null,
    ): NextcloudConnectionProbe|NextcloudDelegateProbe {
        $policy = FilePolicyService::globalConfig();

        $baseUrl ??= (string) $policy['nextcloud_server_url'];
        $verifyTls ??= (bool) $policy['nextcloud_verify_tls'];

        return $target === NextcloudInstanceMode::Delegue
            ? $this->verifyDelegate($baseUrl, $verifyTls, $delegateUser ?? (string) $policy['nextcloud_delegue_user'])
            : $this->verifyAdmin($baseUrl, $verifyTls, $adminUser ?? (string) $policy['nextcloud_admin_user']);
    }

    // =========================================================================
    // Interne
    // =========================================================================

    private function verifyAdmin(string $baseUrl, bool $verifyTls, string $adminUser): NextcloudConnectionProbe
    {
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

    private function verifyDelegate(string $baseUrl, bool $verifyTls, string $delegateUser): NextcloudDelegateProbe
    {
        try {
            $config = NextcloudDelegateConfig::fromValues(
                $baseUrl,
                $delegateUser,
                (string) ($this->credentials->password(NextcloudDelegateConfig::CREDENTIAL_NAME) ?? ''),
                $verifyTls,
            );
        } catch (NextcloudConfigurationException $e) {
            return NextcloudDelegateProbe::misconfigured($e->getMessage());
        }

        return (new NextcloudDelegateClient($config))->probe();
    }
}
