<?php

/**
 * Stub ldap.inc.php — empêche le chargement du vrai legacy ldap.inc.php.
 * Notre shim (legacy/ldap.inc.php) est déjà chargé via le bootstrap.
 */

require_once __DIR__ . '/../ldap.inc.php';
