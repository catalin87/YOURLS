<?php

/**
 * Connect to DB
 *
 * @since 1.0
 * @param string $context Optional context. Default: ''. See yourls_get_db()
 * @return \YOURLS\Database\YDB
 */
function yourls_db_connect($context = '') {
    global $ydb;

    if ( !defined( 'YOURLS_DB_USER' )
         or !defined( 'YOURLS_DB_PASS' )
         or !defined( 'YOURLS_DB_NAME' )
         or !defined( 'YOURLS_DB_HOST' )
    ) {
        yourls_die( yourls__( 'Incorrect DB config, please refer to documentation' ), yourls__( 'Fatal error' ), 503 );
    }

    $dbhost = YOURLS_DB_HOST;
    $user = YOURLS_DB_USER;
    $pass = YOURLS_DB_PASS;
    $dbname = YOURLS_DB_NAME;

    // This action is deprecated
    yourls_do_action( 'set_DB_driver', 'deprecated' );

    /**
     * Build the Doctrine DBAL connection from the YOURLS_DB_* constants. DoctrineConnector honours
     * the historical db_connect_charset / db_connect_custom_dsn / db_connect_driver_option /
     * db_connect_attributes filters, so plugins hooking those keep working.
     */
    $connection = \YOURLS\Database\DoctrineConnector::fromConstants( $context );

    $ydb = new \YOURLS\Database\YDB( $connection, $user, $pass );
    $ydb->init();

    // Past this point, we're connected
    yourls_debug_log( 'Connected to ' . $dbname . '@' . $dbhost );

    yourls_debug_mode( YOURLS_DEBUG );

    return $ydb;
}

/**
 * Helper function: return instance of the DB
 *
 * Instead of:
 *     global $ydb;
 *     $ydb->do_stuff()
 * Prefer :
 *     yourls_get_db()->do_stuff()
 *
 * @since  1.7.10
 * @param string $context Optional context. Default: ''.
 *   If not provided, the function will trigger a notice to encourage developers to provide a context while not
 *   breaking existing code. A context is a string describing the operation for which the DB is requested.
 *   Use a naming schema starting with a prefix describing the operation, followed by a short description:
 *   - Prefix should be either "read-" or "write-", as follows:
 *        * "read-" for operations that only read from the DB (eg get_keyword_infos)
 *        * "write-" for operations that write to the DB (eg insert_link_in_db)
 *   - The description should be lowercase, words separated with underscores, eg "insert_link_in_db".
 *   Examples:
 *   - read-fetch_keyword
 *   - write-insert_link_in_db
 * @return \YOURLS\Database\YDB
 */
function yourls_get_db($context = '') {
    // Allow plugins to short-circuit the whole function
    $pre = yourls_apply_filter( 'shunt_get_db', yourls_shunt_default(), $context );
    if ( yourls_shunt_default() !== $pre ) {
        return $pre;
    }

    // Validate context and raise notice if missing or malformed
    if ($context == '' || !preg_match('/^(read|write)-[a-z0-9_]+$/', $context)) {
        $db = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $file = $db[0]['file'];
        $line = $db[0]['line'];

        if ($context == '') {
            $msg = 'Undefined yourls_get_db() context';
        } else {
            $msg = 'Improperly formatted yourls_get_db() context ("' . $context . '")';
        }

        trigger_error( $msg . ' at <b>' . $file . ':' . $line .'</b>', E_USER_NOTICE );
    }

    yourls_do_action( 'get_db_action', $context );

    global $ydb;
    $ydb = ( isset( $ydb ) ) ? $ydb : yourls_db_connect($context);
    return yourls_apply_filter('get_db', $ydb, $context);
}

/**
 * Helper function : set instance of DB, or unset it
 *
 * Instead of:
 *     global $ydb;
 *     $ydb = stuff
 * Prefer :
 *     yourls_set_db( stuff )
 * (This is mostly used in the test suite)
 *
 * @since 1.7.10
 * @param  mixed $db    Either a \YOURLS\Database\YDB instance, or anything. If null, the function will unset $ydb
 * @return void
 */
function yourls_set_db($db) {
    global $ydb;

    if (is_null($db)) {
        unset($ydb);
    } else {
        $ydb = $db;
    }
}
