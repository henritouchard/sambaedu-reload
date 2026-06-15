<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateMode;
use App\Enums\StateScope;
use App\Models\UserGroup;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Services\Filesystem\ShareService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Type `drives` (contrat §7, identifiant DÉJÀ figé — NFR12) — **projection en
 * lecture seule** des partages de classe existants vers des montages de lecteur
 * réseau (Story 27.2, AC1/AC2 ; décision Henri n° 1 = MVP-A).
 *
 * **MVP-A : PROJECTION, pas de table** (décision n° 1). Il n'existe AUCUN modèle
 * `Share`, AUCUNE table SQL de partages, AUCUNE notion de lettre de lecteur dans
 * le codebase : les partages de classe sont **filesystem-truth**
 * (`/var/sambaedu/Classes/Classe_<name>`, gérés par {@see ShareService}). Ce
 * provider DÉRIVE les montages depuis les **classes du user** — il ne crée AUCUN
 * partage, ne touche AUCUN FS, ne modifie JAMAIS `ShareService` (lu/projeté
 * seulement). MVP-B (table `drives` + pivot + UI) a été **écarté** par Henri.
 *
 * **Ciblage par les classes du user** (décision n° 3) : maille `user_group`, via
 * les `userGroupIds` déjà résolus du {@see TargetContext} restreints aux groupes
 * `type='classe'`. **JAMAIS** par CN AD (`ad_*`) — NFR7, critère Keycloak. Si la
 * compilation est machine-only (`user` null), aucun lecteur (un montage de
 * classe dépend du login).
 *
 * **Lettres conventionnelles figées serveur** (décision n° 2) : aucune
 * convention de lettre historique n'a été trouvée dans le legacy SE4 (`net use`
 * legacy ne montait que `z:` pour l'installeur WPKG). Convention retenue, figée
 * serveur : `K:` = classe (un lecteur par classe du user). `H:` (home user) est
 * réservé pour une projection future du home et **non émis ici** (le home n'est
 * pas un partage de classe). Pas de colonne `letter` SQL (MVP-A). En cas de
 * pluri-classes, les lettres successives sont `K:`, `L:`, `M:`… (ordre
 * déterministe par nom de classe asc) pour ne pas écraser un montage.
 *
 * **Émis PARTOUT** (décision n° 6) : indépendamment du `WorkstationEnvironment`
 * (le resolver 26.1 n'est PAS consommé) — un montage réseau est réseau par
 * nature, comportement uniforme y compris sur poste local/nomade.
 *
 * **Scope `session`** : un lecteur réseau est monté DANS la session user (lettre
 * par-user, UNC dépendant du login). Le handler agent `drives` est exécuté par
 * le compagnon de session.
 *
 * Payload v1 : `{letter, unc, label}` — UNC `\\<se4fs>\Classe_<name>\<login>\`,
 * tokens `<se4fs>`/`<login>` substitués LOCALEMENT par l'agent (iso 27.1).
 * Toujours des strings (§4.1).
 */
final class DrivesStateProvider implements StateProvider
{
    /**
     * Première lettre de la convention figée (décision n° 2) : `K:` = classe.
     * Les classes suivantes prennent `L:`, `M:`… (incrément déterministe).
     */
    private const FIRST_CLASS_LETTER = 'K';

    public function __construct(
        private readonly ShareService $shares,
    ) {}

    public function type(): string
    {
        return 'drives';
    }

    public function semantics(): ResourceSemantics
    {
        return ResourceSemantics::Aggregate;
    }

    public function mode(): StateMode
    {
        // Défaut du type (décision n° 7) — `strict`, posture sûre. Pas de toggle
        // UI pour cette story.
        return StateMode::Strict;
    }

    public function scope(): StateScope
    {
        return StateScope::Session;
    }

    /**
     * Un candidat PAR classe du user. Émis indépendamment du
     * `WorkstationEnvironment` (décision n° 6). Machine-only (user null) → aucun
     * lecteur.
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        if ($ctx->user === null || $ctx->userGroupIds === []) {
            return collect();
        }

        // Classes du user = groupes `type='classe'` parmi les userGroupIds DÉJÀ
        // résolus du contexte (jamais de re-requête d'appartenance — D2/23.4).
        // Tri par nom asc = ordre déterministe stable pour l'attribution des
        // lettres (le hash d'agrégat ne doit pas dépendre du plan SQL).
        $classes = UserGroup::query()
            ->whereIn('id', $ctx->userGroupIds)
            ->where('type', 'classe')
            ->orderBy('name')
            ->get();

        if ($classes->isEmpty()) {
            return collect();
        }

        $login = (string) $ctx->user->login;

        $candidates = [];
        $index = 0;
        foreach ($classes as $class) {
            $bare = $this->shares->bareClassName((string) $class->name);
            if ($bare === null) {
                continue; // nom de classe invalide (caractères suspects) — ignoré
            }

            $letter = $this->letterForIndex($index);
            if ($letter === null) {
                // Au-delà de Z: : plus de lettre disponible (cas pathologique
                // > 16 classes). On s'arrête, mais on TRACE les classes droppées
                // (sinon disparition silencieuse de lecteurs côté poste).
                $dropped = $classes->count() - $index;
                Log::channel('agent')->warning(
                    '[DrivesStateProvider] Classes droppées faute de lettres de lecteur disponibles (> 16)',
                    [
                        'action_type' => 'agent.drives.letters_exhausted',
                        'login' => $login,
                        'assigned' => $index,
                        'dropped' => $dropped,
                    ],
                );
                break;
            }
            $index++;

            $candidates[] = new StateCandidate(
                maille: StateMaille::UserGroup,
                payload: [
                    'letter' => $letter.':',
                    // UNC vers le sous-dossier de la classe du user (iso legacy
                    // `Classes/Classe_<name>/<login>`). Tokens `<se4fs>`/`<login>`
                    // substitués localement par l'agent (jamais de secret en
                    // payload, aucune dépendance réseau au calcul).
                    'unc' => '\\\\<se4fs>\\Classe_'.$bare.'\\<login>\\',
                    'label' => 'Classe '.$bare,
                ],
                updatedAt: $class->updated_at,
                sourceId: (int) $class->id,
                // Mode null → le compilateur retombe sur mode() (strict).
                mode: null,
            );
        }

        return collect($candidates);
    }

    /**
     * Lettre conventionnelle pour la n-ième classe : `K`, `L`, … jusqu'à `Z`.
     * Null au-delà de `Z` (cas pathologique — un user avec plus de 16 classes).
     */
    private function letterForIndex(int $index): ?string
    {
        $ord = ord(self::FIRST_CLASS_LETTER) + $index;
        if ($ord > ord('Z')) {
            return null;
        }

        return chr($ord);
    }
}
