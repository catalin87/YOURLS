<?php

declare(strict_types=1);

namespace YOURLS\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use YOURLS\Database\TableRegistry;

/**
 * Create the three YOURLS core tables.
 *
 * The SQL is kept identical to what yourls_create_sql_tables() used to run before 1.11, charsets
 * and collations included: an install created by this migration must be byte for byte compatible
 * with one created by an older YOURLS, since the same code reads from it afterwards.
 *
 * Table names are read from TableRegistry rather than hardcoded, because they depend on the
 * user's YOURLS_DB_PREFIX.
 */
final class Version20240101000000 extends AbstractMigration {

    public function getDescription(): string {
        return 'Create YOURLS core tables: url, options and log';
    }

    public function up(Schema $schema): void {
        $url     = TableRegistry::get('url');
        $options = TableRegistry::get('options');
        $log     = TableRegistry::get('log');

        $this->addSql(
            'CREATE TABLE IF NOT EXISTS `'.$url.'` ('.
            '`keyword` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT \'\','.
            '`url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,'.
            '`title` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,'.
            '`timestamp` timestamp NOT NULL DEFAULT current_timestamp(),'.
            '`ip` varchar(41) COLLATE utf8mb4_unicode_ci NOT NULL,'.
            '`clicks` int(10) unsigned NOT NULL,'.
            'PRIMARY KEY (`keyword`),'.
            'KEY `ip` (`ip`),'.
            'KEY `timestamp` (`timestamp`),'.
            'KEY `url_idx` (`url`(30))'.
            ') DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;'
        );

        $this->addSql(
            'CREATE TABLE IF NOT EXISTS `'.$options.'` ('.
            '`option_id` bigint(20) unsigned NOT NULL auto_increment,'.
            '`option_name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL default \'\','.
            '`option_value` longtext COLLATE utf8mb4_unicode_ci NOT NULL,'.
            'PRIMARY KEY  (`option_id`,`option_name`),'.
            'KEY `option_name` (`option_name`)'.
            ') AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
        );

        $this->addSql(
            'CREATE TABLE IF NOT EXISTS `'.$log.'` ('.
            '`click_id` int(11) NOT NULL auto_increment,'.
            '`click_time` datetime NOT NULL,'.
            '`shorturl` varchar(100) BINARY NOT NULL,'.
            '`referrer` varchar(200) NOT NULL,'.
            '`user_agent` varchar(255) NOT NULL,'.
            '`ip_address` varchar(41) NOT NULL,'.
            '`country_code` char(2) NOT NULL,'.
            'PRIMARY KEY  (`click_id`),'.
            'KEY `shorturl` (`shorturl`)'.
            ') AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
        );
    }

    public function down(Schema $schema): void {
        foreach (['log', 'options', 'url'] as $table) {
            $this->addSql('DROP TABLE IF EXISTS `'.TableRegistry::get($table).'`;');
        }
    }

    /**
     * These statements are DDL: MySQL commits implicitly around them anyway.
     */
    public function isTransactional(): bool {
        return false;
    }

}
