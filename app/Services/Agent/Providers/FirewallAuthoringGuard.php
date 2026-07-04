<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

/**
 * Story 36.2 (AC3) — garde-fou d'AUTHORING des projections `windows/firewall` :
 * refuse à la SOURCE les règles que l'agent refuserait (défense en profondeur,
 * le serveur peut avoir tort mais ne doit JAMAIS produire un catalogue
 * dangereux). Jumeau de {@see FsAclAuthoringGuard} — même API (violations
 * NOMMÉES, service PUR sans requête/écriture, constantes publiques réutilisables
 * par le futur formulaire).
 *
 * **Décision Henri Q3 — le cœur de sécurité.** « Couper Internet » ne doit
 * JAMAIS couper le poste de son SERVEUR : un `action: block` couvrant le réseau
 * local (RFC1918) ou tout (`/0`) est REFUSÉ. Le calcul est une INTERSECTION
 * MATHÉMATIQUE d'intervalles IPv4/IPv6 (leçon review 36.1 #3 — jamais un match
 * textuel : `192.160.0.0/12` recouvre 192.168/16 sans jamais l'écrire,
 * `0.0.0.0/0` et `::/0` couvrent tout). `remote_scope: internet` est SÛRE par
 * construction (les plages émises par le handler EXCLUENT tout ça) — c'est
 * l'usage nominal. L'échappatoire assumée = `remote_scope: explicit` avec des
 * adresses/CIDR PUBLICS uniquement.
 *
 * **Alignement serveur↔agent (leçon review 36.1 #4).** {@see PROTECTED_RANGES}
 * est le MIROIR EXACT des plages protégées de l'agent Go
 * (`firewallProtectedRanges`, `agent/shared/handler_firewall.go`). L'autorité
 * finale reste l'agent (qui refuse aussi dans `Test` ET `Apply`) ; le serveur
 * refuse en amont pour ne jamais servir un catalogue dangereux.
 *
 * **Ce qu'il refuse** (au-delà de Q3, D12) : enums hors domaine ; `rule_id` hors
 * slug ; `remote_scope: explicit` sans `remote_addresses` (ou vide, ou entrée
 * non parsable — mot-clé Windows, plage `a-b`, chaîne arbitraire) ;
 * `remote_addresses` présent avec `remote_scope: internet` (forme unique) ;
 * `ports` avec `protocol: any` ; port hors 1-65535 ou borne inversée ; toute
 * projection portant AU MOINS une règle `action: block` sans `warning` non vide.
 *
 * **Pas de ciblage par utilisateur (Q4).** Le mécanisme `firewall` est de portée
 * MACHINE : « couper Internet » se cible par parc/salle (un override
 * UserGroup/User est structurellement SANS EFFET) — pas un garde-fou runtime, un
 * fait de compilation.
 *
 * **Angle mort assumé v1 — `action: allow` N'EST PAS validé.** Seul `block` est
 * soumis au refus Q3 (couper le LAN) ; une règle `allow` (rouvrir une portée)
 * n'est aujourd'hui contrainte par AUCUN garde-fou de politique. C'est une
 * décision de politique EN ATTENTE (question ouverte utilisateur) — tracée ici
 * comme angle mort connu, pas comme oubli.
 */
final class FirewallAuthoringGuard
{
    /** Directions admises (D3). */
    public const DIRECTIONS = ['in', 'out'];

    /** Actions admises (D3). */
    public const ACTIONS = ['allow', 'block'];

    /** Portées distantes admises (D3). */
    public const REMOTE_SCOPES = ['internet', 'explicit'];

    /** Protocoles admis (D3). */
    public const PROTOCOLS = ['any', 'tcp', 'udp'];

    /** Verbe de convergence (D3, TOUJOURS explicite côté payload — piège #2/#13). */
    public const ENSURE = ['present', 'absent'];

