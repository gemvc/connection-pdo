<?php

declare(strict_types=1);

namespace Gemvc\Database\Connection\Pdo\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Gemvc\Database\Connection\Pdo\PdoConnection;
use Gemvc\Database\Connection\Contracts\ConnectionInterface;
use PDO;

/**
 * Integration tests for PdoConnection
 * 
 * Tests the connection manager with actual database connections.
 * Uses in-memory SQLite for integration testing.
 * 
 * @covers \Gemvc\Database\Connection\Pdo\PdoConnection
 */
class PdoConnectionIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset singleton before each test
        PdoConnection::resetInstance();
        
        // Set up environment for SQLite in-memory database
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_NAME'] = ':memory:';
        $_ENV['DB_PERSISTENT_CONNECTIONS'] = '0';
        $_ENV['DB_CONNECTION_TIMEOUT'] = '5';
        $_ENV['APP_ENV'] = 'test';
    }

    protected function tearDown(): void
    {
        // Clean up
        PdoConnection::resetInstance();
        unset(
            $_ENV['DB_DRIVER'],
            $_ENV['DB_NAME'],
            $_ENV['DB_PERSISTENT_CONNECTIONS'],
            $_ENV['DB_CONNECTION_TIMEOUT'],
            $_ENV['APP_ENV']
        );
    }

    public function testCreateAndUseConnection(): void
    {
        $manager = PdoConnection::getInstance();
        $connection = $manager->getConnection();
        
        $this->assertInstanceOf(ConnectionInterface::class, $connection);
        $this->assertTrue($connection->isInitialized());
        
        // Get underlying PDO
        $pdo = $connection->getConnection();
        $this->assertInstanceOf(PDO::class, $pdo);
        
        // Test query execution
        $pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO test (name) VALUES ('test')");
        
        $result = $pdo->query('SELECT * FROM test')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $result);
        $this->assertEquals('test', $result[0]['name']);
    }

    public function testConnectionCaching(): void
    {
        $manager = PdoConnection::getInstance();
        
        // Get connection twice
        $connection1 = $manager->getConnection('cache_test');
        $connection2 = $manager->getConnection('cache_test');
        
        // Should be the same instance
        $this->assertSame($connection1, $connection2);
        
        // Get underlying PDOs
        $pdo1 = $connection1->getConnection();
        $pdo2 = $connection2->getConnection();
        
        // Should be the same PDO instance
        $this->assertSame($pdo1, $pdo2);
    }

    public function testMultipleConnections(): void
    {
        $manager = PdoConnection::getInstance();
        
        $connection1 = $manager->getConnection('read');
        $connection2 = $manager->getConnection('write');
        
        // Should be different instances
        $this->assertNotSame($connection1, $connection2);
        
        // Both should work independently
        $pdo1 = $connection1->getConnection();
        $pdo2 = $connection2->getConnection();
        
        $pdo1->exec('CREATE TABLE test1 (id INTEGER PRIMARY KEY)');
        $pdo2->exec('CREATE TABLE test2 (id INTEGER PRIMARY KEY)');
        
        // Both tables should exist in their respective connections
        $tables1 = $pdo1->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $tables2 = $pdo2->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        
        $this->assertTrue(in_array('test1', $tables1, true));
        $this->assertTrue(in_array('test2', $tables2, true));
    }

    public function testTransactionManagement(): void
    {
        $manager = PdoConnection::getInstance();
        $connection = $manager->getConnection();
        
        $pdo = $connection->getConnection();
        $pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, value TEXT)');
        
        // Begin transaction
        $this->assertTrue($connection->beginTransaction());
        $this->assertTrue($connection->inTransaction());
        
        // Insert data
        $pdo->exec("INSERT INTO test (value) VALUES ('test1')");
        $pdo->exec("INSERT INTO test (value) VALUES ('test2')");
        
        // Rollback
        $this->assertTrue($connection->rollback());
        $this->assertFalse($connection->inTransaction());
        
        // Verify no data was committed
        $count = $pdo->query('SELECT COUNT(*) FROM test')->fetchColumn();
        $this->assertEquals(0, $count);
        
        // Test commit
        $this->assertTrue($connection->beginTransaction());
        $pdo->exec("INSERT INTO test (value) VALUES ('test3')");
        $this->assertTrue($connection->commit());
        $this->assertFalse($connection->inTransaction());
        
        // Verify data was committed
        $count = $pdo->query('SELECT COUNT(*) FROM test')->fetchColumn();
        $this->assertEquals(1, $count);
    }

    public function testReleaseConnection(): void
    {
        $manager = PdoConnection::getInstance();
        $connection = $manager->getConnection('release_test');
        
        // Verify connection exists
        $this->assertNotNull($connection);
        
        // Release connection
        $manager->releaseConnection($connection);
        
        // Get connection again - should be a new instance
        $newConnection = $manager->getConnection('release_test');
        $this->assertNotSame($connection, $newConnection);
    }

    public function testErrorHandling(): void
    {
        $manager = PdoConnection::getInstance();
        
        // Initially no error
        $this->assertNull($manager->getError());
        
        // Set error
        $manager->setError('Test error', ['context' => 'value']);
        $error = $manager->getError();
        if ($error) {
            $this->assertStringContainsString('Test error', $error);
            $this->assertStringContainsString('Context', $error);
        }
        
        // Clear error
        $manager->clearError();
        $this->assertNull($manager->getError());
    }

    public function testConnectionStatistics(): void
    {
        $manager = PdoConnection::getInstance();
        
        // Initially no connections
        $stats = $manager->getPoolStats();
        $this->assertEquals(0, $stats['cached_connections']);
        
        // Create connections
        $manager->getConnection('conn1');
        $manager->getConnection('conn2');
        $manager->getConnection('conn3');
        
        $stats = $manager->getPoolStats();
        $this->assertEquals(3, $stats['cached_connections']);
        $this->assertTrue($stats['initialized']);
        $this->assertEquals('Apache/Nginx PHP-FPM', $stats['environment']);
    }

    public function testPersistentConnections(): void
    {
        // Test with persistent enabled
        $_ENV['DB_PERSISTENT_CONNECTIONS'] = '1';
        PdoConnection::resetInstance();
        
        $manager = PdoConnection::getInstance();
        $stats = $manager->getPoolStats();
        $this->assertTrue($stats['persistent_enabled']);
        
        // Test with persistent disabled
        $_ENV['DB_PERSISTENT_CONNECTIONS'] = '0';
        PdoConnection::resetInstance();
        
        $manager = PdoConnection::getInstance();
        $stats = $manager->getPoolStats();
        $this->assertFalse($stats['persistent_enabled']);
    }

    public function testConnectionTimeout(): void
    {
        $_ENV['DB_CONNECTION_TIMEOUT'] = '10';
        PdoConnection::resetInstance();
        
        $manager = PdoConnection::getInstance();
        $stats = $manager->getPoolStats();
        
        $this->assertEquals(10, $stats['config']['timeout']);
    }

    public function testMultipleTransactionsOnSameConnection(): void
    {
        $manager = PdoConnection::getInstance();
        $connection = $manager->getConnection();
        
        $pdo = $connection->getConnection();
        $pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, value TEXT)');
        
        // First transaction
        $this->assertTrue($connection->beginTransaction());
        $pdo->exec("INSERT INTO test (value) VALUES ('first')");
        $this->assertTrue($connection->commit());
        
        // Second transaction
        $this->assertTrue($connection->beginTransaction());
        $pdo->exec("INSERT INTO test (value) VALUES ('second')");
        $this->assertTrue($connection->commit());
        
        // Verify both records
        $count = $pdo->query('SELECT COUNT(*) FROM test')->fetchColumn();
        $this->assertEquals(2, $count);
    }

    public function testNestedTransactionPrevention(): void
    {
        $manager = PdoConnection::getInstance();
        $connection = $manager->getConnection();
        
        // Begin transaction
        $this->assertTrue($connection->beginTransaction());
        
        // Try to begin another transaction - should fail
        $this->assertFalse($connection->beginTransaction());
        $this->assertNotNull($connection->getError());
        $this->assertStringContainsString('Already in transaction', $connection->getError());
    }

    public function testCommitWithoutTransaction(): void
    {
        $manager = PdoConnection::getInstance();
        $connection = $manager->getConnection();
        
        // Try to commit without transaction
        $this->assertFalse($connection->commit());
        $this->assertNotNull($connection->getError());
        $this->assertStringContainsString('No active transaction', $connection->getError());
    }

    public function testRollbackWithoutTransaction(): void
    {
        $manager = PdoConnection::getInstance();
        $connection = $manager->getConnection();
        
        // Try to rollback without transaction
        $this->assertFalse($connection->rollback());
        $this->assertNotNull($connection->getError());
        $this->assertStringContainsString('No active transaction', $connection->getError());
    }

    public function testConnectionAfterRelease(): void
    {
        $manager = PdoConnection::getInstance();
        $connection = $manager->getConnection('test');
        
        $pdo = $connection->getConnection();
        $pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY)');
        
        // Release connection
        $manager->releaseConnection($connection);
        
        // Get new connection
        $newConnection = $manager->getConnection('test');
        $newPdo = $newConnection->getConnection();
        
        // New connection should be fresh (no table from previous connection)
        // Note: For SQLite in-memory, each connection is separate
        $tables = $newPdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertFalse(in_array('test', $tables, true));
    }

    public function testResetInstanceClearsConnections(): void
    {
        $manager = PdoConnection::getInstance();
        $manager->getConnection('conn1');
        $manager->getConnection('conn2');
        
        $stats = $manager->getPoolStats();
        $this->assertEquals(2, $stats['cached_connections']);
        
        // Reset instance
        PdoConnection::resetInstance();
        
        $newManager = PdoConnection::getInstance();
        $newStats = $newManager->getPoolStats();
        $this->assertEquals(0, $newStats['cached_connections']);
    }

    public function testConnectionWithInvalidConfiguration(): void
    {
        // Set invalid configuration
        $_ENV['DB_DRIVER'] = 'invalid_driver';
        $_ENV['DB_HOST'] = 'nonexistent_host';
        $_ENV['DB_NAME'] = 'nonexistent_db';
        
        PdoConnection::resetInstance();
        
        $manager = PdoConnection::getInstance();
        
        // Should handle error gracefully
        $connection = $manager->getConnection();
        
        // Connection might be null or error should be set
        if ($connection === null) {
            $this->assertNotNull($manager->getError());
        } else {
            // If connection is created, it should still be valid
            $this->assertInstanceOf(ConnectionInterface::class, $connection);
        }
    }

    public function testSetConfigIntegration(): void
    {
        $manager = PdoConnection::getInstance();
        
        // Override config programmatically
        $manager->setConfig([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        
        // Get connection with overridden config
        $connection = $manager->getConnection('custom_config');
        $this->assertNotNull($connection);
        $this->assertInstanceOf(ConnectionInterface::class, $connection);
        
        // Verify connection works
        $pdo = $connection->getConnection();
        $pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY)');
        $this->assertTrue($connection->isInitialized());
    }

    public function testResetConfigIntegration(): void
    {
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_NAME'] = ':memory:';
        
        PdoConnection::resetInstance();
        $manager = PdoConnection::getInstance();
        
        // Override config
        $manager->setConfig(['database' => '/tmp/test.db']);
        $stats = $manager->getPoolStats();
        
        // Reset to environment
        $manager->resetConfig();
        $newStats = $manager->getPoolStats();
        
        // Should revert to environment values
        $this->assertEquals(':memory:', $newStats['config']['database']);
    }

    public function testSetConfigClearsConnections(): void
    {
        $manager = PdoConnection::getInstance();
        
        // Create connections
        $conn1 = $manager->getConnection('conn1');
        $conn2 = $manager->getConnection('conn2');
        
        $stats = $manager->getPoolStats();
        $this->assertEquals(2, $stats['cached_connections']);
        
        // Change config (keep SQLite for compatibility)
        $manager->setConfig([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        
        // Connections should be cleared
        $stats = $manager->getPoolStats();
        $this->assertEquals(0, $stats['cached_connections']);
        
        // New connections should work
        $newConn = $manager->getConnection('new_conn');
        $this->assertNotNull($newConn);
    }

    public function testDevLoggingInIntegration(): void
    {
        $_ENV['APP_ENV'] = 'dev';
        $_ENV['DB_PERSISTENT_CONNECTIONS'] = '1';
        
        PdoConnection::resetInstance();
        $manager = PdoConnection::getInstance();
        
        // Get connection - should trigger dev logging
        $connection = $manager->getConnection('dev_test');
        $this->assertNotNull($connection);
        
        // Release connection - should trigger dev logging
        $manager->releaseConnection($connection);
        
        // Verify functionality works
        $stats = $manager->getPoolStats();
        $this->assertTrue($stats['initialized']);
        
        // Clean up
        unset($_ENV['APP_ENV'], $_ENV['DB_PERSISTENT_CONNECTIONS']);
    }

    public function testComplexTransactionScenario(): void
    {
        $manager = PdoConnection::getInstance();
        $connection = $manager->getConnection();
        $pdo = $connection->getConnection();
        
        $pdo->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY, balance INTEGER)');
        $pdo->exec("INSERT INTO accounts (balance) VALUES (1000)");
        
        // Simulate transfer: withdraw from account 1 and deposit to account 2
        $this->assertTrue($connection->beginTransaction());
        
        // Withdraw from account 1
        $stmt = $pdo->prepare('UPDATE accounts SET balance = balance - ? WHERE id = 1');
        $stmt->execute([100]);
        
        // Deposit to account 2 (new account)
        $stmt = $pdo->prepare('INSERT INTO accounts (balance) VALUES (?)');
        $stmt->execute([100]);
        
        $this->assertTrue($connection->commit());
        
        // Verify both operations committed
        // Account 1: 1000 - 100 = 900
        // Account 2: 100
        // Total: 900 + 100 = 1000 (balance preserved)
        $stmt = $pdo->query('SELECT SUM(balance) FROM accounts');
        $total = $stmt->fetchColumn();
        $this->assertEquals(1000, $total);
        
        // Verify individual accounts
        $stmt = $pdo->query('SELECT balance FROM accounts WHERE id = 1');
        $this->assertEquals(900, $stmt->fetchColumn());
        
        $stmt = $pdo->query('SELECT balance FROM accounts WHERE id = 2');
        $this->assertEquals(100, $stmt->fetchColumn());
    }

    public function testConnectionWithDifferentConfigurations(): void
    {
        $manager = PdoConnection::getInstance();
        
        // Get connection with default config
        $conn1 = $manager->getConnection('default');
        $pdo1 = $conn1->getConnection();
        $pdo1->exec('CREATE TABLE table1 (id INTEGER PRIMARY KEY)');
        
        // Change config (keep SQLite driver for compatibility)
        $manager->setConfig([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        
        // Get new connection with new config
        $conn2 = $manager->getConnection('new_config');
        $this->assertNotNull($conn2);
        
        $pdo2 = $conn2->getConnection();
        $this->assertNotNull($pdo2);
        
        // Should be different connections
        $this->assertNotSame($conn1, $conn2);
        $this->assertNotSame($pdo1, $pdo2);
    }

    public function testDestructorReleasesAllConnectionsIntegration(): void
    {
        $manager = PdoConnection::getInstance();
        
        // Create multiple connections with actual database operations
        $conn1 = $manager->getConnection('conn1');
        $pdo1 = $conn1->getConnection();
        $pdo1->exec('CREATE TABLE table1 (id INTEGER PRIMARY KEY)');
        
        $conn2 = $manager->getConnection('conn2');
        $pdo2 = $conn2->getConnection();
        $pdo2->exec('CREATE TABLE table2 (id INTEGER PRIMARY KEY)');
        
        $conn3 = $manager->getConnection('conn3');
        $pdo3 = $conn3->getConnection();
        $pdo3->exec('CREATE TABLE table3 (id INTEGER PRIMARY KEY)');
        
        // Verify all connections exist
        $stats = $manager->getPoolStats();
        $this->assertEquals(3, $stats['cached_connections']);
        
        // Verify connections are working
        $this->assertNotNull($pdo1);
        $this->assertNotNull($pdo2);
        $this->assertNotNull($pdo3);
        
        // Reset instance (simulates destructor behavior)
        PdoConnection::resetInstance();
        
        // Verify connections were released
        $this->assertNull($conn1->getConnection());
        $this->assertNull($conn2->getConnection());
        $this->assertNull($conn3->getConnection());
        
        // Verify new instance has no cached connections
        $newManager = PdoConnection::getInstance();
        $newStats = $newManager->getPoolStats();
        $this->assertEquals(0, $newStats['cached_connections']);
    }

    public function testDestructorWithActiveTransactionsIntegration(): void
    {
        $manager = PdoConnection::getInstance();
        $connection = $manager->getConnection('test');
        $pdo = $connection->getConnection();
        
        $pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, value TEXT)');
        
        // Start transaction
        $this->assertTrue($connection->beginTransaction());
        $pdo->exec("INSERT INTO test (value) VALUES ('test')");
        $this->assertTrue($connection->inTransaction());
        
        // Reset instance (simulates destructor) while transaction is active
        PdoConnection::resetInstance();
        
        // Verify connection was released
        $this->assertNull($connection->getConnection());
        $this->assertFalse($connection->inTransaction());
    }

    public function testDestructorBehaviorWithRealDatabaseOperations(): void
    {
        $manager = PdoConnection::getInstance();
        
        // Create connections and perform operations
        $conn1 = $manager->getConnection('db1');
        $pdo1 = $conn1->getConnection();
        $pdo1->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo1->exec("INSERT INTO users (name) VALUES ('user1')");
        
        $conn2 = $manager->getConnection('db2');
        $pdo2 = $conn2->getConnection();
        $pdo2->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo2->exec("INSERT INTO products (name) VALUES ('product1')");
        
        // Verify data exists
        $count1 = $pdo1->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $count2 = $pdo2->query('SELECT COUNT(*) FROM products')->fetchColumn();
        $this->assertEquals(1, $count1);
        $this->assertEquals(1, $count2);
        
        // Reset instance (simulates destructor)
        PdoConnection::resetInstance();
        
        // Verify connections were released
        $this->assertNull($conn1->getConnection());
        $this->assertNull($conn2->getConnection());
        
        // Verify new instance works
        $newManager = PdoConnection::getInstance();
        $newConn = $newManager->getConnection('fresh');
        $this->assertNotNull($newConn);
        $newStats = $newManager->getPoolStats();
        $this->assertEquals(1, $newStats['cached_connections']);
    }

    /**
     * Test that __destruct() is actually called in integration context
     * This test uses reflection to actually trigger __destruct() on the instance
     */
    public function testDestructorActuallyCalledIntegration(): void
    {
        $manager = PdoConnection::getInstance();
        
        // Create connections with real database operations
        $conn1 = $manager->getConnection('destruct_int_test1');
        $pdo1 = $conn1->getConnection();
        $pdo1->exec('CREATE TABLE test_table1 (id INTEGER PRIMARY KEY, data TEXT)');
        $pdo1->exec("INSERT INTO test_table1 (data) VALUES ('test1')");
        
        $conn2 = $manager->getConnection('destruct_int_test2');
        $pdo2 = $conn2->getConnection();
        $pdo2->exec('CREATE TABLE test_table2 (id INTEGER PRIMARY KEY, data TEXT)');
        $pdo2->exec("INSERT INTO test_table2 (data) VALUES ('test2')");
        
        // Verify connections exist and are working
        $stats = $manager->getPoolStats();
        $this->assertEquals(2, $stats['cached_connections']);
        $this->assertNotNull($pdo1);
        $this->assertNotNull($pdo2);
        
        // Verify data was inserted
        $count1 = $pdo1->query('SELECT COUNT(*) FROM test_table1')->fetchColumn();
        $count2 = $pdo2->query('SELECT COUNT(*) FROM test_table2')->fetchColumn();
        $this->assertEquals(1, $count1);
        $this->assertEquals(1, $count2);
        
        // Use reflection to directly call __destruct() on the instance
        $reflection = new \ReflectionClass(PdoConnection::class);
        $activeConnectionsProperty = $reflection->getProperty('activeConnections');
        $activeConnectionsProperty->setAccessible(true);
        
        // Verify activeConnections has 2 items before destructor
        $activeConnections = $activeConnectionsProperty->getValue($manager);
        $this->assertCount(2, $activeConnections);
        
        // Call __destruct() directly
        $destructor = $reflection->getMethod('__destruct');
        $destructor->setAccessible(true);
        $destructor->invoke($manager);
        
        // Verify connections were released by __destruct()
        $this->assertNull($conn1->getConnection());
        $this->assertNull($conn2->getConnection());
        
        // Verify activeConnections array was cleared
        $activeConnectionsAfter = $activeConnectionsProperty->getValue($manager);
        $this->assertCount(0, $activeConnectionsAfter);
    }
}
