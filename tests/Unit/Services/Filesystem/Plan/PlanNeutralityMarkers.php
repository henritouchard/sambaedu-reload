<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Plan;

use App\Services\Filesystem\Plan\FilePlan;

/**
 * Story 60.1/60.2 — le VOCABULAIRE interdit d'un plan, et la façon de le chercher.
 *
 * Extrait de {@see PlanNeutralityGuardTest} par la story 60.2 pour que la garde
 * s'exerce AUSSI sur un plan issu de la chaîne complète (groupe réel en base →
 * recette accrochée → plan), sans dupliquer la liste. Deux listes de marqueurs qui
 * divergeraient seraient pires qu'une seule : la garde la plus faible ferait
 * croire à la couverture.
 */
trait PlanNeutralityMarkers
{
    /**
     * Marqueurs INTERDITS dans un plan sérialisé.
     *
     * @return array<string, string> étiquette => marqueur
     */
    public static function forbiddenMarkers(): array
    {
        return [
            'mode de permission' => 'rwx',
            'mode de permission (lecture-exécution)' => ':rx',
            'commande de pose de liste d\'accès' => 'setfacl',
            'commande de lecture de liste d\'accès' => 'getfacl',
            'entrée propriétaire d\'une liste d\'accès' => 'user::',
            'entrée de groupe d\'une liste d\'accès' => 'group:',
            'entrée héritée d\'une liste d\'accès' => 'default:',
            'masque d\'une liste d\'accès' => 'mask::',
            'entrée « autres » d\'une liste d\'accès' => 'other::',
            'groupe d\'annuaire échappé' => 'domain\\040admins',
            'racine système' => '/var/',
            'racine des répertoires réseau' => 'Partages',
            'racine des classes' => '/Classes',
            'élévation de privilège' => 'sudo',
            'refus explicite' => 'deny',
        ];
    }

    /**
     * Texte BRUT du plan : toutes ses clés et valeurs scalaires mises bout à bout.
     *
     * On ne cherche PAS dans le JSON, et c'est le méta-test qui l'a imposé. Le JSON
     * échappe l'antislash : un plan portant `domain\040admins` sort en
     * `domain\\040admins`, et une recherche de la forme brute ne le trouve pas — la
     * garde aurait donc été AVEUGLE au seul marqueur qui contient un antislash,
     * c'est-à-dire au nom de groupe d'annuaire échappé, exactement celui que la
     * couche du dessous écrit dans ses listes d'accès. La garde doit porter sur le
     * VOCABULAIRE du plan, pas sur les accidents de son encodage.
     */
    protected function plainTextOf(FilePlan $plan): string
    {
        $parts = [];
        $walk = static function (array $data) use (&$walk, &$parts): void {
            foreach ($data as $key => $value) {
                $parts[] = (string) $key;
                if (is_array($value)) {
                    $walk($value);
                } elseif ($value !== null && ! is_bool($value)) {
                    $parts[] = (string) $value;
                }
            }
        };
        $walk($plan->toArray());

        return implode("\n", $parts);
    }

    /**
     * Assertion de garde : ce plan ne porte AUCUN marqueur de la couche du
     * dessous.
     */
    protected function assertPlanIsNeutral(FilePlan $plan, string $context = ''): void
    {
        $haystack = $this->plainTextOf($plan);

        foreach (self::forbiddenMarkers() as $label => $marker) {
            $this->assertStringNotContainsStringIgnoringCase(
                $marker,
                $haystack,
                sprintf('LIGNE DE COUPE FRANCHIE : %s (« %s ») dans le plan sérialisé. %s', $label, $marker, $context),
            );
        }
    }
}
