<?php

/**
 * Doctrine DBAL wrapper for YOURLS that creates the almighty YDB object.
 *
 * A fine example of a "class that knows too much" (see https://en.wikipedia.org/wiki/God_object)
 *
 * Note to plugin authors: you most likely SHOULD NOT use directly methods and properties of this class. Use instead
 * function wrappers (e.g. don't use $ydb->option, or $ydb->set_option(), use yourls_*_options() functions instead).
 *
 * Since 1.10.5 this class is backed by Doctrine DBAL instead of Aura SQL. The public API is
 * unchanged: the fetch* methods still take a SQL statement with named ":placeholders" and an array
 * of values, and still return the very same shapes (stdClass objects for fetchObject/fetchObjects,
 * arrays elsewhere), so existing plugins and core code keep working.
 *
 * Queries built internally by YOURLS use the DBAL QueryBuilder (see the query_builder() and
 * table() helpers), which quotes table identifiers built from the user defined YOURLS_DB_PREFIX.
 *
 * @since 1.7.3
 */

namespace YOURLS\Database;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use PDO;
use PDOException;
use YOURLS\Database\Middleware\ProfilerMiddleware;

class YDB {

    /**
     * The Doctrine DBAL connection
     * @var Connection
     */
    protected Connection $connection;

    /**
     * Connection parameters, as passed to Doctrine's DriverManager
     * @var array
     */
    protected array $params = [];

    /**
     * The profiler, which logs queries and debug messages
     * @var Profiler
     */
    protected Profiler $profiler;

    /**
     * Debug mode, default false
     * @var bool
     */
    protected bool $debug = false;

    /**
     * Page context (ie "infos", "bookmark", "plugins"...)
     * @var string
     */
    protected string $context = '';

    /**
     * Information related to a short URL keyword (e.g. timestamp, long URL, ...)
     *
     * @var array
     *
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
     * @since 1.7.3
     * @param string $dsn     The data source name, eg "mysql:host=localhost;dbname=yourls;charset=utf8mb4"
     * @param string $user    The username
     * @param string $pass    The password
     * @param array  $options Driver-specific options
     * @param array  $attributes Driver attributes, set on the underlying PDO object
     */
    public function __construct($dsn, $user, $pass, $options = [], $attributes = []) {
        $this->params = self::dsn_to_params((string)$dsn, (string)$user, (string)$pass, (array)$options, (array)$attributes);

        // Instantiate the profiler right away: even when the connection fails, code such as
        // yourls_die() may call methods that reach for the profiler.
        $this->start_profiler();
    }

    /**
     * Translate a PDO style DSN into Doctrine DBAL connection parameters
     *
     * YOURLS has always exposed the DSN to plugins through the 'db_connect_custom_dsn' filter, so
     * we keep accepting a DSN string and convert it, rather than forcing everyone to a new format.
     *
     * @since  1.10.5
     * @param  string $dsn
     * @param  string $user
     * @param  string $pass
     * @param  array  $options    PDO driver options
     * @param  array  $attributes PDO attributes
     * @return array              Doctrine DBAL connection parameters
     */
    protected static function dsn_to_params(string $dsn, string $user, string $pass, array $options = [], array $attributes = []): array {
        $driver_map = [
            'mysql'  => 'pdo_mysql',
            'pgsql'  => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            'oci'    => 'pdo_oci',
            'sqlsrv' => 'pdo_sqlsrv',
        ];

        [$scheme, $rest] = array_pad(explode(':', $dsn, 2), 2, '');
        $scheme = strtolower(trim($scheme));

        $params = [
            'driver'        => $driver_map[$scheme] ?? 'pdo_mysql',
            'user'          => $user,
            'password'      => $pass,
            'driverOptions' => $options + $attributes,
        ];

        // sqlite DSNs are "sqlite:/path/to/file" or "sqlite::memory:"
        if ($scheme === 'sqlite') {
            if ($rest === '' || $rest === ':memory:') {
                $params['memory'] = true;
            } else {
                $params['path'] = $rest;
            }

            return $params;
        }

        // Other PDO DSNs are a list of "key=value" pairs separated by semicolons
        $dsn_map = [
            'host'    => 'host',
            'port'    => 'port',
            'dbname'  => 'dbname',
            'charset' => 'charset',
            'unix_socket' => 'unix_socket',
        ];

        foreach (explode(';', $rest) as $pair) {
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $pair, 2);
            $key = strtolower(trim($key));
            if (isset($dsn_map[$key])) {
                $params[$dsn_map[$key]] = $key === 'port' ? (int)$value : $value;
            }
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
        $this->connect_to_DB();

        $this->set_emulate_state();
    }

