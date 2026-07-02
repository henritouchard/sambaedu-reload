<?php

declare(strict_types=1);

namespace Tests\Unit\Doctor\Checks;

use App\Doctor\Checks\Gpo\GpoTemplateVersionPinCheck;
use App\Doctor\Level;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Garde-fou « bump oublié » : {@see GpoTemplateVersionPinCheck} épingle, par
 * template GPO versionné, le couple (Version GPT.INI, hash du contenu).
 *
 * Ce test rejoue le check sur les VRAIS fichiers de `resources/gpo/` : il
 * échoue donc dès qu'un dev modifie le contenu d'un template épinglé sans
 * reporter le nouveau couple dans `PINS` (et, en pratique, sans bumper la
 * Version) — exactement le scénario à attraper EN DEV-CYCLE (host, sans DC),
 * avant que `import_gpo` (force=false) ne saute silencieusement la
 * republication SYSVOL. Cf. `project_gpo_template_edit_needs_version_bump`.
 */
final class GpoTemplateVersionPinCheckTest extends TestCase
{
    #[Test]
    public function pinned_templates_match_their_committed_content_and_version(): void
    {
        $result = (new GpoTemplateVersionPinCheck())->run();

        self::assertSame(
            Level::Ok,
            $result->level,
            "Pin contenu↔version désaligné : {$result->detail}",
        );
    }

    #[Test]
    public function it_exposes_the_gpo_tag_for_doctor_filtering(): void
    {
        self::assertSame('gpo', (new GpoTemplateVersionPinCheck())->tag());
    }
}
