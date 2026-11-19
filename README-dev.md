# Internal Developer Readme

## Prerequisites

- PHP 8.1+
- Composer

## Setup

```bash
composer install
```

## Tests

```bash
vendor/bin/phpunit
```

The test suite uses PHPUnit, Brain\Monkey, and Mockery. WordPress classes are stubbed in `tests/stubs.php`.

## Building

The build script scopes vendor dependencies with PHP-Scoper to avoid conflicts with other plugins:

```bash
./build.sh production
```

Output goes to `build/`. The build process:
1. Installs production dependencies
2. Scopes vendor namespaces under `FreespokeDeps\`
3. Copies plugin files and stamps config placeholders from `config.yaml`
