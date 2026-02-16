# Contributing to AIVory Monitor PHP Agent

Thank you for your interest in contributing to the AIVory Monitor PHP Agent. Contributions of all kinds are welcome -- bug reports, feature requests, documentation improvements, and code changes.

## How to Contribute

- **Bug reports**: Open an issue at [GitHub Issues](https://github.com/aivorynet/agent-php/issues) with a clear description, steps to reproduce, and your environment details (PHP version, OS, web server).
- **Feature requests**: Open an issue describing the use case and proposed behavior.
- **Pull requests**: See the Pull Request Process below.

## Development Setup

### Prerequisites

- PHP 8.1 or later
- Composer

### Build and Test

```bash
cd monitor-agents/agent-php
composer install
composer test
```

### Running the Agent

Require the package via Composer and call the initialization function in your application bootstrap. See the README for integration details.

## Coding Standards

- Follow the existing code style in the repository.
- Write tests for all new features and bug fixes.
- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards.
- Keep error handler and exception handler hooks well-documented.
- Ensure compatibility with PHP 8.1+.

## Pull Request Process

1. Fork the repository and create a feature branch from `main`.
2. Make your changes and write tests.
3. Ensure all tests pass (`composer test`).
4. Submit a pull request on [GitHub](https://github.com/aivorynet/agent-php) or GitLab.
5. All pull requests require at least one review before merge.

## Reporting Bugs

Use [GitHub Issues](https://github.com/aivorynet/agent-php/issues). Include:

- PHP version (`php --version`) and OS
- Agent version
- Web server (Apache, Nginx, etc.) and SAPI (FPM, CLI, etc.)
- Error output or stack traces
- Minimal reproduction steps

## Security

Do not open public issues for security vulnerabilities. Report them to **security@aivory.net**. See [SECURITY.md](SECURITY.md) for details.

## License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE).
