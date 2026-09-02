<?php

/**
 * YOURLS installer service.
 *
 * Orchestrates a full YOURLS install without the web UI:
 *   1. run pre-flight checks (PDO, DB version, PHP version),
 *   2. create the schema via Doctrine migrations (falling back to the legacy CREATE TABLE path if
 *      migrations can't run for some reason),
 *   3. seed the initial option rows and the sample short URLs,
 *   4. write the .htaccess / web.config rewrite rules.
 *
 * It reuses the existing, battle-tested YOURLS functions (yourls_initialize_options(),
 * yourls_insert_sample_links(), yourls_create_htaccess(), etc.) so behavior matches the classic
 * installer exactly — only the *table creation* step is modernized to Doctrine migrations.
 *
 * @since 1.11
 */

namespace YOURLS\Console;

use YOURLS\Database\MigrationsFactory;

class Installer {

    /**
     * Result buffers, mirroring yourls_create_sql_tables()'s return shape.
     *
     * @var array
     */
    protected array $success = [];

    /**
     * @var array
     */
    protected array $errors = [];

    /**
     * Run pre-flight environment checks. Returns a list of error strings (empty if all good).
     *
     * @return string[]
     */
    public function preflight(): array {
        $errors = [];

        if ( !yourls_check_PDO() ) {
            $errors[] = yourls__( 'PHP extension for PDO not found' );
        }
        if ( !yourls_check_database_version() ) {
            $errors[] = yourls_s( '%s version is too old. Ask your server admin for an upgrade.', 'MySQL' );
        }
        if ( !yourls_check_php_version() ) {
            $errors[] = yourls_s( '%s version is too old. Ask your server admin for an upgrade.', 'PHP' );
        }

        return $errors;
    }

    /**
     * Is YOURLS already installed?
     *
     * @return bool
     */
    public function isInstalled(): bool {
        return yourls_is_installed();
    }

    /**
     * Create the database schema using Doctrine migrations.
     *
     * @param  bool $useMigrations When false, uses the legacy CREATE TABLE path (yourls_create_sql_tables).
     * @return bool  True on success.
     */
    public function createSchema( bool $useMigrations = true ): bool {
        // A custom user/db.php drop-in may want to fully own table creation. Honour the historical
        // shunt so those setups keep working.
        $pre = yourls_apply_filter( 'shunt_yourls_create_sql_tables', yourls_shunt_default() );
        if ( yourls_shunt_default() !== $pre ) {
            $this->mergeLegacyResult( (array) $pre );
            return empty( $pre['error'] ?? [] );
        }

        if ( $useMigrations ) {
            try {
                MigrationsFactory::migrateToLatest();
                $this->success[] = yourls__( 'YOURLS tables successfully created (via Doctrine migrations).' );
                return true;
            } catch ( \Throwable $e ) {
                // Fall back to the legacy path so an install never hard-fails on a migrations hiccup.
                $this->errors[] = yourls_s( 'Doctrine migration failed (%s), falling back to legacy table creation.', $e->getMessage() );
            }
        }

        // Legacy CREATE TABLE path (also seeds options + sample links).
        $result = yourls_create_sql_tables();
        $this->mergeLegacyResult( $result );
        return empty( $result['error'] );
    }

    /**
     * Seed the initial option rows and sample short URLs.
     *
     * Safe to call after a migration-based schema creation (which does NOT seed). When the legacy
     * createSchema() path ran, it already seeded — call seed() only on the migrations path.
     *
     * @return bool
     */
    public function seed(): bool {
        $ok = true;

        if ( !yourls_initialize_options() ) {
            $this->errors[] = yourls__( 'Could not initialize options' );
            $ok = false;
        }
        if ( !yourls_insert_sample_links() ) {
            $this->errors[] = yourls__( 'Could not insert sample short URLs' );
            $ok = false;
        }

        return $ok;
    }

    /**
     * Write the rewrite rules (.htaccess or web.config).
     *
     * @return bool
     */
    public function writeRewriteRules(): bool {
        if ( yourls_create_htaccess() ) {
            $this->success[] = yourls__( 'File .htaccess successfully created/updated.' );
            return true;
        }
        $this->errors[] = yourls__( 'Could not write .htaccess. You will have to do it manually.' );
        return false;
    }

    /**
     * Full install: preflight -> schema -> seed -> rewrite rules.
     *
     * @param  bool $useMigrations
     * @return bool True when the install completed with no errors.
     */
    public function install( bool $useMigrations = true ): bool {
        $this->success = [];
        $this->errors  = [];

        $errors = $this->preflight();
        if ( $errors ) {
            $this->errors = array_merge( $this->errors, $errors );
            return false;
        }

        if ( $this->isInstalled() ) {
            $this->errors[] = yourls__( 'YOURLS already installed.' );
            return false;
        }

        $this->writeRewriteRules();

        $migrated = $this->createSchema( $useMigrations );

        // The migrations path does not seed; the legacy path seeds itself. Detect which ran by
        // checking whether options already exist.
        if ( $migrated && $useMigrations && (int) yourls_get_option( 'db_version', 0 ) === 0 ) {
            $this->seed();
        }

        return empty( $this->errors );
    }

    /**
     * @param array $result A yourls_create_sql_tables()-style ['success'=>[], 'error'=>[]] array.
     * @return void
     */
    protected function mergeLegacyResult( array $result ): void {
        if ( !empty( $result['success'] ) ) {
            $this->success = array_merge( $this->success, (array) $result['success'] );
        }
        if ( !empty( $result['error'] ) ) {
            $this->errors = array_merge( $this->errors, (array) $result['error'] );
        }
    }

    /**
     * @return string[]
     */
    public function getSuccessMessages(): array {
        return $this->success;
    }

    /**
     * @return string[]
     */
    public function getErrorMessages(): array {
        return $this->errors;
    }
}
