<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Extensions;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use App\Models\OidcClient;
use Throwable;

/**
 * Story 56.5 — **Legs de la review 56.4 #4** : détecter les CLIENTS OIDC
 * FANTÔMES d'une extension.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  L'ANGLE MORT, EN UNE PHRASE
 *
 *  {@see \App\Services\Extensions\ExtensionScopeService::grantedScopesFor()}
 *  affiche les scopes du client `enabled` le PLUS RÉCENT d'une clé, alors que
 *  `revokeScope()` agit sur TOUS les clients `enabled` de cette clé.
 *  L'asymétrie est volontaire et bien orientée (on révoque plus large qu'on
 *  n'affiche, jamais l'inverse) — mais elle laisse un angle mort : un second
 *  client `enabled`, hérité d'une installation mal nettoyée, peut porter des
 *  scopes que le client affiché n'a PAS. L'admin ne les voit pas, donc il ne
 *  pense pas à les révoquer, et ils continuent d'être servis.
 *
 *  La review a jugé le cas non actionnable pour 56.4 (« la détection de clients
 *  fantômes appartient au périmètre santé/diagnostic, pas à l'UI des scopes ») et
 *  l'a légué ici. Le geste retenu est donc un DIAGNOSTIC, pas un nettoyage
 *  automatique : SE5 ne révoque jamais un client tout seul — `ext:remove` est le
 *  nettoyeur désigné, et il est déclenché par l'admin.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Verdicts :
 *  - `ok` : au plus un client `enabled` par clé d'extension.
 *  - `error` : plusieurs clients `enabled` pour une clé, ET l'un des fantômes
 *    porte un scope ABSENT du client affiché. C'est exactement le scénario de la
 *    review : de la donnée servie qu'aucun écran ne montre. Le détail nomme la
 *    clé et les scopes invisibles — jamais un `client_id`, jamais un secret
 *    (NFR3 : le journal du doctor est lisible par tout admin).
 *  - `warn` : plusieurs clients `enabled` pour une clé, mais aucun scope
 *    invisible. L'état reste anormal (il est le symptôme d'une installation
 *    interrompue) sans conséquence de confidentialité.
 *
 * ⚠️ Un client `enabled` dont la clé ne correspond à AUCUNE `app` installée
 * n'est PAS une anomalie : l'app-témoin `sso-demo` (55.3) est une extension
 * `link` et possède un client légitime. Signaler ce cas produirait un faux
 * positif permanent sur toute instance où le témoin est activé — et un check qui
 * crie au loup ne se lit plus.
 *
 * Table absente / DB down ⇒ `warn` explicite, jamais une exception (patron
 * `ControlHubReachableCheck` : ne pas doubler le check database).
 */
final class ExtensionsOidcClientsCheck implements EnvironmentCheck
{
    public function tag(): string
    {
        return 'extensions';
    }

    public function name(): string
    {
        return 'Extensions (clients OIDC)';
    }

    public function run(): CheckResult
    {
        try {
            /** @var array<string, list<OidcClient>> $byKey */
            $byKey = OidcClient::query()
                ->where('enabled', true)
                ->where('extension_key', '<>', '')
                ->orderByDesc('id')
                ->get()
                ->groupBy(fn (OidcClient $client): string => (string) $client->extension_key)
                ->map(fn ($clients): array => $clients->all())
                ->all();
        } catch (Throwable $e) {
            return CheckResult::warn(
                sprintf('registre des clients OIDC illisible : %s', substr($e->getMessage(), 0, 120)),
                'Vérifier les migrations (php artisan migrate) et la connexion DB.',
            );
        }

        $invisible = [];
        $duplicated = [];

        foreach ($byKey as $key => $clients) {
            if (count($clients) < 2) {
                continue;
            }

            $duplicated[] = sprintf('%s (%d clients actifs)', $key, count($clients));

            // Le client AFFICHÉ par la fiche est le plus récent (`orderByDesc('id')`).
            $displayed = $clients[0]->grantedScopes();

            $hidden = [];
            foreach (array_slice($clients, 1) as $ghost) {
                $hidden = array_merge($hidden, array_diff($ghost->grantedScopes(), $displayed));
            }

            $hidden = array_values(array_unique($hidden));

            if ($hidden !== []) {
                $invisible[] = sprintf('%s → %s', $key, implode(', ', $hidden));
            }
        }

        if ($invisible !== []) {
            return CheckResult::error(
                sprintf(
                    'des scopes sont servis SANS être visibles sur la fiche (client OIDC fantôme) : %s.',
                    implode(' ; ', $invisible),
                ),
                'Réinstaller l\'extension concernée (ext:remove puis ext:install) : le retrait révoque TOUS ses clients. '
                    .'La révocation par scope depuis la fiche agit déjà sur tous les clients actifs, mais elle ne peut pas '
                    .'porter sur un scope qui ne s\'affiche pas.',
            );
        }

        if ($duplicated !== []) {
            return CheckResult::warn(
                sprintf(
                    'plusieurs clients OIDC actifs pour une même extension : %s. Aucun scope invisible pour l\'instant.',
                    implode(' ; ', $duplicated),
                ),
                'Trace probable d\'une installation interrompue. Réinstaller l\'extension (ext:remove puis ext:install) remet le registre au propre.',
            );
        }

        return CheckResult::ok('un seul client OIDC actif par extension (aucun fantôme).');
    }
}
