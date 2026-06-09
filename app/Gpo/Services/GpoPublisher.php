<?php

declare(strict_types=1);

namespace App\Gpo\Services;

use App\Config\SambaEduConfig;
use App\Gpo\Dto\GpoTemplate;
use App\Gpo\Support\GpoLogger;
use App\Gpo\Support\GpoTemplateRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Publie (importe + spécialise) **n'importe quelle** GPO templatée dans SYSVOL
 * via le shim legacy `import_gpo`. **Side effect SYSVOL** (`samba-tool` /
 * `smbclient`).
 *
 * Généralisation directe de la boucle legacy `gpo-maj.php` :
 *
 *     foreach (list_gpo_templates_etab() as $gpo)
 *         import_gpo($config, $gpo, "se4_".$gpo, true, true);
 *
 * appliquée ici à **une** GPO résolue par son `displayName` via
 * {@see GpoTemplateRegistry}. `WpkgGpoSynchronizer` reste à part : il porte un
 * audit WPKG riche (couverture Bearer, `packages.xml`…) que ce publisher
 * générique n'a pas vocation à reproduire — `se4_wpkg` y conserve son chemin
 * dédié. Ce publisher sert le clic « Publier l'étage 2 » de la page détail GPO
 * pour toute template (se4_wpkg inclus, sans privilège particulier).
 *
 * Une GPO **sans template** (créée à la main, built-in Windows, tierce) n'est
 * pas publiable : `publish()` lève une `RuntimeException` explicite — il n'y a
 * aucune source SYSVOL à (re)déposer.
 */
class GpoPublisher
{
    /**
     * Clés `SambaEduConfig` de **fallback** (env testing sans legacy chargé).
     * En prod, `import_gpo` reçoit le config legacy COMPLET via `get_config()`
     * (cf. {@see resolveLegacyConfig}) — `import_gpo` enchaîne `search_ad` /
     * `modify_ad` / `gposetlink` qui exigent la connexion LDAP + la map `dn`,
     * absentes de ces seules clés de spécialisation.
     */
    private const SPECIALISE_CONFIG_KEYS = [
        'domain',
        'samba_domain',
        'se4fs_name',
        'se4ad_name',
        'domain_sid',
        'se4install_name',
        'ldap_base_dn',
        'cloud_name',
    ];

    private const LOCK_PREFIX = 'gpo:publish:';
    private const LOCK_TIMEOUT_SECONDS = 300;
    private const LOCK_WAIT_SECONDS = 30;

    public function __construct(
        private readonly GpoTemplateRegistry $registry,
        private readonly SambaEduConfig $config,
    ) {}

