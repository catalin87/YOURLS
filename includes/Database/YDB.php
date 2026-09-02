<?php

/**
 * Doctrine DBAL wrapper for YOURLS that creates the almighty YDB object.
 *
 * A fine example of a "class that knows too much" (see https://en.wikipedia.org/wiki/God_object)
 *
 * Historically this class extended Aura\Sql\ExtendedPdo. It has been migrated to Doctrine DBAL
 * while preserving its ENTIRE public surface so that the thousands of legacy call sites
 * (yourls_get_db()->fetchObjects(...), ->fetchAffected(...), ->set_option(...), ...) keep working
 * unchanged.
 *
 * Critical backward-compatibility contracts preserved here:
 *   - fetchObject()  returns a stdClass object, or false when no row is found
 *   - fetchObjects() returns an array of stdClass objects (empty array when none)
 *   - fetchOne()     returns an associative array, or false when no row is found
 *   - fetchPairs()   returns an associative array (first column => second column)
 *   - fetchValue()   returns the scalar value of the first column of the first row, or false
 *   - fetchCol()     returns a flat array of the first column
 *   - fetchAffected()returns an int (rows affected / matched)
 *   - perform()      returns the underlying statement/result
 *   - named placeholders (":name") with an associative array of binds keep working
 *   - errors surface as \PDOException so existing catch(PDOException|Exception) blocks still work
 *
 * Note to plugin authors: you most likely SHOULD NOT use directly methods and properties of this
 * class. Use instead function wrappers (e.g. don't use $ydb->option, or $ydb->set_option(), use
 * yourls_*_options() functions instead).
 *
 * @since 1.7.3
 */

namespace YOURLS\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use PDO;

class YDB {

    /**
     * The Doctrine DBAL connection.
     *
     * @var Connection
     */
    protected Connection $connection;

    /**
     * Custom profiler / query + debug logger.
     *
     * @var Profiler
     */
    protected Profiler $profiler;

    /**
     * Page context (ie "infos", "bookmark", "plugins"...)
     * @var string
     */
    protected string $context = '';

    /**
     * Information related to a short URL keyword (e.g. timestamp, long URL, ...)
     *
     * @var array
     */
    protected array $infos = [];

    /**
     * Is YOURLS installed and ready to run?
     * @var bool
     */
    protected bool $installed = false;

    /**
     * Options
     * @var array
     */
    protected array $option = [];

    /**
     * Plugin admin pages information
     * @var array
     */
    protected array $plugin_pages = [];

    /**
     * Plugin information
     * @var array
     */
    protected array $plugins = [];

    /**
     * Are we emulating prepare statements ?
     * @var bool
     */
    protected bool $is_emulate_prepare = false;

    /**
     * Bypass shunt filter? See fetch_wrapper()
     * @var bool
     */
    private bool $bypass_shunt_filter = false;

    /**
     * Connection parameters kept so we can (re)connect lazily and report config errors nicely.
     *
     * @var array
     */
    protected array $params;

    /**
     * @since 1.7.3
     *
     * The signature is kept flexible (like the old PDO-style constructor accepting extra args) so
     * that existing code that instantiates YDB with ($dsn, $user, $pass, $driver_options, $attributes)
     * keeps working. Here we translate those PDO-style arguments into Doctrine DBAL connection params.
     *
     * @param string $dsn     The data source name (PDO-style mysql DSN)
     * @param string $user    The username
     * @param string $pass    The password
     * @param array  $options Driver-specific PDO options
     * @param array  $attributes Optional PDO attributes (kept for signature compatibility)
     */
    public function __construct($dsn, $user, $pass, $options = [], $attributes = []) {
        // A pre-built Doctrine DBAL Connection can be injected as the first argument (used by
        // yourls_db_connect(), which resolves the connection through DoctrineConnector so all the
        // db_connect_* filters apply). Otherwise we accept a legacy PDO-style DSN and build lazily.
        if ($dsn instanceof Connection) {
            $this->connection = $dsn;
            $this->params = [];
        } else {
            $this->params = self::dsn_to_params((string) $dsn, (string) $user, (string) $pass, (array) $options);
        }
    }

