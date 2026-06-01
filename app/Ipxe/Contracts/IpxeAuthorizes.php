<?php

declare(strict_types=1);

namespace App\Ipxe\Contracts;

use App\Ipxe\Services\IpxeAuthOutcome;
use Illuminate\Http\Request;

/**
 * Story 4.10 (correctif review #12) — Contrat d'autorisation iPXE.
 *
 * **But** : permettre de stubber l'auth iPXE dans les tests sans étendre la
 * classe concrète {@see \App\Ipxe\Services\IpxeAuthService} (qui contient
 * le bind LDAP via `AuthenticationService` et les appels Spatie pour la
 * permission `computer.install`). Étendre la classe concrète exigeait soit
 * un constructeur custom qui contournait l'injection, soit la levée de
 * `final` — les deux fragilisent le contrat de prod.
 *
 * **Bénéfice** :
 *  - `IpxeAuthService` peut redevenir `final` (interdit l'extension
 *    non-contrôlée en prod — pattern « ouvert à l'extension, fermé à la
 *    modification » via le contrat, pas via l'héritage).
 *  - Le contrat d'autorisation iPXE est explicite : une seule méthode
 *    {@see authorize()} qui renvoie un {@see IpxeAuthOutcome}.
 *  - Les implémentations alternatives (stubs de test, mock partiel,
 *    décorateur futur — ex. cache, audit) deviennent triviales.
 *
 * **Pattern** : tous les consommateurs iPXE (handlers, orchestrateurs)
 * type-hintent désormais `IpxeAuthorizes` au lieu de `IpxeAuthService`.
 * Le binding DI dans {@see \App\Providers\IpxeServiceProvider::register()}
 * pointe le contrat vers `IpxeAuthService`. En test, on remplace l'instance
 * du contrat par une stub locale (cf. `tests/Support/IpxeAuthTestHelper`).
 */
interface IpxeAuthorizes
{
    /**
     * Autorise (ou refuse) un appel iPXE sensible.
     *
     * @param string $context Suffixe de log (ex. `admin`, `maintenance`,
     *                        `action`, `install_linux`, `enrollment.name`).
     *                        Utilisé pour bâtir l'event `ipxe.<context>.auth_*`.
     */
    public function authorize(Request $request, string $context): IpxeAuthOutcome;
}
