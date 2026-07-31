<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Extensions;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Models\Extension;
use App\Services\Extensions\ExtensionHealthService;
use Throwable;

/**
 * Story 56.5 (AC3, FR34) — Les backends des extensions `app` installées
 * répondent-ils, et l'état persisté est-il encore crédible ?
 *
 * Auto-découvert par `sambaedu:doctor` (scan de `app/Doctor/Checks/<Tag>/`) :
 * aucun registre à modifier. Filtrable par `--tag=extensions`, `--json` gratuit.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  READ-ONLY STRICT — ce check n'écrit RIEN
 *
 *  Il SONDE en direct (comme {@see \App\Doctor\Checks\ControlHub\ControlHubReachableCheck}
 *  fait un HEAD live : le précédent est établi), mais il ne persiste JAMAIS les
 *  colonnes `health_*`. La règle d'or du contrat {@see EnvironmentCheck} est
 *  « aucun side effect » : la persistance appartient à `ext:health:check` et au
 *  bouton « Sonder maintenant » de la fiche. Un test dédié vérifie que les
 *  colonnes sont inchangées après `run()`.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Le verdict porte sur un CONCERN, pas sur une extension (décision n° 4 de la
 * story) : un seul check agrégé, dont le DÉTAIL nomme les clés fautives. Le
 * par-extension vit sur la fiche.
 *
 *  - `error` : au moins un backend ne répond pas. Le détail nomme les clés, le
 *    `fix` donne la commande de diagnostic — il ne la LANCE pas : on ne
 *    redémarre jamais un service tout seul (pas d'auto-réparation, décision de
 *    périmètre de la story).
 *  - `warn` : tout répond, mais l'état PERSISTÉ est périmé — le scheduler est
 *    probablement arrêté. C'est un diagnostic à part entière : la tuile, elle,
 *    ne dit rien dans ce cas (un scheduler mort n'est pas une extension morte).
 *  - `ok` : tout répond et l'état persisté est frais. Le détail nomme le compte
 *    et les écarts de versions.
 *
 * Registre illisible (table absente, DB down) ⇒ `warn` explicite, jamais une
 * exception : on ne double pas le check `database` (patron `ControlHubReachableCheck`).
 */
final class ExtensionsReachableCheck implements EnvironmentCheck
{
    public function __construct(
        private readonly ExtensionHealthService $health,
    ) {
    }

    public function tag(): string
    {
        return 'extensions';
    }

    public function name(): string
    {
        return 'Extensions (backends)';
    }

