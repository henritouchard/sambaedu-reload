<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sert les assets d'installation OS (kernel/initrd debian-installer, sysresccd,
 * clonezilla, .wim/.squashfs Windows...) via une route Laravel UNIQUE
 * `GET /ipxe/os/{path}`.
 *
 * **But** : remplacer les `Alias` Apache par-emplacement (non versionnes,
 * perdus au redeploiement) par une seule route + des racines whitelistees en
 * config (`ipxe.actions.os_assets.roots`). Ajouter/deplacer un emplacement =
 * editer la config (versionnee), jamais Apache.
 *
 * **Mecanisme** : l'`Alias /ipxe` du vhost reload porte `FallbackResource
 * /index.php`, donc une URL `/ipxe/os/...` qui n'est pas un fichier physique
 * arrive a Laravel (meme principe que `/ipxe/boot`, `/ipxe/linux/preseed`).
 *
 * **Perf** : si `os_assets.xsendfile_enabled` (mod_xsendfile actif cote
 * Apache), on delegue l'envoi des octets a Apache via l'en-tete X-Sendfile
 * (zero streaming PHP-FPM — indispensable au boot de masse rentree). Sinon,
 * {@see BinaryFileResponse} (streaming + support Range/HEAD, OK pour volumes
 * moderes). Bascule via env, sans toucher au code.
 *
 * **Securite** : LAN-only (middleware `auth.v1.lan-only`), anti-traversal
 * strict (`realpath` confine aux roots). Pas de JWT (un firmware iPXE n'a pas
 * d'OS pour porter un Bearer — parite 3.x).
 */
class IpxeOsAssetController extends Controller
{
    public function handle(Request $request, string $path): Response
    {
        $real = $this->resolveExistingFile($path);
        if ($real === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $headers = ['Content-Type' => 'application/octet-stream'];

        $xsendfileHeader = (string) config('ipxe.actions.os_assets.xsendfile_header', 'X-Sendfile');
        if ($xsendfileHeader !== '' && (bool) config('ipxe.actions.os_assets.xsendfile_enabled', false)) {
            // Apache (mod_xsendfile) lit l'en-tete et sert le fichier lui-meme.
            return response('', Response::HTTP_OK, array_merge($headers, [
                $xsendfileHeader => $real,
            ]));
        }

        // Fallback sans mod_xsendfile : BinaryFileResponse (streaming, Range/HEAD).
        return new BinaryFileResponse($real, Response::HTTP_OK, $headers);
    }

    /**
     * Resout `$path` vers un fichier reel confine dans l'un des roots
     * whitelistes. Retourne null si introuvable ou hors perimetre.
     *
     * Anti path-traversal : rejet de `..`/byte nul + verification que le
     * `realpath` resolu reste sous le `realpath` du root.
     */
    private function resolveExistingFile(string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '..')) {
            return null;
        }

        /** @var array<int,string> $roots */
        $roots = (array) config('ipxe.actions.os_assets.roots', []);
        foreach ($roots as $root) {
            $realRoot = realpath((string) $root);
            if ($realRoot === false) {
                continue;
            }

            $candidate = realpath($realRoot . DIRECTORY_SEPARATOR . $path);
            if ($candidate === false || ! is_file($candidate)) {
                continue;
            }

            if ($candidate === $realRoot || str_starts_with($candidate, $realRoot . DIRECTORY_SEPARATOR)) {
                return $candidate;
            }
        }

        return null;
    }
}
