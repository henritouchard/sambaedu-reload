<?php

declare(strict_types=1);

namespace App\Services\Filesystem;

use App\Exceptions\Filesystem\NetworkShareLetterCollisionException;
use App\Models\NetworkShare;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Services\Agent\Providers\DrivesStateProvider;
use Illuminate\Support\Facades\DB;

/**
 * Story 34.2 — Validation PRÉDICTIVE des lecteurs réseau gérés (T5, AC4).
 *
 * Service de **PURE LECTURE** calqué sur {@see \App\Services\ControlHub\Resolution\UpstreamLockCollisionDetector}
 * (story 30.5) : il PRÉDIT, AVANT écriture/provision, les deux pièges connus
 * (34.1) — il n'écrit RIEN, n'émet AUCUN candidat, n'introduit AUCUNE précédence
 * (D2 reste confiné au `StateCompiler`). Il LIT `network_shares` + le pivot
 * `network_share_assignables` (Postgres) et la liste figée des lettres réservées
 * {@see DrivesStateProvider::RESERVED_LETTERS} (foyer canonique unique, Q4).
 *
 * Trois règles :
 *  - **(a) WG-montage-seul** ({@see warnings()}) : un répertoire assigné UNIQUEMENT
 *    à des parcs (`WorkstationGroup`), sans aucun grant `User`/`UserGroup`, rend la
 *    lettre VISIBLE mais ne contribue AUCUNE ACL POSIX (invariant 34.1 :
 *    `buildAcls` ignore les WG). Warning NON bloquant (le montage-seul reste un
 *    usage légitime). Finding M5 / piège #1.
 *  - **(b) collision de lettre** ({@see letterCollisions()} / {@see assertNoLetterCollision()}) :
 *    deux répertoires DISTINCTS à lettre EXPLICITE identique pour une audience qui
 *    se recouvre. Erreur BLOQUANTE. Finding M1 / piège #3.
 *  - **(c) lettre réservée** ({@see isReservedLetter()}) : une lettre explicite ∈
 *    K/H/I/L/A-D écraserait un lecteur fixe (home K:, classes H:) ou un disque
 *    local. Erreur (attrapée à la saisie, AC2 ; re-confirmée ici defense-in-depth).
 */
class NetworkShareValidator
{
    /**
     * Lettres réservées, lues depuis le foyer canonique du provider (source unique
     * — non-régression testée : AC6). Caractères nus majuscules (`K`, `H`, …).
     *
     * @return list<string>
     */
    public function reservedLetters(): array
    {
        return DrivesStateProvider::RESERVED_LETTERS;
    }

    /**
     * `true` si la lettre explicite (`'K:'`, `'k'`, ` H `) tombe sur une lettre
     * RÉSERVÉE par le système. `null`/vide → `false` (= attribution auto, sûre).
     */
    public function isReservedLetter(?string $letter): bool
    {
        $bare = $this->bareLetter($letter);

        return $bare !== null && in_array($bare, $this->reservedLetters(), true);
    }

    /**
     * Avertissements NON bloquants pour un répertoire (règle (a)). Liste vide si
     * tout va bien.
     *
     * @return list<string>
     */
    public function warnings(NetworkShare $share): array
    {
        $warnings = [];

        if ($share->id !== null && $this->isWorkstationGroupMountOnly((int) $share->id)) {
            $warnings[] = "Ce répertoire n'est assigné qu'à des parcs (postes) : "
                .'la lettre sera VISIBLE sur ces postes, mais aucun accès réel ne sera accordé. '
                .'Ajoutez un utilisateur ou un groupe d\'utilisateurs pour ouvrir l\'accès en lecture ou écriture.';
        }

        return $warnings;
    }

    /**
     * Collisions de lettre prédites pour `$share` (règle (b)). Raisonne sur
     * `$share->letter` EN MÉMOIRE (la lettre que l'on s'apprête à enregistrer) et
     * sur l'audience PERSISTÉE du pivot — on peut donc valider AVANT de sauver la
     * nouvelle lettre. Une lettre vide/auto ⇒ aucune collision figée prédictible
     * (l'auto-assignation du provider gère le reste).
     *
     * @return list<array{letter:string,shareId:int,shareName:string,otherId:int,otherName:string,sharedCount:int}>
     */
    public function letterCollisions(NetworkShare $share): array
    {
        $bare = $this->bareLetter($share->letter);
        if ($bare === null) {
            return [];
        }

        $myAudience = $share->id !== null ? $this->audienceKeys((int) $share->id) : [];
        if ($myAudience === []) {
            // Sans audience, aucun poste/session ne verrait les deux lettres
            // ensemble : pas de collision possible.
            return [];
        }

        $others = NetworkShare::query()
            ->whereNotNull('letter')
            ->when($share->id !== null, fn ($q) => $q->where('id', '!=', $share->id))
            ->get(['id', 'name', 'letter']);

        $collisions = [];
        foreach ($others as $other) {
            if ($this->bareLetter($other->letter) !== $bare) {
                continue;
            }
            $shared = array_intersect($myAudience, $this->audienceKeys((int) $other->id));
            if ($shared === []) {
                continue;
            }
            $collisions[] = [
                'letter' => $bare.':',
                'shareId' => (int) $share->id,
                'shareName' => (string) $share->name,
                'otherId' => (int) $other->id,
                'otherName' => (string) $other->name,
                'sharedCount' => count($shared),
            ];
        }

        return $collisions;
    }

