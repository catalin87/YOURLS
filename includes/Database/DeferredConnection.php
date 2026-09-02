<?php

/**
 * A Doctrine Migrations connection loader that resolves the YOURLS connection on demand
 *
 * Doctrine's own ExistingConnection wants a Connection handed to it upfront. That would make
 * bin/console open the database just to build its command list, and abort on a machine whose
 * database is not reachable yet - exactly the machine where `yourls:install` is needed.
 *
 * @since 1.11
 */

namespace YOURLS\Database;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ConnectionLoader;

class DeferredConnection implements ConnectionLoader {

    /**
     * Resolved connection, if we have been asked for one already
     * @var Connection|null
     */
    private ?Connection $connection = null;

    /**
     * Return the connection YOURLS is using, connecting on first call
     *
     * @since  1.11
     * @param  string|null $name Unused: YOURLS only ever has one connection
     * @return Connection
     */
    public function getConnection(string|null $name = null): Connection {
        return $this->connection ??= yourls_get_db('write-migrations')->get_connection();
    }

}
