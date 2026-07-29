# Changelog

All notable changes to `laravel-cloudwatch` will be documented in this file.

## 2.0.1 - 2026-07-29

### Changed

- Trimmed the Laravel Boost guidelines to the safety rules and a pointer to the `laravel-cloudwatch` skill, cutting the token cost they add to every consuming app. The full detail stays in the skill, which loads on demand.

## 2.0.0 - 2026-07-29

### Added

- Laravel Boost support: consuming apps that run `boost:install` now receive package guidelines, and a `laravel-cloudwatch` skill ships with the package covering configuration, debugging missing logs, and the extension points.

### Changed

- **Breaking:** the package now requires PHP 8.4 or newer and Laravel 12 or newer. Earlier releases declared PHP 8.2 support but shipped PHP 8.3 syntax, so installs on 8.2 never actually worked.
