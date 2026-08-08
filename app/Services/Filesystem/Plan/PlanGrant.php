<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Plan;

use App\Exceptions\Filesystem\PlanResolutionException;

/**
 * Story 60.1 → 62.4 — OCTROI porté par un nœud de plan : « ce sujet peut FAIRE
 * ceci ici ».
 *
 * **Positif, toujours.** Un octroi est une LISTE DE VERBES, non vide, prise dans
 * un vocabulaire fermé de quatre. Il n'existe AUCUN moyen d'exprimer une
 * interdiction : pas de champ de refus, pas de valeur « aucun », pas de priorité.
 * L'absence d'octroi est la seule restriction, et l'union au plus permissif reste
 * la doctrine en cas de conflit. Les vraies interdictions vivent ailleurs, dans un
 * mécanisme machine, et n'ont rien à faire dans un plan de fichiers.
 *
 * ---------------------------------------------------------------------------
 * **LE CONTRAT SÉMANTIQUE DES QUATRE VERBES (décision Henri, Q2 = A, 2026-08-08).**
 *
 * Ce paragraphe est NORMATIF. Il est épinglé par un test de documentation, parce
 * qu'un vocabulaire dont chacun devine le périmètre est un vocabulaire que deux
 * backends implémenteront différemment.
 *
 *  | verbe        | ce qu'il autorise, EXACTEMENT                                  |
 *  |--------------|----------------------------------------------------------------|
 *  | `lire`       | lister le dossier, le traverser, ouvrir le contenu d'un fichier |
 *  | `editer`     | modifier le CONTENU d'un fichier EXISTANT, et RIEN D'AUTRE      |
 *  | `creer`      | faire apparaître une entrée nouvelle dans le dossier            |
 *  | `supprimer`  | faire disparaître une entrée existante du dossier               |
 *
 * Les deux gestes que tout le monde croit être des cas particuliers :
 *
 *  - **renommer** (dans le même dossier) = **créer + supprimer**. Ce n'est PAS
 *    « éditer » : le contenu du fichier n'est pas touché, c'est l'ENTRÉE du
 *    dossier qui disparaît d'un côté et apparaît de l'autre ;
 *  - **déplacer** = **supprimer à la source + créer à destination**. Deux
 *    dossiers, donc deux octrois consultés, et l'un des deux peut manquer.
 *
 * **Pourquoi cette découpe et pas une autre.** Elle est la seule fidèle au
 * mécanisme, sur les deux plans de fichiers que SE5 vise :
 *  - le serveur de fichiers historique exige la permission d'écriture SUR LE
 *    FICHIER pour en changer le contenu, et la permission d'écriture SUR LE
 *    DOSSIER (plus sa traversée) pour tout renommage, création ou suppression.
 *    Deux objets, deux bits : « éditer » et « créer/supprimer » ne sont pas la
 *    même autorisation, et les confondre donne à un déposant le droit d'effacer
 *    le travail des autres ;
 *  - le plan de fichiers distant de l'Epic 61 porte NATIVEMENT la même
 *    distinction (quatre bits séparés : lecture, mise à jour, création,
 *    suppression). Le vocabulaire s'y consomme donc sans traduction.
 *
 * **Conséquence assumée sur les recettes seedées (Q3 = A).** L'ancien vocabulaire
 * binaire a été traduit une fois pour toutes : `ro` → `lire` seul, `rw` → les
 * QUATRE verbes. C'est le seul mappage qui ne retire d'accès à personne (doctrine
 * additive de l'epic). Les recettes livrées sont donc maximalement permissives ;
 * les raffiner est le travail de l'écran de la story 62.6, pas d'une conversion
 * silencieuse.
 *
 * ---------------------------------------------------------------------------
 * **Trois états, à ne jamais confondre** (le backend les traduira différemment) :
 *
 *  | état                        | signification                                   |
 *  |-----------------------------|-------------------------------------------------|
 *  | octroi ACTIF                | le rôle a ces verbes                            |
 *  | octroi SUSPENDU             | l'octroi existe, il est temporairement vide,    |
 *  |                             | le dossier et les données restent               |
 *  | rôle dans la CLÔTURE du nœud| le rôle n'a JAMAIS reçu d'octroi ici            |
 *
 * Un octroi suspendu n'est PAS une omission : il est sérialisé, il se compare, et
 * un backend doit pouvoir le rendre comme un octroi explicitement vide. Le
 * distinguer de l'absence est ce qui empêche une désactivation de se transformer
 * en suppression. La suspension reste le DRAPEAU dédié : une liste de verbes vide
 * n'est pas un octroi suspendu, c'est un octroi invalide — le constructeur le
 * refuse.
 *
 * `roleKey` référence une `key` de la spécification de rôles de la recette, ou le
 * jeton réservé du membre énuméré (nœuds par membre). Ce jeton n'étant pas un
 * rôle de recette, un octroi nominatif ne « décharge » aucun rôle de la clôture —
 * exactement ce qu'on veut : le dossier personnel d'un élève ne doit rien accorder
 * à la classe entière.
 */
