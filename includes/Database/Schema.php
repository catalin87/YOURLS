<?php

/**
 * YOURLS canonical table schema, expressed once and reused by:
 *   - the Doctrine migration (Version20260101000000_InitialSchema),
 *   - the bin/console yourls:install command,
 *   - the legacy yourls_create_sql_tables() install path.
 *
 * The DDL matches the historical schema in functions-install.php exactly (charsets, collations,
 * keys) so an install produced here is byte-compatible with a classic YOURLS install. Table names
 * are derived from the dynamic YOURLS_DB_PREFIX and are validated before use (see tableName()).
 *
 * @since 1.11
 */

namespace YOURLS\Database;

use Doctrine\DBAL\Connection;

class Schema {

    /**
     * Return the physical table name for a logical suffix, validating the dynamic prefix.
     *
     * Table names are SQL identifiers and cannot be bound as parameters, so we validate both
     * the admin-controlled prefix and the composed name against a strict allow-list. Callers use
     * the returned name inside backtick-quoted DDL.
     *
     * @param string $suffix 'url' | 'options' | 'log'
     * @return string
     */
    public static function tableName(string $suffix): string {
        $prefix = defined('YOURLS_DB_PREFIX') ? (string) YOURLS_DB_PREFIX : '';

        if ($prefix !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
            throw new \InvalidArgumentException('YOURLS_DB_PREFIX contains illegal characters.');
        }

        $name = $prefix . $suffix;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException('Computed table name "' . $name . '" is not a valid identifier.');
        }
        return $name;
    }

    /**
     * Return the CREATE TABLE statements keyed by physical table name.
     *
     * @return array<string,string>
     */
    public static function createStatements(): array {
        $url     = self::tableName('url');
        $options = self::tableName('options');
        $log     = self::tableName('log');

        $tables = [];

        $tables[$url] =
            'CREATE TABLE IF NOT EXISTS `' . $url . '` ('
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
            . ') DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;';

        $tables[$options] =
            'CREATE TABLE IF NOT EXISTS `' . $options . '` ('
            . '`option_id` bigint(20) unsigned NOT NULL auto_increment,'
            . '`option_name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL default \'\','
            . '`option_value` longtext COLLATE utf8mb4_unicode_ci NOT NULL,'
            . 'PRIMARY KEY  (`option_id`,`option_name`),'
            . 'KEY `option_name` (`option_name`)'
            . ') AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        $tables[$log] =
            'CREATE TABLE IF NOT EXISTS `' . $log . '` ('
            . '`click_id` int(11) NOT NULL auto_increment,'
            . '`click_time` datetime NOT NULL,'
            . '`shorturl` varchar(100) BINARY NOT NULL,'
            . '`referrer` varchar(200) NOT NULL,'
            . '`user_agent` varchar(255) NOT NULL,'
            . '`ip_address` varchar(41) NOT NULL,'
            . '`country_code` char(2) NOT NULL,'
            . 'PRIMARY KEY  (`click_id`),'
            . 'KEY `shorturl` (`shorturl`)'
            . ') AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

        return $tables;
    }

    /**
     * Return the DROP TABLE statements (used by migration down()).
     *
     * @return array<int,string>
     */
    public static function dropStatements(): array {
        return [
            'DROP TABLE IF EXISTS `' . self::tableName('log') . '`;',
            'DROP TABLE IF EXISTS `' . self::tableName('options') . '`;',
            'DROP TABLE IF EXISTS `' . self::tableName('url') . '`;',
        ];
    }

    /**
     * Execute all CREATE TABLE statements against a Doctrine connection.
     *
     * @param Connection $connection
     * @return array<string,bool> Map of table name => created/exists success.
     */
    public static function createAll(Connection $connection): array {
        $results = [];
        foreach (self::createStatements() as $name => $ddl) {
            $connection->executeStatement($ddl);
            // Verify the table now exists. fetchOne() returns the table name or false; rowCount()
            // is unreliable for SELECT-like results across drivers, so we check the value.
            $found = $connection->executeQuery('SHOW TABLES LIKE ?', [$name])->fetchOne();
            $results[$name] = ($found !== false && $found !== null);
        }
        return $results;
    }
}
