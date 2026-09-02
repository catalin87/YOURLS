<?php

/**
 * The DB layer must keep throwing PDOException on errors.
 *
 * YOURLS ran on PDO for its whole life, so core code (see \YOURLS\Database\Options) and third
 * party plugins catch \PDOException around queries. Doctrine DBAL throws its own exception types,
 * so \YOURLS\Database\YDB converts them back. These tests pin that contract down.
 */
#[\PHPUnit\Framework\Attributes\Group('db')]
class DBExceptionTest extends PHPUnit\Framework\TestCase {

    /**
     * Every fetch method must surface a database error as a PDOException
     */
    public static function fetch_methods(): Iterator {
        yield 'fetchAffected' => ['fetchAffected'];
        yield 'fetchAll'      => ['fetchAll'];
        yield 'fetchAssoc'    => ['fetchAssoc'];
        yield 'fetchCol'      => ['fetchCol'];
        yield 'fetchObject'   => ['fetchObject'];
        yield 'fetchObjects'  => ['fetchObjects'];
        yield 'fetchOne'      => ['fetchOne'];
        yield 'fetchPairs'    => ['fetchPairs'];
        yield 'fetchValue'    => ['fetchValue'];
        yield 'perform'       => ['perform'];
        // fetchGroup and query() do not go through the same code path as the others
        yield 'fetchGroup'    => ['fetchGroup'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fetch_methods')]
    public function test_fetch_methods_throw_pdo_exception($method) {
        $ydb = yourls_get_db('read-test_exception');

        $this->expectException(PDOException::class);
        $ydb->$method('SELECT * FROM this_table_does_not_exist_1337');
    }

    /**
     * query() runs raw SQL and bypasses the fetch wrapper: it must convert too
     */
    public function test_query_throws_pdo_exception() {
        $ydb = yourls_get_db('read-test_exception_query');

        $this->expectException(PDOException::class);
        $ydb->query('SELECT * FROM this_table_does_not_exist_1337');
    }

    /**
     * A syntax error must be a PDOException as well, not a Doctrine one
     */
    public function test_syntax_error_throws_pdo_exception() {
        $ydb = yourls_get_db('read-test_exception_syntax');

        $this->expectException(PDOException::class);
        $ydb->fetchValue('SELECT MOST DEFINITELY NOT SQL');
    }

    /**
     * The converted exception must carry the driver's message and SQLSTATE, so code that
     * inspects them keeps working
     */
    public function test_exception_carries_driver_details() {
        $ydb = yourls_get_db('read-test_exception_details');

        try {
            $ydb->fetchValue('SELECT * FROM this_table_does_not_exist_1337');
            $this->fail('Expected a PDOException');
        } catch (PDOException $e) {
            $this->assertStringContainsString('this_table_does_not_exist_1337', $e->getMessage());
            // MySQL reports a missing table as SQLSTATE 42S02
            $this->assertSame('42S02', $e->getCode());
            $this->assertIsArray($e->errorInfo);
            $this->assertSame('42S02', $e->errorInfo[0]);
        }
    }

    /**
     * This is the exact pattern Options::get_all_options() relies on to detect a missing table
     * and trigger the install redirect
     */
    public function test_options_missing_table_pattern() {
        $ydb = yourls_get_db('read-test_exception_options');
        $caught = false;

        try {
            $ydb->fetchPairs('SELECT option_name, option_value FROM nonexistent_options_table');
        } catch (PDOException $e) {
            $caught = true;
        }

        $this->assertTrue($caught, 'A missing options table must raise a PDOException');

        // ...and the existence probe that follows must report 0 without throwing
        $this->assertSame(0, $ydb->fetchAffected("SHOW TABLES LIKE 'nonexistent_options_table'"));
    }

    /**
     * The existence probe must report 1 for a table that does exist
     */
    public function test_show_tables_probe_counts_rows() {
        $ydb = yourls_get_db('read-test_show_tables');

        $this->assertSame(1, $ydb->fetchAffected(sprintf("SHOW TABLES LIKE '%s'", YOURLS_DB_TABLE_URL)));
    }
}
