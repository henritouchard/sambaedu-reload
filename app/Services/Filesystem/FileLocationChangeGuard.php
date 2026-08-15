<?php

declare(strict_types=1);

namespace App\Services\Filesystem;

use App\Exceptions\Filesystem\FileLocationRefusalException;
use App\Models\NetworkShare;
use App\Models\User;
use App\Models\UserGroup;

/**
 * Story 63.3 — CHANGER L'EMPLACEMENT D'UN ESPACE QUI PORTE DES DONNÉES EST
 * REFUSÉ, ET LE REFUS NOMME LE CHANTIER QUI LE LÈVERA.
 *
 * La doctrine est déjà écrite pour les répertoires gérés
 * (`\App\Services\Filesystem\Backend\FileBackendSelection`, cité en FQCN) :
 * *« Le choix se fait À LA CRÉATION, jamais après […] La migration d'un partage
 * d'un backend à l'autre […] est un chantier à part entière »*. Les deux espaces
 * de l'instance héritent de la même règle : tant que **Epic 64 — la bascule
 * d'autorité** n'a pas livré le déménagement des données, déplacer un espace
 * peuplé serait promettre un mouvement que personne n'exécute.
 *
 * **Le refus est TOTAL : rien n'est écrit.** Ni le réglage des emplacements, ni
 * son miroir. Même forme que la sonde-garde de l'écran de connexion : une
 * soumission refusée laisse la décision précédente en vigueur, et l'écran
 * reprend les valeurs persistées plutôt que d'afficher un état que la base ne
 * porte pas.
 *
 * ---------------------------------------------------------------------------
 * **COMMENT SE CONSTATE « DES DONNÉES EXISTENT » — L'ARBITRAGE, ET SON COÛT.**
 *
 * Deux constats d'existence en base, et rien d'autre. Ni parcours du stockage
 * (coûteux depuis un écran, et le vocabulaire du serveur de fichiers n'a pas le
 * droit de vivre ici — ce fichier est scanné), ni interrogation du cloud (un
 * appel réseau depuis un rendu d'écran, alors que l'écran doit rester
 * utilisable instance injoignable).
 *
 * Et il y a une raison de fond qui rend ce constat EXACT plutôt
 * qu'approximatif : l'espace personnel servi par le serveur de fichiers est de
 * l'infrastructure d'agent, posée pour TOUT compte (Bureau redirigé,
 * raccourcis, profils applicatifs), et l'arbre de l'espace partagé est produit
 * pour TOUT groupe. Sur une instance qui porte au moins un compte d'annuaire
 * actif, la réponse à « des données existent-elles ? » est OUI, sans qu'aucune
 * mesure ne soit nécessaire.
 *
 * **La règle est donc CONSERVATRICE, et c'est assumé** : une instance qui
 * porterait des comptes sans aucun fichier serait refusée. L'inverse —
 * autoriser une bascule silencieuse — est le seul risque inacceptable, cette
 * garde existant contre la perte de données.
 *
 * ⚠️ **CE QUE CETTE GARDE NE VOIT PAS (correction de revue).** Une première
 * rédaction affirmait que le seul cas « aucune donnée » est l'instance NEUVE.
 * C'est FAUX, et c'est le faux négatif dangereux : sur une instance
 * **reprise** — SE5 posé sur un serveur de fichiers déjà en service, avant le
 * premier import d'annuaire — `users` et `user_groups` sont VIDES pendant que
 * les espaces existants sont pleins. La garde autorise alors la bascule sans un
 * mot, et c'est justement la population que ce chantier vise.
 *
 * Ce qu'elle protège exactement : les instances **déjà pilotées par SE5**,
 * c'est-à-dire celles dont la base porte les comptes et les groupes. Elle est
 * AVEUGLE à une reprise d'existant, et aucune détection n'est ajoutée ici :
 * la seule qui serait honnête supposerait de parcourir le stockage, ce que
 * l'arbitrage ci-dessus a écarté (et que la garde d'architecture interdit à ce
 * fichier). L'écran porte donc, à côté du bouton d'enregistrement, la phrase
 * qui dit que le choix se fige dès qu'un compte existe : sur une reprise
 * d'existant, la fenêtre où le choix reste libre se referme au premier import.
 * ---------------------------------------------------------------------------
 *
 * **ELLE NE PORTE QUE SUR LES DEUX EMPLACEMENTS.** Changer le cloud actif, une
 * adresse, un identifiant, un secret, la vérification du certificat ou le
 * chemin d'accès n'est jamais refusé par elle. Le RICOCHET, lui, est couvert :
 * changer le cloud actif alors qu'un emplacement désigne le cloud OBLIGE à
 * changer aussi cet emplacement ({@see FileLocations::make()} refuse la
 * combinaison), la soumission retombe donc sous cette garde et est refusée avec
 * le même motif. Basculer d'un produit à l'autre n'est libre que lorsque les
 * deux emplacements sont restés sur le serveur de fichiers.
 *
 * **Aucun octet de donnée n'est lu, écrit ni déplacé par cette garde.** Elle
 * n'émet que des constats d'existence indexés : UN pour l'espace personnel,
 * jusqu'à DEUX pour l'espace partagé — donc **trois au plus**, et seulement pour
 * les objets dont la soumission change réellement la valeur.
 */