final class PlanGrant
{
    /**
     * Vocabulaire de VERBES. Valeurs snake_case SANS accent — convention maison
     * des vocabulaires fermés du dépôt (`non_implemente`, `applique`, `echec`).
     *
     * Aucun mode de permission, aucun bit, aucun nom de mécanisme : le plan ne
     * connaît aucun modèle d'exécution, et la traduction vers ce qu'un backend
     * sait rendre vit sous la ligne de contrat.
     */
    public const VERB_LIRE = 'lire';

    public const VERB_EDITER = 'editer';

    public const VERB_CREER = 'creer';

    public const VERB_SUPPRIMER = 'supprimer';

    /**
     * ORDRE CANONIQUE — l'ordre de DÉCLARATION, pas l'ordre alphabétique.
     *
     * C'est un choix de SÉRIALISATION, pas d'affichage : le déterminisme octet
     * pour octet de la story 60.1 (deux résolutions du même état donnent la même
     * chaîne) en dépend, et l'affichage reste libre de ses libellés.
     *
     * @var list<string>
     */
    public const VERBS = [self::VERB_LIRE, self::VERB_EDITER, self::VERB_CREER, self::VERB_SUPPRIMER];

    /**
     * Les trois verbes de MUTATION : ceux qui changent quelque chose. `lire` seul
     * ne mute rien. Sert aux deux bords de la frontière binaire des assignations,
     * et à la règle de dégradation des backends (« jamais un verbe de mutation que
     * l'octroi ne porte pas »).
     *
     * @var list<string>
     */
    public const MUTATION_VERBS = [self::VERB_EDITER, self::VERB_CREER, self::VERB_SUPPRIMER];

    public readonly string $roleKey;

    public readonly PlanSubject $subject;

    /** @var list<string> verbes du vocabulaire fermé, dédupliqués, en ordre canonique */
    public readonly array $verbs;

    public readonly bool $suspendable;

    public readonly bool $suspended;

    /**
     * @param  list<string>  $verbs  liste NON VIDE de verbes du vocabulaire fermé
     */
    public function __construct(
        string $roleKey,
        PlanSubject $subject,
        array $verbs,
        bool $suspendable = false,
        bool $suspended = false,
    ) {
        if ($roleKey === '') {
            throw PlanResolutionException::make('un octroi doit référencer un rôle.');
        }
        if ($suspended && ! $suspendable) {
            throw PlanResolutionException::make(
                'un octroi non suspendable ne peut pas être suspendu (l\'équipe garde son accès quand l\'échange est fermé).'
            );
        }

        $this->roleKey = $roleKey;
        $this->subject = $subject;
        $this->verbs = self::canonicalize($verbs);
        $this->suspendable = $suspendable;
        $this->suspended = $suspended;
    }