    /**
     * Publie la GPO `$displayName` dans SYSVOL. Lock applicatif par GPO
     * (évite deux imports concurrents de la même GPO).
     *
     * Résolution **par nom** (review F3) : `import_gpo` localise la GPO cible en
     * AD via `search_ad($config, $displayName)` (iso-legacy `gpo-maj.php`), PAS
     * via le GUID de la page appelante. Conséquence : si deux GPO partagent le
     * même `displayName`, la cible est ambiguë (`search_ad` en prend une). Les
     * GPO gérées par SE5 (`se4_*`, `etab_*`) ont par convention un nom unique,
     * donc le risque est théorique — mais on ne garantit pas que la GPO publiée
     * soit exactement celle dont le GUID est affiché.
     *
     * Lock (review F4) : clé par `displayName`. Ne couvre PAS une course avec
     * {@see WpkgGpoSynchronizer} (lock fixe `gpo:wpkg:sync`) sur `se4_wpkg` —
     * probabilité faible (deux UIs déclenchées en parallèle), non unifié pour
     * garder `WpkgGpoSynchronizer` inchangé.
     *
     * @param  bool  $force  Écrase la GPO même si sa version SYSVOL est ≥ celle de la template.
     * @return GpoTemplate La template effectivement publiée.
     *
     * @throws RuntimeException Si la GPO n'a pas de template (AC : non publiable),
     *                          si le lock est indisponible, ou si `import_gpo` échoue.
     */
    public function publish(string $displayName, bool $force = false, ?string $operationId = null): GpoTemplate
    {
        $operationId ??= (string) Str::uuid();

        $template = $this->registry->templateFor($displayName);
        if ($template === null) {
            throw new RuntimeException(sprintf(
                'GPO `%s` non publiable : aucune archive-template correspondante dans `%s`. '
                . 'Son contenu SYSVOL n\'est pas généré par SambaEdu (GPO créée à la main, built-in ou tierce) — '
                . 'restaurez-la depuis une sauvegarde ou éditez-la manuellement.',
                $displayName,
                (string) config('sambaedu.gpo.templates_dir', ''),
            ));
        }

        $log = GpoLogger::action('gpo.publish.start', operationId: $operationId, context: [
            'gpo_name' => $template->displayName,
            'archive' => $template->archive,
            'force' => $force,
        ]);

        $lock = Cache::lock(self::LOCK_PREFIX . md5($template->displayName), self::LOCK_TIMEOUT_SECONDS);
        $acquired = false;

        try {
            if (! $lock->block(self::LOCK_WAIT_SECONDS)) {
                throw new RuntimeException(sprintf(
                    'Publication de la GPO `%s` déjà en cours par un autre processus (lock indisponible après %ds).',
                    $template->displayName,
                    self::LOCK_WAIT_SECONDS,
                ));
            }
            $acquired = true;
            $log->step('lock acquired');

            $this->invokeImport($template, $force, $operationId);

            $log->success(['outcome' => 'published', 'archive' => $template->archive]);
            GpoLogger::action('gpo.publish.end', operationId: $operationId, context: [
                'gpo_name' => $template->displayName,
                'outcome' => 'published',
            ])->success();

            return $template;
        } catch (\Throwable $e) {
            $log->failure($e);
            GpoLogger::action('gpo.publish.end', operationId: $operationId, context: [
                'gpo_name' => $template->displayName,
                'outcome' => 'failure',
            ])->failure($e);
            throw $e;
        } finally {
            if ($acquired) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                    // best-effort — le lock expirera de toute façon (TTL).
                }
            }
        }
    }

    /**
     * Invoque `import_gpo($config, $displayName, $archive, $update=true, $force)`.
     *
     * Iso {@see WpkgGpoSynchronizer}::invokeImport : binding testable
     * `legacy.import_gpo` en priorité, fallback sur la fonction legacy chargée
     * par `legacy/bootstrap.php`. Best effort (DO3) : `import_gpo` retourne
     * `void`/`null` en succès et `false` en échec explicite → on lève alors.
     *
     * @param array<string,string> $legacyConfig
     */
    private function invokeImport(GpoTemplate $template, bool $force, string $operationId): void
    {
        $legacyConfig = $this->resolveLegacyConfig();

        $log = GpoLogger::action('gpo.publish.import', operationId: $operationId, context: [
            'gpo_name' => $template->displayName,
            'archive' => $template->archive,
            'force' => $force,
        ]);

        try {
            if (app()->bound('legacy.import_gpo')) {
                $fn = app('legacy.import_gpo');
                if (! is_callable($fn)) {
                    throw new RuntimeException('Binding `legacy.import_gpo` non callable.');
                }
                $result = $fn($legacyConfig, $template->displayName, $template->archive, true, $force);
            } else {
                if (! function_exists('import_gpo')) {
                    $this->loadLegacyGpoIncludes();
                }
                if (! function_exists('import_gpo')) {
                    throw new RuntimeException(
                        'Fonction legacy `import_gpo` indisponible — vérifier `legacy/bootstrap.php`.',
                    );
                }
                // @legacy-port path="sambaedu/includes/gpo.inc.php (import_gpo)"
                $result = call_user_func('import_gpo', $legacyConfig, $template->displayName, $template->archive, true, $force);
            }

            if ($result === false) {
                throw new RuntimeException(sprintf(
                    'Shim legacy `import_gpo` a retourné `false` pour `%s` — vérifier les logs samba-tool/smbclient (KRB5CCNAME, ACLs SYSVOL).',
                    $template->displayName,
                ));
            }

            $log->success(['outcome' => 'imported']);
        } catch (\Throwable $e) {
            // Best effort, pas de rollback (DO3) : la spécialisation a pu réussir
            // avant l'échec. On loggue le risque d'incohérence SYSVOL pour la
            // récupération manuelle.
            $log->step(
                'État SYSVOL potentiellement incohérent — vérifier via samba-tool gpo listall et SYSVOL.',
                ['gpo_name' => $template->displayName],
                'critical',
            );
            $log->failure($e);
            throw $e;
        }
    }

    /**
     * Config legacy passé à `import_gpo`. **Config COMPLET requis** : `import_gpo`
     * appelle `search_ad`/`modify_ad`/`gposetlink` (connexion LDAP + map `dn`)
     * en plus de `specialise_gpo` (placeholders). On délègue donc à `get_config()`
     * — pattern iso `RoamingProfileService` (`$config = get_config()` avant
     * `search_ad`). Sans ça, `search_ad` ne peut pas binder et renvoie `false` →
     * `count(false)` dans `import_gpo:971` (bug observé 2026-06-08).
     *
     * Fallback (env testing sans legacy bootstrap) : clés de spécialisation
     * seules — suffisant car le binding `legacy.import_gpo` y est mocké.
     *
     * @return array<string,mixed>
     */
    private function resolveLegacyConfig(): array
    {
        try {
            if (! function_exists('get_config')) {
                $this->loadLegacyGpoIncludes();
            }
            if (function_exists('get_config')) {
                $full = get_config();
                if (is_array($full) && $full !== []) {
                    return $full;
                }
            }
        } catch (\Throwable $e) {
            GpoLogger::action('gpo.publish.config_fallback')->step(
                'get_config() indisponible — fallback clés de spécialisation : ' . $e->getMessage(),
                level: 'warning',
            );
        }

        $out = [];
        foreach (self::SPECIALISE_CONFIG_KEYS as $k) {
            $out[$k] = (string) ($this->config->get($k, '') ?? '');
        }
        return $out;
    }

    /**
     * @legacy-port path="sambaedu/includes/gpo.inc.php"
     */
    private function loadLegacyGpoIncludes(): void
    {
        $bootstrap = base_path('legacy/bootstrap.php');
        if (is_file($bootstrap)) {
            require_once $bootstrap;
        }
    }
}
