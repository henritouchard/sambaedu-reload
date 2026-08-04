<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Story 60.3 — RÉSULTAT d'un backend SUR UN NŒUD. Enum fermée à sept états.
 *
 * **Pourquoi par nœud et jamais globalement.** Le sondage d'ouverture d'epic a
 * mesuré, contre une instance réelle, un mode de rupture qui n'est pas un échec :
 * l'octroi posé sur un ancêtre PROPAGE au sous-arbre, l'instruction de retrait sur
 * le dossier privé des enseignants est acceptée `200 OK` **sans aucun effet**, et
 * la relecture rend ensuite un accès en lecture là où on demandait zéro. Un
 * résultat agrégé en « ça a marché » reproduit exactement cette fuite : il est vrai
 * pour l'ensemble et faux pour le nœud qui compte. D'où sept états portés PAR NŒUD,
 * et des agrégats qui ne sont que des vues dérivées
 * ({@see \App\Services\Filesystem\Backend\ReconciliationReport}).
 *
 * **Aucun code de transport n'entre ici.** Les trois sémantiques natives mesurées
 * pour « c'était déjà fait » (`405` sur une création de dossier rejouée, statut
 * `102` sur un groupe existant, réémission rendant le même identifiant) sont
 * NORMALISÉES par l'adaptateur en un seul état : {@see self::Conforme}. Un champ
 * de code natif ou de statut HTTP dans un rapport rouvrirait la ligne de coupe et
 * obligerait chaque appelant au-dessus d'elle à connaître trois dialectes. Une
 * règle d'architecture le tient
 * ({@see \Tests\Architecture\PlanNamespaceIsolationTest}).
 *
 * ---------------------------------------------------------------------------
 * **LES TROIS FAÇONS DE NE RIEN FAIRE — à ne jamais écraser l'une sur l'autre.**
 *
 * C'est la correction la plus importante de cette story (Henri, 2026-08-04), et
 * c'est aussi la simplification la plus tentante : dire « pas supporté » dans les
 * trois cas. Ce serait écrire dans le code une affirmation FAUSSE.
 *
 *  | état              | ce qui est vrai                         | qui en est propriétaire | durée      |
 *  |-------------------|-----------------------------------------|-------------------------|------------|
 *  | `non_exprimable`  | le MODÈLE du backend n'a pas le concept | le backend              | permanent  |
 *  | `non_implemente`  | le mécanisme existe, SE5 ne le pilote pas | notre code            | temporaire |
 *  | `non_execute`     | ce backend n'exécute rien, par conception | la conception         | sans objet |
 *
 * Exemples MESURÉS, pour que la nuance ne se perde pas :
 *  - `non_exprimable` — le plafond de zone chez un backend distant dont le quota
 *    est par utilisateur et non par dossier (mesuré : quota illimité rendu sur un
 *    compte élève). Aucune story ne le rendra possible ; l'administrateur doit
 *    choisir un autre backend pour ce besoin.
 *  - `non_implemente` — le plafond de zone côté POSIX. Le système de fichiers SAIT
 *    plafonner une arborescence (quotas de projet, volume monté et vérifié). S'il
 *    ne plafonne pas, c'est que NOUS ne l'avons pas branché : la story qui le
 *    ferait est suspendue. Dire « non supporté » de POSIX serait une contre-vérité.
 *  - `non_execute` — le backend d'aperçu. Ni limite de modèle, ni dette de code :
 *    ne rien faire EST sa fonction.
 *
 * **Doctrine d'affichage, écrite ici parce que c'est ici qu'on la cherchera.**
 * Les deux déclins ne se rendent PAS de la même façon, et une future UI d'édition
 * ne les traite pas pareil :
 *  - `non_exprimable` : « non supporté par ce backend ». Un réglage non exprimable
 *    ne se PROPOSE PAS — l'offrir puis le refuser à l'application est le défaut du
 *    signal accepté sans destinataire. On MASQUE.
 *  - `non_implemente` : « non piloté par SE5 pour l'instant ». Le réglage existe,
 *    il est visible et INDISPONIBLE-POUR-L'INSTANT. On GRISE, on n'efface pas :
 *    l'administrateur doit savoir que c'est une dette datée, pas une impossibilité.
 *
 * Cases PascalCase, valeurs snake_case (convention maison).
 */
