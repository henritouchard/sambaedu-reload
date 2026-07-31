<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Admin;

use SambaEdu\ExtBbb\Bbb\BbbApiClient;
use SambaEdu\ExtBbb\Env;
use SambaEdu\ExtBbb\Http\Request;
use SambaEdu\ExtBbb\Http\Response;
use SambaEdu\ExtBbb\Http\SessionStore;
use SambaEdu\ExtBbb\Identity;
use SambaEdu\ExtBbb\Store;
use SambaEdu\ExtBbb\View;

/**
 * Story 57.1 — **LA CONFIGURATION DES SERVEURS BBB (AC2).**
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CE QUE CETTE PAGE CORRIGE DU LEGACY
 *
 *  1. **Un serveur = une LIGNE.** SE4 tenait trois listes CSV parallèles,
 *     indexées par position, dans un fichier de configuration. Supprimer un
 *     serveur intermédiaire décalait les index : le serveur suivant héritait du
 *     secret d'un autre. Le bug n'existe plus par construction.
 *  2. **Vraie validation.** SE4 se contentait de `strlen >= 10` sur l'URL et sur
 *     le secret. Ici : analyse de l'URL, schéma exigé, et surtout un test de
 *     connexion CHECKSUMMÉ qui prouve le couple (URL, secret).
 *  3. **TLS vérifié** (voir le client BBB) et **secrets hors du système de
 *     fichiers partagé** : ils vivent dans la base de l'extension, 0600, dans un
 *     répertoire d'état 0700 possédé par l'utilisateur dynamique du service.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Le secret est un champ WRITE-ONLY.** Il n'est jamais réaffiché : ni dans un
 * `value`, ni dans une URL, ni dans un journal. En liste il est masqué, avec ses
 * quatre derniers caractères pour que l'administrateur reconnaisse lequel il a
 * saisi. À l'édition, un champ laissé vide CONSERVE le secret existant — sans
 * quoi éditer une URL l'effacerait.
 *
 * **Réservée au rôle `admin`, strictement.** Toute autre valeur — `prof`,
 * `eleve`, `administratif`, ou un rôle inconnu — reçoit un 403. Le claim `role`
 * est une DONNÉE : c'est ici, à chaque requête, que la décision d'accès se
 * prend.
 */
final class ServersController
{
    private const FLASH = 'admin.flash';

    private const CSRF = 'admin.csrf';

    public function __construct(
        private readonly Store $store,
        private readonly BbbApiClient $api,
        private readonly View $view,
        private readonly Env $env,
    ) {
    }

    public function handle(Request $request, SessionStore $session): Response
    {
        $identity = Identity::fromSessionStore($session);

        if ($identity === null) {
            return Response::redirect('/login');
        }

        if (! $identity->isAdmin()) {
            return Response::html(
                $this->view->page('error', [
                    'code' => 'bbb.admin.forbidden',
                    'message' => 'Cette page est réservée aux administrateurs.',
                    'canRetry' => false,
                ], 'Accès refusé', $this->env, $identity),
                403,
            );
        }

        if (strtoupper($request->method) === 'POST') {
            return $this->submit($request, $session, $identity);
        }

        $editId = (int) $request->query('edit');
        $editing = $editId > 0 ? $this->store->server($editId) : null;

        return $this->render($identity, $session, $this->takeFlash($session), $editing, []);
    }

    // =====================================================================

    private function submit(Request $request, SessionStore $session, Identity $identity): Response
    {
        if (! $this->csrfMatches($request, $session)) {
            // Un POST sans jeton valide n'est pas une saisie de l'administrateur :
            // c'est une requête fabriquée ailleurs. On ne « corrige » pas, on
            // refuse.
            return Response::html(
                $this->view->page('error', [
                    'code' => 'bbb.admin.csrf',
                    'message' => 'Formulaire expiré ou invalide.',
                    'canRetry' => false,
                ], 'Formulaire refusé', $this->env, $identity),
                403,
            );
        }

        $action = $request->input('action');
        $id = (int) $request->input('id');

        return match ($action) {
            'create' => $this->save($request, $session, $identity, null),
            'update' => $this->save($request, $session, $identity, $id),
            'delete' => $this->delete($session, $id),
            'toggle' => $this->toggle($session, $id),
            'test' => $this->test($session, $id),
            default => Response::redirect('/admin/servers'),
        };
    }

