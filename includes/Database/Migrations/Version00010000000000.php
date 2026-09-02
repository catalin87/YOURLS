<?php

/**
 * Initial YOURLS schema migration.
 *
 * Creates the three core YOURLS tables (url, options, log) with byte-for-byte the same column
 * types, collations and indexes as the legacy yourls_create_sql_tables(), so a database created via
 * `bin/console yourls:install` (which runs Doctrine migrations) is identical to one created by the
 * classic web installer.
 *
 * The physical table names derive from the admin-defined YOURLS_DB_PREFIX. They are run through
 * \YOURLS\Database\TablePrefix::quote() so a malformed/hostile prefix can never inject SQL through
 * the identifier position (table names cannot be bound as parameters).
 *
 * @since 1.11
 */

namespace YOURLS\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use YOURLS\Database\TablePrefix;

final class Version00010000000000 extends AbstractMigration {

    public function getDescription(): string {
        return 'Create the initial YOURLS schema: url, options and log tables.';
    }

    public function up(Schema $schema): void {
        $url     = TablePrefix::quote(YOURLS_DB_TABLE_URL);
        $options = TablePrefix::quote(YOURLS_DB_TABLE_OPTIONS);
        $log     = TablePrefix::quote(YOURLS_DB_TABLE_LOG);

        // --- URL table (short URLs). utf8mb4_bin keeps keywords/URLs case-sensitive.
        $this->addSql(
            "CREATE TABLE IF NOT EXISTS $url ("
            . "`keyword` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',"
            . "`url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,"
            . "`title` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,"
            . "`timestamp` timestamp NOT NULL DEFAULT current_timestamp(),"
            . "`ip` varchar(41) COLLATE utf8mb4_unicode_ci NOT NULL,"
            . "`clicks` int(10) unsigned NOT NULL,"
            . "PRIMARY KEY (`keyword`),"
            . "KEY `ip` (`ip`),"
            . "KEY `timestamp` (`timestamp`),"
            . "KEY `url_idx` (`url`(30))"
            . ") DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;"
        );

        // --- Options table. Note the (unusual, historical) composite PK (option_id, option_name).
        $this->addSql(
            "CREATE TABLE IF NOT EXISTS $options ("
            . "`option_id` bigint(20) unsigned NOT NULL auto_increment,"
            . "`option_name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL default '',"
            . "`option_value` longtext COLLATE utf8mb4_unicode_ci NOT NULL,"
            . "PRIMARY KEY  (`option_id`,`option_name`),"
            . "KEY `option_name` (`option_name`)"
            . ") AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
        );

        // --- Log table (click stats).
        $this->addSql(
            "CREATE TABLE IF NOT EXISTS $log ("
            . "`click_id` int(11) NOT NULL auto_increment,"
            . "`click_time` datetime NOT NULL,"
            . "`shorturl` varchar(100) BINARY NOT NULL,"
            . "`referrer` varchar(200) NOT NULL,"
            . "`user_agent` varchar(255) NOT NULL,"
            . "`ip_address` varchar(41) NOT NULL,"
            . "`country_code` char(2) NOT NULL,"
            . "PRIMARY KEY  (`click_id`),"
            . "KEY `shorturl` (`shorturl`)"
            . ") AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
        );
    }

    public function down(Schema $schema): void {
        $this->addSql( 'DROP TABLE IF EXISTS ' . TablePrefix::quote(YOURLS_DB_TABLE_LOG) );
        $this->addSql( 'DROP TABLE IF EXISTS ' . TablePrefix::quote(YOURLS_DB_TABLE_OPTIONS) );
        $this->addSql( 'DROP TABLE IF EXISTS ' . TablePrefix::quote(YOURLS_DB_TABLE_URL) );
    }
}
