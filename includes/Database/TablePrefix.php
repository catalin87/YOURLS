<?php

/**
 * Secure handling of the dynamic YOURLS database table prefix.
 *
 * The table prefix (YOURLS_DB_PREFIX) and the derived table names (YOURLS_DB_TABLE_URL,
 * YOURLS_DB_TABLE_OPTIONS, YOURLS_DB_TABLE_LOG) are defined by the site administrator in
 * config.php. They are interpolated into SQL as identifiers, NOT as bound values (you cannot
 * bind a table/identifier name in SQL). That makes them a potential injection vector if a
 * malicious or malformed prefix ever reaches a query builder.
 *
 * This class is the single choke point that validates and quotes those identifiers before they
 * are handed to Doctrine DBAL's QueryBuilder. A prefix/table name that does not match a strict
 * identifier whitelist is rejected outright, so no crafted prefix can break out of the identifier
 * context.
 *
 * @since 1.11
 */

namespace YOURLS\Database;

class TablePrefix {

    /**
     * Strict whitelist for a MySQL identifier used by YOURLS.
     *
     * YOURLS table names are always ASCII letters, digits and underscores (see the CREATE TABLE
     * statements and the YOURLS_DB_PREFIX.'url'/'options'/'log' derivation). We deliberately keep
     * this stricter than MySQL's full identifier grammar: anything outside [A-Za-z0-9_] is refused.
     */
    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_]+$/';

    /**
     * Validate a table name (or prefix) and return it unchanged, or throw.
     *
     * @param  string $identifier Raw table name / prefix coming from config
     * @return string             The validated identifier
     * @throws \InvalidArgumentException When the identifier contains unexpected characters
     */
    public static function validate(string $identifier): string {
        if ($identifier === '' || !preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
            throw new \InvalidArgumentException(
                sprintf('Unsafe SQL identifier "%s": table names and prefixes must match %s', $identifier, self::IDENTIFIER_PATTERN)
            );
        }
        return $identifier;
    }

    /**
     * Validate then backtick-quote a table name for safe interpolation into SQL / QueryBuilder.
     *
     * Even though validate() guarantees the identifier is injection-free, we still quote it with
     * backticks so reserved words and numeric-looking prefixes are handled correctly by MySQL.
     * Any stray backtick is stripped defensively before quoting (validate() already forbids it).
     *
     * @param  string $identifier Raw table name / prefix
     * @return string             Backtick-quoted, validated identifier, e.g. "`yourls_url`"
     */
    public static function quote(string $identifier): string {
        $identifier = self::validate($identifier);
        return '`' . str_replace('`', '', $identifier) . '`';
    }
}
