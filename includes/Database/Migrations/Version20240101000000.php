<?php

declare(strict_types=1);

/**
 * Initial YOURLS schema.
 *
 * Creates the three core tables (url, options, log) with the exact same definition YOURLS has
 * always used, so an install created by this migration is byte for byte compatible with one
 * created by the historical yourls_create_sql_tables().
 *
 * Table names honour YOURLS_DB_PREFIX via \YOURLS\Database\Schema, and every identifier is quoted
 * by the platform, so an exotic prefix cannot break the statements.
 *
 * @since 1.10.5
 */

namespace YOURLS\Database\Migrations;

use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\Migrations\AbstractMigration;
use YOURLS\Database\Schema;

final class Version20240101000000 extends AbstractMigration {

    public function getDescription(): string {
        return 'Create the YOURLS core tables (url, options, log)';
    }

    public function up(DbalSchema $schema): void {
        $platform = $this->connection->getDatabasePlatform();
        $mysql    = $platform instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

        $url     = $this->connection->quoteIdentifier(Schema::url());
        $options = $this->connection->quoteIdentifier(Schema::options());
        $log     = $this->connection->quoteIdentifier(Schema::log());

        if (!$mysql) {
            // Portable definitions, used by the test suite on other platforms
            $this->addSql(sprintf(
                'CREATE TABLE IF NOT EXISTS %s ('
                . '%s VARCHAR(100) NOT NULL, '
                . '%s TEXT NOT NULL, '
                . '%s TEXT DEFAULT NULL, '
                . '%s TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . '%s VARCHAR(41) NOT NULL, '
                . '%s INTEGER NOT NULL, '
                . 'PRIMARY KEY (%s))',
                $url,
                $this->connection->quoteIdentifier('keyword'),
                $this->connection->quoteIdentifier('url'),
                $this->connection->quoteIdentifier('title'),
                $this->connection->quoteIdentifier('timestamp'),
                $this->connection->quoteIdentifier('ip'),
                $this->connection->quoteIdentifier('clicks'),
                $this->connection->quoteIdentifier('keyword'),
            ));

            $this->addSql(sprintf(
                'CREATE TABLE IF NOT EXISTS %s ('
                . '%s INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, '
                . '%s VARCHAR(64) NOT NULL DEFAULT \'\', '
                . '%s TEXT NOT NULL)',
                $options,
                $this->connection->quoteIdentifier('option_id'),
                $this->connection->quoteIdentifier('option_name'),
                $this->connection->quoteIdentifier('option_value'),
            ));

            $this->addSql(sprintf(
                'CREATE TABLE IF NOT EXISTS %s ('
                . '%s INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, '
                . '%s DATETIME NOT NULL, '
                . '%s VARCHAR(100) NOT NULL, '
                . '%s VARCHAR(200) NOT NULL, '
                . '%s VARCHAR(255) NOT NULL, '
                . '%s VARCHAR(41) NOT NULL, '
                . '%s CHAR(2) NOT NULL)',
                $log,
                $this->connection->quoteIdentifier('click_id'),
                $this->connection->quoteIdentifier('click_time'),
                $this->connection->quoteIdentifier('shorturl'),
                $this->connection->quoteIdentifier('referrer'),
                $this->connection->quoteIdentifier('user_agent'),
                $this->connection->quoteIdentifier('ip_address'),
                $this->connection->quoteIdentifier('country_code'),
            ));

            return;
        }

        $this->addSql(
            'CREATE TABLE IF NOT EXISTS ' . $url . ' ('
            . '`keyword` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT \'\','
            . '`url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,'
            . '`title` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,'
            . '`timestamp` timestamp NOT NULL DEFAULT current_timestamp(),'
            . '`ip` varchar(41) COLLATE utf8mb4_unicode_ci NOT NULL,'
            . '`clicks` int(10) unsigned NOT NULL,'
            . 'PRIMARY KEY (`keyword`),'
            . 'KEY `ip` (`ip`),'
            . 'KEY `timestamp` (`timestamp`),'
            . 'KEY `url_idx` (`url`(30))'
            . ') DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;'
        );

        $this->addSql(
            'CREATE TABLE IF NOT EXISTS ' . $options . ' ('
            . '`option_id` bigint(20) unsigned NOT NULL auto_increment,'
            . '`option_name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL default \'\','
            . '`option_value` longtext COLLATE utf8mb4_unicode_ci NOT NULL,'
            . 'PRIMARY KEY  (`option_id`,`option_name`),'
            . 'KEY `option_name` (`option_name`)'
            . ') AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
        );

        $this->addSql(
            'CREATE TABLE IF NOT EXISTS ' . $log . ' ('
            . '`click_id` int(11) NOT NULL auto_increment,'
            . '`click_time` datetime NOT NULL,'
            . '`shorturl` varchar(100) BINARY NOT NULL,'
            . '`referrer` varchar(200) NOT NULL,'
            . '`user_agent` varchar(255) NOT NULL,'
            . '`ip_address` varchar(41) NOT NULL,'
            . '`country_code` char(2) NOT NULL,'
            . 'PRIMARY KEY  (`click_id`),'
            . 'KEY `shorturl` (`shorturl`)'
            . ') AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
        );
    }

    public function down(DbalSchema $schema): void {
        foreach ([Schema::log(), Schema::options(), Schema::url()] as $table) {
            $this->addSql('DROP TABLE IF EXISTS ' . $this->connection->quoteIdentifier($table));
        }
    }

    /**
     * These are raw CREATE TABLE statements tailored to each platform, so skip the schema diff
     * warning Doctrine emits for migrations that don't use the Schema API.
     */
    public function isTransactional(): bool {
        // MySQL cannot roll back DDL, and wrapping it in a transaction only causes implicit commits
        return false;
    }
}
