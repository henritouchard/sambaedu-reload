<?php

/*
 * Stub anti-collision wpkg_libsql.php
 *
 * `associations_out.php` fait `include("wpkg_libsql.php")` qui, sans ce stub,
 * résoudrait vers `sambaedu/includes/wpkg_libsql.php` (l'original avec mysqli_*)
 * — car les stubs/ sont préfixés dans include_path.
 *
 * Ce stub intercepte le chargement via include_path et redirige vers le shim
 * `legacy/wpkg_libsql.php` (déjà chargé par bootstrap.php via require_once avec
 * chemin absolu). PHP reconnaît le chemin déjà require'd → no-op sûr.
 *
 * Sans ce stub : Fatal "Cannot redeclare function" sur les fonctions shimmés.
 */

require_once __DIR__ . '/../wpkg_libsql.php';
