<?php
/**
 * Stub ihm.inc.php — VENDORE IN-REPO (Story 38.4, D6).
 *
 * Les modules Tier 3 font `include "ihm.inc.php"` (SANS _once) plusieurs fois
 * par requête. On délègue à un corps vendoré chargé via require_once : la
 * déduplication par realpath garantit UNE seule déclaration des fonctions
 * legacy (le guard `if(defined)return;` ne suffit PAS — PHP lie les fonctions
 * top-level dès l'include, avant le return). Plus AUCUNE délégation
 * /var/www/sambaedu.
 */
require_once __DIR__ . '/_vendored/ihm.body.php';
