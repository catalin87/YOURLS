<?php

/**
 * Doctrine DBAL wrapper for YOURLS that creates the almighty YDB object.
 *
 * A fine example of a "class that knows too much" (see https://en.wikipedia.org/wiki/God_object)
 *
 * Note to plugin authors: you most likely SHOULD NOT use directly methods and properties of this class. Use instead
 * function wrappers (e.g. don't use $ydb->option, or $ydb->set_option(), use yourls_*_options() functions instead).
 *
 * Since 1.11 this class is backed by Doctrine DBAL instead of Aura SQL. The fetch* API is unchanged:
 * statements still use PDO style named placeholders (":name") and fetchObject()/fetchObjects() still
 * return stdClass instances, because a lot of YOURLS core and plugin code reads results as objects.
 *
 * @since 1.7.3
 */

namespace YOURLS\Database;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use PDO;

class YDB {

    /**
     * The Doctrine DBAL connection doing the actual work
     * @var Connection
     */
    protected Connection $connection;

    /**
     * Query profiler
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
     * Parameter types declared by the QueryBuilder currently being run, see fetch_from()
     * @var array
     */
    private array $query_builder_types = [];

    /**
     * @since 1.11
     * @param Connection $connection A Doctrine DBAL connection
     */
    public function __construct(Connection $connection) {
        $this->connection = $connection;
        $this->profiler   = new Profiler(new Logger());
    }

    /**
     * Build a YDB instance from DBAL connection parameters
     *
     * The connection is lazy: DBAL only dials the server on the first query, so as with the previous
     * Aura SQL implementation we get a usable $ydb object even when the DB is dead or misconfigured
     * (and for instance yourls_die() can correctly die, even if using $ydb methods).
     *
     * @since  1.11
     * @param  array $params DBAL connection parameters
     * @return YDB
     */
    public static function from_params(array $params): self {
        return new self(DriverManager::getConnection($params));
    }

    /**
     * Return the underlying Doctrine DBAL connection
     *
     * @since  1.11
     * @return Connection
     */
    public function get_connection(): Connection {
        return $this->connection;
    }

    /**
     * Return a new Doctrine QueryBuilder bound to this connection
     *
     * This is the preferred way to build queries in YOURLS core since 1.11. Remember that table names
     * are never placeholders: pass them through \YOURLS\Database\TableRegistry so a malformed
     * YOURLS_DB_PREFIX can't inject SQL.
     *
     * @since  1.11
     * @return QueryBuilder
     */
    public function create_query_builder(): QueryBuilder {
        return $this->connection->createQueryBuilder();
    }

