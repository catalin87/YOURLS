<?php

/**
 * `bin/console yourls:install`
 *
 * Installs YOURLS from the command line: runs pre-flight checks, creates the schema via Doctrine
 * migrations, seeds options + sample links, and writes the rewrite rules — the CLI equivalent of
 * the admin/install.php web installer.
 *
 * @since 1.11
 */

namespace YOURLS\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'yourls:install',
    description: 'Install YOURLS and create the database schema via Doctrine migrations.'
)]
class InstallCommand extends Command {

    protected function configure(): void {
        $this
            ->setName('yourls:install')
            ->setDescription('Install YOURLS and create the database schema via Doctrine migrations.')
            ->addOption(
                'legacy-schema',
                null,
                InputOption::VALUE_NONE,
                'Create tables with the legacy CREATE TABLE path instead of Doctrine migrations.'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Proceed even if YOURLS looks already installed (migrations are idempotent).'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $io->title('YOURLS installer');

        $installer = new Installer();

        // Pre-flight
        $errors = $installer->preflight();
        if ($errors) {
            foreach ($errors as $e) {
                $io->error($e);
            }
            return Command::FAILURE;
        }

        if ($installer->isInstalled() && !$input->getOption('force')) {
            $io->warning('YOURLS already appears to be installed. Use --force to run anyway.');
            return Command::SUCCESS;
        }

        $useMigrations = !$input->getOption('legacy-schema');

        $io->section($useMigrations ? 'Creating schema (Doctrine migrations)' : 'Creating schema (legacy)');
        $installer->writeRewriteRules();
        $schemaOk = $installer->createSchema($useMigrations);

        if ($schemaOk && $useMigrations && (int) yourls_get_option('db_version', 0) === 0) {
            $io->section('Seeding options and sample links');
            $installer->seed();
        }

        foreach ($installer->getSuccessMessages() as $msg) {
            $io->writeln('<info>✓</info> ' . strip_tags($msg));
        }

        $errs = $installer->getErrorMessages();
        if ($errs) {
            foreach ($errs as $msg) {
                $io->writeln('<error>✗ ' . strip_tags($msg) . '</error>');
            }
            $io->error('YOURLS installation completed with errors.');
            return Command::FAILURE;
        }

        $io->success('YOURLS installed successfully. Log in at ' . yourls_admin_url());
        return Command::SUCCESS;
    }
}
