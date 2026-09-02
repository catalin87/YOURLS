<?php

/**
 * Doctrine DBAL connector for YOURLS.
 *
 * This class is the modern replacement for the ezSQL/Aura.Sql query engine. It wraps a
 * Doctrine\DBAL\Connection and exposes:
 *
 *   - a QueryBuilder factory (queryBuilder()) for building queries programmatically,
 *   - a secure table-name helper (table()) that validates the dynamic DB prefix,
 *   - a set of fetch_* helpers whose return shapes are byte-for-byte compatible with the
 *     legacy PDO/Aura layer used by \YOURLS\Database\YDB.
 *
 * CRITICAL COMPATIBILITY CONTRACT
 * -------------------------------
 * A large amount of legacy YOURLS (and third party plugin) code consumes rows as
 * \stdClass objects (via fetchObject / fetchObjects) and NOT as arrays. Other code paths
 * expect associative arrays (fetchOne / fetchAll / fetchAssoc). This class reproduces the
 * exact same row shapes the old Aura\Sql\ExtendedPdo produced, so callers do not have to
 * change. See \YOURLS\Database\YDB for the routing of each method.
 *
 * SECURITY - DYNAMIC TABLE PREFIX
 * -------------------------------
 * The DB table prefix (YOURLS_DB_PREFIX) is admin-controlled config, never user input, but
 * because it is interpolated into SQL identifiers (which cannot be bound as parameters), we
 * defensively validate it against a strict allow-list and quote it with the platform's
 * identifier quoting. See table() / quoteIdentifier().
 *
 * @since 1.11
 */

namespace YOURLS\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;

class DoctrineConnector {

    /**
     * The wrapped Doctrine DBAL connection.
     *
     * @var Connection
     */
    protected Connection $connection;

    /**
     * Cache of validated, fully-qualified (prefixed) table identifiers.
     *
     * @var array<string,string>
     */
    protected array $table_cache = [];

    /**
     * @param Connection $connection A ready Doctrine DBAL connection
     */
    public function __construct(Connection $connection) {
        $this->connection = $connection;
    }

    /**
     * Build a Doctrine DBAL Connection from YOURLS-style DSN + credentials and wrap it.
     *
     * We reuse the exact same DSN string that class-mysql.php builds so the connection
     * charset/host/port handling stays identical. Doctrine parses a PDO-style URL, so we
     * translate the "mysql:host=...;dbname=...;charset=..." DSN into a params array.
     *
     * @param string $dsn     A PDO mysql DSN, e.g. "mysql:host=127.0.0.1;dbname=yourls;charset=utf8mb4"
     * @param string $user    DB user
     * @param string $pass    DB password
     * @param array  $options Driver options (PDO::ATTR_* keyed). Passed through to the driver.
     * @return self
     */
    public static function fromDsn(string $dsn, string $user, string $pass, array $options = []): self {
        $params = self::parsePdoDsn($dsn);
        $params['user']          = $user;
        $params['password']      = $pass;
        $params['driver']        = 'pdo_mysql';
        // Preserve the caller-provided PDO driver options (e.g. attributes set by plugins)
        if ($options) {
            $params['driverOptions'] = $options;
        }

        $connection = DriverManager::getConnection($params);

        return new self($connection);
    }

    /**
     * Translate a PDO "mysql:key=value;key=value" DSN into Doctrine connection params.
     *
     * @param string $dsn
     * @return array<string,mixed>
     */
    protected static function parsePdoDsn(string $dsn): array {
        $params = [];

        // Strip the "mysql:" (or other driver) scheme prefix
        $colon = strpos($dsn, ':');
        $body  = $colon === false ? $dsn : substr($dsn, $colon + 1);

        foreach (explode(';', $body) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            $key   = trim(substr($pair, 0, $eq));
            $value = trim(substr($pair, $eq + 1));

            switch ($key) {
                case 'host':
                    // YOURLS may fold "host;port=NNNN" into the host part upstream; handle both.
                    $params['host'] = $value;
                    break;
                case 'port':
                    $params['port'] = (int) $value;
                    break;
                case 'dbname':
                    $params['dbname'] = $value;
                    break;
                case 'charset':
                    $params['charset'] = $value;
                    break;
                case 'unix_socket':
                    $params['unix_socket'] = $value;
                    break;
                default:
                    // Unknown DSN attribute: keep it verbatim so nothing is silently dropped
                    $params[$key] = $value;
            }
        }

        // If YOURLS already merged the port into the host as "host;port=NNNN"
        if (isset($params['host']) && str_contains($params['host'], ';port=')) {
            [$host, $port]  = explode(';port=', $params['host'], 2);
            $params['host'] = $host;
            $params['port'] = (int) $port;
        }

        return $params;
    }