    /** Slug d'identité de règle (identité GLOBALE inter-capacités, piège #10). */
    public const RULE_ID = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /**
     * Plages PROTÉGÉES sur lesquelles un `action: block` est REFUSÉ (Q3, D5) —
     * RFC1918 + loopback + link-local + ULA, IPv4 ET IPv6. MIROIR EXACT du Go
     * (`firewallProtectedRanges`). Un préfixe `/0` (`0.0.0.0/0`, `::/0`) recouvre
     * n'importe laquelle de ces plages → refusé par l'intersection, sans cas
     * spécial. NB : le calcul est un CHEVAUCHEMENT d'intervalles, jamais un match
     * de chaîne (piège #7).
     *
     * @var list<string>
     */
    public const PROTECTED_RANGES = [
        // IPv4
        '10.0.0.0/8',        // RFC1918
        '172.16.0.0/12',     // RFC1918
        '192.168.0.0/16',    // RFC1918
        '127.0.0.0/8',       // loopback
        '169.254.0.0/16',    // link-local
        // IPv6
        '::1/128',           // loopback
        'fe80::/10',         // link-local
        'fc00::/7',          // ULA
    ];

    /**
     * Valide un ensemble de projections d'authoring `firewall`.
     *
     * @param  list<array{capability:string, warning:?string, spec:mixed}>  $projections
     *         une entrée par projection windows/firewall : `capability` = key
     *         lisible (messages), `warning` = message d'implications de la
     *         capacité (règle « block ⇒ warning non vide »), `spec` =
     *         `{"rules": […]}` décodé.
     * @return list<string> violations lisibles (vide = authoring valide)
     */
    public function violations(array $projections): array
    {
        $violations = [];

        foreach ($projections as $projection) {
            $capability = (string) ($projection['capability'] ?? '?');
            $warning = $projection['warning'] ?? null;
            $hasBlock = false;

            foreach ($this->rules($projection['spec'] ?? null) as $rule) {
                $ruleId = (string) ($rule['rule_id'] ?? '');
                $direction = strtolower(trim((string) ($rule['direction'] ?? '')));
                $action = strtolower(trim((string) ($rule['action'] ?? '')));
                $remoteScope = strtolower(trim((string) ($rule['remote_scope'] ?? '')));
                $protocol = strtolower(trim((string) ($rule['protocol'] ?? '')));

                // Slug d'identité.
                if (! preg_match(self::RULE_ID, $ruleId)) {
                    $violations[] = sprintf("firewall [%s] : rule_id '%s' hors slug (^[a-z0-9][a-z0-9_-]{0,63}$).", $capability, $ruleId);
                }

                // Enums bornés (D3).
                if (! in_array($direction, self::DIRECTIONS, true)) {
                    $violations[] = sprintf("firewall [%s] règle '%s' : direction '%s' hors domaine (in|out).", $capability, $ruleId, $direction);
                }
                if (! in_array($action, self::ACTIONS, true)) {
                    $violations[] = sprintf("firewall [%s] règle '%s' : action '%s' hors domaine (allow|block).", $capability, $ruleId, $action);
                }
                if (! in_array($remoteScope, self::REMOTE_SCOPES, true)) {
                    $violations[] = sprintf("firewall [%s] règle '%s' : remote_scope '%s' hors domaine (internet|explicit).", $capability, $ruleId, $remoteScope);
                }
                if (! in_array($protocol, self::PROTOCOLS, true)) {
                    $violations[] = sprintf("firewall [%s] règle '%s' : protocol '%s' hors domaine (any|tcp|udp).", $capability, $ruleId, $protocol);
                }
                $rawEnsure = $rule['ensure'] ?? null;
                if (is_array($rawEnsure) && array_is_list($rawEnsure)) {
                    // Une LISTE n'est ni un littéral ni une map valeur-capacité
                    // (corr. review #5) : forme d'authoring malformée refusée
                    // EXPLICITEMENT (sinon elle passait en silence — aucune valeur
                    // n'était validée, fail-closed en aval mais erreur masquée).
                    $violations[] = sprintf("firewall [%s] règle '%s' : forme `ensure` inattendue (ni littéral ni map valeur-capacité).", $capability, $ruleId);
                } else {
                    foreach ($this->ensureValues($rawEnsure) as $ensure) {
                        if (! in_array(strtolower(trim((string) $ensure)), self::ENSURE, true)) {
                            $violations[] = sprintf("firewall [%s] règle '%s' : ensure '%s' hors domaine (present|absent).", $capability, $ruleId, (string) $ensure);
                        }
                    }
                }

                if ($action === 'block') {
                    $hasBlock = true;
                }

                $addresses = $this->stringList($rule['remote_addresses'] ?? null);

                // Cohérence conditionnelle de `remote_scope`.
                if ($remoteScope === 'explicit') {
                    if ($addresses === []) {
                        $violations[] = sprintf("firewall [%s] règle '%s' : remote_scope 'explicit' exige au moins une adresse dans remote_addresses.", $capability, $ruleId);
                    }
                    foreach ($addresses as $addr) {
                        $range = $this->parseRange($addr);
                        if ($range === null) {
                            $violations[] = sprintf("firewall [%s] règle '%s' : adresse '%s' non parsable (attendu : IP ou CIDR IPv4/IPv6, jamais un mot-clé Windows ni une plage a-b).", $capability, $ruleId, $addr);

                            continue;
                        }
                        // Q3 : un `block explicit` chevauchant une plage protégée
                        // est REFUSÉ (intersection mathématique, piège #7).
                        if ($action === 'block' && $this->overlapsProtected($range)) {
                            $violations[] = sprintf(
                                "firewall [%s] règle '%s' : action 'block' sur '%s' chevauche une plage protégée (RFC1918/loopback/link-local/ULA ou /0) — couper le réseau local du serveur est INTERDIT (Q3). Utiliser remote_scope 'internet' ou des adresses publiques uniquement.",
                                $capability,
                                $ruleId,
                                $addr,
                            );
                        }
                    }
                } elseif ($addresses !== []) {
                    $violations[] = sprintf("firewall [%s] règle '%s' : remote_addresses n'est admis qu'avec remote_scope 'explicit' (internet = plages figées côté handler).", $capability, $ruleId);
                }

                // Cohérence conditionnelle de `ports`.
                $ports = $this->stringList($rule['ports'] ?? null);
                if ($ports !== []) {
                    if ($protocol === 'any') {
                        $violations[] = sprintf("firewall [%s] règle '%s' : ports interdits avec protocol 'any' (préciser tcp|udp).", $capability, $ruleId);
                    }
                    foreach ($ports as $port) {
                        if (! $this->isValidPort($port)) {
                            $violations[] = sprintf("firewall [%s] règle '%s' : port '%s' invalide (attendu N ou N-M, 1-65535, N ≤ M).", $capability, $ruleId, $port);
                        }
                    }
                }
            }

            // Block ⇒ warning non vide (AC3, miroir deny⇒warning de 36.1).
            if ($hasBlock && trim((string) ($warning ?? '')) === '') {
                $violations[] = sprintf(
                    "firewall [%s] : au moins une règle `block` sans `warning` non vide — l'implication (connectivité coupée) doit être confirmée.",
                    $capability,
                );
            }
        }

        return $violations;
    }

