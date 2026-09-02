<?php

declare(strict_types=1);

/**
 * Initial YOURLS schema migration.
 *
 * Creates the three core tables (url, options, log) using the canonical DDL from
 * \YOURLS\Database\Schema, honoring the dynamic YOURLS_DB_PREFIX. This replaces the ad-hoc
 * CREATE TABLE statements that lived in yourls_create_sql_tables().
 *
 * @since 1.11
 */

namespace YOURLS\Database\Migrations;

use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\Migrations\AbstractMigration;
use YOURLS\Database\Schema;

final class Version20260101000000_InitialSchema extends AbstractMigration {

    public function getDescription(): string {
        return 'Create YOURLS core tables (url, options, log).';
    }

    /**
     * We use raw DDL (addSql) rather than the Doctrine Schema diff API, because the historical
     * YOURLS tables use MySQL-specific column types, collations and prefix-length indexes
     * (e.g. KEY url_idx (url(30))) that must be reproduced verbatim for byte-compatibility.
     *
     * @param DbalSchema $schema
     * @return void
     */
    public function up(DbalSchema $schema): void {
        foreach (Schema::createStatements() as $ddl) {
            $this->addSql($ddl);
        }
    }

    /**
     * @param DbalSchema $schema
     * @return void
     */
    public function down(DbalSchema $schema): void {
        foreach (Schema::dropStatements() as $ddl) {
            $this->addSql($ddl);
        }
    }

    /**
     * These migrations run DDL that is transaction-unsafe on MySQL (implicit commits), so we
     * disable the per-migration transaction wrapper.
     *
     * @return bool
     */
    public function isTransactional(): bool {
        return false;
    }
}
