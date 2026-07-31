<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\Oidc\Services\OidcClientRegistry;
use App\Auth\Oidc\Support\OidcClaimsResolver;
use App\OidcWitness\Support\WitnessCredentials;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Story 55.3 — `php artisan oidc:witness:enable [--rotate]`
 *
 * Provisionne l'app-témoin SSO : déclare son client confidentiel au registre
 * OIDC et pose son fichier de credentials. **Doctrine ops du projet** : toute
 * opération multi-instance est une commande artisan IDEMPOTENTE, jamais une
 * procédure manuelle à rejouer (patron `oidc:keys:init` / `auth:ca:init`).
 *
 * Ce que cette commande préfigure : en Epic 56, c'est l'INSTALLATION d'une
 * extension `app` qui fera ces deux gestes — déclarer le client, poser sa
 * configuration chez elle. Le témoin le fait à la main, une fois, pour que le
 * contrat soit démontré avant d'être industrialisé.
 *
 * ⚠️ **NFR3 — le secret n'est JAMAIS affiché ni journalisé.** Contrairement à
 * `oidc:client:register`, dont l'appelant humain doit recopier le secret dans
 * une configuration tierce, ici le destinataire du secret est un FICHIER que la
 * commande écrit elle-même (0600). L'afficher n'aurait aucune utilité et
 * l'exposerait à l'historique du terminal et aux journaux d'exploitation.
 *
 * Codes retour : 0 succès ou no-op, 1 échec explicite.
 */
class OidcWitnessEnable extends Command
{
    /** Nom affiché du client au registre. */
    public const CLIENT_NAME = 'App-témoin SSO';

    /** Clé de l'extension du registre à laquelle le client est adossé. */
    public const EXTENSION_KEY = 'sso-demo';

    /** `redirect_uri` STRICTE — chemin absolu de l'instance, égalité exacte. */
    public const REDIRECT_URI = '/sso-demo/callback';

    /**
     * Story 56.4 — Les scopes ACCORDÉS au témoin, DÉRIVÉS de ce qu'il demande.
     *
     * `config('oidc.witness.scope')` moins `openid` (plancher du protocole,
     * jamais accordé explicitement) : le témoin obtient exactement ce que sa
     * page affiche — nom, rôle, groupes. Dériver plutôt que recopier est ce qui
     * empêche les deux réglages de diverger le jour où l'un change.
     *
     * ⚠️ Sans cet octroi, `oidc:witness:enable` provisionnerait un client à
     * `granted_scopes = []` : le témoin se connecterait toujours, mais
     * n'afficherait plus que son `sub` — un downscope silencieux qui ferait
     * croire à une régression du contrat de claims.
     *
     * @return list<string>
     */
    public static function grantedScopes(): array
    {
        $requested = OidcClaimsResolver::parseScope((string) config('oidc.witness.scope', ''));

        return array_values(array_filter(
            $requested,
            static fn (string $scope): bool => $scope !== 'openid',
        ));
    }

    /** @var string */
    protected $signature = 'oidc:witness:enable
        {--rotate : Révoque le client existant et en régénère un (secret renouvelé)}';

    /** @var string */
    protected $description = 'Provisionne l\'app-témoin SSO (client OIDC + fichier de credentials 0600).';

