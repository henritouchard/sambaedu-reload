<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Support\Collection;

/**
 * Type `drives` (contrat §7, identifiant DÉJÀ figé — NFR12) — **projection en
 * lecture seule** des partages réseau standards SambaEdu vers des montages de
 * lecteur, gérés NATIVEMENT par l'agent (et non plus par l'attribut AD
 * `homeDrive`/`homeDirectory` ni la GPO « lecteurs reseau » legacy).
 *
 * **Pourquoi natif** : le bon `K: = home` venait jusqu'ici du compte AD
 * (appliqué par Windows au logon), pas de SE5. L'agent doit devenir l'autorité
 * sur les lecteurs (successeur de GPO/AD) — sinon deux mécanismes se marchent
 * dessus (l'ancien provider posait un lecteur de CLASSE sur K:, écrasant le home
 * natif pour les élèves). Décision Henri (2026-06-29) : l'agent émet le jeu
 * standard iso-legacy, lettres FIXES.
 *
 * **Lettres figées serveur** (iso-legacy `individuel.php`) :
 *  - `K:` = **home** de l'utilisateur (partage `users`, sous-dossier = login) —
 *    `\\<se4fs>\users\<user>\`. C'est « Mes documents / Bureau ».
 *  - `H:` = **racine du partage `classes`** — `\\<se4fs>\classes\`. L'utilisateur
 *    navigue vers sa/ses classe(s) (`H:\Classe_<nom>\<login>`, ACL POSIX par
 *    élève). On ne cible JAMAIS une classe unique : un user peut en avoir
 *    plusieurs (jusqu'à 3) — un lecteur par classe écraserait les autres.
 *
 * `I:` (Docs) et `L:` (Progs) ne sont **pas** portés : leur usage est couvert
 * autrement en SE5 (fonds d'écran natifs, distribution applicative WPKG) ou
 * relève d'un futur système de partages/ACL (cf. module legacy `acls/`,
 * restauration au déploiement via `/admin/sync-from-ad`).
 *
 * **Émis pour toute session user**, indépendamment du `WorkstationEnvironment`
 * (un montage réseau est réseau par nature) et de l'appartenance à une classe
 * (H: = racine du partage, ACL-gated — comportement uniforme, iso le mapping
 * legacy qui donnait K/H à tous). Machine-only (`user` null) → aucun lecteur :
 * un montage dépend du login de session.
 *
 * **Scope `session`** : monté DANS la session user (lettre par-user, UNC du home
 * dépendant du login), appliqué par le compagnon de session.
 *
 * Payload v1 : `{letter, unc, label}` — tokens `<se4fs>`/`<user>` substitués
 * LOCALEMENT par l'agent (iso 27.1). Toujours des strings (§4.1).
 */
final class DrivesStateProvider implements StateProvider
{
    public function type(): string
    {
        return 'drives';
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
     * Jeu standard {K: home, H: classes} pour toute session user. Ordre des
     * candidats aggregate fixé par `sourceId` asc (K puis H) — déterminisme du
     * hash. `updatedAt` null : aucune notion de récence (lettres distinctes, pas
     * de conflit intra-maille).
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        if ($ctx->user === null) {
            return collect();
        }

        return collect([
            // K: — home de l'utilisateur (partage `users`, sous-dossier = login).
            new StateCandidate(
                maille: StateMaille::User,
                payload: [
                    'letter' => 'K:',
                    'unc' => '\\\\<se4fs>\\users\\<user>\\',
                    'label' => 'Mes documents',
                ],
                updatedAt: null,
                sourceId: 1,
            ),
            // H: — racine du partage `classes` (navigation vers la/les classe(s)).
            new StateCandidate(
                maille: StateMaille::Broadcast,
                payload: [
                    'letter' => 'H:',
                    'unc' => '\\\\<se4fs>\\classes\\',
                    'label' => 'Classes',
                ],
                updatedAt: null,
                sourceId: 2,
            ),
        ]);
    }
}
