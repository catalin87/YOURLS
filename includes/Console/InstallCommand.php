<?php

/**
 * `bin/console yourls:install` — install YOURLS from the command line.
 *
 * This is the modern, scriptable replacement for admin/install.php. It:
 *   1. Boots YOURLS (so config, constants and the DB connection are available).
 *   2. Creates the DB table structure by running the Doctrine migration
 *      (\YOURLS\Database\Migrations\Version20260101000000_InitialSchema). If the Doctrine
 *      Migrations component isn't installed, it falls back to \YOURLS\Database\Schema::createAll(),
 *      which executes the same DDL directly — so the command always works.
 *   3. Initializes the options table and inserts the sample links (same as the web installer).
 *   4. Creates/updates the .htaccess file.
 *
 * @since 1.11
 */

namespace YOURLS\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use YOURLS\Database\Schema;

class InstallCommand extends Command {

    /**
     * @var string
     */
    protected static $defaultName = 'yourls:install';

    /**
     * @var string
     */
    protected static $defaultDescription = 'Install YOURLS: create DB tables via Doctrine migrations and seed options.';

    protected function configure(): void {
        $this
            ->setName('yourls:install')
            ->setDescription('Install YOURLS: create DB tables via Doctrine migrations and seed options.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Proceed even if YOURLS looks already installed.')
            ->addOption('no-sample', null, InputOption::VALUE_NONE, 'Do not insert the sample short URLs.')
            ->setHelp(
                "Creates the YOURLS database schema (Doctrine migrations), initializes the options\n"
                . "table, inserts sample links, and writes the .htaccess file.\n\n"
                . "Run this instead of opening admin/install.php in a browser."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $io->title('YOURLS installer');

        $this->bootstrapYourls();

        // Pre-flight checks (same as admin/install.php).
        $errors = [];
        if (!yourls_check_PDO()) {
            $errors[] = 'PHP extension for PDO not found.';
        }
        if (!yourls_check_database_version()) {
            $errors[] = 'MySQL version is too old.';
        }
        if (!yourls_check_php_version()) {
            $errors[] = 'PHP version is too old.';
        }
        if ($errors) {
            foreach ($errors as $e) {
                $io->error($e);
            }
            return Command::FAILURE;
        }

        if (yourls_is_installed() && !$input->getOption('force')) {
            $io->warning('YOURLS already appears to be installed. Use --force to run anyway.');
            return Command::SUCCESS;
        }

        // 1) Create tables via Doctrine migration (preferred) or direct DDL fallback.
        $io->section('Creating database schema');
        try {
            $created = $this->createSchema($io);
        } catch (\Throwable $e) {
            $io->error('Schema creation failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
        foreach ($created as $table => $ok) {
            $ok ? $io->writeln("  <info>✓</info> Table <comment>$table</comment>")
                : $io->writeln("  <error>✗</error> Table <comment>$table</comment>");
        }
        if (in_array(false, $created, true)) {
            $io->error('One or more tables could not be created.');
            return Command::FAILURE;
        }

        // 2) Initialize options + sample links (reuse the canonical YOURLS functions).
        $io->section('Seeding data');
        if (!yourls_initialize_options()) {
            $io->error('Could not initialize options.');
            return Command::FAILURE;
        }
        $io->writeln('  <info>✓</info> Options initialized');

        if (!$input->getOption('no-sample')) {
            if (yourls_insert_sample_links()) {
                $io->writeln('  <info>✓</info> Sample links inserted');
            } else {
                $io->writeln('  <comment>!</comment> Sample links could not be inserted (non-fatal)');
            }
        }

        // 3) .htaccess
        $io->section('Web server config');
        if (yourls_create_htaccess()) {
            $io->writeln('  <info>✓</info> .htaccess created/updated');
        } else {
            $io->writeln('  <comment>!</comment> Could not write .htaccess (create it manually).');
        }

        $io->success('YOURLS installed successfully.');
        return Command::SUCCESS;
    }

    /**
     * Boot YOURLS in "installing" mode so bootstrap does not redirect to the web installer.
     *
     * @return void
     */
    protected function bootstrapYourls(): void {
        if (!defined('YOURLS_INSTALLING')) {
            define('YOURLS_INSTALLING', true);
        }
        if (!defined('YOURLS_ADMIN')) {
            define('YOURLS_ADMIN', true);
        }
        // Make installation verbose to help troubleshooting, like the web installer.
        if (!defined('YOURLS_ABSPATH')) {
            require_once dirname(__DIR__, 2) . '/includes/load-yourls.php';
        }
    }

    /**
     * Create the schema, preferring the Doctrine migration path.
     *
     * @param SymfonyStyle $io
     * @return array<string,bool> table => success
     */
    protected function createSchema(SymfonyStyle $io): array {
        $connector = yourls_get_db_connector('write-install_schema');

        // Preferred: run the Doctrine migration through the DBAL connection.
        if ($connector && $this->runMigrations($connector->getConnection(), $io)) {
            // Verify the tables exist after migrating.
            return $this->verifyTables($connector->getConnection());
        }

        // Fallback A: DBAL connection present but Migrations component missing -> run DDL directly.
        if ($connector) {
            $io->writeln('  <comment>(Doctrine Migrations unavailable — applying schema DDL directly)</comment>');
            return Schema::createAll($connector->getConnection());
        }

        // Fallback B: no Doctrine at all -> use the legacy installer, which uses the live engine.
        $io->writeln('  <comment>(Doctrine DBAL unavailable — using legacy yourls_create_sql_tables())</comment>');
        $result  = yourls_create_sql_tables();
        // yourls_create_sql_tables() returns ['success'=>[...], 'error'=>[...]]; empty error == ok.
        $success = empty($result['error']);

        // Translate legacy result into our table=>bool map (best effort).
        return [
            Schema::tableName('url')     => $success,
            Schema::tableName('options') => $success,
            Schema::tableName('log')     => $success,
        ];
    }

    /**
     * Run Doctrine Migrations up to the latest version.
     *
     * @param \Doctrine\DBAL\Connection $connection
     * @param SymfonyStyle              $io
     * @return bool True if migrations ran, false if the Migrations component is not installed.
     */
    protected function runMigrations(\Doctrine\DBAL\Connection $connection, SymfonyStyle $io): bool {
        if (!class_exists(\Doctrine\Migrations\DependencyFactory::class)) {
            return false;
        }

        $config = new \Doctrine\Migrations\Configuration\Migration\ConfigurationArray([
            'migrations_paths' => [
                'YOURLS\\Database\\Migrations' => dirname(__DIR__) . '/Database/Migrations',
            ],
            'table_storage' => [
                'table_name' => Schema::tableName('migrations'),
            ],
            'all_or_nothing'          => false,
            'transactional'           => false,
            'check_database_platform' => false,
        ]);

        $connectionLoader = new \Doctrine\Migrations\Configuration\Connection\ExistingConnection($connection);
        $factory          = \Doctrine\Migrations\DependencyFactory::fromConnection($config, $connectionLoader);

        $planCalc      = $factory->getMigrationPlanCalculator();
        $aliasResolver = $factory->getVersionAliasResolver();

        try {
            $version = $aliasResolver->resolveVersionAlias('latest');
        } catch (\Throwable $e) {
            // No migrations to run.
            $io->writeln('  <comment>No migrations found to apply.</comment>');
            return true;
        }

        $plan = $planCalc->getPlanUntilVersion($version);

        // Ensure the migrations metadata table exists before migrating.
        $factory->getMetadataStorage()->ensureInitialized();

        // Migrator::migrate() requires an explicit MigratorConfiguration (there is no
        // getMigratorConfiguration() on the DependencyFactory in doctrine/migrations 3.x).
        $migratorConfiguration = (new \Doctrine\Migrations\MigratorConfiguration())
            ->setDryRun(false)
            ->setAllOrNothing(false)
            ->setTimeAllQueries(false);

        $migratorService = $factory->getMigrator();
        $migratorService->migrate($plan, $migratorConfiguration);

        $io->writeln('  <info>✓</info> Doctrine migrations applied to <comment>' . (string) $version . '</comment>');
        return true;
    }

    /**
     * Verify the core tables exist.
     *
     * @param \Doctrine\DBAL\Connection $connection
     * @return array<string,bool>
     */
    protected function verifyTables(\Doctrine\DBAL\Connection $connection): array {
        $results = [];
        foreach (['url', 'options', 'log'] as $suffix) {
            $name  = Schema::tableName($suffix);
            $found = $connection->executeQuery('SHOW TABLES LIKE ?', [$name])->fetchOne();
            $results[$name] = ($found !== false && $found !== null);
        }
        return $results;
    }
}
