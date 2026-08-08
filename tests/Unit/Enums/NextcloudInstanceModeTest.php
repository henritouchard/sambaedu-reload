<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\NextcloudInstanceMode;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 61.2 — AC1 : le mode d'administration est un ENUM FERMÉ, à défaut sûr, et
 * ses textes de promesse/dégradation VIVENT AVEC LUI.
 *
 * Le test pivot est {@see self::an_unknown_stored_value_falls_back_and_is_logged()} :
 * un repli silencieux ferait qu'une instance déclarée « déléguée » se remettrait à
 * émettre des opérations d'administration sans que personne ne l'apprenne.
 */
class NextcloudInstanceModeTest extends TestCase
{
    #[Test]
    public function the_vocabulary_is_closed_to_two_positions(): void
    {
        self::assertSame(['admin', 'delegue'], NextcloudInstanceMode::values());

        // `posix` n'est PAS un mode d'administration d'instance : c'est l'état de
        // tout partage aujourd'hui, et l'absence d'instance le laisse seul en lice.
        self::assertNull(NextcloudInstanceMode::tryFrom('posix'));
    }

    #[Test]
    public function the_default_is_the_administered_instance(): void
    {
        self::assertSame(NextcloudInstanceMode::Admin, NextcloudInstanceMode::DEFAULT);
        self::assertSame(NextcloudInstanceMode::Admin, NextcloudInstanceMode::fromStored(null));
        self::assertSame(NextcloudInstanceMode::Admin, NextcloudInstanceMode::fromStored(''));
    }

    #[Test]
    public function a_known_value_round_trips(): void
    {
        self::assertSame(NextcloudInstanceMode::Delegue, NextcloudInstanceMode::fromStored('delegue'));
        self::assertSame(NextcloudInstanceMode::Admin, NextcloudInstanceMode::fromStored('admin'));
        self::assertSame(
            NextcloudInstanceMode::Delegue,
            NextcloudInstanceMode::fromStored(NextcloudInstanceMode::Delegue),
        );
    }

    #[Test]
    public function an_unknown_stored_value_falls_back_and_is_logged(): void
    {
        $logged = [];
        Log::listen(function ($message) use (&$logged): void {
            $logged[] = [$message->level, $message->message];
        });

        self::assertSame(NextcloudInstanceMode::Admin, NextcloudInstanceMode::fromStored('nextcloud_delegue'));

        self::assertContains(
            ['warning', 'nextcloud.mode.unknown_value'],
            $logged,
            'un repli sur le défaut doit être DIT : sinon un mode inconnu redevient admin en silence',
        );
    }

    #[Test]
    public function each_case_carries_a_label_and_a_summary(): void
    {
        foreach (NextcloudInstanceMode::cases() as $case) {
            self::assertNotSame('', $case->label());
            self::assertNotSame('', $case->summary());
        }

        // Libellé au SUJET neutre : il nomme le mode, il ne porte pas d'impératif
        // (convention des capacités — l'état est la valeur sélectionnée).
        foreach (NextcloudInstanceMode::cases() as $case) {
            self::assertStringNotContainsStringIgnoringCase('activer', $case->label());
        }
    }

    /** AC6 — les cinq dégradations du délégué sont dites, et elles sont dites ICI. */
    #[Test]
    public function the_delegated_mode_states_its_five_degradations(): void
    {
        $degradations = NextcloudInstanceMode::Delegue->degradations();

        self::assertCount(5, $degradations);

        $all = mb_strtolower(implode(' | ', $degradations));

        // 1. l'arborescence pend d'un compte porteur (propriété, quota, suppression)
        self::assertStringContainsString('compte porteur', $all);
        self::assertStringContainsString('quota', $all);
        // 2. l'octroi est par utilisateur, et la resynchro n'est pas livrée
        self::assertStringContainsString('par utilisateur', $all);
        self::assertStringContainsString('resynchronisation', $all);
        // 3. aucun plafond de zone
        self::assertStringContainsString('plafond de zone', $all);
        // 4. le nœud privé est inexprimable — la mesure du spike
        self::assertStringContainsString('nœud privé', $all);
        self::assertStringContainsString('sans effet', $all);
        // 5. ni montages, ni gestion des comptes
        self::assertStringContainsString('stockage externe', $all);
    }

    #[Test]
    public function the_administered_mode_degrades_nothing_and_promises_the_full_authority(): void
    {
        self::assertSame([], NextcloudInstanceMode::Admin->degradations());

        $promises = mb_strtolower(implode(' | ', NextcloudInstanceMode::Admin->promises()));

        self::assertStringContainsString('groupe', $promises);
        self::assertStringContainsString('plafonds de zone', $promises);
    }

    /**
     * L'honnêteté TEMPORELLE : déclarer un mode ne branche aucun partage
     * aujourd'hui. La taire ferait croire qu'une bascule vient d'avoir lieu.
     */
    #[Test]
    public function both_modes_share_the_temporal_honesty_notice(): void
    {
        $notice = NextcloudInstanceMode::temporalHonesty();

        self::assertStringContainsString('AUCUN partage', $notice);
        self::assertStringContainsString('61.3', $notice);
    }

    /** D9 — le changement de mode ne supprime rien, et l'écran doit le dire. */
    #[Test]
    public function the_mode_change_promises_no_implicit_removal(): void
    {
        $notice = NextcloudInstanceMode::noImplicitRemoval();

        self::assertStringContainsString('ni supprimés ni modifiés', $notice);
    }
}
