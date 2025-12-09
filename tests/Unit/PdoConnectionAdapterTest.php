<?php

declare(strict_types=1);

namespace Gemvc\Database\Connection\Pdo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Gemvc\Database\Connection\Pdo\PdoConnectionAdapter;
use PDO;
use ReflectionClass;

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

    /**
     * Test beginTransaction() handles PDOException
     * 
     * This test covers the catch block in beginTransaction() (lines 106-109).
     * We use a mock PDO that throws PDOException to trigger the exception path.
     */
    public function testBeginTransactionHandlesPdoException(): void
    {
        // Create a mock PDO that throws PDOException on beginTransaction
        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->once())
            ->method('beginTransaction')
            ->willThrowException(new \PDOException('Database connection lost'));
        
        $adapter = new PdoConnectionAdapter($mockPdo);
        
        $result = $adapter->beginTransaction();
        
        $this->assertFalse($result);
        $this->assertNotNull($adapter->getError());
        $this->assertStringContainsString('Failed to begin transaction', $adapter->getError());
        $this->assertStringContainsString('Database connection lost', $adapter->getError());
        $this->assertFalse($adapter->inTransaction());
    }

    /**
     * Test commit() handles PDOException
     * 
     * This test covers the catch block in commit() (lines 133-136).
     * We use a mock PDO that throws PDOException to trigger the exception path.
     */
    public function testCommitHandlesPdoException(): void
    {
        // Create a mock PDO that throws PDOException on commit
        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->once())
            ->method('commit')
            ->willThrowException(new \PDOException('Transaction already committed'));
        
        $adapter = new PdoConnectionAdapter($mockPdo);
        
        // Manually set inTransaction to true to pass the check
        $reflection = new ReflectionClass($adapter);
        $inTransactionProperty = $reflection->getProperty('inTransaction');
        $inTransactionProperty->setAccessible(true);
        $inTransactionProperty->setValue($adapter, true);
        
        $result = $adapter->commit();
        
        $this->assertFalse($result);
        $this->assertNotNull($adapter->getError());
        $this->assertStringContainsString('Failed to commit transaction', $adapter->getError());
        $this->assertStringContainsString('Transaction already committed', $adapter->getError());
        // inTransaction should remain true because commit failed
        $this->assertTrue($inTransactionProperty->getValue($adapter));
    }

    /**
     * Test rollback() handles PDOException
     * 
     * This test covers the catch block in rollback() (lines 160-163).
     * We use a mock PDO that throws PDOException to trigger the exception path.
     */
    public function testRollbackHandlesPdoException(): void
    {
        // Create a mock PDO that throws PDOException on rollBack
        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->once())
            ->method('rollBack')
            ->willThrowException(new \PDOException('No active transaction'));
        
        $adapter = new PdoConnectionAdapter($mockPdo);
        
        // Manually set inTransaction to true to pass the check
        $reflection = new ReflectionClass($adapter);
        $inTransactionProperty = $reflection->getProperty('inTransaction');
        $inTransactionProperty->setAccessible(true);
        $inTransactionProperty->setValue($adapter, true);
        
        $result = $adapter->rollback();
        
        $this->assertFalse($result);
        $this->assertNotNull($adapter->getError());
        $this->assertStringContainsString('Failed to rollback transaction', $adapter->getError());
        $this->assertStringContainsString('No active transaction', $adapter->getError());
        // inTransaction should remain true because rollback failed
        $this->assertTrue($inTransactionProperty->getValue($adapter));
    }
}
