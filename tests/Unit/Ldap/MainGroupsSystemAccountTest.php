<?php

namespace Tests\Unit\Ldap;

use App\Constants\Ldap\MainGroups;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Les comptes réservés sont reconnus par ÉGALITÉ, jamais par sous-chaîne.
 *
 * Le legacy détectait les sous-chaînes « admin », « exam », « invite » et
 * « test » — motifs non ancrés, donc capturant de vrais patronymes. Un login
 * ainsi capturé était exclu de la synchro AD→SQL (`UserSyncService`) : ni ligne
 * `users`, ni rôle Spatie. Ces tests verrouillent la non-régression.
 */
class MainGroupsSystemAccountTest extends TestCase
{
    public static function reservedLogins(): array
    {
        return [
            'admin' => ['admin'],
            'casse differente' => ['ADMIN'],
            'administrateur windows' => ['Administrator'],
            'compte kerberos' => ['krbtgt'],
            'compte d installation' => ['se4install'],
            'service web' => ['www-sambaedu'],
            'invite francise' => ['invite'],
            'prefixe technique' => ['api-inventaire'],
            'prefixe technique casse' => ['API-Inventaire'],
        ];
    }

    public static function realPeopleLogins(): array
    {
        return [
            // Chacun de ces logins contient un motif legacy en sous-chaîne.
            'badminton contient admin' => ['badminton.leo'],
            'examine contient exam' => ['examine.paul'],
            'invited contient invite' => ['invited.luc'],
            'testard contient test' => ['testard.jean'],
            'administrativement quelconque' => ['adminatole.durand'],
            'login sans piege' => ['dupont.jean'],
        ];
    }

    #[Test]
    #[DataProvider('reservedLogins')]
    public function it_reconnait_les_comptes_reserves(string $login): void
    {
        $this->assertTrue(
            MainGroups::isSystemAccount($login),
            "Le login réservé « {$login} » devrait être reconnu comme compte système."
        );
    }

    #[Test]
    #[DataProvider('realPeopleLogins')]
    public function it_ne_capture_pas_les_patronymes(string $login): void
    {
        $this->assertFalse(
            MainGroups::isSystemAccount($login),
            "Le login « {$login} » désigne une personne : le capturer l'exclurait de la synchro AD→SQL."
        );
    }

    #[Test]
    public function seuls_les_comptes_non_interactifs_bloquent_une_attribution(): void
    {
        // `admin` ouvre une vraie session : on doit pouvoir lui attribuer un
        // raccourci, même s'il reste protégé contre la suppression.
        $this->assertFalse(MainGroups::isNonInteractiveAccount('admin'));
        $this->assertTrue(MainGroups::isSystemAccount('admin'));

        // `krbtgt` n'ouvre jamais de session : rien ne peut lui être attribué.
        $this->assertTrue(MainGroups::isNonInteractiveAccount('krbtgt'));
        $this->assertTrue(MainGroups::isNonInteractiveAccount('api-inventaire'));
        $this->assertFalse(MainGroups::isNonInteractiveAccount('dupont.jean'));
    }
}
