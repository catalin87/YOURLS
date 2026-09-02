<?php

/**
 * Safe resolution of YOURLS table names.
 *
 * Table names are built from the user-supplied YOURLS_DB_PREFIX constant, and they end up being
 * concatenated into SQL strings (Doctrine's QueryBuilder has no placeholder for identifiers).
 * Everything that interpolates a table name should go through this class so a hostile or simply
 * malformed prefix cannot break out of the identifier.
 *
 * @since 1.11
 */

namespace YOURLS\Database;

use YOURLS\Exceptions\ConfigException;

class TableRegistry {

    /**
     * Logical table names, mapped to the constant holding their fully prefixed name
     */
    public const TABLES = [
        'url'     => 'YOURLS_DB_TABLE_URL',
        'options' => 'YOURLS_DB_TABLE_OPTIONS',
        'log'     => 'YOURLS_DB_TABLE_LOG',
    ];

    /**
     * A table name we accept: letters, digits, underscore and dollar, as MySQL allows for unquoted identifiers.
     *
     * We deliberately refuse backticks, spaces, dots and semicolons: a prefix containing any of those is a
     * configuration mistake at best, an injection attempt at worst.
     */
    private const VALID_IDENTIFIER = '/^[A-Za-z0-9_$]{1,64}$/';

    /**
     * Assert a table name is a plain, safe SQL identifier and return it unchanged.
     *
     * @since  1.11
     * @param  string $table  Table name, typically YOURLS_DB_TABLE_URL & friends
     * @return string         The very same table name, guaranteed injection-free
     * @throws ConfigException  If the name is not a valid identifier
     */
    public static function validate(string $table): string {
        if (preg_match(self::VALID_IDENTIFIER, $table) !== 1) {
            throw new ConfigException(sprintf(
                'Invalid table name "%s". Check YOURLS_DB_PREFIX in your config: it must only contain letters, digits and underscores.',
                $table
            ));
        }

        return $table;
    }

    /**
     * Return a table name, quoted with backticks and safe to concatenate in a SQL string.
     *
     * @since  1.11
     * @param  string $table  Table name, typically YOURLS_DB_TABLE_URL & friends
     * @return string         Backquoted table name, eg '`yourls_url`'
     * @throws ConfigException  If the name is not a valid identifier
     */
    public static function quote(string $table): string {
        return '`'.self::validate($table).'`';
    }

    /**
     * Return the validated name of a YOURLS core table from its logical name.
     *
     * @since  1.11
     * @param  string $name  Logical table name: 'url', 'options' or 'log'
     * @return string        Prefixed table name, eg 'yourls_url'
     * @throws ConfigException  If the logical name is unknown, or the resulting name is unsafe
     */
    public static function get(string $name): string {
        if (!isset(self::TABLES[$name])) {
            throw new ConfigException(sprintf('Unknown YOURLS table "%s"', $name));
        }

        $constant = self::TABLES[$name];
        if (!defined($constant)) {
            throw new ConfigException(sprintf('Constant %s is not defined', $constant));
        }

        return self::validate((string)constant($constant));
    }

    /**
     * Return all YOURLS core table names, validated, keyed by logical name.
     *
     * @since  1.11
     * @return array<string,string>
     * @throws ConfigException
     */
    public static function all(): array {
        $tables = [];
        foreach (array_keys(self::TABLES) as $name) {
            $tables[$name] = self::get($name);
        }

        return $tables;
    }

}
