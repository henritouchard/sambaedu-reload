<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend;

use App\Enums\FileBackendName;
use App\Services\Filesystem\Backend\NodeReconciliation;
use App\Services\Filesystem\Backend\PreviewBackend;
use App\Services\Filesystem\Backend\ReconciliationReport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Services\Filesystem\Backend\Support\RootedClassPlan;
use Tests\Unit\Services\Filesystem\Plan\PlanNeutralityMarkers;

/**
 * Story 60.3 — LA COUPE TIENT AUSSI SUR LE CHEMIN DU RETOUR.
 *
 * Les gardes des stories 60.1/60.2 vérifient qu'un PLAN ne porte aucun terme de la
 * couche d'exécution. Elles ne disaient rien de ce qui REMONTE : un rapport est
 * fabriqué par un backend, c'est-à-dire par du code qui vit SOUS la ligne et qui a
 * tout le vocabulaire concret sous la main. C'est la porte dérobée naturelle, et
 * elle a un seul battant : le champ `detail`, seul texte libre d'un rapport.
 *
 * La liste de marqueurs est celle des stories précédentes, RÉUTILISÉE : deux
 * listes qui divergeraient seraient pires qu'une seule, la plus faible ferait
 * croire à la couverture.
 */
class BackendReportNeutralityTest extends TestCase
{
    use PlanNeutralityMarkers;
    use RootedClassPlan;

    /** Texte BRUT d'un rapport sérialisé : clés et valeurs scalaires bout à bout. */
    private function plainTextOfArray(array $data): string
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
        $walk($data);

        return implode("\n", $parts);
    }

    /** @return list<string> étiquettes des marqueurs trouvés */
    private function markersIn(string $haystack): array
    {
        $found = [];
        foreach (self::forbiddenMarkers() as $label => $marker) {
            if (stripos($haystack, $marker) !== false) {
                $found[] = $label;
            }
        }

        return $found;
    }

    #[Test]
    public function the_reconciliation_report_of_the_class_plan_carries_no_marker_of_the_layer_below(): void
    {
        $plan = $this->rootedClassPlan();
        $report = (new PreviewBackend())->provision($plan);

        $this->assertSame(
            [],
            $this->markersIn($this->plainTextOfArray($report->toArray())),
            'LIGNE DE COUPE FRANCHIE par un rapport de réconciliation.',
        );
    }

    #[Test]
    public function the_inspection_report_of_the_class_plan_carries_no_marker_of_the_layer_below(): void
    {
        $plan = $this->rootedClassPlan();
        $report = (new PreviewBackend())->inspect($plan);

        $this->assertSame(
            [],
            $this->markersIn($this->plainTextOfArray($report->toArray())),
            'LIGNE DE COUPE FRANCHIE par une relecture d\'état.',
        );
    }

    #[Test]
    public function the_quota_report_of_the_class_plan_carries_no_marker_of_the_layer_below(): void
    {
        $plan = $this->rootedClassPlan();
        $report = (new PreviewBackend())->quota($plan);

        $this->assertSame([], $this->markersIn($this->plainTextOfArray($report->toArray())));
    }

    /**
     * MÉTA-TEST — chaque marqueur entre par le SEUL champ par lequel il peut
     * entrer : le texte libre d'un `detail`. Sans ce contrôle, une garde qui ne
     * regarderait pas `detail` resterait éternellement au vert.
     */
    #[Test]
    public function the_guard_sees_every_marker_when_it_enters_through_the_detail_field(): void
    {
        $plan = $this->rootedClassPlan();

        foreach (self::forbiddenMarkers() as $label => $marker) {
            $report = ReconciliationReport::covering(
                FileBackendName::Preview,
                $plan,
                array_map(
                    static fn (string $path): NodeReconciliation => $path === '_profs'
                        ? NodeReconciliation::echec($path, 'échec : ' . $marker)
                        : NodeReconciliation::conforme($path),
                    $plan->nodePaths(),
                ),
            );

            $this->assertContains(
                $label,
                $this->markersIn($this->plainTextOfArray($report->toArray())),
                sprintf('la garde ne voit pas « %s » (« %s ») entré par le champ detail : elle est aveugle', $label, $marker),
            );
        }
    }

    /**
     * Aucun chemin ABSOLU ne peut se cacher dans un rapport — même contrôle que
     * sur le plan, transposé au chemin du retour.
     */
    #[Test]
    public function no_value_of_a_report_is_an_absolute_path(): void
    {
        $plan = $this->rootedClassPlan();

        foreach ([
            (new PreviewBackend())->provision($plan)->toArray(),
            (new PreviewBackend())->inspect($plan)->toArray(),
        ] as $data) {
            foreach (explode("\n", $this->plainTextOfArray($data)) as $value) {
                $this->assertStringStartsNotWith('/', $value, 'chemin absolu dans un rapport : ' . $value);
            }
        }
    }
}
