<?php

/**
 * Wires Doctrine Migrations to YOURLS' existing Doctrine DBAL connection.
 *
 * Doctrine Migrations is configured entirely in code (no doctrine CLI config files), reusing the
 * live YOURLS connection so migrations run against exactly the database YOURLS is configured for,
 * with the admin's table prefix. The migration classes live in includes/Database/Migrations and are
 * namespaced \YOURLS\Database\Migrations.
 *
 * @since 1.11
 */

namespace YOURLS\Database;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;

class MigrationsFactory {

    /**
     * Build a Doctrine Migrations DependencyFactory bound to the given (or the live YOURLS) DBAL
     * connection.
     *
     * @param  Connection|null $connection Optional explicit connection. Defaults to yourls_get_db()'s.
     * @return DependencyFactory
     */
    public static function dependencyFactory( ?Connection $connection = null ): DependencyFactory {
        $connection = $connection ?? yourls_get_db('write-doctrine_migrations')->connection();

        $config = new ConfigurationArray( self::config() );

        return DependencyFactory::fromConnection(
            $config,
            new ExistingConnection( $connection )
        );
    }

    /**
     * The Doctrine Migrations configuration array.
     *
     * @return array
     */
    public static function config(): array {
        return [
            // Map the migrations namespace to its directory.
            'migrations_paths' => [
                'YOURLS\\Database\\Migrations' => YOURLS_INC . '/Database/Migrations',
            ],
            // Where Doctrine records which migrations have run. Prefixed so it lives alongside the
            // YOURLS tables and is unique per install.
            'table_storage' => [
                'table_name' => self::migrationsTableName(),
            ],
            'all_or_nothing'            => true,
            'transactional'            => false, // MySQL DDL is not transactional; avoid false safety.
            'check_database_platform'   => false,
            'organize_migrations'       => 'none',
        ];
    }

    /**
     * Name of the Doctrine migration-versions bookkeeping table, honouring the YOURLS prefix and
     * validated for safety.
     *
     * @return string
     */
    public static function migrationsTableName(): string {
        $prefix = defined('YOURLS_DB_PREFIX') ? YOURLS_DB_PREFIX : 'yourls_';
        // Validate the prefix so a hostile prefix can't smuggle SQL into the bookkeeping table name.
        TablePrefix::validate( $prefix . 'migration_versions' );
        return $prefix . 'migration_versions';
    }

    /**
     * Run all pending migrations up to the latest version.
     *
     * @param  Connection|null $connection
     * @return void
     */
    public static function migrateToLatest( ?Connection $connection = null ): void {
        $dependencyFactory = self::dependencyFactory( $connection );

        $migrator       = $dependencyFactory->getMigrator();
        $planCalculator = $dependencyFactory->getMigrationPlanCalculator();
        $aliasResolver  = $dependencyFactory->getVersionAliasResolver();

        // Resolve the "latest" available migration version and build a migration plan up to it.
        $version = $aliasResolver->resolveVersionAlias( 'latest' );
        $plan    = $planCalculator->getPlanUntilVersion( $version );

        // Nothing to do (already at latest) -> the plan is empty, migrate() is a no-op.
        $migratorConfiguration = ( new MigratorConfiguration() )
            ->setAllOrNothing( true )
            ->setDryRun( false );

        $migrator->migrate( $plan, $migratorConfiguration );
    }
}
