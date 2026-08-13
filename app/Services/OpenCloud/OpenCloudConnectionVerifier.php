<?php

declare(strict_types=1);

namespace App\Services\OpenCloud;

use App\Exceptions\OpenCloud\OpenCloudConfigurationException;
use App\Services\FilePolicyService;
use App\Services\ServiceCredentials;
use Illuminate\Support\Facades\Cache;

/**
 * LA VÉRIFICATION DE CONFIGURATION EST FAIL-CLOSED, ET SON DIAGNOSTIC SURVIT.
 *
 * Trois propriétés, reprises du patron éprouvé sur l'autre produit :
 *
 *  1. le **fail-closed** — une configuration que le compte ne peut pas honorer est
 *     refusée AVEC SON MOTIF, jamais acceptée puis dégradée en silence ;
 *  2. la sonde porte sur les valeurs que l'administrateur vient de SAISIR et qui
 *     ne sont **pas encore persistées** — sonder l'état persisté ne dirait rien de
 *     la cible qu'on s'apprête à enregistrer ;
 *  3. la **persistance du diagnostic**, et avec elle l'état « déclaré mais NON
 *     VÉRIFIÉ depuis le dernier changement de secret ». Une propriété d'écran ne
 *     le tiendrait pas : au prochain montage elle repart vide, et l'écran
 *     redevient muet sur une configuration qui n'est plus confirmée —
 *     c'est-à-dire qu'il laisse croire qu'elle l'est.
 *
 * **La sonde ne s'exécute QUE quand ce qui définit la connexion change.** Le point
 * de sauvegarde de l'écran est global à l'onglet : sonder à chaque enregistrement
 * ferait d'une panne d'instance un verrou sur des réglages qui ne la concernent
 * pas.
 *
 * **Aucune écriture d'épreuve, jamais** : la sonde est en lecture seule, et ce
 * qu'elle ne peut pas constater sans écrire est DIT dans son message vert.
 */
final class OpenCloudConnectionVerifier
{
    /**
     * Dernier diagnostic de connexion, PERSISTÉ.
     *
     * Domicile : le même mécanisme d'exploitation que le diagnostic de l'autre
     * produit — reconstructible à tout moment par « Tester la connexion », jamais
     * une autorité. Le perdre ne coûte qu'un clic ; le voir mentir coûterait une
     * configuration réputée bonne qui ne l'est pas.
     */
    public const DIAGNOSTIC_CACHE_KEY = 'opencloud:connection:last-diagnostic';

    /** Trente jours : l'état de vérification ne se périme pas en une session. */
    public const DIAGNOSTIC_CACHE_DAYS = 30;

    public function __construct(private readonly ServiceCredentials $credentials) {}

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
     */
    public function verify(
        ?string $baseUrl = null,
        ?bool $verifyTls = null,
        ?string $adminUser = null,
    ): OpenCloudConnectionProbe {
        $policy = FilePolicyService::globalConfig();

        $baseUrl ??= (string) $policy['opencloud_server_url'];
        $verifyTls ??= (bool) $policy['opencloud_verify_tls'];
        $adminUser ??= (string) $policy['opencloud_admin_user'];

        try {
            $config = OpenCloudConnectionConfig::fromValues(
                $baseUrl,
                $adminUser,
                (string) ($this->credentials->password(OpenCloudConnectionConfig::CREDENTIAL_NAME) ?? ''),
                $verifyTls,
            );
        } catch (OpenCloudConfigurationException $e) {
            // Configuration incomplète : **aucun appel n'est émis**, et le refus
            // nomme ce qui manque plutôt que de faire croire à une panne.
            return OpenCloudConnectionProbe::unreachable($e->getMessage());
        }

        return (new OpenCloudAdminClient(new OpenCloudGraphTransport($config)))->probe();
    }
}
