<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

use App\Ipxe\Enums\LinuxDesktopVariant;
use App\Ipxe\Enums\LinuxDistribution;
use App\Ipxe\Exceptions\PreseedGenerationException;
use App\Ipxe\Support\PreseedPlaceholders;
use App\Models\Workstation;
use Illuminate\Support\Facades\Log;

/**
 * Story 3.4 — D5 / AC2.1 / AC2.2 / AC2.3.
 *
 * Service d'assemblage dynamique du fichier preseed Linux text/plain
 * consommé par debian-installer / ubuntu-installer en début d'install.
 *
 * **Port natif** de `sambaedu/ipxe/linux/preseed.php` (194 LOC) simplifié au
 * scope 3.4 (sans `se4ad`/`se4fs`/`deb_serv`/`deb_kiosk`/`deb_nextcloud`/
 * `deb_gnome_perso`/`primtux` — voir story 3.4 § HORS-SCOPE D14).
 *
 * **Algorithme iso-legacy `preseed.php:86-159`** :
 *
 *   1. Lecture des fragments base selon `$distribution`/`$variant` :
 *      - Debian standard → `debian.cfg` + `debian_<variant>.cfg` + `sambaedu.cfg` + `simple_boot.cfg`.
 *      - Ubuntu          → `ubuntu.cfg` + `simple_boot.cfg`.
 *      - Nird (Debian dérivée perso) → `debian.cfg` + `debian_perso.cfg` + `simple_boot.cfg`.
 *   2. Ajout conditionnel `aptcache.cfg` si `config('sambaedu.linux.apt_proxy')`
 *      défini, sinon `nocache.cfg` (+ `proxy.cfg` si `server_proxy`).
 *   3. Ajout conditionnel `commande_fin.cfg` si
 *      `config('sambaedu.linux.commande_fin_preseed')` défini.
 *   4. Lecture de la config consolidée via {@see PreseedPlaceholders::catalog()}.
 *   5. Construction des `$params` par-poste (hostname, uuid sanitizés).
 *   6. Interpolation des placeholders `###_<KEY>_###` via
 *      {@see PreseedPlaceholders::interpolate()}.
 *   7. Log audit channel `ipxe` (sha256 only, jamais le preseed en clair).
 *   8. Retour string concaténée.
 *
 * **Sécurité** :
 *  - Anti-injection : hostname/uuid sanitizés via
 *    {@see IpxeHostnameSanitizer::sanitizeForIpxeOutput()} + tous les
 *    placeholders passent par {@see PreseedPlaceholders::sanitize()}.
 *  - Aucune écriture disque (parité legacy `/tmp/{name}.preseed` retirée).
 *  - Aucun secret dans les logs (sha256 only).
 */
final class LinuxPreseedService
{
    /**
     * Channel Monolog dédié (iso 3.1 D7).
     */
    private function channel(): string
    {
        return (string) config('ipxe.log.channel', 'ipxe');
    }

    /**
     * Génère le preseed assemblé en text/plain pour un poste donné.
     *
     * @param  Workstation  $workstation  Poste résolu via {@see WorkstationLocator}.
     * @param  LinuxDistribution  $distribution  Debian|Ubuntu|Nird.
     * @param  LinuxDesktopVariant  $variant     Base|Gnome|Lxde|Kde|Mate|Xfce|Cinnamon.
     * @param  array{mask?:string, gateway?:string, perso?:bool, ip?:string}  $params  Override optionnels.
     * @return string                           Preseed assemblé (text/plain ~4000 chars).
     * @throws PreseedGenerationException si fragment manquant ou config invalide.
     */
    public function generate(
        Workstation $workstation,
        LinuxDistribution $distribution,
        LinuxDesktopVariant $variant,
        array $params = [],
    ): string {
        // 1-2-3. Assemblage des fragments selon distribution + variant.
        $fragments = $this->resolveFragmentsList($distribution, $variant);
        $template = $this->loadFragments($fragments);

        // 4. Construction du tableau $config consolidé depuis le catalogue.
        $config = $this->buildConfig();

        // 5. Construction des $params par-poste (sanitization defense-in-depth).
        $rawHostname = (string) ($workstation->name ?? '');
        $perPostParams = [
            'hostname' => IpxeHostnameSanitizer::sanitizeForIpxeOutput(strtolower($rawHostname)),
            'uuid' => IpxeHostnameSanitizer::sanitizeForIpxeOutput((string) ($workstation->uuid ?? '')),
        ];

        // 6. Interpolation des placeholders : merge config + params (params win).
        $merged = array_merge($config, $perPostParams);
        $preseed = PreseedPlaceholders::interpolate($template, $merged);

        // 7. Log audit channel ipxe (sha256 only — pas de fuite secret).
        $this->logGenerated(
            $workstation,
            $distribution,
            $variant,
            $preseed,
            (string) ($params['ip'] ?? ''),
        );

        return $preseed;
    }