    /**
     * Entrées `rules[]` d'une `spec` (défensif : spec inattendue = liste vide).
     *
     * @return list<array<string,mixed>>
     */
    private function rules(mixed $spec): array
    {
        if (! is_array($spec) || ! isset($spec['rules']) || ! is_array($spec['rules'])) {
            return [];
        }

        return array_values(array_filter($spec['rules'], 'is_array'));
    }

    /**
     * Valeurs possibles d'un champ `ensure` : littéral (1) OU chaque valeur d'une
     * map valeur-capacité. Absent ⇒ aucune valeur à valider (défaut `present`).
     * Le cas LISTE (ni littéral ni map) est traité EN AMONT par {@see violations()}
     * comme une violation explicite (corr. review #5) — la branche `array_is_list`
     * ci-dessous reste un garde défensif au cas où ce helper serait réutilisé.
     *
     * @return list<mixed>
     */
    private function ensureValues(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (is_array($raw)) {
            if (array_is_list($raw)) {
                return [];
            }

            return array_values(array_filter($raw, static fn ($v): bool => is_scalar($v)));
        }

        return [$raw];
    }

    /**
     * Normalise une valeur en liste de strings non vides (les autres formes ⇒ []).
     *
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $v) {
            if (! is_scalar($v)) {
                continue;
            }
            $s = trim((string) $v);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * Un port `"N"` ou `"N-M"` est-il valide (1-65535, N ≤ M) ?
     */
    private function isValidPort(string $port): bool
    {
        $port = trim($port);
        if (preg_match('/^(\d{1,5})-(\d{1,5})$/', $port, $m)) {
            $lo = (int) $m[1];
            $hi = (int) $m[2];

            return $lo >= 1 && $hi <= 65535 && $lo <= $hi;
        }
        if (preg_match('/^\d{1,5}$/', $port)) {
            $n = (int) $port;

            return $n >= 1 && $n <= 65535;
        }

        return false;
    }

