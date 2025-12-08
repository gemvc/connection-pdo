<?php

declare(strict_types=1);

namespace Gemvc\Database\Connection\Pdo;

use PDO;
use PDOException;
use Gemvc\Database\Connection\Contracts\ConnectionManagerInterface;
use Gemvc\Database\Connection\Contracts\ConnectionInterface;
use Gemvc\Database\Connection\Pdo\PdoConnectionAdapter;

/**
 * PDO Connection Manager for Apache/Nginx PHP-FPM
 * 
 * This is the **real implementation** that creates actual PDO connections.
 * It implements ConnectionManagerInterface from connection-contracts package.
 * 
 * **IMPORTANT: This is NOT connection pooling!**
 * - This class implements **simple connection caching** (one connection per name)
 * - No pool size limits
 * - No idle connection management
 * - No connection rotation
 * - Appropriate for PHP-FPM (one connection per process/request)
 * 
 * **For true connection pooling, use connection-swoole package (for Swoole environments).**
 * 
 * **Architecture:**
 * - Creates actual PDO connections: `new PDO($dsn, $user, $pass)` - **REAL IMPLEMENTATION**
 * - Implements ConnectionManagerInterface (from connection-contracts)
 * - Returns ConnectionInterface (wraps PDO with PdoConnectionAdapter)
 * - Part of gemvc/connection-pdo package
 * - **Only depends on connection-contracts** (no framework dependencies)
 * 
 * **Features:**
 * - Persistent PDO connections by default (configurable)
 * - Simple connection caching (one connection per name, within request)
 * - Basic error handling
 * - Environment-based configuration (reads $_ENV directly)
 * - Returns ConnectionInterface (not raw PDO)
 * - **Performance Optimizations:**
 *   - Persistent connections enabled by default (DB_PERSISTENT_CONNECTIONS=1)
 *   - Optimized PDO options for MySQL
 *   - Configurable connection timeout (DB_CONNECTION_TIMEOUT)
 *   - Cached DSN string
 * 
 * **Environment Variables:**
 * - `DB_PERSISTENT_CONNECTIONS=1` - Enable persistent connections (default: enabled/1)
 * - `DB_CONNECTION_TIMEOUT=5` - Connection timeout in seconds (default: 5)
 * 
 * **Dependencies:**
 * - Only depends on: gemvc/connection-contracts
 * - No framework dependencies (ProjectHelper, DatabaseManagerInterface, etc.)
 * - Reads environment variables directly from $_ENV
 */
class PdoConnection implements ConnectionManagerInterface
{
    /** @var self|null Singleton instance */
    private static ?self $instance = null;

    /** @var array<string, ConnectionInterface> Active connections by connection name (simple caching, not pooling) */
    private array $activeConnections = [];

    /** @var string|null Last error message */
    private ?string $error = null;

    /** @var bool Whether the manager is initialized */
    private bool $initialized = false;

    /** @var array<string, mixed> Connection configuration */
    private array $config = [];

    /** @var array<string, mixed>|null Overridden configuration (null = use $_ENV) */
    private ?array $overriddenConfig = null;

    /** @var bool Whether to use persistent connections */
    private bool $usePersistentConnections = false;

    /** @var string|null Cached DSN string */
    private ?string $cachedDsn = null;

    /**
     * Constructor
     * 
     * ⚠️ **WARNING: DO NOT USE DIRECTLY!**
     * 
     * **Always use `PdoConnection::getInstance()` instead of `new PdoConnection()`**
     * 
     * This constructor is public for PHPUnit coverage reporting purposes, but you should
     * **NEVER** instantiate this class directly. Always use the singleton pattern:
     * 
     * ```php
     * // ✅ CORRECT - Always use this:
     * $manager = PdoConnection::getInstance();
     * 
     * // ❌ WRONG - Never do this:
     * $manager = new PdoConnection(); // Creates separate instance, breaks singleton!
     * ```
     * 
     * **Why?**
     * - Direct instantiation creates a separate instance, breaking the singleton pattern
     * - Connection caching won't be shared across your application
     * - Configuration might be inconsistent
     * - Multiple instances can cause connection leaks
     * 
     * @internal This is public only for PHPUnit coverage. Use getInstance() instead.
     */
    public function __construct()
    {
        $this->initialize();
    }

