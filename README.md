# Doctrine middleware for RDS authentication

[![codecov](https://codecov.io/gh/tacticmedia/rds-auth-middleware/graph/badge.svg?token=XZINN5HXOB)](https://codecov.io/gh/tacticmedia/rds-auth-middleware)

**TL;DR**: Doctrine middleware that bridges the gap between Doctrine's convenience and AWS RDS's advanced security features: IAM authentication and automatic password rotation. Use this bridge to improve your baseline security posture with minimal effort.

**Important**: Symfony users should install [`tacticmedia/rds-auth-bundle`](https://github.com/tacticmedia/rds-auth-bundle) instead, which configures this package through bundle configuration.

A Doctrine DBAL driver middleware that supplies the database credential for an Amazon RDS instance. At connection time it selects one of three modes:

- When **IAM username** is configured: replace the user and password with a short-lived [RDS IAM authentication token](docs/iam-authentication.md).
- When the **Secret ARN** is configured: connect with the configured password; when the database rejects it, read the current password from [Secrets Manager](docs/managed-password.md) and retry once. This recovers from automated RDS `ManageMasterUserPassword` rotation without a deployment.
- Neither configured: pass the connection parameters through unchanged.

Before you choose IAM authentication, read its [limitations](docs/iam-authentication.md#limitations): AWS requires 300 to 1000 MiB extra database memory for it, which rules out small instances. The managed password mode exists for exactly those deployments.

## Installation

```bash
composer require tacticmedia/rds-auth-middleware
```

## Quick start

```php
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use TacticMedia\RdsAuth\RdsAuthMiddleware;
use TacticMedia\RdsAuth\RdsIamTokenProvider;
use TacticMedia\RdsAuth\RdsSecretPasswordProvider;

$region = getenv('AWS_REGION') ?: 'us-east-1';

$middleware = new RdsAuthMiddleware(
    new RdsIamTokenProvider($region),
    new RdsSecretPasswordProvider($region),
    getenv('RDS_IAM_USERNAME') ?: null,          // null disables the IAM path
    getenv('RDS_SECRET_ARN') ?: null,            // null disables the refresh path
    $cachePool,                                  // any PSR-6 pool; omit to disable caching
);

$configuration = new Configuration();
$configuration->setMiddlewares([$middleware]);

$connection = DriverManager::getConnection($params, $configuration);
```

## Documentation

- [Getting started](docs/getting-started.md) - requirements, installation, wiring, mode selection
- [IAM token authentication](docs/iam-authentication.md) - token flow, AWS-side setup, TLS, limitations
- [Managed password](docs/managed-password.md) - Secrets Manager rotation recovery, failure detection
- [Caching](docs/caching.md) - PSR-6 behaviour, TTLs, invariants, key format
- [Database engines](docs/database-engines.md) - engine detection, ports, TLS per engine
- [Architecture](docs/architecture.md) - class map, connection flow, design decisions
- [Testing](docs/testing.md) - suites, Docker services, environment variables, fixtures

## Development

```bash
composer test               # PHPUnit unit suite
composer test:integration   # PHPUnit integration suite, needs Docker services
composer qa                 # rector, cs, stan, test in sequence
```

See [docs/testing.md](docs/testing.md) for the Docker services, environment variables, and reference fixtures.

## License

MIT. See [LICENSE](LICENSE).
