<?php

declare(strict_types=1);

namespace Gemvc\Database\Connection\Pdo\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Gemvc\Database\Connection\Pdo\PdoConnectionAdapter;
use Gemvc\Database\Connection\Contracts\ConnectionInterface;
use PDO;

/**
 * Integration tests for PdoConnectionAdapter
 */
class PdoConnectionAdapterIntegrationTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        // Create in-memory SQLite connection for testing
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create test table
        $this->pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
    }

    protected function tearDown(): void
    {
        $this->pdo = null;
    }

    public function testImplementsConnectionInterface(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        
        $this->assertInstanceOf(ConnectionInterface::class, $adapter);
    }

    public function testTransactionFlow(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        
        // Begin transaction
        $this->assertTrue($adapter->beginTransaction());
        $this->assertTrue($adapter->inTransaction());
        
        // Insert data
        $stmt = $this->pdo->prepare('INSERT INTO test (name) VALUES (?)');
        $stmt->execute(['test']);
        
        // Commit
        $this->assertTrue($adapter->commit());
        $this->assertFalse($adapter->inTransaction());
        
        // Verify data persisted
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM test');
        $this->assertEquals(1, $stmt->fetchColumn());
    }

    public function testRollback(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        
        // Begin transaction
        $adapter->beginTransaction();
        
        // Insert data
        $stmt = $this->pdo->prepare('INSERT INTO test (name) VALUES (?)');
        $stmt->execute(['test']);
        
        // Rollback
        $this->assertTrue($adapter->rollback());
        $this->assertFalse($adapter->inTransaction());
        
        // Verify data was rolled back
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM test');
        $this->assertEquals(0, $stmt->fetchColumn());
    }

    public function testGetConnectionReturnsPdo(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        $connection = $adapter->getConnection();
        
        $this->assertInstanceOf(PDO::class, $connection);
        $this->assertSame($this->pdo, $connection);
    }

    public function testReleaseConnection(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        
        // Verify connection exists
        $this->assertNotNull($adapter->getConnection());
        $this->assertTrue($adapter->isInitialized());
        
        // Release connection
        $adapter->releaseConnection($this->pdo);
        
        // Connection should be released
        $this->assertNull($adapter->getConnection());
        $this->assertFalse($adapter->isInitialized());
    }

    public function testReleaseConnectionWithWrongObject(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        $wrongPdo = new PDO('sqlite::memory:');
        
        // Release with wrong object should not affect adapter
        $adapter->releaseConnection($wrongPdo);
        
        // Connection should still be available
        $this->assertNotNull($adapter->getConnection());
        $this->assertSame($this->pdo, $adapter->getConnection());
    }

    public function testErrorHandling(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        
        // Initially no error
        $this->assertNull($adapter->getError());
        
        // Set error
        $adapter->setError('Test error', ['key' => 'value']);
        $error = $adapter->getError();
        
        $this->assertNotNull($error);
        $this->assertStringContainsString('Test error', $error);
        $this->assertStringContainsString('Context', $error);
        
        // Clear error
        $adapter->clearError();
        $this->assertNull($adapter->getError());
    }

    public function testIsInitialized(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        $this->assertTrue($adapter->isInitialized());
        
        $adapterNull = new PdoConnectionAdapter(null);
        $this->assertFalse($adapterNull->isInitialized());
    }

    public function testMultipleTransactionOperations(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        
        // First transaction
        $this->assertTrue($adapter->beginTransaction());
        $stmt = $this->pdo->prepare('INSERT INTO test (name) VALUES (?)');
        $stmt->execute(['first']);
        $this->assertTrue($adapter->commit());
        
        // Second transaction
        $this->assertTrue($adapter->beginTransaction());
        $stmt->execute(['second']);
        $this->assertTrue($adapter->commit());
        
        // Verify both records
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM test');
        $this->assertEquals(2, $stmt->fetchColumn());
    }

    public function testTransactionWithErrorHandling(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        
        // Begin transaction
        $this->assertTrue($adapter->beginTransaction());
        
        // Try to begin another - should fail
        $this->assertFalse($adapter->beginTransaction());
        $this->assertNotNull($adapter->getError());
        $this->assertStringContainsString('Already in transaction', $adapter->getError());
        
        // Commit the first transaction
        $this->assertTrue($adapter->commit());
        
        // Now try commit without transaction - should fail
        $this->assertFalse($adapter->commit());
        $this->assertStringContainsString('No active transaction', $adapter->getError());
    }
}