enum FileBackendOutcome: string
{
    /**
     * L'état voulu est DÉJÀ celui du backend — rien n'a été écrit à ce passage.
     * C'est aussi la normalisation des trois idempotences natives mesurées.
     */
    case Conforme = 'conforme';

    /** Un écart existait, ce passage l'a corrigé. */
    case Applique = 'applique';

    /**
     * La réconciliation est engagée mais pas achevée (traitement asynchrone).
     * Ce n'est NI un succès NI un échec : c'est ce qu'un contrat de forme
     * distante doit savoir dire au lieu de mentir dans un booléen (D2).
     */
    case EnAttente = 'en_attente';

    /** L'exécution a échoué. `detail` nomme la cause — obligatoire. */
    case Echec = 'echec';

    /**
     * Le MODÈLE de ce backend n'a pas le concept demandé. Permanent, propriété du
     * backend. `detail` obligatoire : il doit nommer CE QUI n'est pas exprimable
     * (par exemple l'octroi hérité qu'aucune instruction ne referme).
     */
    case NonExprimable = 'non_exprimable';

    /**
     * Le mécanisme EXISTE côté backend, SE5 ne le pilote pas encore. Temporaire,
     * propriété de notre code. `detail` obligatoire : il doit dire ce qui manque
     * de NOTRE côté, pas prétendre à une limite du backend.
     */
    case NonImplemente = 'non_implemente';

    /**
     * Ce backend n'exécute rien, par conception. Aucun `detail` n'est exigé —
     * mais un backend d'aperçu a tout intérêt à en fournir un : c'est là qu'il
     * rend visible ce qu'il a REÇU (la clôture d'un nœud, notamment).
     */
    case NonExecute = 'non_execute';

    /** Libellé FR — les sept états ont des libellés DISTINCTS (AC10). */
    public function label(): string
    {
        return match ($this) {
            self::Conforme => 'Déjà conforme',
            self::Applique => 'Appliqué',
            self::EnAttente => 'Réconciliation en cours',
            self::Echec => 'Échec',
            self::NonExprimable => 'Non supporté par ce backend',
            self::NonImplemente => 'Non piloté par SE5 pour l\'instant',
            self::NonExecute => 'Aucune exécution (aperçu)',
        };
    }

    /**
     * `true` si l'état EXIGE un `detail` non vide. Vérifié AU CONSTRUCTEUR de
     * {@see \App\Services\Filesystem\Backend\NodeReconciliation}, pas en
     * convention : un échec sans cause et un déclin sans raison sont exactement
     * les silences que cette story existe pour rendre impossibles.
     */
    public function requiresDetail(): bool
    {
        return match ($this) {
            self::Echec, self::NonExprimable, self::NonImplemente => true,
            default => false,
        };
    }

    /** `true` si le backend a refusé de faire — pour l'une des trois raisons. */
    public function isDecline(): bool
    {
        return match ($this) {
            self::NonExprimable, self::NonImplemente, self::NonExecute => true,
            default => false,
        };
    }

    /**
     * `true` pour le SEUL déclin permanent : une limite du modèle du backend.
     * L'UI masque le réglage correspondant (voir la doctrine du docblock d'enum).
     */
    public function isModelLimit(): bool
    {
        return $this === self::NonExprimable;
    }

    /**
     * `true` pour le SEUL déclin temporaire : une dette de notre code. L'UI grise
     * le réglage — visible, indisponible pour l'instant.
     */
    public function isImplementationDebt(): bool
    {
        return $this === self::NonImplemente;
    }

    /** `true` si ne rien faire est la CONCEPTION du backend (ni limite, ni dette). */
    public function isByDesign(): bool
    {
        return $this === self::NonExecute;
    }

    /** `true` si le nœud est dans l'état voulu à l'issue de ce passage. */
    public function isConverged(): bool
    {
        return $this === self::Conforme || $this === self::Applique;
    }

    /** `true` si la valeur brute appartient au vocabulaire fermé. */
    public static function isKnown(mixed $value): bool
    {
        return is_string($value) && self::tryFrom($value) !== null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