    public function run(): CheckResult
    {
        try {
            /** @var list<Extension> $apps */
            $apps = Extension::query()
                ->where('type', ExtensionType::App)
                ->where('status', ExtensionStatus::Integrated)
                ->whereNotNull('installed_port')
                ->orderBy('key')
                ->get()
                ->all();
        } catch (Throwable $e) {
            return CheckResult::warn(
                sprintf('registre d\'extensions illisible : %s', substr($e->getMessage(), 0, 120)),
                'Vérifier les migrations (php artisan migrate) et la connexion DB.',
            );
        }

        if ($apps === []) {
            return CheckResult::ok('aucune extension app installée.');
        }

        $down = [];
        $stale = [];
        $drift = [];
        $notProbed = [];
        $lastIncident = null;

        // ⚠️ BUDGET DE TEMPS (review 56.5 #1) — ce check tourne DANS une requête
        // HTTP, aux côtés des autres checks réseau de `/admin/settings/system-status`.
        // Les sondes sont séquentielles et un backend mort coûte
        // `connect_timeout + timeout` : sans borne, quelques extensions mortes
        // suffisaient à dépasser le `max_execution_time` de PHP-FPM. Un outil
        // de diagnostic qui tombe précisément quand on en a besoin est un
        // mauvais outil. Au-delà du budget on rend un verdict PARTIEL, en
        // nommant ce qui n'a pas été mesuré — jamais une page en erreur, et
        // jamais un « tout va bien » silencieux sur des extensions non sondées.
        $budget = (float) config('extensions.health.doctor_probe_budget', 20);
        $startedAt = microtime(true);

        foreach ($apps as $app) {
            $key = (string) $app->key;

            if ($budget > 0.0 && (microtime(true) - $startedAt) >= $budget) {
                $notProbed[] = $key;

                continue;
            }

            // ⚠️ MÊME `probe()` que la commande planifiée : « joignable » n'a
            // qu'un seul énoncé dans le projet (leçon review 56.1 #3). Un
            // doctor qui aurait sa propre définition pourrait contredire la
            // tuile — le pire des diagnostics.
            if (! $this->health->probe($app)['reachable']) {
                $down[] = $key;
            } elseif ($app->healthIsStale()) {
                // Périmé ALORS QUE la sonde directe répond : ce n'est pas
                // l'extension qui va mal, c'est la mesure.
                $stale[] = $key;
            }

            if ($app->hasVersionDrift()) {
                $drift[] = sprintf('%s (%s installée, %s au catalogue)', $key, $app->installed_version, $app->version);
            }

            if ($app->health_last_incident_at !== null
                && ($lastIncident === null || $app->health_last_incident_at->gt($lastIncident['at']))) {
                $lastIncident = [
                    'at' => $app->health_last_incident_at,
                    'key' => $key,
                    'detail' => (string) $app->health_last_incident_detail,
                ];
            }
        }

        $suffix = $this->suffix($drift, $lastIncident);

        // Budget épuisé : le verdict doit dire ce qu'il N'A PAS mesuré, avant
        // tout autre constat. Un `ok` portant sur la moitié du parc serait un
        // faux « tout va bien » — et c'est bien la seule chose qu'un check de
        // santé ne doit jamais dire.
        if ($notProbed !== []) {
            return CheckResult::warn(
                sprintf(
                    'diagnostic PARTIEL : %d extension(s) sur %d n\'ont pas été sondées (budget de %d s épuisé) — %s. '
                        .'%s%s',
                    count($notProbed),
                    count($apps),
                    (int) $budget,
                    implode(', ', $notProbed),
                    $down === []
                        ? 'Les extensions sondées répondent.'
                        : sprintf('Et %d ne répondent pas : %s.', count($down), implode(', ', $down)),
                    $suffix,
                ),
                'Un backend mort coûte le délai complet de la sonde : commencer par relever les extensions injoignables '
                    .'(php artisan ext:health:check les mesure toutes, hors requête web).',
            );
        }

        if ($down !== []) {
            return CheckResult::error(
                sprintf(
                    '%d extension(s) app sur %d ne répondent pas : %s.%s',
                    count($down),
                    count($apps),
                    implode(', ', $down),
                    $suffix,
                ),
                sprintf(
                    'Diagnostiquer le service : systemctl status sambaedu-ext-%s (SE5 ne redémarre jamais un backend tout seul).',
                    $down[0],
                ),
            );
        }

        if ($stale !== []) {
            return CheckResult::warn(
                sprintf(
                    // ⚠️ Formulation VRAIE dans les DEUX cas : « jamais mesuré »
                    // (extension installée il y a moins de 5 min, le scheduler
                    // n'a pas encore tourné) et « mesure trop vieille »
                    // (scheduler arrêté). Affirmer « muet depuis plus de 900 s »
                    // serait faux dans le premier cas — et un diagnostic qui peut
                    // être faux est pire qu'une absence de diagnostic.
                    'tous les backends répondent, mais l\'état persisté n\'est pas exploitable pour : %s '
                        .'(jamais mesuré, ou mesuré il y a plus de %d s). La tuile ne signalera donc aucune panne.%s',
                    implode(', ', $stale),
                    (int) config('extensions.health.stale_after', 900),
                    $suffix,
                ),
                'Vérifier le scheduler Laravel (php artisan schedule:list, et le cron qui appelle schedule:run). '
                    .'Une extension installée à l\'instant devient normale au prochain passage (5 min) — ou tout de suite avec php artisan ext:health:check.',
            );
        }

        return CheckResult::ok(sprintf(
            '%d extension(s) app installée(s), toutes joignables.%s',
            count($apps),
            $suffix,
        ));
    }

    /**
     * Complément de détail commun aux trois verdicts : écarts de versions et
     * dernier incident connu.
     *
     * ⚠️ « écart de versions » est un FAIT ({@see Extension::hasVersionDrift()}),
     * pas la DÉCISION « une mise à jour est proposable »
     * (`ExtensionCatalogService::hasUpdateAvailable()`, qui exige en plus une
     * source qui propose encore). Le doctor veut le fait : un opérateur doit
     * savoir qu'il tourne en 1.0.0 alors que le catalogue publie 2.0.0, même si
     * la source est momentanément gelée.
     *
     * @param  list<string>  $drift
     * @param  array{at: \Illuminate\Support\Carbon, key: string, detail: string}|null  $lastIncident
     */
    private function suffix(array $drift, ?array $lastIncident): string
    {
        $parts = [];

        if ($drift !== []) {
            $parts[] = 'écart de version : '.implode(', ', $drift);
        }

        if ($lastIncident !== null) {
            $parts[] = sprintf(
                'dernier incident : %s le %s%s',
                $lastIncident['key'],
                $lastIncident['at']->format('d/m/Y H:i'),
                $lastIncident['detail'] !== '' ? ' — '.$lastIncident['detail'] : '',
            );
        }

        return $parts === [] ? '' : ' '.implode(' ; ', $parts).'.';
    }
}
