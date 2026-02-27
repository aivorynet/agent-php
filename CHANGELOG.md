# Changelog

All notable changes to the AIVory Monitor PHP Agent will be documented in this file.

This project adheres to [Semantic Versioning](https://semver.org/).

## [0.1.1] - 2026-02-27

### Changed
- Updated WebSocket endpoint to `wss://api.aivory.net/monitor/agent`

## [0.1.0] - 2026-02-16

### Added
- Automatic exception capture via set_exception_handler
- Shutdown function registration for fatal error capture
- Manual exception capture API
- WebSocket transport to AIVory backend
- PSR-4 autoloading under AIVory\Monitor namespace
- Configurable sampling rate and capture depth
- Environment variable and programmatic configuration
- PHP 8.0+ support
