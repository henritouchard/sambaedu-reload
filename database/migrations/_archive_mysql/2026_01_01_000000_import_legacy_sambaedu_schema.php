<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     * 
     * Import du schéma complet de la base de données legacy sambaedu.
     * Toutes les tables sont créées avec IF NOT EXISTS pour permettre
     * une migration progressive.
     */
    public function up(): void
    {
        // Désactiver les vérifications de clés étrangères temporairement
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: applications
        DB::statement("CREATE TABLE IF NOT EXISTS `applications` (
            `id_app` int(11) NOT NULL AUTO_INCREMENT,
            `id_nom_app` varchar(255) NOT NULL,
            `nom_app` varchar(255) NOT NULL,
            `xml` varchar(255) NOT NULL,
            `url_xml` varchar(255) NOT NULL,
            `sha_xml` varchar(128) NOT NULL,
            `url_log` varchar(255) NOT NULL,
            `categorie` varchar(255) NOT NULL,
            `compatibilite` tinyint(4) NOT NULL,
            `version` varchar(255) NOT NULL,
            `branche` varchar(20) NOT NULL,
            `date` datetime NOT NULL,
            `id_depot` int(11) NOT NULL,
            `active` tinyint(4) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id_app`),
            KEY `id_depot` (`id_depot`,`branche`),
            KEY `id_nom_app` (`id_nom_app`,`id_depot`,`branche`),
            KEY `id_depot_2` (`id_depot`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT='legacy'");

        // Table: connexions_stat
        DB::statement("CREATE TABLE IF NOT EXISTS `connexions_stat` (
            `id_connexions_stat` int(11) NOT NULL AUTO_INCREMENT,
            `netbios_name` varchar(255) NOT NULL,
            `date` date NOT NULL,
            `h0` bit(4) NOT NULL DEFAULT b'0',
            `h1` bit(4) NOT NULL DEFAULT b'0',
            `h2` bit(4) NOT NULL DEFAULT b'0',
            `h3` bit(4) NOT NULL DEFAULT b'0',
            `h4` bit(4) NOT NULL DEFAULT b'0',
            `h5` bit(4) NOT NULL DEFAULT b'0',
            `h6` bit(4) NOT NULL DEFAULT b'0',
            `h7` bit(4) NOT NULL DEFAULT b'0',
            `h8` bit(4) NOT NULL DEFAULT b'0',
            `h9` bit(4) NOT NULL DEFAULT b'0',
            `h10` bit(4) NOT NULL DEFAULT b'0',
            `h11` bit(4) NOT NULL DEFAULT b'0',
            `h12` bit(4) NOT NULL DEFAULT b'0',
            `h13` bit(4) NOT NULL DEFAULT b'0',
            `h14` bit(4) NOT NULL DEFAULT b'0',
            `h15` bit(4) NOT NULL DEFAULT b'0',
            `h16` bit(4) NOT NULL DEFAULT b'0',
            `h17` bit(4) NOT NULL DEFAULT b'0',
            `h18` bit(4) NOT NULL DEFAULT b'0',
            `h19` bit(4) NOT NULL DEFAULT b'0',
            `h20` bit(4) NOT NULL DEFAULT b'0',
            `h21` bit(4) NOT NULL DEFAULT b'0',
            `h22` bit(4) NOT NULL DEFAULT b'0',
            `h23` bit(4) NOT NULL DEFAULT b'0',
            PRIMARY KEY (`id_connexions_stat`),
            UNIQUE KEY `netbios_name` (`netbios_name`,`date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT='legacy'");

        // Table: dependance
        DB::statement("CREATE TABLE IF NOT EXISTS `dependance` (
            `id_dependance` int(11) NOT NULL AUTO_INCREMENT,
            `id_app` int(11) NOT NULL,
            `id_app_requise` int(11) NOT NULL,
            PRIMARY KEY (`id_dependance`),
            UNIQUE KEY `id_app` (`id_app`,`id_app_requise`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT='legacy'");

        // Table: depot
        DB::statement("CREATE TABLE IF NOT EXISTS `depot` (
            `id_depot` int(11) NOT NULL AUTO_INCREMENT,
            `url_depot` varchar(255) NOT NULL,
            `nom_depot` varchar(255) NOT NULL,
            `depot_actif` tinyint(4) NOT NULL,
            `depot_principal` tinyint(4) NOT NULL,
            `hash_xml` varchar(255) DEFAULT NULL,
            PRIMARY KEY (`id_depot`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT='legacy'");

        // Table: depot_applications
        DB::statement("CREATE TABLE IF NOT EXISTS `depot_applications` (
            `id_depot_applications` int(11) NOT NULL AUTO_INCREMENT,
            `id_nom_app` varchar(255) NOT NULL,
            `nom_app` varchar(255) NOT NULL,
            `xml` varchar(255) NOT NULL,
            `url_xml` varchar(255) NOT NULL,
            `sha_xml` varchar(128) NOT NULL,
            `url_log` varchar(255) NOT NULL,
            `categorie` varchar(255) NOT NULL,
            `compatibilite` tinyint(4) NOT NULL,
            `version` varchar(255) NOT NULL,
            `branche` varchar(20) NOT NULL,
            `date` datetime NOT NULL,
            `id_depot` int(11) NOT NULL,
            `active` tinyint(4) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id_depot_applications`),
            KEY `id_depot` (`id_depot`,`branche`),
            KEY `id_nom_app` (`id_nom_app`,`id_depot`,`branche`),
            KEY `id_depot_2` (`id_depot`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT='legacy'");

        // Table: journal_app
        DB::statement("CREATE TABLE IF NOT EXISTS `journal_app` (
            `id_journal_app` int(11) NOT NULL AUTO_INCREMENT,
            `id_app` int(11) NOT NULL,
            `operation_journal_app` varchar(3) NOT NULL,
            `user_journal_app` varchar(255) NOT NULL,
            `date_journal_app` datetime NOT NULL,
            `xml_journal_app` varchar(255) NOT NULL,
            `sha_journal_app` varchar(128) NOT NULL,
            PRIMARY KEY (`id_journal_app`),
            KEY `id_app` (`id_app`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT='legacy'");

        // Table: machines (pour ipxe/WOL)
        DB::statement("CREATE TABLE IF NOT EXISTS `machines` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(20) NOT NULL DEFAULT '',
            `starttime` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            `stoptime` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            `os` varchar(20) NOT NULL DEFAULT '',
            `wol` int(11) NOT NULL DEFAULT 0,
            `ipxe` int(11) NOT NULL DEFAULT 0,
            `error` int(11) NOT NULL DEFAULT 0,
            `speed` int(11) NOT NULL DEFAULT 0,
            `vlan` varchar(4) NOT NULL DEFAULT '',
            `port` varchar(20) NOT NULL DEFAULT '',
            `switchIP` varchar(20) NOT NULL DEFAULT '',
            `switchName` varchar(32) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            KEY `name` (`name`,`stoptime`),
            KEY `stoptime` (`stoptime`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT='legacy'");

        // Table: mise_en_forme
        DB::statement("CREATE TABLE IF NOT EXISTS `mise_en_forme` (
            `id_mef` int(11) NOT NULL AUTO_INCREMENT,
            `label_mef` varchar(25) NOT NULL,
            `value_mef` varchar(6) NOT NULL,
            `test_mef` varchar(6) NOT NULL,
            `default_mef` varchar(6) NOT NULL,
            PRIMARY KEY (`id_mef`),
            UNIQUE KEY `label_mef` (`label_mef`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT='legacy'");

        // Table: parc (legacy mais utilisée par Laravel)
        DB::statement("CREATE TABLE IF NOT EXISTS `parc` (
            `id_parc` int(11) NOT NULL AUTO_INCREMENT,
            `nom_parc` varchar(255) NOT NULL,
            `nom_parc_wpkg` varchar(255) DEFAULT NULL,
            `uuid` varchar(36) DEFAULT NULL,
            `flag_parc` tinyint(4) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id_parc`),
            UNIQUE KEY `nom_parc` (`nom_parc`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci");

        // Table: parc_profile (relation many-to-many legacy mais utilisée par Laravel)
        DB::statement("CREATE TABLE IF NOT EXISTS `parc_profile` (
            `id_parc_profile` int(11) NOT NULL AUTO_INCREMENT,
            `id_parc` int(11) NOT NULL,
            `id_poste` int(11) NOT NULL,
            PRIMARY KEY (`id_parc_profile`),
            UNIQUE KEY `id_parc` (`id_parc`,`id_poste`),
            KEY `id_parc_2` (`id_parc`),
            KEY `id_poste` (`id_poste`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci");

        // Table: poste_app (applications installées sur les postes)
        DB::statement("CREATE TABLE IF NOT EXISTS `poste_app` (
            `id_poste_rapport` int(11) NOT NULL AUTO_INCREMENT,
            `id_poste` int(11) NOT NULL,
            `id_app` int(11) NOT NULL,
            `id_nom_app` varchar(255) NOT NULL,
            `revision_poste_app` varchar(255) NOT NULL,
            `statut_poste_app` varchar(13) NOT NULL,
            `reboot_poste_app` tinyint(4) NOT NULL,
            PRIMARY KEY (`id_poste_rapport`),
            KEY `id_app` (`id_app`),
            KEY `id_poste` (`id_poste`),
            KEY `id_app&id_poste` (`id_app`,`id_poste`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT='legacy'");

        // Table: postes (machines/ordinateurs - utilisée par Laravel)
        DB::statement("CREATE TABLE IF NOT EXISTS `postes` (
            `id_poste` int(11) NOT NULL AUTO_INCREMENT,
            `nom_poste` varchar(255) NOT NULL,
            `OS_poste` varchar(20) NOT NULL,
            `date_rapport_poste` datetime NOT NULL,
            `ip_poste` varchar(15) NOT NULL,
            `mac_address_poste` varchar(17) NOT NULL,
            `sha_rapport_poste` varchar(128) NOT NULL,
            `file_log_poste` varchar(255) NOT NULL,
            `file_rapport_poste` varchar(255) NOT NULL,
            `date_modification_poste` datetime NOT NULL,
            `uuid_poste` varchar(36) DEFAULT NULL,
            `flag_poste` tinyint(4) NOT NULL DEFAULT 1,
            PRIMARY KEY (`id_poste`),
            UNIQUE KEY `nom_poste` (`nom_poste`),
            UNIQUE KEY `uuid_poste` (`uuid_poste`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci");

        // Table: quotas
        DB::statement("CREATE TABLE IF NOT EXISTS `quotas` (
            `nom` varchar(255) DEFAULT NULL,
            `quotasoft` mediumint(9) DEFAULT NULL,
            `quotahard` mediumint(9) DEFAULT NULL,
            `partition` varchar(20) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT='legacy'");

        // Réactiver les vérifications de clés étrangères
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Ne rien faire - on ne supprime pas les tables legacy
        // car elles peuvent contenir des données importantes
    }
};
