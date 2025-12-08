# gemvc/connection-pdo

PDO connection library implementation package for GEMVC framework.

## Package Information

- **Package Name:** `gemvc/connection-pdo`
- **Namespace:** `Gemvc\Database\Connection\Pdo\`
- **Type:** PDO connection implementation package
- **Environment:** Apache/Nginx PHP-FPM (simple connections)
- **Framework-Specific:** ⚠️ No - This package is framework-agnostic (only depends on `connection-contracts`)
- **Depends On:** `gemvc/connection-contracts: ^1.0`

## Purpose

This package provides PDO connection implementation for GEMVC framework:

1. **`PdoConnection`** - Real implementation that creates actual PDO connections
   - Creates: `new PDO($dsn, $user, $pass)` - **REAL IMPLEMENTATION**
   - Implements: `ConnectionManagerInterface` (from `connection-contracts` package)
   - Used by: Framework's connection management system
   - **Supports multiple database drivers:**
     - **MySQL** (default, primary) - Optimized with MySQL-specific features
     - **SQLite** - Supported for testing and development
     - **Other PDO drivers** - PostgreSQL, etc. (via standard DSN format)

2. **`PdoConnectionAdapter`** - Adapter that wraps PDO instances
   - Wraps: Existing PDO instances (doesn't create them)
   - Implements: `ConnectionInterface` (from `connection-contracts` package)
   - Used by: `PdoConnection` to wrap created PDO instances

It's designed for traditional PHP-FPM environments where each request gets its own PHP process.

## Features

- ✅ **Real Implementation:** Creates actual PDO connections (`PdoConnection`)
- ✅ **Adapter:** Wraps PDO instances for contracts (`PdoConnectionAdapter`)
- ✅ **Multi-Driver Support:** MySQL (default), SQLite, and other PDO drivers
- ✅ **MySQL Optimizations:** Charset/collation setup, buffered queries, strict SQL mode
- ✅ Simple PDO connection management
- ✅ Transaction support (begin, commit, rollback)
- ✅ Error handling
- ✅ Connection state tracking
- ✅ Implements `ConnectionManagerInterface` (real implementation)
- ✅ Implements `ConnectionInterface` (adapter)

## Installation

```bash
composer require gemvc/connection-pdo
```

## Dependencies

### Required
- `php >= 8.2`
- `gemvc/connection-contracts: ^1.0` - For `ConnectionInterface`

### Framework Dependencies (Runtime)
- None - This package only depends on `connection-contracts` package
- Reads environment variables directly from `$_ENV` (no framework helpers needed)

**Note:** This package is framework-agnostic and only depends on `connection-contracts`. The framework should ensure `$_ENV` is populated before using this package.

## Usage

### Using the Real Implementation

#### MySQL (Default)

```php
use Gemvc\Database\Connection\Pdo\PdoConnection;

// Set environment variables (or use defaults)
$_ENV['DB_DRIVER'] = 'mysql';
$_ENV['DB_HOST'] = 'localhost';
$_ENV['DB_NAME'] = 'my_database';
$_ENV['DB_USER'] = 'my_user';
$_ENV['DB_PASSWORD'] = 'my_password';

// Get singleton instance (creates actual PDO connection)
$manager = PdoConnection::getInstance();

// Get connection (real implementation creates it, returns ConnectionInterface)
$connection = $manager->getConnection();

// Get underlying PDO instance
$pdo = $connection->getConnection();

// Use PDO directly
$stmt = $pdo->prepare("SELECT * FROM users");
$stmt->execute();

// Or use connection interface methods
$connection->beginTransaction();
$connection->commit();
```

#### SQLite (for Testing/Development)

```php
use Gemvc\Database\Connection\Pdo\PdoConnection;

// Set environment for SQLite
$_ENV['DB_DRIVER'] = 'sqlite';
$_ENV['DB_NAME'] = ':memory:'; // or '/path/to/database.db'

// Get connection
$manager = PdoConnection::getInstance();
$connection = $manager->getConnection();
$pdo = $connection->getConnection();

// Use SQLite connection
$pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY, name TEXT)');
```

#### Programmatic Configuration Override (for CLI/Docker)

For CLI commands in dockerized applications where you need to override database configuration:

```php
use Gemvc\Database\Connection\Pdo\PdoConnection;

// Get manager instance
$manager = PdoConnection::getInstance();

// Override configuration programmatically (useful for CLI/docker)
$manager->setConfig([
    'host' => 'mysql-container',  // Override host for docker
    'port' => 3306,
    'database' => 'my_database',
    'username' => 'my_user',
    'password' => 'my_password',
    // Other config keys are optional and will use defaults
]);

// Get connection with overridden config
$connection = $manager->getConnection();

// Reset back to $_ENV if needed
$manager->resetConfig();
```

**Note:** `setConfig()` clears any cached connections and DSN, ensuring new connections use the updated configuration.

### Using the Adapter

```php
use Gemvc\Database\Connection\Pdo\PdoConnectionAdapter;
use PDO;

// Create PDO connection (or get from manager)
$pdo = new PDO($dsn, $user, $pass);

// Wrap in adapter for contracts
$adapter = new PdoConnectionAdapter($pdo);

