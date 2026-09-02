<?php

declare(strict_types=1);

/**
 * `bin/console yourls:install`
 *
 * Installs YOURLS from the command line: runs the Doctrine migrations to create the schema, seeds
 * the options table and the sample short URLs, and writes the .htaccess / web.config file.
 *
 * @since 1.10.5
 */

namespace YOURLS\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use YOURLS\Database\Installer;
use YOURLS\Database\Schema;

#[AsCommand(
    name: 'yourls:install',
    description: 'Install YOURLS: create the database schema and seed the default data',
)]
class InstallCommand extends Command {

    protected function configure(): void {
        $this
            ->addOption('no-seed', null, InputOption::VALUE_NONE, 'Only create the schema, do not insert options or sample links')
            ->addOption('no-htaccess', null, InputOption::VALUE_NONE, 'Do not create or update the .htaccess / web.config file')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Run even if YOURLS looks already installed')
            ->setHelp(<<<'HELP'
The <info>yourls:install</info> command installs YOURLS.

It runs the Doctrine migrations that create the <comment>url</comment>, <comment>options</comment> and
<comment>log</comment> tables (honouring your YOURLS_DB_PREFIX), then inserts the default options
and a few sample short URLs.

  <info>php bin/console yourls:install</info>

To create the schema only, without any sample data:

  <info>php bin/console yourls:install --no-seed</info>
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);

        $io->title('Installing YOURLS');

        // Pre-flight checks, same as the web installer performs
        $problems = [];

        if (!yourls_check_PDO()) {
            $problems[] = 'PHP extension for PDO not found';
        }

        if (!yourls_check_php_version()) {
            $problems[] = sprintf('PHP version is too old (running %s, need 8.2+)', PHP_VERSION);
        }

        if (!yourls_check_database_version()) {
            $problems[] = sprintf('Database version is too old (running %s)', yourls_get_database_version());
        }

        if ($problems !== []) {
            $io->error($problems);

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Database' => YOURLS_DB_NAME],
            ['Host'     => YOURLS_DB_HOST],
            ['Prefix'   => Schema::prefix()],
            ['Site'     => yourls_get_yourls_site()],
        );

        if (yourls_is_installed() && !$input->getOption('force')) {
            $io->warning('YOURLS is already installed. Use --force to run the installer anyway.');

            return Command::SUCCESS;
        }

        // Create/update the rewrite rules file
        if (!$input->getOption('no-htaccess')) {
            if (yourls_create_htaccess()) {
                $io->text('<info>✓</info> Rewrite rules file created/updated.');
            } else {
                $io->warning('Could not write the .htaccess file in the YOURLS root directory. You will have to do it manually: https://yourls.org/htaccess');
            }
        }

        $io->section('Running migrations');

        $logger = new ConsoleLogger($io);
        $result = Installer::install($logger, !$input->getOption('no-seed'));

        foreach ($result['success'] as $message) {
            $io->text('<info>✓</info> ' . strip_tags($message));
        }

        if ($result['error'] !== []) {
            $io->error(array_map('strip_tags', $result['error']));

            return Command::FAILURE;
        }

        $io->success('YOURLS is installed.');
        $io->text(sprintf('Admin area: <comment>%s</comment>', yourls_admin_url()));

        return Command::SUCCESS;
    }
}