    /**
     * Normalise une liste de verbes : refus du vide, refus de l'inconnu,
     * déduplication, ordre canonique.
     *
     * @param  array<mixed>  $verbs
     * @return list<string>
     */
    public static function canonicalize(array $verbs): array
    {
        $kept = [];
        foreach ($verbs as $verb) {
            if (! is_string($verb) || ! in_array($verb, self::VERBS, true)) {
                throw PlanResolutionException::make(sprintf(
                    'verbe inconnu « %s » (attendu : %s).',
                    is_scalar($verb) ? (string) $verb : gettype($verb),
                    implode('|', self::VERBS),
                ));
            }
            $kept[$verb] = true;
        }

        if ($kept === []) {
            throw PlanResolutionException::make(sprintf(
                'un octroi porte au moins un verbe (attendu : %s) — une liste vide n\'est pas un octroi, '
                . 'et la suspension a son propre drapeau.',
                implode('|', self::VERBS),
            ));
        }

        // L'ordre canonique est celui de la DÉCLARATION, jamais celui de l'entrée.
        return array_values(array_filter(self::VERBS, static fn (string $v): bool => isset($kept[$v])));
    }

    /** L'octroi porte-t-il ce verbe ? (sans considération de suspension). */
    public function hasVerb(string $verb): bool
    {
        return in_array($verb, $this->verbs, true);
    }

    /** L'octroi porte-t-il au moins un verbe de MUTATION ? */
    public function mutates(): bool
    {
        return array_intersect(self::MUTATION_VERBS, $this->verbs) !== [];
    }

    /** L'octroi porte-t-il effectivement ses verbes ? (`false` = suspendu). */
    public function isActive(): bool
    {
        return ! $this->suspended;
    }

    /** Le même octroi, suspendu. Sans effet s'il n'est pas suspendable. */
    public function suspend(): self
    {
        if (! $this->suspendable || $this->suspended) {
            return $this;
        }

        return new self($this->roleKey, $this->subject, $this->verbs, true, true);
    }

    /** Clé de tri STABLE : (type de sujet, id, rôle d'arête, verbes canoniques, rôle). */
    public function sortKey(): string
    {
        return $this->subject->sortKey() . "\0" . implode(',', $this->verbs) . "\0" . $this->roleKey;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'role' => $this->roleKey,
            'subject' => $this->subject->toArray(),
            'verbs' => $this->verbs,
            'suspendable' => $this->suspendable,
            'suspended' => $this->suspended,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     *
     * **AUCUNE conversion de l'ancien vocabulaire ici, et c'est délibéré.** Un
     * payload portant la clé `access` (`ro`/`rw`) est REFUSÉ. La conversion vit
     * dans la migration des données stockées, jouée UNE fois ; l'accepter à la
     * désérialisation la ferait vivre indéfiniment et laisserait deux vocabulaires
     * coexister dans les JSON — exactement ce que le garde-fou d'epic interdit.
     */
    public static function fromArray(array $data): self
    {
        $subject = $data['subject'] ?? null;
        if (! is_array($subject)) {
            throw PlanResolutionException::make('octroi sérialisé sans sujet.');
        }

        if (array_key_exists('access', $data)) {
            throw PlanResolutionException::make(sprintf(
                'octroi sérialisé au vocabulaire ABANDONNÉ « access » (%s) : les octrois se disent désormais '
                . 'en verbes (%s). Il n\'y a pas de conversion à la relecture — re-résoudre le plan depuis '
                . 'la source SQL.',
                is_scalar($data['access']) ? (string) $data['access'] : gettype($data['access']),
                implode('|', self::VERBS),
            ));
        }

        $verbs = $data['verbs'] ?? null;
        if (! is_array($verbs)) {
            throw PlanResolutionException::make(sprintf(
                'octroi sérialisé sans liste de verbes (attendu : %s).',
                implode('|', self::VERBS),
            ));
        }

        return new self(
            (string) ($data['role'] ?? ''),
            PlanSubject::fromArray($subject),
            array_values($verbs),
            (bool) ($data['suspendable'] ?? false),
            (bool) ($data['suspended'] ?? false),
        );
    }
}
