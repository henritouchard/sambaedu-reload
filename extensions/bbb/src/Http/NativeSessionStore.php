<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Http;

use SambaEdu\ExtBbb\Url;

/**
 * Story 57.1 — Le magasin natif de PHP, réglé pour vivre SOUS le préfixe de
 * l'extension.
 *
 * Trois réglages non négociables :
 *
 * 1. **Nom de cookie propre** (`se5_ext_bbb`) : il cohabite avec celui de SE5 sur
 *    le même hôte ; deux `PHPSESSID` se marcheraient dessus.
 * 2. **`cookie_path` = le préfixe de l'extension** : le navigateur ne renvoie
 *    alors le cookie qu'aux URL de l'extension, jamais au reste de SE5.
 * 3. `HttpOnly`, `SameSite=Lax`, `Secure` dès que SE5 est servi en HTTPS —
 *    `Lax` et non `Strict` parce que le retour du fournisseur OIDC est une
 *    navigation entrante, que `Strict` amputerait de son cookie d'état.
 *
 * ⚠️ **D'où vient `Secure` — review 57.1 #1.** La tentation est de lire
 * `$_SERVER['HTTPS']` : derrière `ProxyPass "…" "http://127.0.0.1:<port>/"`,
 * cette variable n'est JAMAIS posée, quel que soit le protocole vu par le
 * navigateur. `X-Forwarded-Proto` non plus n'est pas une source fiable : le
 * fragment généré par le helper root ne pose que `X-Forwarded-Prefix`, et le
 * vhost qui l'inclut est en `*:80`. S'en remettre à ces deux signaux, c'est
 * n'émettre `Secure` JAMAIS.
 *
 * La source de vérité est le **schéma de l'issuer** : l'extension est servie
 * sous `/ext/bbb` de la MÊME origine que SE5 (le fragment est un `ProxyPass`
 * du vhost de SE5, et la tuile du lanceur est fabriquée par SE5 depuis son
 * `APP_URL`). Un issuer en `https://` signifie donc que le navigateur atteint
 * l'extension en HTTPS — et c'est la seule URL de SE5 que l'extension connaisse
 * (contrat §5 : `SE5_OIDC_ISSUER` est l'une des 7 variables d'environnement).
 * Les deux en-têtes restent acceptés en signal ADDITIONNEL : ils ne peuvent que
 * confirmer, jamais infirmer.
 *
 * **Démarrage PARESSEUX, et c'est une décision.** `ExtensionHealthService`
 * frappe `GET /` toutes les 5 minutes : démarrer un état serveur à chaque sonde
 * remplirait le répertoire de fichiers de session sans aucun utilisateur
 * derrière. Le mécanisme ne démarre donc que si un cookie existe déjà (lecture)
 * ou si l'on écrit vraiment quelque chose.
 */
final class NativeSessionStore implements SessionStore
{
    public const COOKIE_NAME = 'se5_ext_bbb';

    private bool $started = false;

    private bool $unavailable = false;

    /**
     * @param  string  $issuer  `SE5_OIDC_ISSUER` — sa seule lecture ici est son
     *                          SCHÉMA, qui dit si le navigateur atteint
     *                          l'extension en HTTPS (voir le docblock de classe).
     *                          Vide = extension non provisionnée : on ne peut
     *                          rien affirmer, on retombe sur les en-têtes.
     */
    public function __construct(private readonly string $issuer = '')
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (! $this->boot(false)) {
            return $default;
        }

        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        if (! $this->boot(true)) {
            return;
        }

        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        if (! $this->boot(false)) {
            return false;
        }

        return array_key_exists($key, $_SESSION);
    }

    public function forget(string $key): void
    {
        if (! $this->boot(false)) {
            return;
        }

        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        if ($this->boot(true)) {
            session_regenerate_id(true);
        }
    }

    /**
     * Rend la main sur le verrou du fichier d'état. Le contenu déjà écrit est
     * persisté ; une écriture ultérieure rouvrira l'état — `$started` repasse à
     * `false` exprès, et `$unavailable` n'est PAS armé (contrairement à
     * {@see self::destroy()}) : il n'y a rien de détruit ici, juste un verrou
     * relâché. Voir l'interface pour le pourquoi.
     */
    public function close(): void
    {
        if (! $this->started) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $this->started = false;
    }

    public function destroy(): void
    {
        if (! $this->boot(false)) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(self::COOKIE_NAME, '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Lax',
            ]);
        }

        session_destroy();
        $this->started = false;
        $this->unavailable = true;
    }

    /**
     * @param  bool  $forWrite  Une écriture démarre toujours ; une lecture ne
     *                          démarre que si le client présente déjà un cookie.
     */
    private function boot(bool $forWrite): bool
    {
        if ($this->started) {
            return true;
        }

        if ($this->unavailable && ! $forWrite) {
            return false;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            return $this->started = true;
        }

        if (! $forWrite && ! isset($_COOKIE[self::COOKIE_NAME])) {
            return false;
        }

        if (session_status() === PHP_SESSION_DISABLED || headers_sent()) {
            return false;
        }

        $secure = self::secureFlagFor($this->issuer, $_SERVER);

        session_name(self::COOKIE_NAME);
        session_set_cookie_params([
            // Le cookie vit sous le préfixe PUBLIC de l'extension, pas sous le
            // chemin nu que voit le backend derrière le proxy.
            'path' => Url::to('/'),
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $secure,
        ]);

        $this->unavailable = false;

        return $this->started = session_start();
    }

    /**
     * Le cookie doit-il porter `Secure` ? Décision ISOLÉE de PHP pour être
     * vérifiable sans rien démarrer du tout — voir le docblock de classe pour
     * le pourquoi de chaque source.
     *
     * L'issuer d'abord — la seule source que le contrat garantisse. Les deux
     * en-têtes ensuite : ils ne peuvent que CONFIRMER, jamais infirmer. La
     * comparaison porte sur le schéma seul, sans `parse_url` : tout ce qui ne
     * commence pas par `https://` vaut « non », y compris un issuer malformé.
     *
     * @param  array<string, mixed>  $server  Le tableau serveur (`$_SERVER`).
     */
    public static function secureFlagFor(string $issuer, array $server): bool
    {
        if (stripos($issuer, 'https://') === 0) {
            return true;
        }

        if (isset($server['HTTPS']) && is_scalar($server['HTTPS']) && (string) $server['HTTPS'] !== '') {
            return true;
        }

        return isset($server['HTTP_X_FORWARDED_PROTO'])
            && is_scalar($server['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $server['HTTP_X_FORWARDED_PROTO']) === 'https';
    }
}
