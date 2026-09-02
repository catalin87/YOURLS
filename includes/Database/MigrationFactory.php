<?php

/**
 * Builds the Doctrine Migrations dependency factory for YOURLS.
 *
 * The migrations run on the very same DBAL connection YOURLS uses at runtime (the one held by
 * \YOURLS\Database\YDB), so they honour the config.php credentials and, importantly, the user
 * defined YOURLS_DB_PREFIX: table names are resolved through \YOURLS\Database\Schema.
 *
 * @since 1.10.5
 */

namespace YOURLS\Database;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Psr\Log\LoggerInterface;

class MigrationFactory {

    /**
     * Namespace the YOURLS migration classes live in
     */
    public const MIGRATIONS_NAMESPACE = 'YOURLS\\Database\\Migrations';

    /**
     * Build a DependencyFactory for the given connection
     *
     * @since  1.10.5
     * @param  Connection           $connection
     * @param  LoggerInterface|null $logger
     * @return DependencyFactory
     */
    public static function create(Connection $connection, ?LoggerInterface $logger = null): DependencyFactory {
        $configuration = new Configuration();

        $configuration->addMigrationsDirectory(
            self::MIGRATIONS_NAMESPACE,
            __DIR__ . '/Migrations'
        );

        /* Keep the migrations bookkeeping table under the same prefix as everything else, so
         * several YOURLS installs can share one database. */
        $storage = new TableMetadataStorageConfiguration();
        $storage->setTableName(Schema::table('migration_versions'));
        $configuration->setMetadataStorageConfiguration($storage);

        $configuration->setAllOrNothing(false);
        $configuration->setCheckDatabasePlatform(false);

        return DependencyFactory::fromConnection(
            new ExistingConfiguration($configuration),
            new ExistingConnection($connection),
            $logger
        );
    }
}
