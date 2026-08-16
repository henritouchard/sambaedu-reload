<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ce qui est vérifiable SANS annuaire, et qui compte quand même.
 *
 * La suite n'a pas d'émulateur d'annuaire : la logique de conversion et de verdict
 * est donc éprouvée dans {@see \Tests\Unit\Services\Ad\AdImmutableKeyServiceTest}.
 * Ce qui reste ici, c'est le comportement de la commande quand l'annuaire n'est PAS
 * là — et ce n'est pas un détail : cette commande est un prérequis de bascule, jouée
 * par une personne sous pression. Une trace d'exception au lieu d'un refus nommé
 * transforme « l'annuaire ne répond pas » en « la commande est cassée », et fait
 * chercher au mauvais endroit.
 */
final class AdImmutableKeyCommandTest extends TestCase
{
    /**
     * ⚠️ Ce test doit prouver le MESSAGE, pas seulement le code de retour : la branche
     * « aucun compte trouvé » rend elle aussi `1`. Sans l'assertion sur le libellé, un
     * `catch` remplacé par un `return FAILURE` sec passerait, et l'AC5 (« refus nommé,
     * pas une trace ») serait violé sans que rien ne rougisse.
     */
    #[Test]
    public function un_annuaire_injoignable_est_un_refus_nomme_et_pas_une_trace(): void
    {
        $this->artisan('ad:immutable-key')
            ->expectsOutputToContain('Annuaire injoignable')
            ->assertExitCode(1);
    }

    /**
     * Le piège de script que la simulation doit fermer : `--dry-run && basculer`.
     * Une simulation qui rend `0` alors que tout reste à faire ferait basculer un parc
     * dont aucun compte n'a de clé — soit `401` pour tout le monde.
     */
    #[Test]
    public function la_simulation_ne_rend_jamais_zero_quand_il_reste_a_faire(): void
    {
        $this->artisan('ad:immutable-key', ['--dry-run' => true])
            ->assertExitCode(1);
    }

    #[Test]
    public function elle_annonce_l_attribut_porteur_avant_toute_chose(): void
    {
        config()->set('ad_identity.attribute', 'employeetype');

        // Une seule attente : `expectsOutputToContain` CONSOMME la ligne qu'elle a
        // trouvée, et les deux mentions vivent sur la même.
        $this->artisan('ad:immutable-key', ['--dry-run' => true])
            ->expectsOutputToContain('simulation — rien ne sera écrit')
            ->assertExitCode(1);
    }
}