    /**
     * Get the singleton instance
     * 
     * **⚠️ IMPORTANT: Always use this method to get the connection manager!**
     * 
     * **DO NOT** use `new PdoConnection()` - always use `getInstance()` instead.
     * 
     * ```php
     * // ✅ CORRECT:
     * $manager = PdoConnection::getInstance();
     * 
     * // ❌ WRONG:
     * $manager = new PdoConnection(); // Breaks singleton pattern!
     * ```
     * 
     * @return self The singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset the singleton instance (useful for testing)
     * 
     * @return void
     */
    public static function resetInstance(): void
    {
        if (self::$instance !== null) {
            // Release all active connections
            foreach (self::$instance->activeConnections as $connection) {
                $driver = $connection->getConnection();
                $connection->releaseConnection($driver);
            }
            self::$instance->activeConnections = [];
            self::$instance->overriddenConfig = null; // Reset config override
            self::$instance = null;
        }
    }

    /**
     * Initialize the database manager
     * 
     * Reads environment variables directly (no framework dependency).
     * Framework should ensure $_ENV is populated before using this.
     * 
     * @return void
     */
    private function initialize(): void
    {
        try {
            // Build database configuration from $_ENV (framework should populate this)
            // No framework dependency - reads directly from environment
            $this->config = $this->buildDatabaseConfig();
            
            // Check if persistent connections are enabled (default: true)
            $persistentEnv = $_ENV['DB_PERSISTENT_CONNECTIONS'] ?? '1';
            $this->usePersistentConnections = (
                $persistentEnv === '1' || 
                $persistentEnv === 'true' || 
                $persistentEnv === 'yes'
            );
            
            $this->initialized = true;
            
            if (($_ENV['APP_ENV'] ?? '') === 'dev') {
                $connectionType = $this->usePersistentConnections ? 'persistent' : 'simple';
                error_log("PdoConnection: Initialized for Apache/Nginx environment ({$connectionType} connections)");
            }
        } catch (\Exception $e) {
            $this->setError('Failed to initialize PdoConnection: ' . $e->getMessage());
            $this->initialized = false;
        }
    }

    /**
     * Get a connection (simple caching, NOT connection pooling)
     * 
     * **Implements ConnectionManagerInterface from connection-contracts**
     * Returns ConnectionInterface (wrapped PDO), not raw PDO.
     * 
     * **Note:** This is NOT connection pooling. It's simple connection caching:
     * - One connection per connection name (cached within request)
     * - No pool size limits
     * - No idle connection management
     * - Appropriate for PHP-FPM (one connection per process)
     * 
     * @param string $poolName Connection name (default: 'default') - required by interface
     * @return ConnectionInterface|null The connection instance or null on failure
     */
    public function getConnection(string $poolName = 'default'): ?ConnectionInterface
    {
        $this->clearError();

        // Return cached connection if available (simple caching, not pooling)
        if (isset($this->activeConnections[$poolName])) {
            return $this->activeConnections[$poolName];
        }

        try {
            // Create actual PDO connection (REAL IMPLEMENTATION)
            $pdo = $this->createConnection();
            
            // Wrap PDO with PdoConnectionAdapter (implements ConnectionInterface)
            $connection = new PdoConnectionAdapter($pdo);
            $this->activeConnections[$poolName] = $connection; // Cache connection
            
            if (($_ENV['APP_ENV'] ?? '') === 'dev') {
                error_log("PdoConnection: New PDO connection created and cached");
            }
            
            return $connection;
        } catch (PDOException $e) {
            $this->setError('Failed to create database connection: ' . $e->getMessage(), [
                'error_code' => $e->getCode(),
                'connection_name' => $poolName
            ]);
            return null;
        }
    }

    /**
     * Release a connection (removes from cache)
     * 
     * **Implements ConnectionManagerInterface from connection-contracts**
     * 
     * **Note:** This is NOT connection pooling. Simply removes connection from cache.
     * 
     * @param ConnectionInterface $connection The connection to release
     * @return void
     */
    public function releaseConnection(ConnectionInterface $connection): void
    {
        // Remove from cached connections
        foreach ($this->activeConnections as $connectionName => $activeConnection) {
            if ($activeConnection === $connection) {
                unset($this->activeConnections[$connectionName]);
                
                // Release on the connection itself
                $driver = $connection->getConnection();
                $connection->releaseConnection($driver);
                
                if (($_ENV['APP_ENV'] ?? '') === 'dev') {
                    error_log("PdoConnection: Connection released: {$connectionName}");
                }
                break;
            }
        }
    }