    /**
     * @return Connection The underlying Doctrine DBAL connection.
     */
    public function getConnection(): Connection {
        return $this->connection;
    }

    /**
     * Create a fresh QueryBuilder.
     *
     * This is the preferred entry point for building queries in modern YOURLS code.
     * Combine with table() for safe prefixed identifiers, and setParameter()/named
     * placeholders for all *values* (never interpolate values into SQL).
     *
     * @return QueryBuilder
     */
    public function queryBuilder(): QueryBuilder {
        return $this->connection->createQueryBuilder();
    }

    /**
     * Return a validated, quoted, fully-qualified table identifier for use in a QueryBuilder.
     *
     * $suffix is one of the logical table names: 'url', 'options', 'log'. The full physical
     * name is <YOURLS_DB_PREFIX><suffix>. Because table names are identifiers (not bindable
     * parameters), we validate both the prefix and the resulting identifier against a strict
     * pattern and then quote them, defeating any identifier-injection even if the prefix were
     * ever tampered with.
     *
     * Passing an already-fully-qualified constant (e.g. YOURLS_DB_TABLE_URL) is also supported:
     * if $suffix already begins with the configured prefix it is used as-is (after validation).
     *
     * @param string $suffix Logical table suffix ('url'|'options'|'log') or a full table name.
     * @return string A safe, quoted identifier ready to drop into QueryBuilder::from()/insert()/etc.
     */
    public function table(string $suffix): string {
        if (isset($this->table_cache[$suffix])) {
            return $this->table_cache[$suffix];
        }

        $prefix = defined('YOURLS_DB_PREFIX') ? (string) YOURLS_DB_PREFIX : '';

        // Harden the prefix: it must be a plain SQL identifier fragment. This is admin config,
        // but we never trust it blindly since it lands in an identifier position.
        if ($prefix !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
            throw new \InvalidArgumentException(
                'Refusing to build SQL: YOURLS_DB_PREFIX contains illegal characters.'
            );
        }

        // If caller already passed a fully-qualified name (constant), don't double-prefix.
        $table = ($prefix !== '' && str_starts_with($suffix, $prefix))
            ? $suffix
            : $prefix . $suffix;

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException(
                'Refusing to build SQL: computed table name "' . $table . '" is not a valid identifier.'
            );
        }

        $quoted = $this->quoteIdentifier($table);
        $this->table_cache[$suffix] = $quoted;