    // ── Intersection d'intervalles IPv4/IPv6 (Q3, piège #7) ──────────────────

    /**
     * Parse une IP littérale OU un CIDR `addr/prefix` (IPv4 ou IPv6) en intervalle
     * `[family, lo, hi]` (bornes = chaînes binaires `inet_pton`, même longueur =
     * comparaison octet-par-octet non signée). Une plage `a-b`, un mot-clé Windows
     * (`LocalSubnet`), une chaîne arbitraire ⇒ `null` (non parsable). AUCUNE
     * validation ne dépend d'un match textuel — tout passe par le numérique.
     *
     * @return array{0:int,1:string,2:string}|null [family(4|6), lo, hi]
     */
    private function parseRange(string $spec): ?array
    {
        $spec = trim($spec);
        $prefix = null;
        $addr = $spec;
        if (str_contains($spec, '/')) {
            [$addr, $p] = explode('/', $spec, 2);
            if ($p === '' || ! ctype_digit($p)) {
                return null;
            }
            $prefix = (int) $p;
        }

        $packed = @inet_pton(trim($addr));
        if ($packed === false) {
            return null;
        }
        $bits = strlen($packed) * 8; // 32 (IPv4) | 128 (IPv6)
        $family = $bits === 32 ? 4 : 6;
        if ($prefix === null) {
            $prefix = $bits;
        }
        if ($prefix < 0 || $prefix > $bits) {
            return null;
        }

        $lo = $this->applyMask($packed, $prefix, false);
        $hi = $this->applyMask($packed, $prefix, true);

        return [$family, $lo, $hi];
    }

    /**
     * Applique un masque de préfixe à une adresse binaire : borne BASSE (bits
     * hôte à 0) ou HAUTE (bits hôte à 1).
     */
    private function applyMask(string $packed, int $prefix, bool $high): string
    {
        $bytes = array_values(unpack('C*', $packed));
        $len = count($bytes);
        for ($i = 0; $i < $len; $i++) {
            $bitStart = $i * 8;
            $maskByte = 0;
            for ($b = 0; $b < 8; $b++) {
                if ($bitStart + $b < $prefix) {
                    $maskByte |= (1 << (7 - $b));
                }
            }
            if ($high) {
                // bits hôte (hors masque) à 1.
                $bytes[$i] = ($bytes[$i] & $maskByte) | (~$maskByte & 0xFF);
            } else {
                // bits hôte à 0.
                $bytes[$i] = $bytes[$i] & $maskByte;
            }
        }

        return pack('C*', ...$bytes);
    }

    /**
     * L'intervalle chevauche-t-il AU MOINS une plage protégée de MÊME famille ?
     * Deux intervalles `[a1,a2]` et `[b1,b2]` se chevauchent ssi
     * `a1 ≤ b2` ET `b1 ≤ a2` (comparaison octet-par-octet non signée =
     * `strcmp` sur des chaînes binaires de même longueur).
     *
     * @param  array{0:int,1:string,2:string}  $range
     */
    private function overlapsProtected(array $range): bool
    {
        [$family, $lo, $hi] = $range;
        foreach (self::PROTECTED_RANGES as $protected) {
            $p = $this->parseRange($protected);
            if ($p === null || $p[0] !== $family) {
                continue;
            }
            [, $plo, $phi] = $p;
            if (strcmp($lo, $phi) <= 0 && strcmp($plo, $hi) <= 0) {
                return true;
            }
        }

        return false;
    }
}
