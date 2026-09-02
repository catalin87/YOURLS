<?php

/**
 * Installs YOURLS: creates the schema through Doctrine Migrations, then seeds options and sample links.
 *
 * Both the web installer (admin/install.php) and the CLI installer (bin/console yourls:install) go
 * through this class, so there is a single definition of what "installing YOURLS" means.
 *
 * @since 1.10.5
 */

namespace YOURLS\Database;

use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Psr\Log\LoggerInterface;
use Throwable;

class Installer {

    /**
     * Run the migrations, then initialize options and sample links.
     *
     * Returns the same array shape yourls_create_sql_tables() has always returned, so callers
     * (and plugins filtering it) keep working:
     *   array( 'success' => array of success strings, 'error' => array of error strings )
     *
     * @since  1.10.5
     * @param  LoggerInterface|null $logger  Optional logger for migration output
     * @param  bool                 $seed    Whether to seed options and sample links
     * @return array
     */
    public static function install(?LoggerInterface $logger = null, bool $seed = true): array {
        $success = [];
        $error   = [];

        // Make the install process verbose to help troubleshoot installation issues
        $debug = yourls_get_debug_mode();
        yourls_debug_mode(true);

        try {
            $created = self::migrate($logger);

            foreach ($created as $table) {
                $success[] = yourls_s("Table '%s' created.", $table);
            }

            if (count($created) === count(Schema::all())) {
                $success[] = yourls__('YOURLS tables successfully created.');
            } else {
                $error[] = yourls__('Error creating YOURLS tables.');
            }
        } catch (Throwable $e) {
            $error[] = yourls__('Error creating YOURLS tables.');
            $error[] = $e->getMessage();

            yourls_debug_mode($debug);

            return ['success' => $success, 'error' => $error];
        }

        if ($seed) {
            // Initializes the option table
            if (!yourls_initialize_options()) {
                $error[] = yourls__('Could not initialize options');
            }

            // Insert sample links
            if (!yourls_insert_sample_links()) {
                $error[] = yourls__('Could not insert sample short URLs');
            }
        }

        // Restore debug mode to its original value
        yourls_debug_mode($debug);

        return ['success' => $success, 'error' => $error];
    }

    /**
     * Run every pending migration.
     *
     * @since  1.10.5
     * @param  LoggerInterface|null $logger
     * @return array  Names of the YOURLS tables that exist once the migrations have run
     */
    public static function migrate(?LoggerInterface $logger = null): array {
        $ydb        = yourls_get_db('write-run_migrations');
        $connection = $ydb->get_connection();

        $factory = MigrationFactory::create($connection, $logger);

        // Create the migrations bookkeeping table if it isn't there yet
        $factory->getMetadataStorage()->ensureInitialized();

        $planner = $factory->getMigrationPlanCalculator();
        $version = $factory->getVersionAliasResolver()->resolveVersionAlias('latest');

        $plan = $planner->getPlanUntilVersion($version);

        if (count($plan) > 0) {
            $migrator       = $factory->getMigrator();
            $migratorConfig = $factory->getConsoleInputMigratorConfigurationFactory()
                                      ->getMigratorConfiguration(new \Symfony\Component\Console\Input\ArrayInput([]));

            $migrator->migrate($plan, $migratorConfig);
        }

        return self::existing_tables();
    }

    /**
     * Which of the YOURLS tables currently exist in the database
     *
     * @since  1.10.5
     * @return array
     */
    public static function existing_tables(): array {
        $ydb     = yourls_get_db('read-existing_tables');
        $manager = $ydb->get_connection()->createSchemaManager();
        $found   = [];

        foreach (Schema::all() as $table) {
            if ($manager->tableExists($table)) {
                $found[] = $table;
            }
        }

        return $found;
    }

    /**
     * Is the schema fully migrated?
     *
     * @since  1.10.5
     * @return bool
     */
    public static function is_migrated(): bool {
        return count(self::existing_tables()) === count(Schema::all());
    }
}