    /**
     * Create a new PDO connection
     * 
     * **This is the real implementation that creates actual PDO connections.**
     * 
     * **Performance Optimizations:**
     * - Persistent connections (configurable via DB_PERSISTENT_CONNECTIONS)
     * - Optimized PDO options for MySQL
     * - Configurable connection timeout
     * - Cached DSN string
     * 
     * @return PDO The new PDO connection
     * @throws PDOException If connection fails
     */
    private function createConnection(): PDO
    {
        $dsn = $this->getDsn();
        $driver = $this->config['driver'] ?? 'mysql';

        // Base PDO options (always applied)
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        // SQLite-specific options
        if ($driver === 'sqlite') {
            // SQLite doesn't support persistent connections in the same way
            // ATTR_EMULATE_PREPARES is not applicable to SQLite
            // Timeout is handled differently for SQLite
        } else {
            // MySQL and other drivers
            $options[PDO::ATTR_EMULATE_PREPARES] = false; // Use native prepared statements (better performance)
            $options[PDO::ATTR_TIMEOUT] = $this->getConnectionTimeout();
            $options[PDO::ATTR_PERSISTENT] = $this->usePersistentConnections; // Configurable persistent connections
        }

        // MySQL-specific optimizations
        if ($driver === 'mysql') {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = sprintf(
                "SET NAMES %s COLLATE %s, SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'",
                is_string($this->config['charset']) ? $this->config['charset'] : 'utf8mb4',
                is_string($this->config['collation']) ? $this->config['collation'] : 'utf8mb4_unicode_ci'
            );
            $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true; // Better for large result sets
            
            // Optional: Enable compression for high-latency connections
            // Uncomment if network latency is an issue:
            // $options[PDO::MYSQL_ATTR_COMPRESS] = true;
        }

        // SQLite doesn't require username/password
        if ($driver === 'sqlite') {
            return new PDO($dsn, null, null, $options);
        }

        return new PDO(
            $dsn,
            is_string($this->config['username']) ? $this->config['username'] : 'root',
            is_string($this->config['password']) ? $this->config['password'] : '',
            $options
        );
    }

    /**
     * Get the DSN string (cached for performance)
     * 
     * @return string The DSN connection string
     */
    private function getDsn(): string
    {
        if ($this->cachedDsn === null) {
            $driver = is_string($this->config['driver']) ? $this->config['driver'] : 'mysql';
            
            // SQLite uses a different DSN format
            if ($driver === 'sqlite') {
                $database = is_string($this->config['database']) ? $this->config['database'] : ':memory:';
                // SQLite DSN: sqlite::memory: or sqlite:/path/to/file.db
                $this->cachedDsn = $database === ':memory:' ? 'sqlite::memory:' : 'sqlite:' . $database;
            } else {
                // MySQL and other drivers use standard format
                $this->cachedDsn = sprintf(
                    '%s:host=%s;port=%s;dbname=%s;charset=%s',
                    $driver,
                    is_string($this->config['host']) ? $this->config['host'] : 'localhost',
                    is_numeric($this->config['port']) ? (string)$this->config['port'] : '3306',
                    is_string($this->config['database']) ? $this->config['database'] : 'gemvc_db',
                    is_string($this->config['charset']) ? $this->config['charset'] : 'utf8mb4'
                );
            }
        }
        return $this->cachedDsn;
    }

    /**
     * Get connection timeout from environment or default
     * 
     * @return int Connection timeout in seconds
     */
    private function getConnectionTimeout(): int
    {
        $timeout = $_ENV['DB_CONNECTION_TIMEOUT'] ?? '5';
        return is_numeric($timeout) ? (int)$timeout : 5;
    }

    /**
     * Build database configuration from environment variables or overridden config
     * 
     * **Note:** This method is protected (not private) to allow test subclasses
     * to override it for testing exception scenarios.
     * 
     * @return array<string, mixed> Database configuration
     */
    protected function buildDatabaseConfig(): array
    {
        // Use overridden config if set, otherwise use $_ENV
        if ($this->overriddenConfig !== null) {
            return $this->overriddenConfig;
        }

        return [
            'driver' => $_ENV['DB_DRIVER'] ?? 'mysql',
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'port' => is_numeric($_ENV['DB_PORT'] ?? null) ? (int)($_ENV['DB_PORT']) : 3306,
            'database' => $_ENV['DB_NAME'] ?? 'gemvc_db',
            'username' => $_ENV['DB_USER'] ?? 'root',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
            'collation' => $_ENV['DB_COLLATION'] ?? 'utf8mb4_unicode_ci',
        ];
    }