    /**
     * Translate a legacy PDO mysql DSN into Doctrine DBAL connection parameters.
     *
     * Accepts DSNs like "mysql:host=127.0.0.1;dbname=yourls;charset=utf8mb4" and
     * "mysql:host=127.0.0.1;port=3307;dbname=yourls;charset=utf8mb4", plus unix_socket.
     *
     * @param string $dsn
     * @param string $user
     * @param string $pass
     * @param array  $options
     * @return array
     */
    protected static function dsn_to_params(string $dsn, string $user, string $pass, array $options): array {
        $params = [
            'driver'   => 'pdo_mysql',
            'user'     => $user,
            'password' => $pass,
            'driverOptions' => $options,
        ];

        // Strip the "mysql:" scheme, then parse the "key=value;key=value" pairs.
        $body = $dsn;
        if (str_contains($dsn, ':')) {
            [$scheme, $body] = explode(':', $dsn, 2);
        }

        foreach (explode(';', $body) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $pair, 2);
            switch (trim($k)) {
                case 'host':        $params['host']        = $v; break;
                case 'port':        $params['port']        = (int) $v; break;
                case 'dbname':      $params['dbname']      = $v; break;
                case 'charset':     $params['charset']     = $v; break;
                case 'unix_socket': $params['unix_socket'] = $v; break;
            }
        }

        if (!isset($params['charset'])) {
            $params['charset'] = 'utf8mb4';
        }