// Use with connection contracts
$connection = $adapter->getConnection(); // Returns PDO object
$adapter->beginTransaction();
$adapter->commit();
```

## Architecture

This package provides **two components** that work together with `connection-contracts`:

### 1. `PdoConnection` - Real Implementation

**Creates actual PDO connections:**
- Creates: `new PDO($dsn, $user, $pass)` - **REAL IMPLEMENTATION**
- Implements: `ConnectionManagerInterface` (from `connection-contracts` package)
- Manages connection lifecycle
- Handles configuration from environment variables
- Returns: `ConnectionInterface` (wrapped PDO via `PdoConnectionAdapter`)
- Simple connection caching (one connection per name, within request)
- No framework dependencies - reads `$_ENV` directly

**Database Driver Support:**
- **MySQL** (default): Primary driver with optimizations (charset, collation, strict mode)
- **SQLite**: Supported for testing/development (uses `:memory:` or file path)
- **Other PDO drivers**: PostgreSQL, etc. (via standard DSN format)

**Configuration Methods:**
1. **Environment Variables** (default): Reads from `$_ENV`
2. **Programmatic Override**: Use `setConfig()` method to override programmatically (useful for CLI/docker)

**Environment Variables:**
- `DB_DRIVER` - Database driver (default: `mysql`, supports: `mysql`, `sqlite`, `pgsql`, etc.)
- `DB_HOST` - Database host (default: `localhost`, not used for SQLite)
- `DB_PORT` - Database port (default: `3306`, not used for SQLite)
- `DB_NAME` - Database name (default: `gemvc_db`, for SQLite use `:memory:` or file path)
- `DB_USER` - Database username (default: `root`, not used for SQLite)
- `DB_PASSWORD` - Database password (default: empty, not used for SQLite)
- `DB_CHARSET` - Database charset (default: `utf8mb4`, MySQL only)
- `DB_COLLATION` - Database collation (default: `utf8mb4_unicode_ci`, MySQL only)
- `DB_PERSISTENT_CONNECTIONS` - Enable persistent connections (default: `1` - enabled, MySQL only)
- `DB_CONNECTION_TIMEOUT` - Connection timeout in seconds (default: `5`, MySQL only)
- `APP_ENV` - Application environment (optional, used for dev logging)

**Programmatic Configuration Methods:**
- `setConfig(array $config): void` - Override configuration programmatically
  - Clears cached connections and DSN
  - Useful for CLI commands in dockerized applications
  - All config keys are optional (uses defaults if not provided)
  - Example: `$manager->setConfig(['host' => 'mysql-container', 'database' => 'my_db'])`
- `resetConfig(): void` - Reset configuration back to `$_ENV` values

### 2. `PdoConnectionAdapter` - Adapter

**Wraps existing PDO instances:**
- Wraps: Existing PDO instances (doesn't create them)
- Implements: `ConnectionInterface` (from `connection-contracts` package)
- Provides transaction management (on Connection, not Manager)
- Error handling and state tracking
- Used by: `PdoConnection` to wrap created PDO instances

### Complete Flow

```
Application/Framework:
  PdoConnection::getInstance()
    └─> Returns: PdoConnection (singleton)
        └─> getConnection() creates: new PDO($dsn, $user, $pass)  ← REAL IMPLEMENTATION
            └─> Wraps PDO with: PdoConnectionAdapter
                └─> Returns: ConnectionInterface

Package Structure:
  PdoConnection (ConnectionManagerInterface)
    └─> Creates: PDO instances
    └─> Wraps with: PdoConnectionAdapter
        └─> Returns: ConnectionInterface

Contracts Package:
  ConnectionManagerInterface (from connection-contracts)
    └─> Implemented by: PdoConnection
    └─> Returns: ConnectionInterface (from connection-contracts)
```

### Integration with connection-contracts

- **`PdoConnection`** implements `ConnectionManagerInterface` (from contracts)
- **`PdoConnectionAdapter`** implements `ConnectionInterface` (from contracts)
- **Result:** Complete implementation of connection contracts, framework-agnostic

## Testing

### Running Tests

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse --level 9
```

### Test Coverage

The package includes comprehensive test coverage:

- **Overall Coverage:** 91.09% lines, 82.76% methods
- **PdoConnection:** 94.04% lines, 88.89% methods
- **PdoConnectionAdapter:** 82.35% lines, 72.73% methods
- **Total Tests:** 137 tests with 370 assertions

### Test Classes

- **PdoConnectionTest** - Unit tests for `PdoConnection` (isolated testing)
- **PdoConnectionClassTest** - Comprehensive test class covering all methods
- **PdoConnectionIntegrationTest** - Integration tests with real database operations
- **PdoConnectionAdapterTest** - Unit tests for `PdoConnectionAdapter`
- **PdoConnectionAdapterIntegrationTest** - Integration tests for adapter

### Generating Coverage Report

```bash
# Generate HTML coverage report
vendor/bin/phpunit --coverage-html coverage-report --coverage-filter src

# View text coverage summary
vendor/bin/phpunit --coverage-text --coverage-filter src
```

The HTML report will be generated in the `coverage-report/` directory.

## License

MIT
