<?php

/**
 * Central place to resolve YOURLS table names.
 *
 * Table names are built from YOURLS_DB_PREFIX, which is defined by the user in config.php. Having
 * a single helper means migrations, the installer and the console all agree on the same names,
 * and that a prefix is never silently assumed to be a safe SQL identifier.
 *
 * @since 1.10.5
 */

namespace YOURLS\Database;

class Schema {

    /**
     * Logical table names, ie the part after the prefix
     */
    public const URL     = 'url';
    public const OPTIONS = 'options';
    public const LOG     = 'log';

    /**
     * Return the prefixed name of a table
     *
     * Note this returns a bare (unquoted) name: pass it through the DBAL schema API or through
     * \YOURLS\Database\YDB::table() before putting it in a SQL string.
     *
     * @since  1.10.5
     * @param  string $name Logical table name, eg 'url'
     * @return string       Prefixed table name, eg 'yourls_url'
     */
    public static function table(string $name): string {
        return self::prefix() . $name;
    }

    /**
     * Return the configured table prefix
     *
     * @since  1.10.5
     * @return string
     */
    public static function prefix(): string {
        return defined('YOURLS_DB_PREFIX') ? (string)YOURLS_DB_PREFIX : '';
    }

    /**
     * Name of the table storing short URLs
     *
     * Honours the YOURLS_DB_TABLE_* constants when they are defined, since a user (or a plugin)
     * may override them independently of the prefix.
     *
     * @since  1.10.5
     * @return string
     */
    public static function url(): string {
        return defined('YOURLS_DB_TABLE_URL') ? (string)YOURLS_DB_TABLE_URL : self::table(self::URL);
    }

    /**
     * Name of the table storing options
     *
     * @since  1.10.5
     * @return string
     */
    public static function options(): string {
        return defined('YOURLS_DB_TABLE_OPTIONS') ? (string)YOURLS_DB_TABLE_OPTIONS : self::table(self::OPTIONS);
    }

    /**
     * Name of the table storing click logs
     *
     * @since  1.10.5
     * @return string
     */
    public static function log(): string {
        return defined('YOURLS_DB_TABLE_LOG') ? (string)YOURLS_DB_TABLE_LOG : self::table(self::LOG);
    }

    /**
     * All YOURLS tables, in creation order
     *
     * @since  1.10.5
     * @return array<string,string>  logical name => prefixed name
     */
    public static function all(): array {
        return [
            self::URL     => self::url(),
            self::OPTIONS => self::options(),
            self::LOG     => self::log(),
        ];
    }
}
