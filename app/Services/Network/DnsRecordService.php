<?php

declare(strict_types=1);

namespace App\Services\Network;

use App\Config\SambaEduConfig;
use App\Gpo\Support\SambaToolRunner;
use App\Ipxe\Services\IpxeHostnameSanitizer;
use App\Services\Network\Data\DnsUpdateOutcome;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 8.4 — DDNS piloté par DHCP, en **level-triggered**.
 *
 * Port natif de `dns_add()` / `dns_delete()`
 * (`sambaedu/includes/samba-tool.inc.php:1233-1336`) avec la correction qui
 * motive la story : le legacy réécrivait le A record à CHAQUE appel, or
 * `on commit` de dhcpd se déclenche à chaque **renouvellement** de bail
 * (~toutes les 5 min avec `default-lease-time 600`). Ici l'état DNS est lu,
 * comparé à l'état voulu, et rien n'est écrit s'il est déjà conforme —
 * doctrine desired-state, iso agent.
 *
 * Périmètre iso-legacy : **enregistrements A uniquement**. Le legacy avait
 * `dns_add_ptr()` / `dns_delete_ptr()` mais leurs appels étaient commentés
 * (lignes 1232, 1236) — on ne les réactive pas ici (pas de régression
 * inventée sur une zone reverse qui peut ne pas exister).
 *
 * Sécurité : toute exécution passe par {@see SambaToolRunner} (mode array,
 * pas de concaténation shell) et tout nom est validé par
 * {@see IpxeHostnameSanitizer::isValidHostname()} AVANT d'atteindre le
 * binaire — le legacy construisait ses commandes par concaténation de string
 * (vecteur d'injection identifié `samba-tool.inc.php:54`).
 */
class DnsRecordService
{
    /**
     * Préfixes de noms ignorés : postes LTSP (`l-`), baux DHCP de machines
     * inconnues (`dhcp-`) et terminaux mobiles — éphémères, aucun intérêt à
     * les publier dans le DNS de l'AD.
     *
     * **Écart DÉLIBÉRÉ avec le legacy** (`samba-tool.inc.php:1246`, `1275`) :
     * il ne skippait ces préfixes que si la machine existait DÉJÀ dans l'AD
     * (`&& ! empty(search_ad(...))`), ce qui contredit son propre commentaire
     * (« on ne fait pas d'enregistrement […] pour les machines inconnues ») et
     * revenait à publier justement les inconnues. On applique l'intention
     * énoncée : skip inconditionnel.
     */
    private const IGNORED_NAME_PREFIXES = ['l-', 'dhcp-', 'iphone'];

    /**
     * Marqueurs de « ce nom n'a pas d'enregistrement » dans la sortie
     * samba-tool — tout le reste est une panne (cf. queryAddresses()).
     */
    private const NO_SUCH_RECORD_MARKERS = [
        'does not exist',
        'NAME_ERROR',
        'WERR_DNS_ERROR_NAME_DOES_NOT_EXIST',
        'DNS_ERROR_NAME_DOES_NOT_EXIST',
    ];

    public function __construct(
        private readonly SambaToolRunner $runner,
        private readonly IpxeHostnameSanitizer $sanitizer,
        private readonly SambaEduConfig $config,
    ) {}

    /**
     * Applique l'état voulu pour un bail DHCP.
     *
     * @param  string  $action  `add` (commit de bail) ou `delete` (release/expiry).
     * @param  string  $name    Nom de machine — vide sur release/expiry, dhcpd
     *                          ne le fournit pas (cf. `dhcpd.conf`).
     * @param  string  $ip      Adresse IPv4 du bail.
     */
    public function apply(string $action, string $name, string $ip): DnsUpdateOutcome
    {
        $outcome = $this->resolve($action, $name, $ip);

        Log::channel('network')->info('[ddns] ' . $outcome->value, [
            'action_type' => 'network.ddns.apply',
            'action' => $action,
            'name' => $name,
            'ip' => $ip,
            'outcome' => $outcome->value,
            'wrote' => $outcome->isWrite(),
        ]);

        return $outcome;
    }

    private function resolve(string $action, string $name, string $ip): DnsUpdateOutcome
    {
        if (! $this->isValidIpv4($ip)) {
            return DnsUpdateOutcome::SKIPPED;
        }

        $zone = $this->zone();
        $server = $this->dnsServer();

        if ($zone === '' || $server === '') {
            // Instance non configurée (env de test, install incomplète) : ne
            // rien tenter plutôt que de lancer samba-tool sur une cible vide.
            return DnsUpdateOutcome::SKIPPED;
        }

        try {
            return match ($action) {
                'add' => $this->applyAdd($server, $zone, $name, $ip),
                'delete' => $this->applyDelete($server, $zone, $name, $ip),
                default => DnsUpdateOutcome::SKIPPED,
            };
        } catch (Throwable $e) {
            Log::channel('network')->error('[ddns] exception', [
                'action_type' => 'network.ddns.apply',
                'action' => $action,
                'name' => $name,
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return DnsUpdateOutcome::FAILED;
        }
    }

    /**
     * `add` : le chemin CHAUD (un appel par renouvellement de bail). Il doit
     * sortir en `UNCHANGED` sans aucune écriture dans le cas nominal.
     */
    private function applyAdd(string $server, string $zone, string $name, string $ip): DnsUpdateOutcome
    {
        $name = $this->normalizeName($name);

        if ($name === null || ! $this->isEligible($name)) {
            return DnsUpdateOutcome::SKIPPED;
        }

        $current = $this->queryAddresses($server, $zone, $name);

        // Cœur de la story : état déjà conforme → aucune commande d'écriture.
        if ($current === [$ip]) {
            return DnsUpdateOutcome::UNCHANGED;
        }

        // Parité legacy : les A périmés du même nom sont retirés avant l'ajout,
        // sinon le nom résoudrait vers deux IP (round-robin aléatoire).
        $stale = array_values(array_filter($current, static fn (string $a): bool => $a !== $ip));
        foreach ($stale as $address) {
            $this->write(['dns', 'delete', $server, $zone, $name, 'A', $address]);
        }

        if (in_array($ip, $current, true)) {
            // L'IP voulue était déjà là, seuls des doublons ont été purgés.
            return $stale === [] ? DnsUpdateOutcome::UNCHANGED : DnsUpdateOutcome::UPDATED;
        }

        $this->write(['dns', 'add', $server, $zone, $name, 'A', $ip]);

        return $stale === [] ? DnsUpdateOutcome::CREATED : DnsUpdateOutcome::UPDATED;
    }

    /**
     * `delete` : chemin FROID (release/expiry). dhcpd ne transmet pas le nom
     * dans ces événements — le legacy tentait un `gethostbyaddr()` qui, faute
     * de zone reverse (PTR jamais créés), retournait l'IP et rendait le
     * nettoyage inopérant. On balaye donc la zone pour retrouver le ou les
     * noms portant cette IP. Coûteux mais hors du chemin chaud.
     */
    private function applyDelete(string $server, string $zone, string $name, string $ip): DnsUpdateOutcome
    {
        $normalized = $this->normalizeName($name);

        if ($normalized !== null && ! $this->isEligible($normalized)) {
            return DnsUpdateOutcome::SKIPPED;
        }

        // Les noms issus du balayage de zone sont des données d'annuaire, pas
        // des entrées de confiance : ils traversent la MÊME validation que le
        // chemin nominal. Sans ça, le service refuserait de créer le A record
        // d'un `l-*`/hors-établissement mais accepterait de supprimer le sien,
        // et un `on expiry` sur une IP réservée pourrait effacer celui d'un
        // serveur (voire du DC). `normalizeName()` rejette aussi tout nom
        // commençant par `-`, qui serait pris pour une option par samba-tool
        // (le mode array protège du shell, pas de l'injection d'argument).
        $names = $normalized !== null
            ? [$normalized]
            : array_values(array_filter(
                array_map(
                    fn (string $scanned): ?string => $this->normalizeName($scanned),
                    $this->namesHoldingAddress($server, $zone, $ip),
                ),
                fn (?string $candidate): bool => $candidate !== null && $this->isEligible($candidate),
            ));

        $deleted = false;

        foreach ($names as $candidate) {
            if (! in_array($ip, $this->queryAddresses($server, $zone, $candidate), true)) {
                continue;
            }

            $this->write(['dns', 'delete', $server, $zone, $candidate, 'A', $ip]);
            $deleted = true;
        }

        return $deleted ? DnsUpdateOutcome::DELETED : DnsUpdateOutcome::UNCHANGED;
    }

    /**
     * A records actuels d'un nom. Lecture explicite via `samba-tool dns query`
     * et NON `gethostbynamel()` (legacy) : le résolveur peut servir une
     * réponse en cache — un cache négatif provoquerait un `add` fantôme à
     * chaque renouvellement, ruinant exactement l'idempotence visée.
     *
     * Un nom absent fait sortir `samba-tool` en non-zéro (« Record or zone
     * does not exist ») : c'est l'état « aucun record », pas une erreur.
     *
     * **Toute AUTRE sortie non-zéro (DC injoignable, timeout, auth refusée)
     * est un état ILLISIBLE, jamais « aucun record »** : la confondre avec un
     * état vide ferait tenter un `add` sur un nom déjà correct — soit un
     * échec logué toutes les 5 minutes, soit un second A record et une
     * résolution en round-robin. Un état illisible doit faire abandonner.
     *
     * @return list<string>
     *
     * @throws \RuntimeException si l'état DNS est illisible.
     */
    private function queryAddresses(string $server, string $zone, string $name): array
    {
        $result = $this->runner->withoutDirectoryUrl()->run(['dns', 'query', $server, $zone, $name, 'A']);

        if (! $result->successful()) {
            if ($this->meansNoSuchRecord($result->errorOutput() . "\n" . $result->output())) {
                return [];
            }

            throw new \RuntimeException(sprintf(
                'Lecture DNS impossible pour %s (exit=%d) : %s',
                $name,
                $result->exitCode() ?? -1,
                trim($result->errorOutput() ?: $result->output()),
            ));
        }

        return $this->parseAddresses($result->output());
    }

    /**
     * Distingue « le nom n'existe pas » (état vide légitime) d'une panne.
     * samba-tool rend le message en clair et/ou le code d'erreur Windows.
     */
    private function meansNoSuchRecord(string $output): bool
    {
        foreach (self::NO_SUCH_RECORD_MARKERS as $marker) {
            if (stripos($output, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Noms de la zone dont un A record pointe vers `$ip`.
     *
     * `samba-tool dns query <server> <zone> @ A` liste la zone entière sous la
     * forme d'un bloc `Name=<nom>,…` suivi de ses lignes `A: <ip> (…)`.
     *
     * @return list<string>
     */
    private function namesHoldingAddress(string $server, string $zone, string $ip): array
    {
        $result = $this->runner->withoutDirectoryUrl()->run(['dns', 'query', $server, $zone, '@', 'A']);

        if (! $result->successful()) {
            return [];
        }

        $names = [];
        $currentName = null;

        foreach (preg_split('/\R/', $result->output()) ?: [] as $line) {
            if (preg_match('/^\s*Name=([^,]*),/', $line, $m) === 1) {
                $currentName = trim($m[1]);

                continue;
            }

            if ($currentName === null || $currentName === '') {
                continue;
            }

            if (preg_match('/^\s*A:\s*([0-9.]+)\b/', $line, $m) === 1 && $m[1] === $ip) {
                $names[] = $currentName;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Extrait les IPv4 des lignes `A: <ip> (flags=…, serial=…, ttl=…)`.
     *
     * @return list<string>
     */
    private function parseAddresses(string $output): array
    {
        preg_match_all('/^\s*A:\s*([0-9]{1,3}(?:\.[0-9]{1,3}){3})\b/m', $output, $matches);

        return array_values(array_unique(array_filter(
            $matches[1] ?? [],
            fn (string $address): bool => $this->isValidIpv4($address),
        )));
    }

    /**
     * Exécute une commande d'écriture. Un échec est logué et propagé en
     * exception pour que l'appelant sorte en `FAILED` — on ne veut pas
     * rapporter `CREATED` sur une commande qui a échoué.
     *
     * @param  list<string>  $args
     */
    private function write(array $args): void
    {
        $result = $this->runner->withoutDirectoryUrl()->run($args);

        if (! $result->successful()) {
            throw new \RuntimeException(sprintf(
                'samba-tool %s a échoué (exit=%d) : %s',
                implode(' ', array_slice($args, 0, 2)),
                $result->exitCode() ?? -1,
                trim($result->errorOutput() ?: $result->output()),
            ));
        }
    }

    /**
     * Normalise et valide un nom. Retourne null si le nom est vide (cas
     * release/expiry) ou s'il ne survit pas à la validation anti-injection.
     */
    private function normalizeName(string $name): ?string
    {
        $sanitized = $this->sanitizer->sanitize($name);

        if ($sanitized === '') {
            return null;
        }

        // Le nom vient d'un bail DHCP : n'importe quel client du LAN choisit
        // son `client-hostname`. La validation stricte est donc obligatoire
        // avant tout passage à samba-tool.
        return $this->sanitizer->isValidHostname($sanitized) ? $sanitized : null;
    }

    /**
     * Garde-fous de parité legacy : préfixes éphémères écartés et, sur une
     * instance rattachée à un établissement, noms hors établissement ignorés
     * (`samba-tool.inc.php:1251` — un serveur central voit passer les baux
     * d'autres établissements).
     */
    private function isEligible(string $name): bool
    {
        foreach (self::IGNORED_NAME_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return false;
            }
        }

        $suffix = $this->establishmentSuffix();

        return $suffix === '' || str_contains($name, $suffix);
    }

    /**
     * Suffixe d'établissement — port de `etab_suffix()`
     * (`sambaedu/includes/config.inc.php:199`) : UAI `0991229y` → `-1229y`.
     * Vide si l'instance n'est pas rattachée (cas /vm localdev) : aucun
     * filtrage alors, parité legacy (`empty($config['etab_ou'])`).
     *
     * Source = `SambaEduConfig` (donc `/etc/sambaedu/sambaedu.conf`, comme le
     * legacy) et NON `config('sambaedu.etab_ou')` : cette clé ne vient que de
     * `env('ETAB_OU')`, absente du `.env.example` ET de la table de backfill
     * du LdapRecordServiceProvider — elle serait vide sur toute instance
     * réelle, désactivant silencieusement le filtre sur un serveur central.
     */
    private function establishmentSuffix(): string
    {
        $uai = (string) $this->config->get('etab_ou', '');

        return preg_match('/^[0-9]{7}[a-z]$/i', $uai) === 1
            ? strtolower('-' . substr($uai, 3))
            : '';
    }

    /**
     * Zone DNS = domaine AD.
     */
    private function zone(): string
    {
        return (string) config('sambaedu.domain', '');
    }

    /**
     * Serveur DNS ciblé — le **FQDN** du DC, jamais une IP : samba-tool
     * s'authentifie en GSSAPI et une IP casse la canonicalisation SASL
     * (`project_ipxe_boot500_sasl_nocanon`). Même résolution que
     * `AdministratorKerberosContext::sysvolHost()`.
     */
    private function dnsServer(): string
    {
        $name = (string) config('sambaedu.se4ad_name', '');

        if ($name !== '') {
            return $name;
        }

        try {
            return $this->config->ldap()->getHosts()[0] ?? '';
        } catch (Throwable) {
            return '';
        }
    }

    private function isValidIpv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
}
