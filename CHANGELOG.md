# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.1] - 2026-05-17

### Fixed

- PHP 8.5 compatibility: use `Pdo\Mysql::ATTR_INIT_COMMAND` and `Pdo\Mysql::ATTR_USE_BUFFERED_QUERY` when available, with fallback to `PDO::MYSQL_ATTR_*` on older PHP versions

### Changed

- Test suite: removed obsolete `ReflectionMethod::setAccessible()` and `ReflectionProperty::setAccessible()` calls (deprecated in PHP 8.5; unnecessary since PHP 8.1)

## [1.0.0] - 2025-12-09

### Added

- Initial release of `gemvc/connection-pdo`
- `PdoConnection` — PDO connection manager implementing `ConnectionManagerInterface` from `gemvc/connection-contracts`
- `PdoConnectionAdapter` — adapter implementing `ConnectionInterface` for wrapping PDO instances
- MySQL (default), SQLite, and other PDO driver support via environment configuration
- MySQL optimizations: charset/collation init command, strict SQL mode, buffered queries, persistent connections
- Programmatic configuration via `setConfig()` / `resetConfig()` (CLI/Docker overrides)
- Simple connection caching per pool name (PHP-FPM friendly; not connection pooling)
- Transaction support on `PdoConnectionAdapter` (`beginTransaction`, `commit`, `rollback`)
- Comprehensive test suite with 100% code coverage target

[Unreleased]: https://github.com/gemvc/connection-pdo/compare/1.0.1...HEAD
[1.0.1]: https://github.com/gemvc/connection-pdo/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/gemvc/connection-pdo/releases/tag/1.0.0
