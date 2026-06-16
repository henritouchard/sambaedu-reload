<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\Printer;
use App\Models\WorkstationGroup;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Services\Print\CupsPrinterService;
use App\Services\Print\Exceptions\CupsDaemonDownException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Type `printers` (contrat §7, identifiant DÉJÀ figé — NFR12) — projection en
 * lecture seule du catalogue d'imprimantes rattachées aux mailles POSTE du
 * poste (pivot `printer_workstation_group`) vers des candidats d'état
 * (Story 27.2, AC1/AC2/AC3).
 *
 * **Lecture Postgres + CUPS PURE** (NFR7, critère Keycloak) : le provider lit
 * le pivot `printer_workstation_group` restreint aux `physicalGroupIds` +
 * `logicalGroupIds` du {@see TargetContext} (mailles POSTE — l'imprimante est
 * une ressource de POSTE, Vérité #9 « l'imprimante de la salle » ; il n'existe
 * AUCUNE relation `UserGroup → Printer`). `CupsPrinterService` est lu
 * UNIQUEMENT pour la métadonnée (description/location) — JAMAIS d'écriture CUPS,
 * JAMAIS l'URI back-end live (décision Henri n° 4). Aucun appel
 * AD/LdapRecord/APCu.
 *
 * **Connexion logique** (décision Henri n° 4) : le payload porte une connexion
 * Samba STABLE `\\<se4fs>\<cups_name>` (partage imprimante), jamais l'URI CUPS
 * (`socket://…`, `ipp://…`). Le token `<se4fs>` est substitué LOCALEMENT par
 * l'agent (iso 27.1) — aucun couplage runtime CUPS, aucune fuite réseau au
 * calcul.
 *
 * **Sémantique `aggregate`** (union) AVEC un sous-item `exclusive` interne — le
 * drapeau de payload `is_default` (décision Henri n° 5). Un poste reçoit l'union
 * des imprimantes de toutes ses mailles, mais **UN SEUL** item porte
 * `is_default: true` : le défaut est réglé EXPLICITEMENT par l'admin sur
 * l'attachement imprimante↔WG (colonne pivot `is_default`), et l'unicité est
 * résolue ICI côté serveur — parmi les WG porteurs d'un défaut applicables au
 * poste, le **WG physique l'emporte sur le logique**, départage déterministe
 * `cups_name` asc. C'est un drapeau de PAYLOAD, PAS une nouvelle sémantique de
 * type ; l'agent applique bêtement le `is_default` reçu (il ne recalcule jamais
 * la spécificité).
 *
 * Le provider étiquette ses candidats BRUTS par maille (PhysicalGroup /
 * LogicalGroup) — zéro précédence, zéro tri (D2 = compilateur), à l'unique
 * exception du calcul du défaut exclusif (décision n° 5). La dédup par contenu
 * (même imprimante au parc ET à la salle) vit dans le `StateCompiler` SEUL.
 *
 * Payload v1 : `{cups_name, connection, description, location, is_default}` —
 * toujours des strings/bool, jamais de float (§4.1).
 */
final class PrintersStateProvider implements StateProvider
{
    public function __construct(
        private readonly CupsPrinterService $cups,
    ) {}

    public function type(): string
    {
        return Printer::TYPE_PRINTERS;
    }

    public function semantics(): ResourceSemantics
    {
        return ResourceSemantics::Aggregate;
    }

    public function scope(): StateScope
    {
        return StateScope::Session;
    }

    /**
     * Un candidat PAR (imprimante × maille POSTE applicable). La métadonnée CUPS
     * (description/location) est lue une fois par nom d'imprimante (cache local)
     * — CUPS injoignable à la compilation = métadonnée vide (le provider ne
     * casse pas l'état ; l'isolation des erreurs d'`apply` vit côté agent).
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        $wgIds = $ctx->workstationGroupIds();
        if ($wgIds === []) {
            return collect();
        }

        // Lecture du pivot restreint aux mailles POSTE — `is_default` chargé via
        // withPivot (cf. Printer::workstationGroups()). Eager-load des groupes
        // (is_physical) pour étiqueter la maille sans re-requête.
        $groups = WorkstationGroup::query()
            ->whereIn('workstation_groups.id', $wgIds)
            ->with('printers')
            ->get();

        // Aplatissement (imprimante, WG) — un candidat par couple. Une même
        // imprimante rattachée à 2 WG du poste produit 2 candidats (le
        // compilateur dédoublonne par contenu — payload identique = un seul item
        // côté agent).
        /** @var list<array{printer: Printer, group: WorkstationGroup}> $pairs */
        $pairs = [];
        foreach ($groups as $group) {
            foreach ($group->printers as $printer) {
                $pairs[] = ['printer' => $printer, 'group' => $group];
            }
        }

        if ($pairs === []) {
            return collect();
        }

        $defaultName = $this->resolveDefaultCupsName($pairs);

        // Rang déterministe et SANS COLLISION par nom CUPS (ordre alpha stable).
        // Le `sourceId` aggregate doit être unique par (group, cups_name) ET
        // ordonner de façon stable (l'ordre serveur fait foi pour `AggregateHash`
        // /ETag) ; un hash court (crc32 % 1e9) pouvait collisionner entre deux
        // noms distincts du même groupe → `usort` non stable → ETag instable.
        $names = array_values(array_unique(array_map(
            static fn (array $p): string => (string) $p['printer']->cups_name,
            $pairs,
        )));
        sort($names, SORT_STRING);
        /** @var array<string, int> $nameRank */
        $nameRank = array_flip($names);

