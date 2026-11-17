# Changelog

## [Unreleased]

## [1.0.1] - 2026-11-17

### Fixed

- Needs `nubo-db/dynoxide` 1.7.0.1 or later. 1.7.0 writes to stderr on
  every start on Windows, which fails any test run in a separate process.

## [1.0.0] - 2026-11-17

The first release. It needs `nubo-db/dynoxide` 1.7.0 or
later, PHP 8.2 or later, and Laravel 12 or 13.

- The `UsesDynoxide` testing trait: one Dynoxide server per test process,
  the app's DynamoDB cache stores, rate limiter, sessions and failed jobs
  pointed at it, the tables the app's config names created, and those tables
  emptied after each test unless `dynoxideResetsTables()` returns false.
- `php artisan dynoxide:serve`, which runs Dynoxide on port 8800 with the
  app's tables for local development. `--timeout` sets how long it waits
  for Dynoxide to be ready.
- A publishable `config/dynoxide.php` for the command's port, host,
  database file and start timeout.