    private function save(Request $request, SessionStore $session, Identity $identity, ?int $id): Response
    {
        $existing = $id !== null ? $this->store->server($id) : null;

        if ($id !== null && $existing === null) {
            $this->flash($session, 'error', 'Ce serveur n\'existe plus.');

            return Response::redirect('/admin/servers');
        }

        $input = [
            'base_url' => trim($request->input('base_url')),
            'scalelite' => $request->input('scalelite') !== '',
            'scalelite_threshold' => trim($request->input('scalelite_threshold')),
            'enabled' => $request->input('enabled') !== '' || $id === null,
        ];

        $secret = $request->input('secret');
        $errors = [];
        $warnings = [];

        $urlCheck = self::validateBaseUrl($input['base_url']);
        if ($urlCheck['error'] !== '') {
            $errors['base_url'] = $urlCheck['error'];
        } elseif ($urlCheck['warning'] !== '') {
            $warnings[] = $urlCheck['warning'];
        }

        if ($secret === '' && $existing === null) {
            $errors['secret'] = 'Le secret partagé est obligatoire.';
        }

        $threshold = 0;
        if ($input['scalelite']) {
            if (! preg_match('/^\d+$/', $input['scalelite_threshold']) || (int) $input['scalelite_threshold'] < 1) {
                $errors['scalelite_threshold'] = 'Le seuil Scalelite doit être un entier supérieur ou égal à 1.';
            } else {
                $threshold = (int) $input['scalelite_threshold'];
            }
        }

        if ($errors !== []) {
            // Rendu DIRECT plutôt que redirection : la saisie est conservée
            // (sauf le secret, qui ne ressort jamais du serveur).
            return $this->render(
                $identity,
                $session,
                [],
                $existing,
                $errors,
                $input,
            );
        }

        $normalizedUrl = $urlCheck['url'];

        if ($existing === null) {
            $this->store->addServer($normalizedUrl, $secret, $threshold, true);
            $this->flash($session, 'success', 'Serveur ajouté.');
        } else {
            $this->store->updateServer(
                (int) $existing['id'],
                $normalizedUrl,
                $secret === '' ? null : $secret,
                $threshold,
                $input['enabled'],
            );
            $this->flash($session, 'success', 'Serveur mis à jour.');
        }

        foreach ($warnings as $warning) {
            $this->flash($session, 'warning', $warning);
        }

        return Response::redirect('/admin/servers');
    }

    private function delete(SessionStore $session, int $id): Response
    {
        if ($this->store->server($id) !== null) {
            $this->store->deleteServer($id);
            $this->flash($session, 'success', 'Serveur supprimé.');
        }

        return Response::redirect('/admin/servers');
    }

    private function toggle(SessionStore $session, int $id): Response
    {
        $server = $this->store->server($id);

        if ($server !== null) {
            $this->store->setServerEnabled($id, ! $server['enabled']);
            $this->flash($session, 'success', $server['enabled'] ? 'Serveur désactivé.' : 'Serveur activé.');
        }

        return Response::redirect('/admin/servers');
    }

