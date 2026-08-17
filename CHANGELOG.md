# Changelog

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-08-17

Initial release.

### Added

- `RdsAuthMiddleware`, a Doctrine DBAL 4 driver middleware that supplies the database credential at connection time. A set IAM username selects IAM token authentication, a set secret ARN selects managed-password authentication, and neither passes the connection parameters through unchanged.
- `RdsIamTokenProvider` and `RdsIamTokenGenerator`, which sign short-lived [RDS IAM authentication tokens](docs/iam-authentication.md) locally with SigV4. `RdsIamTokenGeneratorReferenceTest` compares the output with tokens recorded from an official AWS implementation.
- `RdsSecretPasswordProvider`, which reads the current password from an [RDS-managed secret](docs/managed-password.md) after the database rejects the configured one, then reconnects. This recovers from `ManageMasterUserPassword` rotation without a deployment.
- An optional PSR-6 [credential cache](docs/caching.md). A credential is stored only after the database accepts it, a rejected cached credential is deleted and retried once, and a fresh credential is never retried.
- An optional PSR-14 `ConfiguredPasswordOutdated` event that reports a stale deployed password. It carries the secret ARN and the connection facts, never a credential.
- `DatabaseEngine`, which detects PostgreSQL and MySQL or MariaDB from the wrapped driver to select the default port, the TLS handling, and the [password-rejection rules](docs/database-engines.md).

[Unreleased]: https://github.com/tacticmedia/rds-auth-middleware/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/tacticmedia/rds-auth-middleware/releases/tag/v1.0.0
