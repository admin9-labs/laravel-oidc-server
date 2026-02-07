# Contributing to admin9/laravel-oidc-server

Thank you for considering a contribution. This guide covers the basics you need
to get started.

## Development Environment

Requirements: PHP 8.2+ and Composer.

```bash
git clone https://github.com/admin9-labs/laravel-oidc-server.git
cd laravel-oidc-server
composer install
```

Laravel Passport requires an encryption keypair for token signing. Generate one
inside the test environment (Orchestra Testbench uses `vendor/orchestra/testbench-core/laravel`
as its application skeleton):

```bash
php vendor/bin/testbench passport:install
```

## Running Tests

The project supports both PHPUnit 11 and Pest 3. Either runner works:

```bash
vendor/bin/phpunit
# or
vendor/bin/pest
```

Test files live in `tests/` and follow PSR-4 autoloading under
`Admin9\OidcServer\Tests\`. The PHPUnit configuration is in `phpunit.xml`.

## Coding Standards

- Follow **PSR-12** for code style.
- Every PHP file must declare strict types at the top:
  ```php
  <?php

  declare(strict_types=1);
  ```
- Use PSR-4 autoloading. Source classes belong under `src/` in the
  `Admin9\OidcServer\` namespace; test classes belong under `tests/` in the
  `Admin9\OidcServer\Tests\` namespace.
- Keep methods focused and classes small. Prefer explicit return types.

## Pull Request Process

1. Fork the repository and create a feature branch from `main`.
2. Write or update tests for any changed behaviour.
3. Make sure the full test suite passes before submitting.
4. Keep each pull request focused on a single change.
5. Write a clear PR title and description explaining **what** changed and
   **why**.
6. A maintainer will review your PR. Please be patient and responsive to
   feedback.

## Bug Reports

Open an issue on GitHub with the following information:

- Package version and Laravel/Passport version.
- PHP version.
- A minimal set of steps to reproduce the problem.
- Expected behaviour vs. actual behaviour.
- Any relevant logs or error messages.

## License

By contributing you agree that your contributions will be licensed under the
[MIT License](LICENSE.md) that covers this project.
