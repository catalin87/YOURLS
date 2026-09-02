<?php

/**
 * Factory that builds a Doctrine DBAL Connection for YOURLS.
 *
 * This replaces the Aura\Sql connection bootstrap. It centralises:
 *   - translating YOURLS_DB_* config constants into DBAL connection parameters,
 *   - honouring the historical db_connect_* filters so drop-in DB layers and plugins keep working,
 *   - forcing sane PDO attributes (exceptions on error, real prepares where possible),
 *   - exposing helpers to build connection params from either constants or an explicit array
 *     (the latter is used by the bin/console install command, which may run before config is fully
 *     wired for a live request).
 *
 * @since 1.11
 */

namespace YOURLS\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PDO;

class DoctrineConnector {

    /**
     * Create a Doctrine DBAL connection from an explicit parameter array.
     *
     * @param array $params Doctrine DBAL connection params (driver, host, dbname, user, ...)
     * @return Connection
     */
    public static function create(array $params): Connection {
        $params = self::with_defaults($params);
        return DriverManager::getConnection($params);
    }

    /**
     * Create a Doctrine DBAL connection from YOURLS_DB_* configuration constants.
     *
     * Mirrors the DSN/charset/port logic of the legacy yourls_db_connect(), including the
     * db_connect_charset / db_connect_custom_dsn / db_connect_driver_option / db_connect_attributes
     * filters, so existing plugins that hook these continue to work.
     *
     * @param string $context Optional context string, forwarded to the db_connect_* filters.
     * @return Connection
     */
    public static function fromConstants(string $context = ''): Connection {
        if ( !defined( 'YOURLS_DB_USER' )
             || !defined( 'YOURLS_DB_PASS' )
             || !defined( 'YOURLS_DB_NAME' )
             || !defined( 'YOURLS_DB_HOST' )
        ) {
            yourls_die( yourls__( 'Incorrect DB config, please refer to documentation' ), yourls__( 'Fatal error' ), 503 );
        }

        $dbhost = YOURLS_DB_HOST;
        $user   = YOURLS_DB_USER;
        $pass   = YOURLS_DB_PASS;
        $dbname = YOURLS_DB_NAME;
        $dbport = null;

        // Get custom port if any (host may be "127.0.0.1:3307")
        if (str_contains($dbhost, ':')) {
            [$dbhost, $dbport] = explode(':', $dbhost, 2);
            $dbport = (int) $dbport;
        }

        $charset = yourls_apply_filter( 'db_connect_charset', 'utf8mb4', $context );

        $params = [
            'driver'   => 'pdo_mysql',
            'host'     => $dbhost,
            'dbname'   => $dbname,
            'user'     => $user,
            'password' => $pass,
            'charset'  => $charset,
        ];
        if ($dbport !== null) {
            $params['port'] = $dbport;
        }

        /**
         * Preserve the legacy custom-DSN filter. If a plugin rewrites the DSN string, we parse it
         * back into DBAL params so its host/port/dbname/charset overrides are respected.
         */
        $dsn = sprintf( 'mysql:host=%s;dbname=%s;charset=%s', $dbhost, $dbname, $charset );
        $dsn = yourls_apply_filter( 'db_connect_custom_dsn', $dsn, $context );
        $params = array_merge($params, self::parse_dsn($dsn));

        // Driver options and attributes (key-value pairs), as in the old code.
        $driver_options = (array) yourls_apply_filter( 'db_connect_driver_option', [], $context );
        $attributes     = (array) yourls_apply_filter( 'db_connect_attributes', [], $context );
        $params['driverOptions'] = $driver_options + $attributes;

        return self::create($params);
    }

    /**
     * Apply YOURLS' default PDO attributes/charset unless overridden.
     *
     * @param array $params
     * @return array
     */
    protected static function with_defaults(array $params): array {
        if (!isset($params['charset'])) {
            $params['charset'] = 'utf8mb4';
        }

        $driverOptions = $params['driverOptions'] ?? [];
        // Behave like YOURLS always has: throw on error, and use real (non-emulated) prepares so
        // integer columns come back typed as expected.
        $driverOptions += [
            PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $params['driverOptions'] = $driverOptions;

        return $params;
    }

    /**
     * Parse a legacy "mysql:key=value;key=value" DSN into DBAL params (host/port/dbname/charset/
     * unix_socket). Only keys we understand are returned so it can be array_merge()d over defaults.
     *
     * @param string $dsn
     * @return array
     */
    protected static function parse_dsn(string $dsn): array {
        $out  = [];
        $body = $dsn;
        if (str_contains($dsn, ':')) {
            [, $body] = explode(':', $dsn, 2);
        }
        foreach (explode(';', $body) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $pair, 2);
            switch (trim($k)) {
                case 'host':        $out['host']        = $v; break;
                case 'port':        $out['port']        = (int) $v; break;
                case 'dbname':      $out['dbname']      = $v; break;
                case 'charset':     $out['charset']     = $v; break;
                case 'unix_socket': $out['unix_socket'] = $v; break;
            }
        }
        return $out;
    }
}