    /**
     * Détermine la liste des fragments .cfg à concaténer selon
     * (distribution, variant). Algorithme iso-legacy `preseed.php:86-159`
     * simplifié au scope 3.4.
     *
     * **Ordre des fragments** (CRITIQUE — parité legacy `preseed.php:86-159`) :
     *
     *   - **Debian standard** :
     *     `<apt>` → `debian_<variant>` → `debian.cfg` → `sambaedu.cfg`
     *     → [`commande_fin.cfg`] → `simple_boot.cfg`.
     *
     *   - **Ubuntu** :
     *     `<apt>` → `ubuntu.cfg` → [`commande_fin.cfg`] → `simple_boot.cfg`.
     *
     *   - **Nird** :
     *     `<apt>` → `debian.cfg` → `debian_perso.cfg` → [`commande_fin.cfg`]
     *     → `simple_boot.cfg`.
     *
     * `<apt>` est résolu via {@see resolveAptFragment()} :
     * `aptcache.cfg` | `proxy.cfg` | `nocache.cfg`.
     *
     * **Raison de l'ordre** : iso-legacy `preseed.php:86-159`. Le fragment
     * `simple_boot.cfg` doit être DERNIER (il override le partitionnement
     * `partman-auto`). Le fragment `<apt>` doit être PREMIER (il configure
     * proxy/cache avant la résolution des miroirs Debian/Ubuntu). Le fragment
     * variant (`debian_gnome.cfg` etc.) doit précéder `debian.cfg` pour que
     * `tasksel/first` reste celui du variant (`debian.cfg` ne pose pas
     * `tasksel/first` → pas de conflit ici).
     *
     * Le test `LinuxPreseedServiceTest::it_preserves_fragment_order_for_debian_gnome`
     * gèle l'ordre pour empêcher une régression silencieuse.
     *
     * @return list<string>
     */
    private function resolveFragmentsList(
        LinuxDistribution $distribution,
        LinuxDesktopVariant $variant,
    ): array {
        $aptFragment = $this->resolveAptFragment();
        $commandeFinEnabled = trim((string) config('sambaedu.linux.commande_fin_preseed', '')) !== '';

        if ($distribution === LinuxDistribution::Ubuntu) {
            $fragments = [$aptFragment, 'ubuntu.cfg'];
            if ($commandeFinEnabled) {
                $fragments[] = 'commande_fin.cfg';
            }
            $fragments[] = 'simple_boot.cfg';

            return $fragments;
        }

        if ($distribution === LinuxDistribution::Nird) {
            $fragments = [$aptFragment, 'debian.cfg', 'debian_perso.cfg'];
            if ($commandeFinEnabled) {
                $fragments[] = 'commande_fin.cfg';
            }
            $fragments[] = 'simple_boot.cfg';

            return $fragments;
        }

        // Debian standard — variant-driven (gnome|lxde|kde|mate|xfce|cinnamon|base).
        $variantFragment = 'debian_' . $variant->value . '.cfg';
        $fragments = [
            $aptFragment,
            $variantFragment,
            'debian.cfg',
            'sambaedu.cfg',
        ];
        if ($commandeFinEnabled) {
            $fragments[] = 'commande_fin.cfg';
        }
        $fragments[] = 'simple_boot.cfg';

        return $fragments;
    }

