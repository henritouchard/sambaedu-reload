<?php

/**
 * Bridge autoload — les modules legacy font require_once(dirname(__FILE__).'/../vendor/autoload.php')
 * qui résout vers legacy/modules/vendor/autoload.php.
 *
 * L'autoloader Composer de Laravel est déjà chargé par le bootstrap.
 * Ce fichier existe uniquement pour éviter un fatal error sur le require.
 *
 * Retourne l'autoloader Laravel existant pour compatibilité avec le code
 * qui utilise la valeur de retour ($loader = require 'vendor/autoload.php').
 */

return require_once __DIR__ . '/../../../vendor/autoload.php';
