<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend;

use App\Enums\FileBackendName;
use App\Exceptions\OpenCloud\OpenCloudConfigurationException;
use App\Services\FilePolicyService;
use App\Services\OpenCloud\OpenCloudConnectionConfig;
use InvalidArgumentException;

/**
 * Story 61.3 — CE QUI EST POSABLE, ET POURQUOI ÇA NE L'EST PAS.
 *
 * Le vocabulaire dit ce qui EXISTE ; le registre dit ce qui RÉPOND ; ce service dit
 * ce qu'un administrateur peut CHOISIR ici et maintenant. Les trois sont
 * différents : `nextcloud` existe, il répond, et il reste inchoisissable tant que la
 * capacité « Accès Nextcloud » est éteinte — parce que le choisir alors ferait
 * naître un partage dont aucune réconciliation ne pourra jamais aboutir.
 *
 * **Une case non posable est ABSENTE, avec son motif dit ailleurs — jamais grisée
 * sans explication.** La doctrine d'affichage du vocabulaire de résultat le pose
 * pour les déclins ; on la tient ici aussi : proposer puis refuser est exactement le
 * défaut du signal accepté sans destinataire.
 *
 * **Il n'y a PLUS DE MODE à lire.** La story 61.2 avait deux positions côté
 * Nextcloud (instance administrée, compte porteur délégué) ; le mode délégué a été
 * supprimé le 2026-08-08. La configuration qui ne permet pas un compte
 * administrateur est déjà refusée à la SAISIE, et un second contrôle ici ferait
 * dépendre la posabilité d'un état qui ne peut plus exister.
 *
 * **Le choix se fait À LA CRÉATION, jamais après** (D9). Aucun chemin d'écriture de
 * la colonne n'existe sur un partage déjà provisionné : elle est hors du
 * remplissage de masse du modèle, et le seul écrivain est le geste de création. La
 * migration d'un partage d'un backend à l'autre — déplacer les données, retraduire
 * les droits — est un chantier à part entière, que cette story rend NÉCESSAIRE sans
 * le livrer.
 */
final class FileBackendSelection
{
    /**
     * Les backends qu'un administrateur peut choisir à la création d'un répertoire.
     *
     * L'aperçu n'en fait PAS partie : il ne pose aucun droit, et un répertoire réel
     * servi par lui serait un répertoire dont personne n'écrit jamais les
     * permissions. Il reste une valeur de colonne légitime (les écrans s'en servent
     * pour prévisualiser un plan), pas un choix d'exploitation.
     *
     * @return list<FileBackendName>
     */
    public function selectable(): array
    {
        return array_values(array_filter(
            [FileBackendName::Posix, FileBackendName::Nextcloud, FileBackendName::OpenCloud],
            fn (FileBackendName $name): bool => $this->refusalFor($name) === null,
        ));
    }

    /**
     * Le motif pour lequel ce backend n'est PAS posable, ou `null` s'il l'est.
     *
     * La phrase est destinée à l'administrateur : elle dit ce qui manque et où le
     * régler, jamais « indisponible ».
     */
    public function refusalFor(FileBackendName $name): ?string
    {
        return match ($name) {
            FileBackendName::Posix => null,

            FileBackendName::Nextcloud => FilePolicyService::capabilities()['nextcloud']
                ? null
                : 'La capacité « Accès Nextcloud » est désactivée : activez-la et renseignez la connexion '
                    . 'dans Administration › Fichiers avant de servir un répertoire par Nextcloud.',

            // Capacité INDÉPENDANTE — les deux produits s'activent séparément, et
            // l'un éteint ne dit rien de l'autre — mais la garde va plus loin :
            // elle exige la capacité active **ET** une connexion complète.
            //
            // Une capacité allumée sur une connexion vide fait naître un partage
            // dont AUCUNE réconciliation ne peut aboutir : le backend refusera
            // fail-closed à chaque passage, sur un objet qu'il est désormais
            // interdit de migrer (D9). Le rattrapage tardif au provisionnement est
            // correct, mais il arrive après la seule décision irréversible.
            FileBackendName::OpenCloud => $this->openCloudRefusal(),

            FileBackendName::Preview => 'Le backend d\'aperçu n\'écrit aucun droit : il ne peut pas servir '
                . 'un répertoire réel.',
        };
    }

    /**
     * « Capacité active ET connexion configurée » — les deux, ou le motif.
     *
     * La complétude n'est pas re-vérifiée ici champ par champ : l'objet de
     * configuration du produit la porte déjà, en un seul endroit, et il NOMME ce
     * qui manque. La dupliquer serait s'exposer à ce que les deux divergent.
     */
    private function openCloudRefusal(): ?string
    {
        if (! FilePolicyService::capabilities()['opencloud']) {
            return 'La capacité « Accès OpenCloud » est désactivée : activez-la et renseignez la connexion '
                . 'dans Administration › Fichiers avant de servir un répertoire par OpenCloud.';
        }

        try {
            OpenCloudConnectionConfig::current();
        } catch (OpenCloudConfigurationException $e) {
            return sprintf(
                'La connexion à l\'instance OpenCloud est incomplète : %s Complétez-la dans '
                . 'Administration › Fichiers avant de servir un répertoire par OpenCloud — un répertoire '
                . 'créé maintenant ne pourrait jamais se réconcilier, et le choix de son autorité '
                . 'd\'écriture ne se change pas après coup.',
                $e->getMessage(),
            );
        }

        return null;
    }

    /**
     * Refuse AVANT toute écriture un backend non posable.
     *
     * Rejoué côté service même quand l'écran a déjà filtré : une garde qui ne vit
     * que dans la liste affichée protège l'étourderie, pas la requête forgée.
     *
     * @throws InvalidArgumentException
     */
    public function assertSelectable(FileBackendName $name): void
    {
        $refusal = $this->refusalFor($name);

        if ($refusal !== null) {
            throw new InvalidArgumentException($refusal);
        }
    }

    /**
     * Le backend demandé, ou le défaut, avec sa garde. Un nom hors vocabulaire est
     * refusé en nommant ce qui était attendu — jamais ramené au défaut.
     *
     * @throws InvalidArgumentException
     */
    public function resolve(mixed $raw): FileBackendName
    {
        if ($raw === null || $raw === '') {
            return FileBackendName::Posix;
        }

        $name = is_string($raw) ? FileBackendName::tryFrom($raw) : null;

        if ($name === null) {
            throw new InvalidArgumentException(sprintf(
                'Autorité d\'écriture inconnue « %s » (attendu : %s).',
                is_scalar($raw) ? (string) $raw : gettype($raw),
                implode(', ', array_map(static fn (FileBackendName $n): string => $n->value, $this->selectable())),
            ));
        }

        $this->assertSelectable($name);

        return $name;
    }
}
