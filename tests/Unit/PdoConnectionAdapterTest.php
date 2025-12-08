<?php

declare(strict_types=1);

namespace Gemvc\Database\Connection\Pdo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Gemvc\Database\Connection\Pdo\PdoConnectionAdapter;
use PDO;

/**
 * Unit tests for PdoConnectionAdapter
 * 
 * @covers \Gemvc\Database\Connection\Pdo\PdoConnectionAdapter
 */
class PdoConnectionAdapterTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        // Create in-memory SQLite connection for testing
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    protected function tearDown(): void
    {
        $this->pdo = null;
    }

    public function testConstructorWithPdo(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        
        $this->assertTrue($adapter->isInitialized());
        $this->assertInstanceOf(PDO::class, $adapter->getConnection());
    }

    public function testConstructorWithoutPdo(): void
    {
        $adapter = new PdoConnectionAdapter(null);
        
        $this->assertFalse($adapter->isInitialized());
        $this->assertNull($adapter->getConnection());
    }

    public function testGetConnection(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        $connection = $adapter->getConnection();
        
        $this->assertInstanceOf(PDO::class, $connection);
        $this->assertSame($this->pdo, $connection);
    }

    public function testBeginTransaction(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        
        $result = $adapter->beginTransaction();
        
        $this->assertTrue($result);
        $this->assertTrue($adapter->inTransaction());
    }

    public function testCommit(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        $adapter->beginTransaction();
        
        $result = $adapter->commit();
        
        $this->assertTrue($result);
        $this->assertFalse($adapter->inTransaction());
    }

    public function testRollback(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        $adapter->beginTransaction();
        
        $result = $adapter->rollback();
        
        $this->assertTrue($result);
        $this->assertFalse($adapter->inTransaction());
    }

    public function testErrorHandling(): void
    {
        $adapter = new PdoConnectionAdapter(null);
        
        $this->assertNull($adapter->getError());
        
        $adapter->setError('Test error', ['key' => 'value']);
        
        $this->assertNotNull($adapter->getError());
        
        $adapter->clearError();
        
        $this->assertNull($adapter->getError());
    }

    public function testBeginTransactionWithNullPdo(): void
    {
        $adapter = new PdoConnectionAdapter(null);
        
        $result = $adapter->beginTransaction();
        
        $this->assertFalse($result);
        $this->assertNotNull($adapter->getError());
        $this->assertStringContainsString('No connection available', $adapter->getError());
    }

    public function testBeginTransactionWhenAlreadyInTransaction(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        $adapter->beginTransaction();
        
        $result = $adapter->beginTransaction();
        
        $this->assertFalse($result);
        $this->assertNotNull($adapter->getError());
        $this->assertStringContainsString('Already in transaction', $adapter->getError());
    }

    public function testCommitWithNullPdo(): void
    {
        $adapter = new PdoConnectionAdapter(null);
        
        $result = $adapter->commit();
        
        $this->assertFalse($result);
        $this->assertNotNull($adapter->getError());
        $this->assertStringContainsString('No connection available', $adapter->getError());
    }

    public function testCommitWithoutTransaction(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        
        $result = $adapter->commit();
        
        $this->assertFalse($result);
        $this->assertNotNull($adapter->getError());
        $this->assertStringContainsString('No active transaction to commit', $adapter->getError());
    }

    public function testRollbackWithNullPdo(): void
    {
        $adapter = new PdoConnectionAdapter(null);
        
        $result = $adapter->rollback();
        
        $this->assertFalse($result);
        $this->assertNotNull($adapter->getError());
        $this->assertStringContainsString('No connection available', $adapter->getError());
    }

    public function testRollbackWithoutTransaction(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        
        $result = $adapter->rollback();
        
        $this->assertFalse($result);
        $this->assertNotNull($adapter->getError());
        $this->assertStringContainsString('No active transaction to rollback', $adapter->getError());
    }

    public function testInTransactionWithNullPdo(): void
    {
        $adapter = new PdoConnectionAdapter(null);
        
        $this->assertFalse($adapter->inTransaction());
    }

    public function testInTransactionUsesPdoNativeCheck(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        
        // Start transaction directly on PDO
        $this->pdo->beginTransaction();
        
        // Adapter should detect it via PDO's native check
        $this->assertTrue($adapter->inTransaction());
        
        $this->pdo->rollBack();
        $this->assertFalse($adapter->inTransaction());
    }

    public function testReleaseConnectionWithWrongObject(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        $wrongPdo = new PDO('sqlite::memory:');
        
        // Release with wrong object should not affect adapter
        $adapter->releaseConnection($wrongPdo);
        
        $this->assertNotNull($adapter->getConnection());
        $this->assertSame($this->pdo, $adapter->getConnection());
    }

    public function testReleaseConnectionWithCorrectObject(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        
        $adapter->releaseConnection($this->pdo);
        
        $this->assertNull($adapter->getConnection());
        $this->assertFalse($adapter->inTransaction());
    }

    public function testIsInitializedWhenPdoIsNull(): void
    {
        $adapter = new PdoConnectionAdapter(null);
        
        $this->assertFalse($adapter->isInitialized());
    }

    public function testSetErrorWithNull(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        $adapter->setError('Some error');
        
        $adapter->setError(null);
        
        $this->assertNull($adapter->getError());
    }

    public function testSetErrorWithoutContext(): void
    {
        $adapter = new PdoConnectionAdapter($this->pdo);
        $adapter->setError('Error without context');
        
        $this->assertEquals('Error without context', $adapter->getError());
    }
}
