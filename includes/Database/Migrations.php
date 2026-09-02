<?php

/**
 * Doctrine Migrations plumbing for YOURLS
 *
 * YOURLS table names depend on the user's YOURLS_DB_PREFIX, so migrations cannot hardcode them.
 * Migration classes read them from \YOURLS\Database\TableRegistry, which validates the prefix.
 *
 * @since 1.11
 */

namespace YOURLS\Database;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;

class Migrations {

    /**
     * Namespace the migration classes live in
     */
    public const NAMESPACE = 'YOURLS\\Migrations';

    /**
     * Build the Doctrine Migrations dependency factory for a given connection
     *
     * The metadata table is prefixed like every other YOURLS table, so several YOURLS installs can
     * share one database as long as they use different prefixes.
     *
     * @since  1.11
     * @param  Connection $connection
     * @return DependencyFactory
     */
    public static function dependency_factory(Connection $connection): DependencyFactory {
        return DependencyFactory::fromConnection(self::configuration(), new ExistingConnection($connection));
    }

    /**
     * Build the dependency factory without opening a database connection
     *
     * Used by bin/console, which must be able to list and describe its commands on a machine where
     * the database is not reachable yet. The connection is resolved on first use.
     *
     * @since  1.11
     * @return DependencyFactory
     */
    public static function lazy_dependency_factory(): DependencyFactory {
        return DependencyFactory::fromConnection(self::configuration(), new DeferredConnection());
    }

    /**
     * The migrations configuration: where migrations live, and where their metadata is stored
     *
     * @since  1.11
     * @return ConfigurationArray
     */
    protected static function configuration(): ConfigurationArray {
        return new ConfigurationArray([
            'migrations_paths'        => [self::NAMESPACE => YOURLS_INC.'/Migrations'],
            'table_storage'           => [
                'table_name' => TableRegistry::validate(YOURLS_DB_PREFIX.'migration_versions'),
            ],
            'all_or_nothing'          => false,
            'check_database_platform' => false,
        ]);
    }

    /**
     * Migrate the database up to the latest available version
     *
     * @since  1.11
     * @param  Connection $connection
     * @return string[]   Human readable list of the migrations that were executed
     */
    public static function migrate(Connection $connection): array {
        $factory = self::dependency_factory($connection);

        // Create the metadata table on a fresh database, so the first migrate() has somewhere to
        // record itself (this is what `migrations:sync-metadata-storage` does).
        $factory->getMetadataStorage()->ensureInitialized();

        $planned = $factory->getMigrationPlanCalculator()->getPlanUntilVersion(
            $factory->getVersionAliasResolver()->resolveVersionAlias('latest')
        );

        $executed = [];
        foreach ($planned->getItems() as $item) {
            $executed[] = (string)$item->getVersion();
        }

        $factory->getMigrator()->migrate($planned, (new MigratorConfiguration())->setAllOrNothing(false));

        return $executed;
    }

}
