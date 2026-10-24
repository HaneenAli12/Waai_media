<?php

namespace Tests\Unit;

use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\TestCase as BaseTestCase;
use RuntimeException;
use Tests\TestCase;

class TestDatabaseSafetyGuardTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        HandleExceptions::flushState($this);
        Mockery::close();
        parent::tearDown();
    }

    public function test_guard_accepts_when_database_is_waai_media_testing(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getName')->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->andReturn('waai_media_testing');

        // Should not throw any exception
        TestCase::enforceDatabaseSafety($connection);
        $this->assertTrue(true);
    }

    public function test_guard_rejects_when_database_is_main_waai_media(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getName')->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->andReturn('waai_media');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("CRITICAL TEST DATABASE GUARD: Connection [mysql] attempted to target database 'waai_media'.");

        TestCase::enforceDatabaseSafety($connection);
    }

    public function test_guard_fails_closed_when_database_name_is_empty_or_null(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getName')->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->andReturn('');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CRITICAL TEST DATABASE GUARD: Database name is not configured on connection [mysql].');

        TestCase::enforceDatabaseSafety($connection);
    }

    public function test_guard_fails_closed_when_database_name_is_unexpected_arbitrary_string(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getName')->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->andReturn('production_db');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("CRITICAL TEST DATABASE GUARD: Connection [mysql] attempted to target database 'production_db'.");

        TestCase::enforceDatabaseSafety($connection);
    }

    public function test_guard_evaluates_effective_database_from_parsed_db_url(): void
    {
        $parser = new ConfigurationUrlParser;

        // Simulate DB_URL pointing to the forbidden main database
        $parsedConfigUnsafe = $parser->parseConfiguration([
            'url' => 'mysql://root:secret@127.0.0.1:3306/waai_media',
        ]);

        $this->assertSame('waai_media', $parsedConfigUnsafe['database']);

        $connectionUnsafe = Mockery::mock(Connection::class);
        $connectionUnsafe->shouldReceive('getName')->andReturn('mysql');
        $connectionUnsafe->shouldReceive('getDatabaseName')->andReturn($parsedConfigUnsafe['database']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("CRITICAL TEST DATABASE GUARD: Connection [mysql] attempted to target database 'waai_media'.");

        TestCase::enforceDatabaseSafety($connectionUnsafe);
    }

    public function test_guard_accepts_parsed_db_url_when_pointing_to_testing_database(): void
    {
        $parser = new ConfigurationUrlParser;

        $parsedConfigSafe = $parser->parseConfiguration([
            'url' => 'mysql://root:secret@127.0.0.1:3306/waai_media_testing',
        ]);

        $this->assertSame('waai_media_testing', $parsedConfigSafe['database']);

        $connectionSafe = Mockery::mock(Connection::class);
        $connectionSafe->shouldReceive('getName')->andReturn('mysql');
        $connectionSafe->shouldReceive('getDatabaseName')->andReturn($parsedConfigSafe['database']);

        TestCase::enforceDatabaseSafety($connectionSafe);
        $this->assertTrue(true);
    }

    public function test_connection_established_listener_catches_unsafe_subsequent_connection(): void
    {
        $unsafeConnection = Mockery::mock(Connection::class);
        $unsafeConnection->shouldReceive('getName')->andReturn('secondary_mysql');
        $unsafeConnection->shouldReceive('getDatabaseName')->andReturn('waai_media');

        $event = new ConnectionEstablished($unsafeConnection);

        $listener = function (ConnectionEstablished $event) {
            TestCase::enforceDatabaseSafety($event->connection);
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("CRITICAL TEST DATABASE GUARD: Connection [secondary_mysql] attempted to target database 'waai_media'.");

        $listener($event);
    }

    // =========================================================================
    // Integration-Level Verification (Real Framework Lifecycle & Event Dispatcher)
    // =========================================================================

    public function test_integration_application_boots_when_effective_database_is_waai_media_testing(): void
    {
        $testCase = new class('test_boot') extends TestCase
        {
            public function bootApplication(): mixed
            {
                return $this->createApplication();
            }
        };

        $app = $testCase->bootApplication();

        $this->assertNotNull($app);
        $this->assertSame(
            TestCase::PERMITTED_TEST_DATABASE,
            $app['config']->get('database.connections.mysql.database')
        );
        $this->assertSame(
            TestCase::PERMITTED_TEST_DATABASE,
            $app['db']->connection()->getDatabaseName()
        );

        $app->flush();
    }

    public function test_integration_registered_listener_rejects_newly_resolved_unsafe_connection(): void
    {
        $testCase = new class('test_registered_listener') extends TestCase
        {
            public function bootApplication(): mixed
            {
                return $this->createApplication();
            }
        };

        $app = $testCase->bootApplication();

        // Register a dynamic secondary connection configuration targeting the unsafe main database
        $app['config']->set('database.connections.unsafe_secondary', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'waai_media',
            'username' => 'root',
            'password' => '',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "CRITICAL TEST DATABASE GUARD: Connection [unsafe_secondary] attempted to target database 'waai_media'."
        );

        try {
            // Resolving the connection triggers ConnectionEstablished, which must be caught
            // by the real listener registered inside Tests\TestCase::createApplication().
            $app['db']->connection('unsafe_secondary');
        } finally {
            $app->flush();
        }
    }

    public function test_integration_unsafe_effective_database_is_rejected_before_refresh_database_can_run(): void
    {
        $originalEnv = $_ENV['DB_DATABASE'] ?? null;
        $originalServer = $_SERVER['DB_DATABASE'] ?? null;
        $originalGetEnv = getenv('DB_DATABASE');

        $_ENV['DB_DATABASE'] = 'waai_media';
        $_SERVER['DB_DATABASE'] = 'waai_media';
        putenv('DB_DATABASE=waai_media');

        $testCase = new class('test_guard_aborts_before_refresh_database') extends TestCase
        {
            use RefreshDatabase;

            public bool $refreshDatabaseExecuted = false;

            public function refreshDatabase(): void
            {
                $this->refreshDatabaseExecuted = true;
                parent::refreshDatabase();
            }

            public function executeSetUp(): void
            {
                $this->setUpTheTestEnvironment();
            }
        };

        try {
            $testCase->executeSetUp();
            $this->fail('Expected RuntimeException was not thrown when DB_DATABASE targeted waai_media.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("attempted to target database 'waai_media'", $e->getMessage());
            $this->assertFalse(
                $testCase->refreshDatabaseExecuted,
                'RefreshDatabase::refreshDatabase() must never be executed when createApplication() throws.'
            );
        } finally {
            $_ENV['DB_DATABASE'] = $originalEnv;
            $_SERVER['DB_DATABASE'] = $originalServer;
            if ($originalGetEnv !== false) {
                putenv("DB_DATABASE={$originalGetEnv}");
            } else {
                putenv('DB_DATABASE');
            }
        }
    }
}
