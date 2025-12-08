<?php

declare(strict_types=1);

namespace Gemvc\Database\Connection\Pdo;

use Gemvc\Database\Connection\Contracts\ConnectionInterface;
use PDO;

/**
 * PDO Connection Adapter
 * 
 * Adapts a PDO connection to implement ConnectionInterface from gemvc/connection-contracts.
 * This allows PDO connections to work with the new contracts system.
 * 
 * **Purpose:**
 * - Wraps PDO connections for use with connection contracts
 * - Implements ConnectionInterface (from connection-contracts package)
 * - Provides transaction management (on Connection, not Manager)
 * - Handles connection state and error management
 * 
 * **Architecture:**
 * - Part of gemvc/connection-pdo package
 * - Depends on gemvc/connection-contracts (ConnectionInterface)
 * - Used by `PdoConnection` to wrap created PDO instances
 * - Can also be used directly to wrap existing PDO instances
 * 
 * **Usage:**
 * ```php
 * use Gemvc\Database\Connection\Pdo\PdoConnectionAdapter;
 * use PDO;
 * 
 * $pdo = new PDO($dsn, $user, $pass);
 * $adapter = new PdoConnectionAdapter($pdo);
 * 
 * // Now implements ConnectionInterface
 * $adapter->beginTransaction();
 * $adapter->commit();
 * ```
 */
class PdoConnectionAdapter implements ConnectionInterface
{
    private ?PDO $pdo = null;
    private ?string $error = null;
    private bool $initialized = false;
    private bool $inTransaction = false;

    /**
     * Constructor
     * 
     * @param PDO|null $pdo The PDO connection instance
     */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
        $this->initialized = $pdo !== null;
    }

    /**
     * Get the underlying database connection object
     * 
     * @return object|null The PDO connection object or null on failure
     */
    public function getConnection(): ?object
    {
        return $this->pdo;
    }

    /**
     * Release the connection back to the pool
     * 
     * @param object|null $connection The connection object to release
     * @return void
     */
    public function releaseConnection(?object $connection): void
    {
        // For PDO, we just clear the reference
        // Actual release is handled by the manager
        if ($connection === $this->pdo) {
            $this->pdo = null;
            $this->inTransaction = false;
        }
    }

    /**
     * Begin a database transaction
     * 
     * @return bool True on success, false on failure
     */
    public function beginTransaction(): bool
    {
        if ($this->pdo === null) {
            $this->setError('No connection available');
            return false;
        }

        if ($this->inTransaction) {
            $this->setError('Already in transaction');
            return false;
        }

        try {
            $result = $this->pdo->beginTransaction();
            $this->inTransaction = $result;
            return $result;
        } catch (\PDOException $e) {
            $this->setError('Failed to begin transaction: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Commit the current transaction
     * 
     * @return bool True on success, false on failure
     */
    public function commit(): bool
    {
        if ($this->pdo === null) {
            $this->setError('No connection available');
            return false;
        }

        if (!$this->inTransaction) {
            $this->setError('No active transaction to commit');
            return false;
        }

        try {
            $result = $this->pdo->commit();
            $this->inTransaction = false;
            return $result;
        } catch (\PDOException $e) {
            $this->setError('Failed to commit transaction: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Rollback the current transaction
     * 
     * @return bool True on success, false on failure
     */
    public function rollback(): bool
    {
        if ($this->pdo === null) {
            $this->setError('No connection available');
            return false;
        }

        if (!$this->inTransaction) {
            $this->setError('No active transaction to rollback');
            return false;
        }

        try {
            $result = $this->pdo->rollBack();
            $this->inTransaction = false;
            return $result;
        } catch (\PDOException $e) {
            $this->setError('Failed to rollback transaction: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if currently in a transaction
     * 
     * @return bool True if in transaction, false otherwise
     */
    public function inTransaction(): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        // Use both our tracking and PDO's native check
        return $this->inTransaction || $this->pdo->inTransaction();
    }

    /**
     * Get the last error message
     * 
     * @return string|null Error message or null if no error
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Set an error message
     * 
     * @param string|null $error Error message
     * @param array<string, mixed> $context Additional error context
     * @return void
     */
    public function setError(?string $error, array $context = []): void
    {
        $this->error = $error;
        if ($error !== null && !empty($context)) {
            $this->error .= ' | Context: ' . json_encode($context);
        }
    }

    /**
     * Clear the current error state
     * 
     * @return void
     */
    public function clearError(): void
    {
        $this->error = null;
    }

    /**
     * Check if the connection is initialized
     * 
     * @return bool True if initialized, false otherwise
     */
    public function isInitialized(): bool
    {
        return $this->initialized && $this->pdo !== null;
    }
}
