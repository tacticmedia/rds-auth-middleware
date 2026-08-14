# Documentation

Doctrine DBAL driver middleware that supplies the database credential for an Amazon RDS instance at connection time.

- [Getting started](getting-started.md) - requirements, installation, wiring, mode selection
- [IAM token authentication](iam-authentication.md) - token flow, AWS-side setup, TLS, limitations
- [Managed password](managed-password.md) - Secrets Manager rotation recovery, failure detection
- [Caching](caching.md) - PSR-6 behaviour, TTLs, invariants, key format
- [Database engines](database-engines.md) - engine detection, ports, TLS per engine
- [Architecture](architecture.md) - class map, connection flow, design decisions
- [Testing](testing.md) - suites, Docker services, environment variables, fixtures
