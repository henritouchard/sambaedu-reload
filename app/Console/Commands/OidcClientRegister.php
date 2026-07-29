<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\Oidc\Services\OidcClientRegistry;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Story 55.1 — Task 6.
 *
 * `php artisan oidc:client:register "Nom" --redirect-uri=… [--extension=clé]`
 *
 * Déclare un client confidentiel au registre OIDC. En 55.1 c'est le SEUL canal
 * d'enregistrement : le provisioning automatique à l'installation d'une
 * extension de type `app` arrive avec l'Epic 56, et s'accrochera au même
 * {@see OidcClientRegistry::register()}.
 *
 * ⚠️ **Le secret n'est affiché qu'UNE FOIS** (NFR3). Il n'est stocké nulle part
 * en clair et n'est pas ré-affichable : un secret perdu se remplace (révoquer +
 * réenregistrer), il ne se retrouve pas.
 *
 * Codes retour : 0 succès, 1 erreur de saisie ou d'exécution.
 */
class OidcClientRegister extends Command
{
    /** @var string */
    protected $signature = 'oidc:client:register
        {name : Libellé du client (affiché à l\'exploitation)}
        {--redirect-uri=* : URI de redirection déclarée (répétable, correspondance EXACTE à l\'usage)}
        {--extension= : Clé d\'une extension du registre à laquelle adosser ce client}';

    /** @var string */
    protected $description = 'Déclare un client confidentiel au registre OIDC (SSO des extensions).';

    public function handle(OidcClientRegistry $registry): int
    {
        $name = (string) $this->argument('name');

        /** @var list<string> $redirectUris */
        $redirectUris = array_values(array_map('strval', (array) $this->option('redirect-uri')));

        $extensionKey = $this->option('extension');
        $extensionKey = is_string($extensionKey) && $extensionKey !== '' ? $extensionKey : null;

        try {
            $result = $registry->register($name, $redirectUris, $extensionKey);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        } catch (Throwable $e) {
            $this->error('oidc:client:register a échoué : ' . $e->getMessage());

            return 1;
        }

        $client = $result['client'];

        $this->line('');
        $this->info('============================================================');
        $this->info('  Client OIDC enregistré');
        $this->info('============================================================');
        $this->line('Nom          : ' . $client->name);
        $this->line('Extension    : ' . ($client->extension_key !== '' ? $client->extension_key : '(aucune)'));
        $this->line('client_id    : ' . $result['client_id']);
        $this->line('');
        $this->warn('client_secret (AFFICHÉ UNE SEULE FOIS — non récupérable) :');
        $this->line('  ' . $result['client_secret']);
        $this->line('');
        $this->info('URI de redirection déclarées (correspondance EXACTE, sans joker) :');
        foreach ($client->redirectUris() as $uri) {
            $this->line('  • ' . $uri);
        }

        $this->line('');
        $this->info('Configuration côté extension :');
        $this->line('  issuer   : ' . rtrim((string) config('oidc.issuer', ''), '/'));
        $this->line('  discovery: ' . rtrim((string) config('oidc.issuer', ''), '/') . '/.well-known/openid-configuration');
        $this->line('  PKCE     : OBLIGATOIRE, méthode S256 uniquement');
        $this->line('');
        $this->info('Révocation : php artisan oidc:client:revoke ' . $result['client_id']);
        $this->line('');

        return 0;
    }
}
