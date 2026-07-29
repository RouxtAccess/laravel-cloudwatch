# Changelog

All notable changes to `laravel-cloudwatch` will be documented in this file.

## 2.0.0 - 2026-07-29

### Added

- Laravel Boost support: consuming apps that run `boost:install` now receive package guidelines, and a `laravel-cloudwatch` skill ships with the package covering configuration, debugging missing logs, and the extension points.

### Changed

- **Breaking:** the package now requires PHP 8.4 or newer and Laravel 12 or newer. Earlier releases declared PHP 8.2 support but shipped PHP 8.3 syntax, so installs on 8.2 never actually worked.