        return $params;
    }

    /**
     * Init everything needed
     *
     * Everything we need to set up is done here in init(), not in the constructor, so even
     * when the connection fails (e.g. config error or DB dead), the constructor has worked,
     * and we have a $ydb object properly instantiated (and for instance yourls_die() can
     * correctly die, even if using $ydb methods)
     *
     * @since  1.7.3
     * @return void
     */
    public function init() {
        $this->start_profiler();

        $this->connect_to_DB();

        $this->set_emulate_state();
    }

    /**
     * Build (lazily) and return the Doctrine DBAL connection.
     *
     * @since 1.11
     * @return Connection
     */
    public function connection(): Connection {
        if (!isset($this->connection)) {
            $this->connection = DoctrineConnector::create($this->params);
        }
        return $this->connection;
    }

    /**
     * Return a fresh Doctrine DBAL QueryBuilder bound to this connection.
     *
     * Plugins and core code can use this to build queries fluently. Table names must be passed
     * through TablePrefix::quote() to remain injection-safe (see the Options/install code paths).
     *
     * @since 1.11
     * @return QueryBuilder
     */
    public function createQueryBuilder(): QueryBuilder {
        return $this->connection()->createQueryBuilder();
    }

    /**
     * Check if we emulate prepare statements, and set bool flag accordingly
     *
     * @since  1.7.3
     * @return void
     */
    public function set_emulate_state() {
        try {
            $pdo = $this->connection()->getNativeConnection();
            $this->is_emulate_prepare = ($pdo instanceof PDO)
                ? (bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)
                : false;
        } catch (\Throwable $e) {
            $this->is_emulate_prepare = false;
        }
    }

    /**
     * Get emulate status
     *
     * @since  1.7.3
     * @return bool
     */
    public function get_emulate_state() {
        return $this->is_emulate_prepare;
    }

    /**
     * Initiate real connection to DB server
     *
     * This is to check that the server is running and/or the config is OK
     *
     * @since  1.7.3
     * @return void
     */
    public function connect_to_DB() {
        try {
            $this->connection()->connect();
        } catch ( \Exception $e ) {
            $this->dead_or_error($e);
        }
    }

    /**
     * Die with an error message
     *
     * @since  1.7.3
     *
     * @param \Exception $exception
     *
     * @return void
     */
    public function dead_or_error(\Exception $exception) {
        // Use any /user/db_error.php file
        $file = YOURLS_USERDIR . '/db_error.php';
        if(file_exists($file)) {
            if(yourls_include_file_sandbox( $file ) === true) {
                die();
            }
        }

        $message  = yourls__( 'Incorrect DB config, or could not connect to DB' );
        $message .= '<br/>' . get_class($exception) .': ' . $exception->getMessage();
        yourls_die( yourls__( $message ), yourls__( 'Fatal error' ), 503 );
        die();

    }

    /**
     * Start a Message Logger
     *
     * @since  1.7.3
     * @see    includes/Database/Logger.php
     * @see    includes/Database/Profiler.php
     * @return void
     */
    public function start_profiler() {
        // Instantiate a custom logger and make it the profiler
        $yourls_logger = new Logger();
        $this->profiler = new Profiler($yourls_logger);

        /* By default, make "query" the log level. This way, each internal logging triggered
         * by the DB layer will be a "query", and logging triggered by yourls_debug_log() will be
         * a "debug". See includes/functions-debug.php:yourls_debug_log()
         */
        $this->profiler->setLogLevel('query');
    }

    /**
     * Return the profiler (used by the debug log / query counter).
     *
     * @since 1.7.10
     * @return Profiler
     */
    public function getProfiler(): Profiler {
        return $this->profiler;
    }

    /**
     * @param string $context
     * @return void
     */
    public function set_html_context($context) {
        $this->context = $context;
    }

    /**
     * @return string
     */
    public function get_html_context() {
        return $this->context;
    }

    // Options low level functions, see \YOURLS\Database\Options

    /**
     * @param string $name
     * @param mixed  $value
     * @return void
     */
    public function set_option($name, $value) {
        $this->option[$name] = $value;
    }

    /**
     * @param  string $name
     * @return bool
     */
    public function has_option($name) {
        return array_key_exists($name, $this->option);
    }

    /**
     * @param  string $name
     * @return string
     */
    public function get_option($name) {
        return $this->option[$name];
    }

    /**
     * @param string $name
     * @return void
     */
    public function delete_option($name) {
        unset($this->option[$name]);
    }


    // Infos (related to keyword) low level functions

    /**
     * @param string $keyword
     * @param mixed  $infos
     * @return void
     */
    public function set_infos($keyword, $infos) {
        $this->infos[$keyword] = $infos;
    }

    /**
     * @param  string $keyword
     * @return bool
     */
    public function has_infos($keyword) {
        return array_key_exists($keyword, $this->infos);
    }

    /**
     * @param  string $keyword
     * @return array
     */
    public function get_infos($keyword) {
        return $this->infos[$keyword];
    }

    /**
     * @param string $keyword
     * @return void
     */
    public function delete_infos($keyword) {
        if (isset($this->infos[$keyword])) {
            unset($this->infos[$keyword]);
        }
    }

    /**
     * @param string $keyword
     * @param mixed  $infos
     * @return void
     */
    public function update_infos_if_exists($keyword, $infos) {
        if ($this->has_infos($keyword) && $this->infos[$keyword]) {
            $this->infos[$keyword] = array_merge($this->infos[$keyword], $infos);
        }
    }

    /**
     * @todo: infos & options are working the same way here. Abstract this.
     */


    // Plugin low level functions, see functions-plugins.php

    /**
     * @return array
     */
    public function get_plugins() {
        return $this->plugins;
    }

    /**
     * @param array $plugins
     * @return void
     */
    public function set_plugins(array $plugins) {
        $this->plugins = $plugins;
    }

    /**
     * @param string $plugin  plugin filename
     * @return void
     */
    public function add_plugin($plugin) {
        $this->plugins[] = $plugin;
    }

    /**
     * @param string $plugin  plugin filename
     * @return void
     */
    public function remove_plugin($plugin) {
        unset($this->plugins[$plugin]);
    }


    // Plugin Pages low level functions, see functions-plugins.php

    /**
     * @return array
     */
    public function get_plugin_pages() {
        return is_array( $this->plugin_pages ) ? $this->plugin_pages : [];
    }

    /**
     * @param array $pages
     * @return void
     */
    public function set_plugin_pages(array $pages) {
        $this->plugin_pages = $pages;
    }

    /**
     * @param string   $slug
     * @param string   $title
     * @param callable $function
     * @return void
     */
    public function add_plugin_page( $slug, $title, $function ) {
        $this->plugin_pages[ $slug ] = [
            'slug'     => $slug,
            'title'    => $title,
            'function' => $function,
        ];
    }

    /**
     * @param string $slug
     * @return void
     */
    public function remove_plugin_page( $slug ) {
        unset( $this->plugin_pages[ $slug ] );
    }

    /**
     * Return count of SQL queries performed
     *
     * @since  1.7.3
     * @return int
     */
    public function get_num_queries() {
        return count( (array) $this->get_queries() );
    }

    /**
     * Return SQL queries performed
     *
     * @since  1.7.3
     * @return array
     */
    public function get_queries() {
        $queries = $this->getProfiler()->getLogger()->getMessages();

        // Only keep messages that start with "SQL "
        $queries = array_filter($queries, function($query) {return substr( $query, 0, 4 ) === "SQL ";});

        return $queries;
    }

    /**
     * Set YOURLS installed state
     *
     * @since  1.7.3
     * @param  bool $bool
     * @return void
     */
    public function set_installed($bool) {
        $this->installed = $bool;
    }

    /**
     * Get YOURLS installed state
     *
     * @since  1.7.3
     * @return bool
     */
    public function is_installed() {
        return $this->installed;
    }

    /**
     * Return MySQL version
     *
     * @since  1.7.3
     * @return string
     */
    public function mysql_version() {
        try {
            $pdo = $this->connection()->getNativeConnection();
            if ($pdo instanceof PDO) {
                return (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            }
        } catch (\Throwable $e) {
            // fall through to a DBAL-level query
        }
        return (string) $this->fetchValue('SELECT VERSION()');
    }

    // -----------------------------------------------------------------------------------------
    // Fetch methods — Doctrine DBAL backed, preserving the exact legacy return contracts.
    // Every one routes through fetch_wrapper() so the shunt_fetch_wrapper / fetch_wrapper_statement
    // plugin filters keep working, exactly as before.
    // -----------------------------------------------------------------------------------------

    /**
     * Fetch the number of affected rows
     *
     * @since 1.10.4
     * @param string $statement SQL statement to execute
     * @param array  $values    Optional. Values to bind to the statement. Default empty array.
     * @return int Number of affected rows
     */
    public function fetchAffected(string $statement, array $values = []): int {
        return $this->fetch_wrapper('fetchAffected', $statement, $values);
    }

    /**
     * Fetch all rows (sequential array of associative-array rows)
     *
     * @since 1.10.4
     * @param string $statement SQL statement to execute
     * @param array  $values    Optional. Values to bind to the statement. Default empty array.
     * @return array All rows returned by the query
     */
    public function fetchAll(string $statement, array $values = []): array {
        return $this->fetch_wrapper('fetchAll', $statement, $values);
    }

    /**
     * Fetch all rows as associative arrays
     *
     * @since 1.10.4
     * @param string $statement SQL statement to execute
     * @param array  $values    Optional. Values to bind to the statement. Default empty array.
     * @return array All rows as associative arrays
     */
    public function fetchAssoc(string $statement, array $values = []): array {
        return $this->fetch_wrapper('fetchAssoc', $statement, $values);
    }

    /**
     * Fetch a single column from all rows
     *
     * @since 1.10.4
     * @param string $statement SQL statement to execute
     * @param array  $values    Optional. Values to bind to the statement. Default empty array.
     * @return array First column values from all rows
     */
    public function fetchCol(string $statement, array $values = []): array {
        return $this->fetch_wrapper('fetchCol', $statement, $values);
    }

    /**
     * Fetch rows grouped by the first column
     *
     * @since 1.10.4
     * @param string $statement SQL statement to execute
     * @param array  $values    Optional. Values to bind to the statement. Default empty array.
     * @param int    $style     Optional. Retained for signature compatibility. Default PDO::FETCH_COLUMN.
     * @return array Rows grouped by the first column value
     */
    public function fetchGroup(string $statement, array $values = [], int $style = PDO::FETCH_COLUMN): array {
        return $this->fetch_wrapper('fetchGroup', $statement, $values, $style);
    }

    /**
     * Fetch a single row as an object
     *
     * @since 1.10.4
     * @param string $statement SQL statement to execute
     * @param array  $values    Optional. Values to bind to the statement. Default empty array.
     * @param string $class     Optional. Class name for the returned object. Default 'stdClass'.
     * @param array  $args      Optional. Constructor arguments for the class. Default empty array.
     * @return object|false Object representing the row, or false if no rows found
     */
    public function fetchObject(string $statement, array $values = [], string $class = 'stdClass', array $args = []): object|false {
        return $this->fetch_wrapper('fetchObject', $statement, $values, $class, $args);
    }

    /**
     * Fetch all rows as objects
     *
     * @since 1.10.4
     * @param string $statement SQL statement to execute
     * @param array  $values    Optional. Values to bind to the statement. Default empty array.
     * @param string $class     Optional. Class name for the returned objects. Default 'stdClass'.
     * @param array  $args      Optional. Constructor arguments for the class. Default empty array.
     * @return array All rows as objects
     */
    public function fetchObjects(string $statement, array $values = [], string $class = 'stdClass', array $args = []): array {
        return $this->fetch_wrapper('fetchObjects', $statement, $values, $class, $args);
    }

    /**
     * Fetch a single row as an array
     *
     * @since 1.10.4
     * @param string $statement SQL statement to execute
     * @param array  $values    Optional. Values to bind to the statement. Default empty array.
     * @return array|false Associative array representing the row, or false if no rows found
     */
    public function fetchOne(string $statement, array $values = []): array|false {
        return $this->fetch_wrapper('fetchOne', $statement, $values);
    }

    /**
     * Fetch key-value pairs
     *
     * @since 1.10.4
     * @param string $statement SQL statement to execute
     * @param array  $values    Optional. Values to bind to the statement. Default empty array.
     * @return array Associative array of key-value pairs
     */
    public function fetchPairs(string $statement, array $values = []): array {
        return $this->fetch_wrapper('fetchPairs', $statement, $values);
    }

    /**
     * Fetch a single value
     *
     * @since 1.10.4
     * @param string $statement SQL statement to execute
     * @param array  $values    Optional. Values to bind to the statement. Default empty array.
     * @return mixed Single value from the query result
     */
    public function fetchValue(string $statement, array $values = []): mixed {
        return $this->fetch_wrapper('fetchValue', $statement, $values);
    }

    /**
     * Performs a query with bound values and returns the resulting Doctrine DBAL Result.
     * You most likely should not use this method directly. Use the fetch_* methods instead.
     *
     * @since 1.10.4
     * @param string $statement The SQL statement to perform.
     * @param array  $values    Values to bind to the query
     * @return Result
     */
    public function perform(string $statement, array $values = []): Result {
        return $this->fetch_wrapper('perform', $statement, $values);
    }

    /**
     * Raw query passthrough (kept for compatibility with functions-upgrade.php:231).
     *
     * Historically this called Aura's ExtendedPdo::query() directly, bypassing fetch_wrapper.
     * It executes an arbitrary statement and returns the DBAL Result.
     *
     * @since 1.11 (compat shim)
     * @param string $statement
     * @return Result
     */
    public function query(string $statement): Result {
        try {
            $this->profiler->start();
            $result = $this->connection()->executeQuery($statement);
            $this->profiler->finish($statement, []);
            return $result;
        } catch (\Doctrine\DBAL\Exception $e) {
            throw self::to_pdo_exception($e);
        }
    }

    /**
     * Escape a string for safe inclusion in a query.
     *
     * Only used by the deprecated yourls_escape_real() (functions-deprecated.php). We keep it for
     * strict backward compatibility. It uses the driver's quote() and strips the surrounding quotes
     * to mirror the old ExtendedPdo::escape() behaviour (which returned the inner escaped string).
     *
     * @since 1.11 (compat shim)
     * @param string $string
     * @return string
     */
    public function escape($string) {
        $quoted = $this->connection()->quote((string) $string);
        // quote() wraps the value in single quotes; escape() historically returned it without them.
        if (strlen($quoted) >= 2 && $quoted[0] === "'" && substr($quoted, -1) === "'") {
            return substr($quoted, 1, -1);
        }
        return $quoted;
    }

    /**
     * Wrapper for all fetch methods, allowing plugins to intercept and modify query results.
     *
     * @since 1.10.4
     * @param string $method  The fetch method name to call (e.g., 'fetchAll', 'fetchValue')
     * @param mixed  ...$args Variable number of arguments to pass to the underlying method
     * @return mixed The cached result if available, otherwise the fresh query result
     */
    public function fetch_wrapper(string $method, ...$args): mixed {
        // Allow plugins to short-circuit the whole function if we're not in bypass mode
        if (!$this->bypass_shunt_filter) {
            $pre = yourls_apply_filter('shunt_fetch_wrapper', yourls_shunt_default(), $method, ...$args);
            if (yourls_shunt_default() !== $pre) {
                return $pre;
            }
        }

        // Filter the query statement
        $args[0] = yourls_apply_filter('fetch_wrapper_statement', $args[0], $method, $args);

        return $this->run($method, ...$args);
    }

    /**
     * Actually execute a fetch method against Doctrine DBAL, translating results into the exact
     * shapes the legacy Aura-based API returned.
     *
     * @param string $method
     * @param mixed  ...$args
     * @return mixed
     */
    protected function run(string $method, ...$args): mixed {
        $statement = (string) ($args[0] ?? '');
        $values    = (array) ($args[1] ?? []);

        try {
            $this->profiler->start();

            switch ($method) {
                case 'fetchAffected':
                    $out = (int) $this->connection()->executeStatement($statement, $values);
                    break;

                case 'perform':
                    // Return the DBAL Result (analogous to the old PDOStatement).
                    $out = $this->connection()->executeQuery($statement, $values);
                    break;

                case 'fetchValue':
                    $out = $this->connection()->fetchOne($statement, $values);
                    // DBAL returns false when there is no row -> matches legacy contract.
                    break;

                case 'fetchOne':
                    $row = $this->connection()->fetchAssociative($statement, $values);
                    $out = $row === false ? false : $row;
                    break;

                case 'fetchAll':
                    $out = $this->connection()->fetchAllAssociative($statement, $values);
                    break;

                case 'fetchAssoc':
                    // Legacy Aura contract: rows keyed by the value of their FIRST column.
                    $rows = $this->connection()->fetchAllAssociative($statement, $values);
                    $out = [];
                    foreach ($rows as $row) {
                        $key = reset($row); // first column's value
                        $out[$key] = $row;
                    }
                    break;

                case 'fetchCol':
                    $out = $this->connection()->fetchFirstColumn($statement, $values);
                    break;

                case 'fetchPairs':
                    $out = $this->connection()->fetchAllKeyValue($statement, $values);
                    break;

                case 'fetchGroup':
                    // Group rows by their first column value.
                    $rows = $this->connection()->fetchAllAssociative($statement, $values);
                    $out = [];
                    foreach ($rows as $row) {
                        $key = array_shift($row);
                        $out[$key][] = $row;
                    }
                    break;

                case 'fetchObject':
                    $class = (string) ($args[2] ?? 'stdClass');
                    $ctor  = (array) ($args[3] ?? []);
                    $row = $this->connection()->fetchAssociative($statement, $values);
                    $out = $row === false ? false : self::to_object($row, $class, $ctor);
                    break;

                case 'fetchObjects':
                    $class = (string) ($args[2] ?? 'stdClass');
                    $ctor  = (array) ($args[3] ?? []);
                    $rows = $this->connection()->fetchAllAssociative($statement, $values);
                    $out = [];
                    foreach ($rows as $row) {
                        $out[] = self::to_object($row, $class, $ctor);
                    }
                    break;

                default:
                    throw new \BadMethodCallException("Unknown DB fetch method '$method'");
            }

            $this->profiler->finish($statement, $values, $method);
            return $out;

        } catch (\Doctrine\DBAL\Exception $e) {
            // Translate to \PDOException so legacy catch(PDOException|Exception) blocks keep working.
            throw self::to_pdo_exception($e);
        }
    }

    /**
     * Hydrate an associative row into an object of the requested class (default stdClass).
     *
     * @param array  $row
     * @param string $class
     * @param array  $ctor
     * @return object
     */
    protected static function to_object(array $row, string $class = 'stdClass', array $ctor = []): object {
        if ($class === 'stdClass' || $class === '' || !class_exists($class)) {
            $obj = new \stdClass();
            foreach ($row as $k => $v) {
                $obj->$k = $v;
            }
            return $obj;
        }

        // Mirror PDO::FETCH_CLASS behaviour: instantiate, then set properties from the row.
        $obj = $ctor === [] ? new $class() : new $class(...array_values($ctor));
        foreach ($row as $k => $v) {
            $obj->$k = $v;
        }
        return $obj;
    }

    /**
     * Convert a Doctrine DBAL exception into a \PDOException so that legacy error handling
     * (which catches PDOException / Exception) continues to work unchanged.
     *
     * @param \Throwable $e
     * @return \PDOException
     */
    protected static function to_pdo_exception(\Throwable $e): \PDOException {
        $pdo = new \PDOException($e->getMessage(), (int) $e->getCode(), $e);
        return $pdo;
    }

    /**
     * Execute a callback with filters temporarily disabled
     *
     * This method allows bypassing the plugin filter system for the duration of the callback
     * execution. Useful to prevent infinite loops when a filter needs to call the original method
     * without re-triggering itself.
     *
     * @since 1.10.4
     * @param callable $callback
     * @return mixed
     */
    public function without_filters(callable $callback): mixed {
        $this->bypass_shunt_filter = true;
        try {
            return $callback($this);
        } finally {
            $this->bypass_shunt_filter = false;
        }
    }
}