    public function handle(OidcClientRegistry $registry): int
    {
        $existing = WitnessCredentials::load();
        $rotate = (bool) $this->option('rotate');

        // ── No-op signalé ────────────────────────────────────────────────
        if ($existing !== null && ! $rotate) {
            if ($registry->findEnabledByClientId($existing->clientId) !== null) {
                $this->info('App-témoin déjà provisionnée — aucune action.');
                $this->line('  client_id : ' . $existing->clientId);
                $this->line('  fichier   : ' . WitnessCredentials::path());
                $this->line('  Pour renouveler le secret : --rotate');

                return 0;
            }

            // Fichier présent mais client révoqué/absent : l'état est
            // INCOHÉRENT. On échoue bruyamment plutôt que de laisser une page
            // témoin qui échouera à chaque tentative sans que personne ne sache
            // pourquoi.
            $this->error(
                'Fichier de credentials présent, mais le client « ' . $existing->clientId
                . ' » est révoqué ou inconnu du registre.'
            );
            $this->line('  Rejouer avec --rotate pour réenregistrer un client, '
                . 'ou `php artisan oidc:witness:disable` pour repartir de zéro.');

            return 1;
        }

        // ── Rotation : on révoque AVANT de réenregistrer ─────────────────
        if ($rotate && $existing !== null) {
            $revoked = $registry->revoke($existing->clientId);

            $this->line($revoked['changed']
                ? 'Ancien client révoqué : ' . $existing->clientId
                : 'Ancien client déjà révoqué ou inconnu : ' . $existing->clientId);
        }

        // ── Enregistrement ───────────────────────────────────────────────
        try {
            $result = $registry->register(
                self::CLIENT_NAME,
                [self::REDIRECT_URI],
                self::EXTENSION_KEY,
                self::grantedScopes(),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->line('  L\'extension « ' . self::EXTENSION_KEY . ' » doit être au registre : '
                . '`php artisan db:seed --class=BundledExtensionSeeder --force`.');

            return 1;
        } catch (Throwable $e) {
            $this->error('oidc:witness:enable a échoué : ' . $e->getMessage());

            return 1;
        }

        $credentials = new WitnessCredentials(
            clientId: $result['client_id'],
            clientSecret: $result['client_secret'],
            issuer: rtrim((string) config('oidc.issuer', ''), '/'),
            redirectUri: self::REDIRECT_URI,
        );

        try {
            $credentials->write();
        } catch (Throwable $e) {
            // Le client est déclaré mais son secret n'a pas pu être posé : il
            // est irrécupérable (NFR3). On révoque pour ne pas laisser un
            // client fantôme au registre.
            $registry->revoke($result['client_id']);
            $this->error('Écriture du fichier de credentials impossible — client révoqué : ' . $e->getMessage());

            return 1;
        }

        // ⚠️ Le secret n'apparaît NI ici, NI au journal.
        Log::channel('oidc')->info('[OidcWitnessEnable] oidc.witness.provisioned', [
            'action_type' => 'oidc.witness.provisioned',
            'client_id' => $result['client_id'],
            'extension_key' => self::EXTENSION_KEY,
            'rotated' => $rotate,
        ]);

        $this->line('');
        $this->info('============================================================');
        $this->info($rotate ? '  App-témoin SSO — secret renouvelé' : '  App-témoin SSO provisionnée');
        $this->info('============================================================');
        $this->line('client_id    : ' . $result['client_id']);
        $this->line('redirect_uri : ' . self::REDIRECT_URI);
        $this->line('scopes       : ' . (self::grantedScopes() === []
            ? '(aucun — le témoin ne recevra que son identifiant)'
            : implode(' ', self::grantedScopes())));
        $this->line('issuer       : ' . $credentials->issuer);
        $this->line('credentials  : ' . WitnessCredentials::path() . ' (0600, ' . $this->ownerOf(WitnessCredentials::path()) . ')');
        $this->line('');
        $this->warn('Le client_secret n\'est PAS affiché : il n\'existe que dans ce fichier (NFR3).');
        $this->line('');
        $this->info('Reste à faire pour que la tuile apparaisse :');
        $this->line('  1. php artisan db:seed --class=BundledExtensionSeeder --force');
        $this->line('  2. Intégrer « Démo SSO » depuis /admin/extensions');
        $this->line('');
        $this->info('Désactivation : php artisan oidc:witness:disable');
        $this->line('');

        $this->warnIfNotWebOwner(WitnessCredentials::path());

        return 0;
    }

    /**
     * Nom du propriétaire d'un fichier, `?` si indéterminable (posix absent,
     * uid sans entrée passwd). Affichage seulement — jamais une décision.
     */
    private function ownerOf(string $path): string
    {
        if (! function_exists('posix_getpwuid')) {
            return '?';
        }

        $uid = @fileowner($path);

        if ($uid === false) {
            return '?';
        }

        $info = @posix_getpwuid($uid);

        return is_array($info) && isset($info['name']) ? (string) $info['name'] : (string) $uid;
    }

    /**
     * Dernier filet : {@see WitnessCredentials::write()} aligne déjà la
     * propriété sur `oidc.web_owner`, mais un `chown` peut échouer (systèmes de
     * fichiers exotiques, conteneur sans CAP_CHOWN). Le seul scénario où cela
     * compte est ASYMÉTRIQUE et silencieux : le fichier reste illisible par
     * PHP-FPM, la commande a annoncé un succès, et `/sso-demo` répond 503
     * `witness.credentials_unreadable` sans que rien ne relie les deux.
     *
     * On le dit ici, à l'endroit où l'exploitant regarde, en plus du journal.
     */
    private function warnIfNotWebOwner(string $path): void
    {
        $webOwner = (string) config('oidc.web_owner', '');

        if ($webOwner === '' || ! function_exists('posix_getpwnam')) {
            return;
        }

        $expected = @posix_getpwnam($webOwner);
        $actual = @fileowner($path);

        if ($expected === false || $actual === false || $actual === $expected['uid']) {
            return;
        }

        $this->error('⚠️  ' . $path . ' n\'appartient PAS à ' . $webOwner . ' (propriétaire : ' . $this->ownerOf($path) . ').');
        $this->line('  PHP-FPM ne pourra pas le lire (0600) et /sso-demo répondra 503 witness.credentials_unreadable.');
        $this->line('  Corriger : chown ' . $webOwner . ' ' . $path);
    }
}
