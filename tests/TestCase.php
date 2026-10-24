<?php

namespace Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * The exact permitted test database name.
     */
    public const PERMITTED_TEST_DATABASE = 'waai_media_testing';

    /**
     * Creates the application and enforces fail-closed test database protection.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        // 1. Initial Guard: Validate effective default connection immediately upon application boot
        static::enforceDatabaseSafety($app['db']->connection());

        // 2. Runtime Guard: Intercept all subsequent connection establishments during test execution
        $app['events']->listen(ConnectionEstablished::class, function (ConnectionEstablished $event) {
            static::enforceDatabaseSafety($event->connection);
        });

        return $app;
    }

    /**
     * Validate that a database connection resolves to the strictly permitted test database.
     *
     * @throws RuntimeException
     */
    public static function enforceDatabaseSafety(Connection $connection): void
    {
        $databaseName = $connection->getDatabaseName();

        if (empty($databaseName)) {
            throw new RuntimeException(
                "CRITICAL TEST DATABASE GUARD: Database name is not configured on connection [{$connection->getName()}]. ".
                "Tests must explicitly target '".static::PERMITTED_TEST_DATABASE."'."
            );
        }

        if ($databaseName !== static::PERMITTED_TEST_DATABASE) {
            throw new RuntimeException(
                "CRITICAL TEST DATABASE GUARD: Connection [{$connection->getName()}] attempted to target database '{$databaseName}'. ".
                "Tests are strictly prohibited from running against or modifying any database other than '".static::PERMITTED_TEST_DATABASE."'."
            );
        }
    }
}
