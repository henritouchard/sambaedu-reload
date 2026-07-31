<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests\Support;

use SambaEdu\ExtBbb\Env;
use SambaEdu\ExtBbb\Http\ArraySessionStore;
use SambaEdu\ExtBbb\Http\Request;
use SambaEdu\ExtBbb\Http\Response;
use SambaEdu\ExtBbb\Identity;
use SambaEdu\ExtBbb\Rooms\RoomsController;
use SambaEdu\ExtBbb\Store;
use SambaEdu\ExtBbb\Url;
use SambaEdu\ExtBbb\View;

/**
 * Story 57.2 — Le montage commun des tests de salons : une base réelle dans un
 * répertoire jetable, un client BBB enregistreur, et de quoi injecter une
 * requête pour affirmer une réponse.
 *
 * Aucun serveur HTTP, aucun réseau — c'est ce qui rend la matrice
 * d'autorisation exerçable en entier, cas par cas, sur l'hôte de développement.
 */
final class RoomsWorkbench
{
    public readonly string $directory;

    public readonly Store $store;

    public readonly RecordingBbbApiClient $api;

    public readonly Env $env;

    public function __construct()
    {
        Url::configure('/ext/bbb');

        $this->directory = sys_get_temp_dir() . '/ext-bbb-rooms-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);

        $this->store = new Store($this->directory . '/database.sqlite');
        $this->api = new RecordingBbbApiClient();
        $this->env = Env::capture([
            'SE5_EXT_BASE_PATH' => '/ext/bbb',
            'SE5_OIDC_ISSUER' => 'https://se5.example.test',
            'STATE_DIRECTORY' => $this->directory,
        ]);
    }

    public function controller(): RoomsController
    {
        return new RoomsController($this->store, $this->api, new View(dirname(__DIR__, 2) . '/views'), $this->env);
    }

    /** @param  list<string>  $groups */
    public function sessionFor(string $role, string $sub, string $name = '', array $groups = []): ArraySessionStore
    {
        $session = new ArraySessionStore();

        (new Identity($sub, $name !== '' ? $name : $sub, $role, $groups))->storeIn($session);

        return $session;
    }

    public function get(ArraySessionStore $session): Response
    {
        return $this->controller()->handle(new Request('GET', '/rooms'), $session);
    }

    /** @param  array<string, string|list<string>>  $post */
    public function post(ArraySessionStore $session, string $path, array $post): Response
    {
        $post['_token'] ??= $this->csrf($session);

        return $this->controller()->handle(new Request('POST', $path, [], $post), $session);
    }

    /** Le jeton anti-CSRF courant — il naît au premier rendu de la page. */
    public function csrf(ArraySessionStore $session): string
    {
        $this->get($session);

        return (string) $session->get('rooms.csrf');
    }

    public function destroy(): void
    {
        Url::reset();

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }
}