    /**
     * Set database configuration programmatically (overrides $_ENV)
     * 
     * Useful for CLI commands in dockerized applications where you need to
     * override the database host or other connection parameters.
     * 
     * **Note:** This will clear any cached connections and DSN.
     * 
     * @param array<string, mixed> $config Configuration array with keys:
     *   - 'driver' (optional, default: 'mysql')
     *   - 'host' (optional, default: 'localhost')
     *   - 'port' (optional, default: 3306)
     *   - 'database' (optional, default: 'gemvc_db')
     *   - 'username' (optional, default: 'root')
     *   - 'password' (optional, default: '')
     *   - 'charset' (optional, default: 'utf8mb4')
     *   - 'collation' (optional, default: 'utf8mb4_unicode_ci')
     * @return void
     */
    public function setConfig(array $config): void
    {
        // Merge with defaults to ensure all keys are present
        $this->overriddenConfig = [
            'driver' => $config['driver'] ?? 'mysql',
            'host' => $config['host'] ?? 'localhost',
            'port' => is_numeric($config['port'] ?? null) ? (int)($config['port']) : 3306,
            'database' => $config['database'] ?? 'gemvc_db',
            'username' => $config['username'] ?? 'root',
            'password' => $config['password'] ?? '',
            'charset' => $config['charset'] ?? 'utf8mb4',
            'collation' => $config['collation'] ?? 'utf8mb4_unicode_ci',
        ];

        // Rebuild config
        $this->config = $this->buildDatabaseConfig();

        // Clear cached DSN so it's regenerated with new config
        $this->cachedDsn = null;

        // Release all existing connections since config changed
        foreach ($this->activeConnections as $connection) {
            $driver = $connection->getConnection();
            $connection->releaseConnection($driver);
        }
        $this->activeConnections = [];
    }

    /**
     * Reset configuration back to $_ENV values
     * 
     * Clears any programmatically set configuration and reverts to reading from $_ENV.
     * 
     * @return void
     */
    public function resetConfig(): void
    {
        $this->overriddenConfig = null;
        $this->config = $this->buildDatabaseConfig();
        $this->cachedDsn = null;

        // Release all existing connections since config changed
        foreach ($this->activeConnections as $connection) {
            $driver = $connection->getConnection();
            $connection->releaseConnection($driver);
        }
        $this->activeConnections = [];
    }


    /**
     * Get the last error message
     * 
     * @return string|null Error message or null if no error occurred
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Set an error message
     * 
     * @param string|null $error The error message to set
     * @param array<string, mixed> $context Additional context information
     * @return void
     */
    public function setError(?string $error, array $context = []): void
    {
        if ($error === null) {
            $this->error = null;
            return;
        }

        // Add context information to error message
        if (!empty($context)) {
            $contextStr = ' [Context: ' . json_encode($context) . ']';
            $this->error = $error . $contextStr;
        } else {
            $this->error = $error;
        }
    }

    /**
     * Clear the last error message
     * 
     * @return void
     */
    public function clearError(): void
    {
        $this->error = null;
    }

    /**
     * Check if the database manager is properly initialized
     * 
     * @return bool True if initialized, false otherwise
     */
    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /**
     * Get connection statistics (NOT pool statistics - this is simple connection caching)
     * 
     * **Implements ConnectionManagerInterface from connection-contracts**
     * 
     * **Note:** Method name required by interface, but this is NOT connection pooling.
     * This class implements simple connection caching (one connection per name).
     * 
     * @return array<string, mixed> Connection statistics
     */
    public function getPoolStats(): array
    {
        return [
            'type' => 'PDO Connection Manager (Simple Caching, NOT Pooling)',
            'environment' => 'Apache/Nginx PHP-FPM',
            'cached_connections' => count($this->activeConnections),
            'initialized' => $this->initialized,
            'persistent_enabled' => $this->usePersistentConnections,
            'config' => [
                'driver' => $this->config['driver'] ?? 'unknown',
                'host' => $this->config['host'] ?? 'unknown',
                'database' => $this->config['database'] ?? 'unknown',
                'timeout' => $this->getConnectionTimeout(),
            ]
        ];
    }


    /**
     * Clean up resources on destruction
     */
    public function __destruct()
    {
        // Release all active connections
        foreach ($this->activeConnections as $connection) {
            $driver = $connection->getConnection();
            $connection->releaseConnection($driver);
        }
        $this->activeConnections = [];
    }
}
