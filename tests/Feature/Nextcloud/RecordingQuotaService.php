<?php

declare(strict_types=1);

namespace Tests\Feature\Nextcloud;

use App\Services\Filesystem\XfsQuotaService;

/**
 * Correction de revue 61.3 #1 — UN ANNUAIRE SUBSTITUABLE, ET UN COMPTEUR D'APPELS.
 *
 * Deux choses à prouver, et aucune ne se prouve sans cette couture :
 *
 *  1. **que des GROUPES résolus donnent le bon plafond** — les fonctions d'annuaire
 *     legacy ne sont pas chargées hors du runtime SE4, donc sans double, tous les
 *     comptes seraient indéterminables et la moitié des branches ne serait jamais
 *     exercée ;
 *  2. **que le COÛT reste borné** — la résolution d'annuaire est un aller-retour
 *     par personne sur un chemin qui balaye un établissement entier. C'est
 *     exactement le genre de régression qui ne se voit qu'en production : elle ne
 *     casse rien, elle rend le balayage deux fois plus long. On la mesure ici.
 */
final class RecordingQuotaService extends XfsQuotaService
{
    /** Nombre d'ALLERS-RETOURS d'annuaire réellement émis. */
    public int $lookups = 0;

    /** @var array<string, array{groups: list<string>}> */
    public array $directory = [];

    /**
     * @param  array<string, array{groups: list<string>}>  $directory
     */
    public function __construct(array $directory = [])
    {
        parent::__construct();

        $this->directory = $directory;
    }

    protected function readDirectoryIdentity(string $username): ?array
    {
        $this->lookups++;

        return $this->directory[$username] ?? null;
    }
}
