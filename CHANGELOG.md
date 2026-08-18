# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- The `symfony/polyfill-uuid` conflict blocked installation in projects that replace the polyfill, because a `replace` at `*` matches every conflict constraint. The version floor moved to `require-dev`, which guards the lowest-versions CI job and is invisible to consumers.

## [1.1.1] - 2026-08-17

### Added

- `SECURITY.md`, which tells how to report a vulnerability.
- Tests for the two remaining error branches: unresolvable AWS credentials and a network failure during a secret read. Statement coverage is 100%.

## [1.1.0] - 2026-08-17

### Added

- Support for Doctrine DBAL ^3.10 in addition to ^4.0. CI tests both majors at their lowest and latest versions.

## [1.0.1] - 2026-08-17

### Fixed

- A hyphenation error in the README that inverted the contributions policy.

## [1.0.0] - 2026-08-17

Initial release.

### Added

- `RdsAuthMiddleware`, a Doctrine DBAL 4 driver middleware that supplies the database credential at connection time. A set IAM username selects IAM token authentication, a set secret ARN selects managed-password authentication, and neither passes the connection parameters through unchanged.
- `RdsIamTokenProvider` and `RdsIamTokenGenerator`, which sign short-lived [RDS IAM authentication tokens](docs/iam-authentication.md) locally with SigV4. `RdsIamTokenGeneratorReferenceTest` compares the output with tokens recorded from an official AWS implementation.
- `RdsSecretPasswordProvider`, which reads the current password from an [RDS-managed secret](docs/managed-password.md) after the database rejects the configured one, then reconnects. This recovers from `ManageMasterUserPassword` rotation without a deployment.
- An optional PSR-6 [credential cache](docs/caching.md). A credential is stored only after the database accepts it, a rejected cached credential is deleted and retried once, and a fresh credential is never retried.
- An optional PSR-14 `ConfiguredPasswordOutdated` event that reports a stale deployed password. It carries the secret ARN and the connection facts, never a credential.
- `DatabaseEngine`, which detects PostgreSQL and MySQL or MariaDB from the wrapped driver to select the default port, the TLS handling, and the [password-rejection rules](docs/database-engines.md).

[Unreleased]: https://github.com/tacticmedia/rds-auth-middleware/compare/1.1.1...HEAD
[1.1.1]: https://github.com/tacticmedia/rds-auth-middleware/compare/1.1.0...1.1.1
[1.1.0]: https://github.com/tacticmedia/rds-auth-middleware/compare/1.0.1...1.1.0
[1.0.1]: https://github.com/tacticmedia/rds-auth-middleware/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/tacticmedia/rds-auth-middleware/releases/tag/1.0.0