    /**
     * Run a QueryBuilder through the fetch_* API, so plugin filters still apply
     *
     * QueryBuilder::executeQuery() would talk to the driver directly and silently bypass the
     * 'shunt_fetch_wrapper' and 'fetch_wrapper_statement' filters that plugins rely on. Core code
     * building queries with a QueryBuilder should run them through this method instead.
     *
     * Example:
     *     $qb = $ydb->create_query_builder()
     *               ->select('*')
     *               ->from(TableRegistry::get('url'))
     *               ->where('`keyword` = :keyword')
     *               ->setParameter('keyword', $keyword);
     *     $infos = $ydb->fetch_from('fetchObject', $qb);
     *
     * @since  1.11
     * @param  string       $method  One of the fetch* method names, or 'perform'
     * @param  QueryBuilder $qb      The query to run
     * @param  mixed        ...$extra  Method specific extra arguments (fetch style, class name, ctor args)
     * @return mixed
     */
    public function fetch_from(string $method, QueryBuilder $qb, ...$extra): mixed {
        /* Carry the builder's own parameter types over, so an explicitly typed binding (an int, or
         * an ArrayParameterType) isn't downgraded to a string by param_types() guessing.
         */
        $this->query_builder_types = $qb->getParameterTypes();

        try {
            return $this->fetch_wrapper($method, $qb->getSQL(), $qb->getParameters(), ...$extra);
        } finally {
            $this->query_builder_types = [];
        }
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
     * Check if current driver can PDO::getAttribute(PDO::ATTR_EMULATE_PREPARES)
     * Some combinations of PHP/MySQL don't support this function, and some DBAL drivers
     * aren't PDO based at all.
     *
     * @since  1.7.3
     * @return void
     */
    public function set_emulate_state() {
        try {
            $native = $this->connection->getNativeConnection();
            $this->is_emulate_prepare = $native instanceof PDO
                ? (bool)$native->getAttribute(PDO::ATTR_EMULATE_PREPARES)
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
            // DBAL connects lazily and Connection::connect() is not public, so reach for the
            // native handle: that forces the driver to actually dial the server.
            $this->connection->getNativeConnection();
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
     * Return the query profiler
     *
     * @since  1.7.3
     * @return Profiler
     */
    public function getProfiler(): Profiler {
        return $this->profiler;
    }

    /**
     * Set the query profiler
     *
     * @since  1.11
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
        $native = $this->connection->getNativeConnection();
        if ($native instanceof PDO) {
            return (string)$native->getAttribute(PDO::ATTR_SERVER_VERSION);
        }

        return (string)$this->connection->fetchOne('SELECT VERSION()');
    }

    /**
     * Return the ID generated by the last INSERT
     *
     * @since  1.11
     * @return string
     */
    public function lastInsertId(): string {
        return (string)$this->connection->lastInsertId();
    }

    /**
     * Quote a value for safe inclusion in a SQL statement
     *
     * Prefer bound parameters over this whenever possible.
     *
     * @since  1.11
     * @param  string $value
     * @return string
     */
    public function quote(string $value): string {
        return $this->connection->quote($value);
    }

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
     * Performs a query with bound values and returns the number of affected rows
     *
     * You most likely should not use this method directly. Use the fetch_* methods instead.
     *
     * Note: before 1.11 this returned a PDOStatement. It now returns the number of rows the statement
     * affected, which is what DBAL gives us, and what the (few) core callers actually care about.
     *
     * @since 1.10.4
     * @param string $statement The SQL statement to perform.
     * @param array  $values    Values to bind to the query
     * @return int
     */
    public function perform(string $statement, array $values = []): int {
        return $this->fetch_wrapper('perform', $statement, $values);
    }

    /**
     * Alias of perform(), kept because some legacy code calls $ydb->query()
     *
     * @since 1.11
     * @param string $statement The SQL statement to perform.
     * @param array  $values    Values to bind to the query
     * @return int
     */
    public function query(string $statement, array $values = []): int {
        return $this->perform($statement, $values);
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

        return $this->run($method, ...$args);
    }

    /**
     * Actually run a query through Doctrine DBAL and shape the result the way the caller expects
     *
     * @since  1.11
     * @param  string $method    One of the fetch* method names, or 'perform'
     * @param  string $statement SQL statement, with PDO style named placeholders
     * @param  array  $values    Values to bind
     * @param  mixed  ...$extra  Method specific extra arguments (fetch style, class name, ctor args)
     * @return mixed
     */
    protected function run(string $method, string $statement, array $values = [], ...$extra): mixed {
        $this->profiler->start();
        $types = $this->param_types($values);

        try {
            return match ($method) {
                'perform',
                'fetchAffected' => (int)$this->connection->executeStatement($statement, $values, $types),
                'fetchAll',
                'fetchAssoc'    => $this->connection->fetchAllAssociative($statement, $values, $types),
                'fetchCol'      => $this->connection->fetchFirstColumn($statement, $values, $types),
                'fetchPairs'    => $this->connection->fetchAllKeyValue($statement, $values, $types),
                'fetchValue'    => $this->connection->fetchOne($statement, $values, $types),
                'fetchOne'      => $this->connection->fetchAssociative($statement, $values, $types),
                'fetchObject'   => $this->to_object(
                    $this->connection->fetchAssociative($statement, $values, $types),
                    $extra[0] ?? 'stdClass',
                    $extra[1] ?? []
                ),
                'fetchObjects'  => array_map(
                    fn(array $row) => $this->to_object($row, $extra[0] ?? 'stdClass', $extra[1] ?? []),
                    $this->connection->fetchAllAssociative($statement, $values, $types)
                ),
                'fetchGroup'    => $this->fetch_group($statement, $values, $types, $extra[0] ?? PDO::FETCH_COLUMN),
                default         => throw new \BadMethodCallException(sprintf('Unknown DB method "%s"', $method)),
            };
        } finally {
            $this->profiler->finish($statement, $values, $method);
        }
    }

    /**
     * Derive DBAL parameter types from the bound values
     *
     * Aura SQL used to expand an array bound to a ":placeholder" into a comma separated list, so
     * queries like "WHERE `shorturl` IN ( :list )" worked out of the box. DBAL only does that when
     * the parameter is explicitly typed as an array, so flag those here and keep such queries working.
     *
     * @since  1.11
     * @param  array $values Bound values
     * @return array         Types, keyed like $values, for the array parameters only
     */
    protected function param_types(array $values): array {
        $types = [];

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $types[$key] = ArrayParameterType::STRING;
            }
        }

        // A type the caller declared explicitly on a QueryBuilder always wins over our guess
        return array_merge($types, $this->query_builder_types);
    }

    /**
     * Hydrate a result row into an object, the way PDO::FETCH_CLASS does
     *
     * Properties are assigned directly (bypassing any setter and visibility) before the constructor runs,
     * which is the behaviour core code relies on when fetching stdClass rows.
     *
     * @since  1.11
     * @param  array|false $row   Result row, or false when the query returned nothing
     * @param  string      $class Class to instantiate
     * @param  array       $args  Constructor arguments
     * @return object|false
     */
    protected function to_object(array|false $row, string $class, array $args): object|false {
        if ($row === false) {
            return false;
        }

        if ($class === 'stdClass') {
            return (object)$row;
        }

        $reflection = new \ReflectionClass($class);
        $object     = $reflection->newInstanceWithoutConstructor();

        foreach ($row as $name => $value) {
            // Mimic PDO: unknown columns become public properties, known ones are set even if private
            if ($reflection->hasProperty($name)) {
                $property = $reflection->getProperty($name);
                $property->setAccessible(true);
                $property->setValue($object, $value);
            } else {
                $object->$name = $value;
            }
        }

        if ($constructor = $reflection->getConstructor()) {
            $constructor->invokeArgs($object, $args);
        }

        return $object;
    }

    /**
     * Fetch rows grouped by the value of their first column
     *
     * Reproduces PDO::FETCH_GROUP, with either PDO::FETCH_COLUMN (default, one value per row) or
     * PDO::FETCH_ASSOC (the remaining columns as an associative array).
     *
     * @since  1.11
     * @param  string $statement SQL statement
     * @param  array  $values    Values to bind
     * @param  array  $types     DBAL parameter types
     * @param  int    $style     PDO fetch style constant
     * @return array
     */
    protected function fetch_group(string $statement, array $values, array $types, int $style): array {
        $rows   = $this->connection->fetchAllAssociative($statement, $values, $types);
        $result = [];

        foreach ($rows as $row) {
            $key = array_shift($row);
            if ($style === PDO::FETCH_COLUMN) {
                $result[$key][] = reset($row);
            } else {
                $result[$key][] = $row;
            }
        }

        return $result;
    }

    /**
     * Execute a callback with filters temporarily disabled
     *
     * This method allows bypassing the plugin filter system for the duration of the callback execution. Useful to
     * prevent infinite loops when a filter needs to call the original method without re-triggering itself.
     *
     * Example usage:
     *      $ydb = yourls_get_db('write-get_from_cache');
     *      $result = $ydb->withoutFilters(function($db) use ($method, $args) {
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
