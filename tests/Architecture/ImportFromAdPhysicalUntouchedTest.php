<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Services\Parc\WorkstationGroupService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Story 38.7 / AC9.3 + T7 — la règle d'import SÉLECTIF (« ne créer un groupe que
 * si le parc legacy porte des applications ») s'applique au SEUL import LOGIQUE
 * (`importLogicalGroupsFromAd`, étape 5). L'import PHYSIQUE des salles
 * (`importFromAd`, étape 4, `OU=Computers`) NE DOIT PAS être concerné : une salle
 * sans application reste évidemment à importer (c'est le support des GPO).
 *
 * `importFromAd()` lit l'AD réel sans seam de test : l'exécuter hors annuaire est
 * impossible en HÔTE, et l'instrumenter juste pour prouver un négatif toucherait
 * le chemin physique que la story protège. On prouve donc l'invariant par
 * CARACTÉRISATION du code source de la méthode (patron d'architecture maison, cf.
 * {@see LegacyCronRetirementTest}) : le corps de `importFromAd()` ne câble AUCUN
 * artefact de la règle sélective (lecteur legacy mutualisé, table
 * `applications_profile`, exclusion `_TousLesPostes`).
 */
class ImportFromAdPhysicalUntouchedTest extends TestCase
{
    private function methodSource(string $method): string
    {
        $ref = new ReflectionMethod(WorkstationGroupService::class, $method);
        $file = (string) $ref->getFileName();
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines, "Source de $method illisible");

        $start = $ref->getStartLine() - 1;
        $length = $ref->getEndLine() - $ref->getStartLine() + 1;

        return implode("\n", array_slice($lines, $start, $length));
    }

    #[Test]
    public function la_regle_selective_n_est_pas_cablee_dans_l_import_physique(): void
    {
        $body = $this->methodSource('importFromAd');

        // Aucun artefact de la règle sélective (AC9.3) ne doit apparaître dans
        // l'import physique. Si l'un d'eux y entre un jour, ce test rouge signale
        // que la règle a fui vers le chemin des salles.
        self::assertStringNotContainsString(
            'LegacyParcApplicationReader',
            $body,
            'La règle sélective (lecteur legacy) a fui dans importFromAd() (physique).',
        );
        self::assertStringNotContainsString(
            'legacyParcReader',
            $body,
            'La règle sélective (lecteur legacy) a fui dans importFromAd() (physique).',
        );
        self::assertStringNotContainsString(
            'applications_profile',
            $body,
            'importFromAd() (physique) ne doit pas consulter les applications assignées.',
        );
        self::assertStringNotContainsString(
            '_TousLesPostes',
            $body,
            'importFromAd() (physique) ne doit pas connaître l\'exclusion _TousLesPostes.',
        );
    }

    #[Test]
    public function le_marqueur_de_la_regle_selective_est_bien_present_dans_l_import_logique(): void
    {
        // Contrôle miroir : la règle DOIT vivre dans l'import logique. Sans lui, le
        // test négatif ci-dessus passerait trivialement (règle nulle part) et ne
        // prouverait rien.
        $body = $this->methodSource('importLogicalGroupsFromAd');

        self::assertStringContainsString(
            'LegacyParcApplicationReader',
            $body,
            'La règle sélective doit être câblée dans importLogicalGroupsFromAd() (logique).',
        );
    }
}