    /**
     * Résout le fragment APT cache à utiliser :
     *  - `aptcache.cfg` si `config('sambaedu.linux.apt_proxy')` non vide.
     *  - `proxy.cfg`    si `apt_proxy` vide MAIS `server_proxy` défini.
     *  - `nocache.cfg`  sinon (cas par défaut).
     */
    private function resolveAptFragment(): string
    {
        $aptProxy = trim((string) config('sambaedu.linux.apt_proxy', ''));
        if ($aptProxy !== '') {
            return 'aptcache.cfg';
        }

        $serverProxy = trim((string) config('sambaedu.linux.server_proxy', ''));
        if ($serverProxy !== '') {
            return 'proxy.cfg';
        }

        return 'nocache.cfg';
    }

    /**
     * Charge la concaténation des fragments .cfg depuis
     * `resources/ipxe/linux/`.
     *
     * @param  list<string>  $fragmentNames  Liste de noms de fichiers
     *                                       (ex: `['debian.cfg', 'sambaedu.cfg']`).
     * @return string                        Concaténation séparée par newlines.
     * @throws PreseedGenerationException     Si un fragment est manquant ou
     *                                       illisible.
     */
    private function loadFragments(array $fragmentNames): string
    {
        $basePath = (string) config(
            'ipxe.linux.preseed_fragments_path',
            resource_path('ipxe/linux'),
        );

        $parts = [];
        foreach ($fragmentNames as $name) {
            $path = rtrim($basePath, '/') . '/' . $name;
            if (! is_file($path) || ! is_readable($path)) {
                Log::channel($this->channel())->error('ipxe.linux.preseed.fragment_missing', [
                    'action_type' => 'ipxe.linux.preseed.fragment_missing',
                    'fragment_name' => $name,
                    'path' => $path,
                ]);

                throw new PreseedGenerationException(
                    sprintf('Preseed fragment "%s" introuvable sous %s', $name, $basePath),
                );
            }

            $content = file_get_contents($path);
            if ($content === false) {
                throw new PreseedGenerationException(
                    sprintf('Preseed fragment "%s" illisible', $name),
                );
            }
            $parts[] = $content;
        }

        return implode("\n", $parts);
    }

    /**
     * Construit le tableau de valeurs `$config` consolidé à partir du
     * catalogue {@see PreseedPlaceholders::catalog()}.
     *
     * Chaque clé du catalogue est résolue via `config(<config_key>)`. Les
     * valeurs vides ne sont pas filtrées — l'interpolation remplace par
     * une string vide (parité legacy).
     *
     * @return array<string, string>  Clés lowercase (compatibles avec
     *                                {@see PreseedPlaceholders::interpolate()}).
     */
    private function buildConfig(): array
    {
        $result = [];
        foreach (PreseedPlaceholders::catalog() as $key => $configKey) {
            $value = config($configKey);
            // Les valeurs scalaires (string|int|null) seulement.
            if (is_scalar($value) || $value === null) {
                $result[strtolower($key)] = (string) ($value ?? '');
            } else {
                $result[strtolower($key)] = '';
            }
        }

        return $result;
    }

    /**
     * Émet le log info `ipxe.linux.preseed.generated` avec context audit
     * (sha256 + size + distribution/variant). **NE LOG JAMAIS** le contenu
     * du preseed (D8 — secrets en clair).
     */
    private function logGenerated(
        Workstation $workstation,
        LinuxDistribution $distribution,
        LinuxDesktopVariant $variant,
        string $preseed,
        string $ip,
    ): void {
        Log::channel($this->channel())->info('ipxe.linux.preseed.generated', [
            'action_type' => 'ipxe.linux.preseed.generated',
            'ip' => $ip,
            'workstation_id' => $workstation->id ?? null,
            'workstation_name_prefix' => substr((string) ($workstation->name ?? ''), 0, 6),
            'distribution' => $distribution->value,
            'variant' => $variant->value,
            'preseed_sha256' => hash('sha256', $preseed),
            'preseed_size_bytes' => strlen($preseed),
        ]);
    }
}