    /**
     * Lève {@see NetworkShareLetterCollisionException} si une collision de lettre
     * est prédite (règle (b)). No-op sinon.
     */
    public function assertNoLetterCollision(NetworkShare $share): void
    {
        $collisions = $this->letterCollisions($share);
        if ($collisions !== []) {
            throw NetworkShareLetterCollisionException::fromCollisions($collisions);
        }
    }

    // =========================================================================
    // Helpers de lecture (Postgres, zéro écriture)
    // =========================================================================

    /**
     * `true` si le répertoire a ≥ 1 assignation `WorkstationGroup` ET 0 grant
     * `User`/`UserGroup`.
     */
    private function isWorkstationGroupMountOnly(int $shareId): bool
    {
        $counts = DB::table('network_share_assignables')
            ->where('network_share_id', $shareId)
            ->selectRaw('assignable_type as type, COUNT(*) as c')
            ->groupBy('assignable_type')
            ->pluck('c', 'type');

        $wg = (int) ($counts[WorkstationGroup::class] ?? 0);
        $grants = (int) ($counts[User::class] ?? 0) + (int) ($counts[UserGroup::class] ?? 0);

        return $wg > 0 && $grants === 0;
    }

    /**
     * Clés d'audience (`type#id`) du pivot d'un répertoire — toutes mailles
     * confondues (visibilité). Sert au calcul de recouvrement d'audience.
     *
     * **LIMITATION CONNUE (M-A, review 34.2) — détection best-effort.** Le
     * recouvrement est calculé sur les clés LITTÉRALES du pivot (`User#5`,
     * `UserGroup#12`, `WorkstationGroup#3`), PAS sur l'appartenance effective. Une
     * collision cross-maille n'est donc PAS détectée : si `dave` est assigné en
     * direct (`User#dave`) à un répertoire `P:` et son groupe (`UserGroup#classe`)
     * à un autre répertoire `P:`, la session de `dave` matche les DEUX côté agent
     * mais les clés `User#dave` / `UserGroup#classe` ne s'intersectent pas → non
     * signalé. Idem User/UserGroup vs WorkstationGroup (qui se connectera sur les
     * postes du parc est imprédictible). Détecter ces cas exigerait d'expandre
     * l'appartenance (`UserGroup`→users), à rebours du principe « pure lecture,
     * zéro re-requête d'appartenance » du provider. Décision Henri 2026-06-30 :
     * limitation assumée, fermeture renvoyée à 34.x (avec la lettre stable). Le
     * filet reste utile sur le cas le plus fréquent (même maille).
     *
     * @return list<string>
     */
    private function audienceKeys(int $shareId): array
    {
        return DB::table('network_share_assignables')
            ->where('network_share_id', $shareId)
            ->get(['assignable_type', 'assignable_id'])
            ->map(fn ($row): string => $row->assignable_type.'#'.$row->assignable_id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Normalise une lettre stockée (`'P:'`, `'p'`, ` P `) en char nu majuscule
     * (`'P'`), ou `null` si vide / non alphabétique. Miroir 1:1 de
     * `DrivesStateProvider::bareLetter` (déterminisme partagé).
     */
    public function bareLetter(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = ltrim($raw);
        if ($trimmed === '') {
            return null;
        }
        $char = strtoupper($trimmed[0]);

        return ($char >= 'A' && $char <= 'Z') ? $char : null;
    }

    /**
     * Suggère la prochaine lettre sûre libre (`M:`..`Z:`) pour pré-remplir le
     * formulaire (Q2 — encourager l'explicite). Exclut les lettres réservées et
     * toutes les lettres EXPLICITES déjà attribuées à un autre répertoire.
     * `null` si le pool est saturé (l'admin pourra laisser le champ vide → auto).
     */
    public function suggestNextFreeLetter(): ?string
    {
        $used = [];
        foreach ($this->reservedLetters() as $r) {
            $used[$r] = true;
        }
        foreach (NetworkShare::query()->whereNotNull('letter')->pluck('letter') as $letter) {
            $bare = $this->bareLetter($letter);
            if ($bare !== null) {
                $used[$bare] = true;
            }
        }

        // Pool lu depuis le foyer canonique du provider (même principe que
        // RESERVED_LETTERS, Q4) — pas de `range('M','Z')` codé en dur qui
        // divergerait si le pool changeait côté provider.
        foreach (DrivesStateProvider::LETTER_POOL as $candidate) {
            if (! isset($used[$candidate])) {
                return $candidate.':';
            }
        }

        return null;
    }

    /**
     * Garde-fou de cohérence : le type polymorphe est-il autorisé sur le pivot ?
     */
    public function isAllowedAssignableType(string $type): bool
    {
        return in_array($type, NetworkShare::ALLOWED_ASSIGNABLE_TYPES, true);
    }

    /**
     * Lettres explicites déjà prises par d'AUTRES répertoires (pour griser/avertir
     * dans le picker du formulaire au besoin).
     *
     * @return list<string>
     */
    public function explicitLettersInUse(?int $exceptShareId = null): array
    {
        return NetworkShare::query()
            ->whereNotNull('letter')
            ->when($exceptShareId !== null, fn ($q) => $q->where('id', '!=', $exceptShareId))
            ->pluck('letter')
            ->map(fn ($l) => $this->bareLetter($l))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