        return $quoted;
    }

    /**
     * Quote an SQL identifier (table/column name) using the connection's platform.
     *
     * @param string $identifier
     * @return string
     */
    public function quoteIdentifier(string $identifier): string {
        return $this->connection->quoteIdentifier($identifier);
    }

    /* -----------------------------------------------------------------------------------------
     * Fetch helpers with legacy-compatible return shapes.
     *
     * $statement uses named (:name) or positional (?) placeholders exactly like the old layer.
     * $values are bound as parameters. These mirror Aura\Sql\ExtendedPdo one-to-one so that
     * \YOURLS\Database\YDB can delegate to them transparently.
     * --------------------------------------------------------------------------------------- */

    /**
     * @param string $statement
     * @param array  $values
     * @return int Affected row count.
     */
    public function fetchAffected(string $statement, array $values = []): int {
        return (int) $this->connection->executeStatement($statement, $values);
    }

    /**
     * @param string $statement
     * @param array  $values
     * @return array List of associative-array rows.
     */
    public function fetchAll(string $statement, array $values = []): array {
        return $this->connection->executeQuery($statement, $values)->fetchAllAssociative();
    }

    /**
     * Rows keyed by their first column (last row wins on duplicate keys), each an assoc array.
     *
     * @param string $statement
     * @param array  $values
     * @return array
     */
    public function fetchAssoc(string $statement, array $values = []): array {
        $result = $this->connection->executeQuery($statement, $values);
        $data   = [];
        while (($row = $result->fetchAssociative()) !== false) {
            $data[current($row)] = $row;
        }
        return $data;
    }

    /**
     * First column of every row, as a sequential array.
     *
     * @param string $statement
     * @param array  $values
     * @return array
     */
    public function fetchCol(string $statement, array $values = []): array {
        return $this->connection->executeQuery($statement, $values)->fetchFirstColumn();
    }

    /**
     * Fetch a single row as an object (default \stdClass), column values mapped to properties.
     *
     * NOTE: Doctrine DBAL has no native fetchObject, so we replicate PDO's behavior by casting
     * the associative row to the target class. For the default stdClass this is exactly the row
     * as an object. For a custom class, properties are assigned like PDO::FETCH_CLASS (values
     * set directly on the instance).
     *
     * @param string $statement
     * @param array  $values
     * @param string $class Target class name. Default 'stdClass'.
     * @param array  $args  Constructor args for a custom class.
     * @return object|false The row as an object, or false if there is no row.
     */
    public function fetchObject(
        string $statement,
        array $values = [],
        string $class = 'stdClass',
        array $args = []
    ): object|false {
        $row = $this->connection->executeQuery($statement, $values)->fetchAssociative();
        if ($row === false) {
            return false;
        }
        return $this->rowToObject($row, $class, $args);
    }

    /**
     * Fetch all rows as objects (default \stdClass).
     *
     * @param string $statement
     * @param array  $values
     * @param string $class
     * @param array  $args
     * @return array List of objects.
     */
    public function fetchObjects(
        string $statement,
        array $values = [],
        string $class = 'stdClass',
        array $args = []
    ): array {
        $rows = $this->connection->executeQuery($statement, $values)->fetchAllAssociative();
        $out  = [];
        foreach ($rows as $row) {
            $out[] = $this->rowToObject($row, $class, $args);
        }
        return $out;
    }

    /**
     * Fetch one row as an associative array, or false if none.
     *
     * @param string $statement
     * @param array  $values
     * @return array|false
     */
    public function fetchOne(string $statement, array $values = []): array|false {
        return $this->connection->executeQuery($statement, $values)->fetchAssociative();
    }

    /**
     * Fetch key-value pairs (first column => second column).
     *
     * @param string $statement
     * @param array  $values
     * @return array
     */
    public function fetchPairs(string $statement, array $values = []): array {
        return $this->connection->executeQuery($statement, $values)->fetchAllKeyValue();
    }

    /**
     * Fetch a single scalar (first column of the first row).
     *
     * @param string $statement
     * @param array  $values
     * @return mixed
     */
    public function fetchValue(string $statement, array $values = []): mixed {
        return $this->connection->executeQuery($statement, $values)->fetchOne();
    }

    /**
     * Execute a statement and return the raw Doctrine Result (analogous to a PDOStatement).
     *
     * Kept for parity with the old ->perform()/->query() surface. Prefer the typed fetch_*
     * helpers or the QueryBuilder for new code.
     *
     * @param string $statement
     * @param array  $values
     * @return \Doctrine\DBAL\Result
     */
    public function perform(string $statement, array $values = []): \Doctrine\DBAL\Result {
        return $this->connection->executeQuery($statement, $values);
    }

    /**
     * Convert an associative row to an object, mirroring PDO::FETCH_CLASS / fetchObject().
     *
     * PDO assigns property values BEFORE invoking the constructor; we approximate that by
     * setting the raw properties first, then invoking the constructor with $args (if given).
     *
     * @param array  $row
     * @param string $class
     * @param array  $args
     * @return object
     */
    protected function rowToObject(array $row, string $class, array $args): object {
        if ($class === 'stdClass' || $class === '\stdClass') {
            return (object) $row;
        }

        // Instantiate without calling the constructor, assign properties (PDO order), then
        // call the constructor with the provided args to match PDO::FETCH_CLASS semantics.
        $ref    = new \ReflectionClass($class);
        $object = $ref->newInstanceWithoutConstructor();
        foreach ($row as $name => $value) {
            $object->$name = $value;
        }
        $ctor = $ref->getConstructor();
        if ($ctor !== null) {
            $ctor->invokeArgs($object, $args);
        }
        return $object;
    }
}