        // Cache métadonnée CUPS par nom (un appel getPrinter par imprimante
        // DISTINCTE, jamais N par maille).
        $metaCache = [];

        $candidates = [];
        foreach ($pairs as $pair) {
            $printer = $pair['printer'];
            $group = $pair['group'];
            $cupsName = (string) $printer->cups_name;

            if (! array_key_exists($cupsName, $metaCache)) {
                $metaCache[$cupsName] = $this->metadataFor($cupsName);
            }
            $meta = $metaCache[$cupsName];

            $candidates[] = new StateCandidate(
                maille: $group->is_physical === true
                    ? StateMaille::PhysicalGroup
                    : StateMaille::LogicalGroup,
                payload: [
                    'cups_name' => $cupsName,
                    // Connexion LOGIQUE (décision n° 4) : partage Samba
                    // imprimante, jamais l'URI back-end CUPS. `<se4fs>`
                    // substitué localement par l'agent.
                    'connection' => '\\\\<se4fs>\\'.$cupsName,
                    'description' => (string) ($meta['description'] ?? ''),
                    'location' => (string) ($meta['location'] ?? ''),
                    // Sous-item exclusive : UN SEUL true dans la collection.
                    'is_default' => $cupsName === $defaultName,
                ],
                updatedAt: $printer->updated_at,
                // sourceId : le pivot n'a pas d'id auto-incr ; on dérive un
                // ordre stable et SANS COLLISION depuis (group_id, rang du nom).
                // L'ordre aggregate final est trié par sourceId asc au
                // compilateur, et la dédup garde le premier — ici on veut un
                // ordre DÉTERMINISTE et injectif, pas signifiant.
                sourceId: $this->stableSourceId($group, $nameRank[$cupsName]),
            );
        }

        return collect($candidates);
    }

    /**
     * Résout l'UNIQUE imprimante par défaut du poste (décision n° 5) : parmi les
     * couples (imprimante, WG) porteurs d'un `is_default=true` au pivot, le **WG
     * physique l'emporte sur le logique** ; à spécificité égale, départage
     * déterministe `cups_name` asc. Retourne le `cups_name` gagnant, ou null si
     * aucun défaut réglé.
     *
     * Résolu ICI (pas au compilateur générique) car l'unicité dépend d'un
     * drapeau de payload propre à ce type (le compilateur aggregate ne connaît
     * pas `is_default`). C'est l'exception admise à « zéro précédence côté
     * provider » : le sous-item exclusive est explicitement de la responsabilité
     * du provider (décisions n° 4/5).
     *
     * @param  list<array{printer: Printer, group: WorkstationGroup}>  $pairs
     */
    private function resolveDefaultCupsName(array $pairs): ?string
    {
        /** @var array{0: int, 1: string}|null $best */
        $best = null; // [rang, cups_name] — rang 0 = physique (gagne), 1 = logique

        foreach ($pairs as $pair) {
            $printer = $pair['printer'];
            $group = $pair['group'];

            // `is_default` lu sur le pivot (withPivot). Cast strict : la valeur
            // SQLite/PG peut arriver en 0/1/"0"/"1" — (bool)(int) couvre tout.
            $isDefault = (bool) (int) ($printer->pivot->is_default ?? 0);
            if (! $isDefault) {
                continue;
            }

            $rank = $group->is_physical === true ? 0 : 1;
            $cupsName = (string) $printer->cups_name;

            if ($best === null
                || $rank < $best[0]
                || ($rank === $best[0] && strcmp($cupsName, $best[1]) < 0)
            ) {
                $best = [$rank, $cupsName];
            }
        }

        return $best[1] ?? null;
    }

    /**
     * Métadonnée CUPS (description/location) en LECTURE SEULE — jamais l'URI
     * back-end, jamais d'écriture. CUPS injoignable (daemon down) = métadonnée
     * vide : l'état reste compilable (l'imprimante est toujours servie par sa
     * connexion logique stable), l'erreur réelle d'installation est isolée côté
     * agent (AC4). Imprimante absente de CUPS (orphan SER) = vide aussi.
     *
     * @return array{description: ?string, location: ?string}
     */
    private function metadataFor(string $cupsName): array
    {
        try {
            $printer = $this->cups->getPrinter($cupsName);
        } catch (CupsDaemonDownException $e) {
            Log::channel('agent')->info('[PrintersStateProvider] CUPS injoignable — métadonnée vide', [
                'action_type' => 'agent.printers.cups_down',
                'cups_name' => $cupsName,
            ]);

            return ['description' => null, 'location' => null];
        }

        return [
            'description' => $printer['description'] ?? null,
            'location' => $printer['location'] ?? null,
        ];
    }

    /**
     * Id source stable, déterministe et INJECTIF pour l'ordre aggregate. Le pivot
     * n'ayant pas d'id auto-incrémenté, on compose un entier à partir de l'id du
     * groupe et du RANG ALPHA du nom CUPS (unique par nom). L'ordre final n'a pas
     * de signification métier (aggregate = union), seul son déterminisme compte
     * (même état = même hash/ETag, contrat). Le rang évite toute collision (deux
     * noms distincts du même groupe ⇒ deux sourceId distincts), garantissant un
     * tri stable au compilateur.
     */
    private function stableSourceId(WorkstationGroup $group, int $nameRank): int
    {
        return ((int) $group->id) * 1_000_000_000 + $nameRank;
    }
}
