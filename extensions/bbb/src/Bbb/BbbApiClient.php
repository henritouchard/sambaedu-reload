<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Bbb;

/**
 * Story 57.1 — Le contrat du client BBB, **injectable**.
 *
 * Interface pour une raison de test : la page d'administration doit pouvoir être
 * exercée de bout en bout sans le moindre appel réseau, et le MAPPING des
 * retours (succès / secret refusé / injoignable / réponse inattendue) doit être
 * prouvé sur des réponses réelles de la bibliothèque, pas sur une doublure du
 * mapping lui-même.
 */
interface BbbApiClient
{
    /**
     * Éprouve une paire (URL, secret) par un appel **checksummé**.
     *
     * Un simple `GET` sur l'URL de base ne validerait que l'URL — c'est ce que
     * faisait le legacy avec `server_bbb_is_up()`, et cela laissait passer un
     * secret erroné jusqu'au premier salon créé.
     */
    public function testConnection(string $baseUrl, string $secret): ConnectionResult;
}