    /**
     * Check if we emulate prepare statements, and set bool flag accordingly
     *
     * Some combinations of PHP/MySQL don't support reading this attribute.
     *
     * @since  1.7.3
     * @return void
     */
    public function set_emulate_state() {
        try {
            $pdo = $this->get_pdo();
            $this->is_emulate_prepare = $pdo instanceof PDO
                ? (bool)$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)
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
            $config = new Configuration();
            $config->setMiddlewares([new ProfilerMiddleware($this->profiler)]);

            $this->connection = DriverManager::getConnection($this->params, $config);

            // Doctrine connects lazily: force a real connection so a bad config fails here
            $this->connection->getNativeConnection();
        } catch (\Exception $e) {
            $this->dead_or_error($e);
        }
    }

    /**
     * Return the Doctrine DBAL connection
     *
     * @since  1.10.5
     * @return Connection
     */
    public function get_connection(): Connection {
        return $this->connection;
    }

    /**
     * Return the underlying PDO object, if the driver is a PDO one
     *
     * @since  1.10.5
     * @return object|null
     */
    public function get_pdo() {
        try {
            return $this->connection->getNativeConnection();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Return a new Doctrine QueryBuilder bound to this connection
     *
     * This is the preferred way to build queries in YOURLS core: it takes care of quoting
     * identifiers (including table names built from the user defined YOURLS_DB_PREFIX) and of
     * binding values as real parameters.
     *
     * @since  1.10.5
     * @return QueryBuilder
     */
    public function query_builder(): QueryBuilder {
        return $this->connection->createQueryBuilder();
    }

    /**
     * Safely quote a table (or any other) identifier
     *
     * Table names are built from YOURLS_DB_PREFIX, which is user defined in config.php. Passing
     * them through the platform's identifier quoting makes sure a weird prefix cannot break out
     * of the identifier and inject SQL.
     *
     * @since  1.10.5
     * @param  string $identifier  eg 'yourls_url'
     * @return string              eg '`yourls_url`'
     */
    public function quote_identifier(string $identifier): string {
        return $this->connection->quoteIdentifier($identifier);
    }

    /**
     * Alias of quote_identifier(), for readability when quoting a table name
     *
     * @since  1.10.5
     * @param  string $table
     * @return string
     */
    public function table(string $table): string {
        return $this->quote_identifier($table);
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
         * by the DBAL middleware will be a "query", and logging triggered by yourls_debug_log()
         * will be a "debug". See includes/functions-debug.php:yourls_debug_log()
         */
        $this->profiler->setLoglevel('query');
    }

    /**
     * Get the profiler
     *
     * @since  1.10.5
     * @return Profiler
     */
    public function getProfiler(): Profiler {
        return $this->profiler;
    }

    /**
     * Set the profiler
     *
     * @since  1.10.5
     * @param  Profiler $profiler
     * @return void
     */
    public function setProfiler(Profiler $profiler): void {
        $this->profiler = $profiler;
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
        return $this->connection->getServerVersion();
    }

    /**
     * Fetch the number of affected rows
     *
     * Note that for statements that return a result set (eg "SHOW TABLES LIKE ..."), this returns
     * the number of rows in that result set, which is what YOURLS uses to check table existence.
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
     * Fetch all rows
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
     * @param int    $style     Optional. PDO fetch style constant. Default PDO::FETCH_COLUMN.
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
     * Performs a query with bound values.
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
     * Run a raw SQL statement, without binding any value.
     *
     * @since 1.10.5
     * @param string $statement
     * @return Result
     */
    public function query(string $statement): Result {
        return $this->execute($statement);
    }

    /**
     * Execute a statement and return the DBAL result
     *
     * Named ":placeholders" are bound as real parameters. A value that is an array is bound as a
     * list, so "WHERE x IN (:list)" keeps working the way it did with Aura SQL.
     *
     * @since  1.10.5
     * @param  string $statement
     * @param  array  $values
     * @return Result
     */
    protected function execute(string $statement, array $values = []): Result {
        [$statement, $params, $types] = $this->bind_values($statement, $values);

        /* Historically YOURLS ran on PDO, and code in core and in plugins catches PDOException
         * around queries. Doctrine wraps driver errors in its own exception types, so convert
         * them here - this is the single point every query goes through. */
        try {
            return $this->connection->executeQuery($statement, $params, $types);
        } catch (\Doctrine\DBAL\Exception $e) {
            throw $this->to_pdo_exception($e);
        }
    }

    /**
     * Prepare the parameters and types arrays that Doctrine expects
     *
     * Aura SQL used to expand an array bound to a named placeholder into a comma separated list.
     * Doctrine does the same when the parameter is flagged with an ArrayParameterType, so we
     * detect array values and type them accordingly.
     *
     * @since  1.10.5
     * @param  string $statement
     * @param  array  $values
     * @return array  [$statement, $params, $types]
     */
    protected function bind_values(string $statement, array $values): array {
        $params = [];
        $types  = [];

        foreach ($values as $name => $value) {
            // Accept both ':name' and 'name' as keys, like PDO does
            $key = is_string($name) ? ltrim($name, ':') : $name;

            if (is_array($value)) {
                // An empty IN () list is a SQL syntax error: use a list that matches nothing
                if ($value === []) {
                    $params[$key] = [null];
                    $types[$key]  = ArrayParameterType::STRING;
                    continue;
                }

                $params[$key] = array_values($value);
                $types[$key]  = $this->all_integers($value)
                    ? ArrayParameterType::INTEGER
                    : ArrayParameterType::STRING;
                continue;
            }

            $params[$key] = $value;
            $types[$key]  = $this->parameter_type($value);
        }

        return [$statement, $params, $types];
    }

    /**
     * @param  array $values
     * @return bool  True if every value is an integer
     */
    protected function all_integers(array $values): bool {
        foreach ($values as $value) {
            if (!is_int($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Guess the Doctrine parameter type of a scalar value
     *
     * @since  1.10.5
     * @param  mixed $value
     * @return ParameterType
     */
    protected function parameter_type(mixed $value): ParameterType {
        return match (true) {
            is_null($value) => ParameterType::NULL,
            is_bool($value) => ParameterType::BOOLEAN,
            is_int($value)  => ParameterType::INTEGER,
            default         => ParameterType::STRING,
        };
    }

    /**
     * Run one of the fetch methods against the DB
     *
     * @since  1.10.5
     * @param  string $method
     * @param  mixed  ...$args
     * @return mixed
     * @throws PDOException on a database error, for backward compatibility
     */
    protected function do_fetch(string $method, ...$args): mixed {
        $statement = (string)($args[0] ?? '');
        $values    = (array)($args[1] ?? []);

        // Tell the profiler which YOURLS method is responsible for the query it's about to see
        $this->profiler->set_pending_function($method);

        try {
            switch ($method) {
                case 'fetchAffected':
                    $result = $this->execute($statement, $values);
                    /* A statement that returns rows (eg "SHOW TABLES LIKE ...") reports 0 affected
                     * rows on most drivers, so fall back to counting the returned rows: YOURLS uses
                     * fetchAffected() as a table existence probe. */
                    $affected = $result->rowCount();
                    if ($affected === 0 && $result->columnCount() > 0) {
                        $affected = count($result->fetchAllNumeric());
                    }

                    return (int)$affected;

                case 'fetchAll':
                case 'fetchAssoc':
                    return $this->execute($statement, $values)->fetchAllAssociative();

                case 'fetchCol':
                    return $this->execute($statement, $values)->fetchFirstColumn();

                case 'fetchGroup':
                    return $this->fetch_group($statement, $values, $args[2] ?? PDO::FETCH_COLUMN);

                case 'fetchObject':
                    $row = $this->execute($statement, $values)->fetchAssociative();

                    return $row === false
                        ? false
                        : $this->to_object($row, (string)($args[2] ?? 'stdClass'), (array)($args[3] ?? []));

                case 'fetchObjects':
                    $class = (string)($args[2] ?? 'stdClass');
                    $ctor  = (array)($args[3] ?? []);

                    return array_map(
                        fn(array $row) => $this->to_object($row, $class, $ctor),
                        $this->execute($statement, $values)->fetchAllAssociative()
                    );

                case 'fetchOne':
                    return $this->execute($statement, $values)->fetchAssociative();

                case 'fetchPairs':
                    return $this->execute($statement, $values)->fetchAllKeyValue();

                case 'fetchValue':
                    return $this->execute($statement, $values)->fetchOne();

                case 'perform':
                    return $this->execute($statement, $values);

                default:
                    throw new \BadMethodCallException(sprintf('Unknown fetch method "%s"', $method));
            }
        } finally {
            // execute() converts Doctrine exceptions to PDOException for backward compatibility
            $this->profiler->set_pending_function(null);
        }
    }

    /**
     * Convert a Doctrine exception to a PDOException, preserving message and SQLSTATE
     *
     * @since  1.10.5
     * @param  \Doctrine\DBAL\Exception $e
     * @return PDOException
     */
    protected function to_pdo_exception(\Doctrine\DBAL\Exception $e): PDOException {
        // If a PDOException triggered this, re-use it as-is: it has the richest information
        for ($previous = $e->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
            if ($previous instanceof PDOException) {
                return $previous;
            }
        }

        $exception = new PDOException($e->getMessage(), (int)$e->getCode(), $e);
        if ($e instanceof \Doctrine\DBAL\Exception\DriverException && $e->getSQLState() !== null) {
            $exception->errorInfo = [$e->getSQLState(), $e->getCode(), $e->getMessage()];
        }

        return $exception;
    }

    /**
     * Fetch rows grouped by their first column
     *
     * @since  1.10.5
     * @param  string $statement
     * @param  array  $values
     * @param  int    $style  PDO fetch style
     * @return array
     */
    protected function fetch_group(string $statement, array $values, int $style): array {
        $rows   = $this->execute($statement, $values)->fetchAllNumeric();
        $result = [];

        foreach ($rows as $row) {
            $key  = array_shift($row);
            $rest = $style === PDO::FETCH_COLUMN && count($row) === 1 ? $row[0] : $row;

            $result[$key][] = $rest;
        }

        return $result;
    }

    /**
     * Turn an associative row into an object of the requested class
     *
     * Mirrors PDO::FETCH_CLASS: properties are set on the object before the constructor runs.
     *
     * @since  1.10.5
     * @param  array  $row
     * @param  string $class
     * @param  array  $args  constructor arguments
     * @return object
     */
    protected function to_object(array $row, string $class = 'stdClass', array $args = []): object {
        if ($class === 'stdClass' || $class === '') {
            return (object)$row;
        }

        $reflection = new \ReflectionClass($class);
        $object     = $reflection->newInstanceWithoutConstructor();

        foreach ($row as $name => $value) {
            $object->$name = $value;
        }

        $constructor = $reflection->getConstructor();
        if ($constructor !== null) {
            $constructor->invokeArgs($object, $args);
        }

        return $object;
    }

    /**
     * Wrapper for all fetch methods, allowing plugins to intercept and modify query results.
     *
     * @since 1.10.4
     * @param string $method  The fetch method name to call (e.g., 'fetchAll', 'fetchValue')
     * @param mixed  ...$args Variable number of arguments to pass to the method
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

        return $this->do_fetch($method, ...$args);
    }

    /**
     * Execute a callback with filters temporarily disabled
     *
     * This method allows bypassing the plugin filter system for the duration of the callback execution. Useful to
     * prevent infinite loops when a filter needs to call the original method without re-triggering itself.
     *
     * Example usage:
     *      $ydb = yourls_get_db('write-get_from_cache');
     *      $result = $ydb->without_filters(function($db) use ($method, $args) {
     *          return $db->fetch_wrapper($method, ...$args);
     *      });
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
