<?php

declare(strict_types=1);

namespace App\Services\Filesystem;

use App\Enums\ActiveCloud;
use App\Enums\FileBackendName;
use App\Exceptions\Filesystem\FileLocationRefusalException;
use App\Exceptions\Nextcloud\NextcloudConfigurationException;
use App\Exceptions\OpenCloud\OpenCloudConfigurationException;
use App\Services\Nextcloud\NextcloudConnectionConfig;
use App\Services\OpenCloud\OpenCloudConnectionConfig;

/**
 * Story 63.3 — CE QU'ON PEUT DÉSIGNER COMME EMPLACEMENT, ET POURQUOI ON NE PEUT
 * PAS.
 *
 * Calque littéral de `\App\Services\Filesystem\Backend\FileBackendSelection`
 * (cité en FQCN et NON importé : voir plus bas — ce service n'en dépend ni à la
 * compilation ni à l'exécution) : trois méthodes, `available()` pour la liste,
 * `refusalFor()` pour le motif, `assertAvailable()` pour la garde rejouée.
 *
 * **Une position non posable est ABSENTE de la liste, avec son motif affiché à
 * côté — jamais grisée sans explication, jamais proposée puis refusée.** C'est
 * la doctrine du dépôt, et elle vaut ici mot pour mot.
 *
 * **La garde est REJOUÉE AVANT TOUTE ÉCRITURE**, même quand l'écran a déjà
 * filtré : une garde qui ne vit que dans la liste affichée protège
 * l'étourderie, pas la requête forgée.
 *
 * ---------------------------------------------------------------------------
 * **POURQUOI UN SERVICE DE PLUS, ET PAS `FileBackendSelection`.**
 *
 * Les deux services répondent à deux questions différentes :
 *  - `FileBackendSelection` dit ce qu'un administrateur peut choisir **à la
 *    création d'un répertoire géré** (D9 : le choix ne se change plus après) ;
 *  - celui-ci dit où peut vivre **un des deux espaces de l'instance**.
 *
 * Et surtout, la règle du premier est ASYMÉTRIQUE entre les deux produits :
 * `refusalFor(Nextcloud)` ne regarde que la CAPACITÉ, tandis que sa branche
 * OpenCloud exige capacité **ET** connexion complète. Cette asymétrie est
 * visible et gênante, mais la corriger là-bas changerait le comportement de la
 * création de répertoire géré et de la préfabrication d'arborescences — hors
 * périmètre de cette story, qui laisse ce fichier à ZÉRO DIFF.
 *
 * Ici, la règle est SYMÉTRIQUE : les deux produits sont disponibles si et
 * seulement si leur objet de configuration ne lève pas — soit exactement le
 * critère « cloud configuré » de la commande de reprise
 * `php artisan files:adopt-locations` (citée par sa signature, et non par sa
 * classe : un service n'a pas à dépendre d'une commande) : capacité active ET
 * connexion complète. Un espace placé sur une connexion incomplète serait un
 * espace que rien ne peut servir.
 * ---------------------------------------------------------------------------
 *
 * **AUCUN APPEL RÉSEAU.** « Connexion complète » n'est pas « connexion
 * vérifiée » : les deux objets de configuration ne lisent que le réglage
 * d'instance et le stock de secrets. L'écran doit rester utilisable instance
 * injoignable — c'est précisément là qu'on vient réparer une connexion.
 */
final class FileLocationOptions
{
    /**
     * Aucun cloud actif : il n'y a rien vers quoi placer un espace, et le dire
     * vaut mieux que de proposer une position qui serait refusée au tour
     * suivant.
     */
    public const REFUSAL_NO_ACTIVE_CLOUD = 'Aucun cloud n\'est configuré : choisissez-en un ci-dessus avant d\'y placer un espace.';

    /**
     * Les positions réellement posables pour un emplacement, dans l'ordre
     * d'affichage. Le serveur de fichiers y est TOUJOURS ; le cloud n'y est que
     * s'il est actif ET configuré.
     *
     * @return list<FileBackendName>
     */
    public function available(FileLocations $locations): array
    {
        return array_values(array_filter(
            FileLocations::ACCEPTABLE_AUTHORITIES,
            fn (FileBackendName $name): bool => $this->refusalFor($name, $locations) === null,
        ));
    }

    /**
     * Le motif pour lequel cette position n'est PAS posable, ou `null` si elle
     * l'est. La phrase est destinée à l'administrateur : elle dit ce qui manque
     * et où le régler, jamais « indisponible ».
     */
    public function refusalFor(FileBackendName $name, FileLocations $locations): ?string
    {
        return match ($name) {
            // L'autorité historique est toujours posable : elle ne dépend
            // d'aucune configuration à distance.
            FileBackendName::Posix => null,

            FileBackendName::Nextcloud => $this->cloudRefusal(ActiveCloud::Nextcloud, $locations),
            FileBackendName::OpenCloud => $this->cloudRefusal(ActiveCloud::OpenCloud, $locations),

            // Motif repris de `FileBackendSelection` : l'aperçu n'écrit aucun
            // droit, il ne peut donc être l'autorité d'aucun fichier réel — et
            // {@see FileLocations::make()} le refuse déjà structurellement.
            FileBackendName::Preview => 'Le backend d\'aperçu n\'écrit aucun droit : il ne peut pas servir '
                .'un répertoire réel.',
        };
    }

    /**
     * Refuse AVANT toute écriture une position non posable.
     *
     * Rejouée côté service même quand l'écran a déjà filtré.
     *
     * **Le type levé est DÉDIÉ** ({@see FileLocationRefusalException}, qui étend
     * `InvalidArgumentException`) : l'écran présente ce refus à l'administrateur
     * comme un refus métier, et attraper le type large ferait passer pour un
     * refus de garde n'importe quelle erreur d'argument venue d'ailleurs.
     *
     * @throws FileLocationRefusalException
     */
    public function assertAvailable(FileBackendName $name, FileLocations $locations): void
    {
        $refusal = $this->refusalFor($name, $locations);

        if ($refusal !== null) {
            throw FileLocationRefusalException::positionIsNotAvailable($refusal);
        }
    }

    /**
     * La règle SYMÉTRIQUE des deux produits, en un seul endroit.
     *
     * Trois refus, dans cet ordre : aucun cloud actif ; le produit demandé
     * n'est pas le cloud actif (état que {@see FileLocations::make()} refuse
     * déjà, rejoué ici pour que la liste affichée n'ait jamais à le proposer) ;
     * connexion incomplète, avec le message de l'objet de configuration, qui
     * NOMME ce qui manque.
     */
    private function cloudRefusal(ActiveCloud $produit, FileLocations $locations): ?string
    {
        if ($locations->cloudActif === ActiveCloud::Aucun) {
            return self::REFUSAL_NO_ACTIVE_CLOUD;
        }

        if ($locations->cloudActif !== $produit) {
            return sprintf(
                'Le cloud actif de l\'instance est « %s » : un espace ne peut être placé que sur le '
                .'serveur de fichiers ou sur le cloud actif.',
                $locations->cloudActif->label(),
            );
        }

        try {
            $produit === ActiveCloud::Nextcloud
                ? NextcloudConnectionConfig::current()
                : OpenCloudConnectionConfig::current();
        } catch (NextcloudConfigurationException|OpenCloudConfigurationException $e) {
            return sprintf(
                'La connexion à l\'instance %s est incomplète : %s Complétez-la ci-dessus avant d\'y '
                .'placer un espace.',
                $produit->label(),
                $e->getMessage(),
            );
        }

        return null;
    }
}
