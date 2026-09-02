<?php

/**
 * YOURLS installer
 *
 * Creates the table structure by running the Doctrine migrations, then seeds the options table and
 * the sample short URLs. Shared by admin/install.php (web install) and bin/console (CLI install), so
 * both paths produce exactly the same database.
 *
 * @since 1.11
 */

namespace YOURLS\Database;

class Installer {

    /**
     * Run the pre-requisite checks the installer needs to pass
     *
     * @since  1.11
     * @return string[]  Error messages, empty if everything checks out
     */
    public static function check_prerequisites(): array {
        $errors = [];

        if (!yourls_check_PDO()) {
            $errors[] = yourls__( 'PHP extension for PDO not found' );
            yourls_debug_log( 'PHP PDO extension not found' );
        }

        if (!yourls_check_database_version()) {
            $errors[] = yourls_s( '%s version is too old. Ask your server admin for an upgrade.', 'MySQL' );
            yourls_debug_log( 'MySQL version: ' . yourls_get_database_version() );
        }

        if (!yourls_check_php_version()) {
            $errors[] = yourls_s( '%s version is too old. Ask your server admin for an upgrade.', 'PHP' );
            yourls_debug_log( 'PHP version: ' . PHP_VERSION );
        }

        return $errors;
    }

    /**
     * Create the YOURLS tables by running the Doctrine migrations, and seed them
     *
     * Returns the same array shape yourls_create_sql_tables() has always returned, so callers
     * (and the 'shunt_yourls_create_sql_tables' filter) don't have to care how tables get created.
     *
     * @since  1.11
     * @return array  array( 'success' => array of success strings, 'error' => array of error strings )
     */
    public static function install(): array {
        $error_msg   = [];
        $success_msg = [];

        // Make install process verbose to help troubleshoot installation issues
        $debug = yourls_get_debug_mode();
        yourls_debug_mode(true);

        $ydb = yourls_get_db('write-create_sql_tables');

        try {
            $executed = Migrations::migrate($ydb->get_connection());
            foreach ($executed as $version) {
                $success_msg[] = yourls_s( "Migration '%s' executed.", $version );
            }

            // Confirm the tables the rest of YOURLS expects are really there
            $schema  = $ydb->get_connection()->createSchemaManager();
            $missing = [];
            foreach (TableRegistry::all() as $table) {
                if ($schema->tablesExist([$table])) {
                    $success_msg[] = yourls_s( "Table '%s' created.", $table );
                } else {
                    $missing[]   = $table;
                }
            }

            if ($missing === []) {
                $success_msg[] = yourls__( 'YOURLS tables successfully created.' );
            } else {
                /* The migrations ran (or were already recorded as run) but the tables aren't there.
                 * This happens when the tables were dropped behind the migrations' back, leaving a
                 * stale metadata table. Say so precisely rather than just "error creating tables":
                 * the fix is to drop the metadata table too, or re-run the migration by hand.
                 */
                foreach ($missing as $table) {
                    $error_msg[] = yourls_s( "Error creating table '%s'.", $table );
                }
                $error_msg[] = yourls_s(
                    'Migrations reported nothing to do, but %1$s missing. If you dropped YOURLS tables manually, also drop the migrations table `%2$s` and run the install again.',
                    count($missing) > 1 ? 'these tables are' : 'this table is',
                    YOURLS_DB_PREFIX.'migration_versions'
                );

                // No point seeding options and sample links into tables that don't exist
                yourls_debug_mode( $debug );

                return [ 'success' => $success_msg, 'error' => $error_msg ];
            }
        } catch (\Throwable $e) {
            $error_msg[] = yourls__( 'Error creating YOURLS tables.' );
            $error_msg[] = $e->getMessage();
            yourls_debug_mode( $debug );

            return [ 'success' => $success_msg, 'error' => $error_msg ];
        }

        // Initializes the option table
        if( !yourls_initialize_options() ) {
            $error_msg[] = yourls__( 'Could not initialize options' );
        }

        // Insert sample links
        if( !yourls_insert_sample_links() ) {
            $error_msg[] = yourls__( 'Could not insert sample short URLs' );
        }

        // Restore debug mode to its original value
        yourls_debug_mode( $debug );

        return [ 'success' => $success_msg, 'error' => $error_msg ];
    }

}