    /**
     * Le SEUL appel BBB de toute la story, et il est déclenché par un clic —
     * jamais au rendu d'une page.
     */
    private function test(SessionStore $session, int $id): Response
    {
        $server = $this->store->server($id);

        if ($server === null) {
            $this->flash($session, 'error', 'Ce serveur n\'existe plus.');

            return Response::redirect('/admin/servers');
        }

        $result = $this->api->testConnection((string) $server['base_url'], (string) $server['secret']);

        $this->flash(
            $session,
            $result->alertVariant(),
            sprintf('%s — %s', self::host((string) $server['base_url']), $result->message),
        );

        return Response::redirect('/admin/servers');
    }

    // =====================================================================

    /**
     * @param  list<array{type: string, message: string}>  $flash
     * @param  array<string, mixed>|null  $editing
     * @param  array<string, string>  $errors
     * @param  array<string, mixed>  $old
     */
    private function render(
        Identity $identity,
        SessionStore $session,
        array $flash,
        ?array $editing,
        array $errors,
        array $old = [],
        int $status = 200,
    ): Response {
        return Response::html(
            $this->view->page('admin-servers', [
                'servers' => $this->store->servers(),
                'editing' => $editing,
                'errors' => $errors,
                'old' => $old,
                'flash' => $flash,
                'csrf' => $this->csrfToken($session),
            ], 'Serveurs BBB', $this->env, $identity),
            $errors !== [] ? 422 : $status,
        );
    }

    /**
     * Validation d'URL RÉELLE — ce que le legacy n'avait pas.
     *
     * `http://` n'est pas interdit (un serveur BBB de test peut vivre en clair
     * sur un réseau d'établissement), mais l'avertissement est AFFICHÉ, jamais
     * silencieux : le secret partagé transite dans chaque requête signée.
     *
     * @return array{url: string, error: string, warning: string}
     */
    public static function validateBaseUrl(string $raw): array
    {
        $url = trim($raw);

        if ($url === '') {
            return ['url' => '', 'error' => 'L\'URL du serveur est obligatoire.', 'warning' => ''];
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return [
                'url' => '',
                'error' => 'URL invalide : indiquez une adresse complète, par exemple https://bbb.example.net/bigbluebutton/api',
                'warning' => '',
            ];
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return ['url' => '', 'error' => 'Seuls les schémas http et https sont acceptés.', 'warning' => ''];
        }

        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return [
                'url' => '',
                'error' => 'L\'URL ne doit porter ni paramètre, ni ancre, ni identifiants.',
                'warning' => '',
            ];
        }

        return [
            'url' => rtrim($url, '/'),
            'error' => '',
            'warning' => $scheme === 'http'
                ? 'Attention : ce serveur est déclaré en http. Le secret partagé circulera en clair sur le réseau.'
                : '',
        ];
    }

    /** Masque d'affichage d'un secret : jamais la valeur, jamais rien de réutilisable. */
    public static function maskSecret(string $secret): string
    {
        $tail = strlen($secret) > 8 ? substr($secret, -4) : '';

        return str_repeat('•', 8) . $tail;
    }

    private static function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $url;
    }

    // ── État de page ─────────────────────────────────────────────────────

    private function flash(SessionStore $session, string $type, string $message): void
    {
        /** @var list<array{type: string, message: string}> $current */
        $current = (array) $session->get(self::FLASH, []);
        $current[] = ['type' => $type, 'message' => $message];
        $session->put(self::FLASH, $current);
    }

    /** @return list<array{type: string, message: string}> */
    private function takeFlash(SessionStore $session): array
    {
        /** @var list<array{type: string, message: string}> $current */
        $current = (array) $session->get(self::FLASH, []);
        $session->forget(self::FLASH);

        return $current;
    }

    private function csrfToken(SessionStore $session): string
    {
        $token = $session->get(self::CSRF);

        if (! is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $session->put(self::CSRF, $token);
        }

        return $token;
    }

    private function csrfMatches(Request $request, SessionStore $session): bool
    {
        $expected = $session->get(self::CSRF);
        $received = $request->input('_token');

        return is_string($expected) && $expected !== '' && $received !== '' && hash_equals($expected, $received);
    }
}