final class FileLocationChangeGuard
{
    /** Le nom du chantier qui lèvera ce refus — cité, jamais sous-entendu. */
    public const CHANTIER = 'Epic 64 — la bascule d\'autorité';

    /**
     * Le motif du refus, ou `null` si la soumission passe.
     *
     * L'espace personnel est examiné en premier : quand les deux bougent, c'est
     * le premier motif qui est rendu — un refus par soumission, pas une liste
     * d'erreurs à corriger une par une alors qu'aucune n'est corrigible.
     */
    public function refusalFor(FileLocations $current, FileLocations $submitted): ?string
    {
        if ($submitted->espacePerso !== $current->espacePerso && $this->personalSpaceCarriesData()) {
            return $this->refusal('l\'espace personnel');
        }

        if ($submitted->espacePartage !== $current->espacePartage && $this->sharedSpaceCarriesData()) {
            return $this->refusal('l\'espace partagé');
        }

        return null;
    }

    /**
     * Refuse AVANT toute écriture. Rejouée côté service : une garde qui ne vit
     * que dans l'écran protège l'étourderie, pas la requête forgée.
     *
     * **Le type levé est DÉDIÉ** ({@see FileLocationRefusalException}) : l'écran
     * présente ce refus comme un refus métier, et attraper le type large ferait
     * passer pour un refus de garde n'importe quelle erreur d'argument venue
     * d'ailleurs.
     *
     * @throws FileLocationRefusalException
     */
    public function assertChangeIsAllowed(FileLocations $current, FileLocations $submitted): void
    {
        $refusal = $this->refusalFor($current, $submitted);

        if ($refusal !== null) {
            throw FileLocationRefusalException::spaceCarriesData($refusal);
        }
    }

    /** Le motif, mot pour mot, décliné sur l'objet concerné. */
    private function refusal(string $objet): string
    {
        return sprintf(
            'Refusé : %s porte déjà des données. Le déplacer suppose de les déménager, ce que le '
            .'chantier « %s » livrera ; d\'ici là, l\'emplacement d\'un espace qui porte des données ne '
            .'se change pas.',
            $objet,
            self::CHANTIER,
        );
    }

    /**
     * Un constat, une existence indexée : au moins un compte d'annuaire actif,
     * ou au moins un compte porteur d'une identité chez l'un des deux produits
     * cloud (l'identité est un cache de résolution — sa présence signifie qu'un
     * espace a été provisionné là-bas pour ce compte).
     */
    private function personalSpaceCarriesData(): bool
    {
        return User::query()
            ->where(static function ($query): void {
                $query->where('source', 'ad')->where('is_active', true);
            })
            ->orWhereNotNull('nextcloud_user_id')
            ->orWhereNotNull('opencloud_user_id')
            ->exists();
    }

    /**
     * Second constat : au moins un groupe (dont l'arbre est produit dès qu'il
     * existe) ou au moins un répertoire géré.
     */
    private function sharedSpaceCarriesData(): bool
    {
        return UserGroup::query()->exists() || NetworkShare::query()->exists();
    }
}
