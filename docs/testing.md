# Testing

## Commands

```bash
composer install
composer test                 # unit suite (default PHPUnit suite)
composer test:integration     # integration suite, needs the Docker services below
composer stan                 # PHPStan level 8 over src and tests
composer cs                   # php-cs-fixer fix
composer rector               # rector
composer qa                   # rector, cs, stan, test in sequence
composer coverage             # HTML and Clover reports in coverage/
```

Run a single test file or method:

```bash
vendor/bin/phpunit tests/RdsAuthDriverTest.php
vendor/bin/phpunit --filter testIamModeSwapsCredentials
```

## Suites

No suite requires AWS access.

- Unit (`tests/`, the default suite): signs tokens locally with static credentials and serves Secrets Manager responses from a Symfony `MockHttpClient`.
- Integration (`tests/Integration`): drives the middleware through real endpoints. It exercises the full rotation flow, a genuine password rejection followed by a secret read and a reconnect, and the IAM token transport. The suite skips itself unless `RDS_AUTH_INTEGRATION_TESTS=1` is set; the composer script sets it.

## Integration services

Start the services with the compose integration profile:

```bash
docker compose --profile integration up -d --wait postgres mysql moto
composer test:integration
```

- `postgres`: PostgreSQL 18 with TLS on host port 55432
- `mysql`: MySQL 8.4 with TLS on host port 53306
- `moto`: Secrets Manager API on host port 5566

The MySQL service exports its generated CA certificate to `tests/Integration/docker/mysql-export/ca.pem` because pdo_mysql enables TLS only with a CA file configured.

## Environment variables

`RDS_AUTH_IT_*` variables override the connection targets; defaults live in `tests/Integration/IntegrationTestCase.php`.

| Variable | Default |
| --- | --- |
| `RDS_AUTH_IT_PG_HOST` | `127.0.0.1` |
| `RDS_AUTH_IT_PG_PORT` | `55432` |
| `RDS_AUTH_IT_PG_DATABASE` | `app` |
| `RDS_AUTH_IT_PG_ADMIN_USER` | `admin` |
| `RDS_AUTH_IT_PG_ADMIN_PASSWORD` | `admin` |
| `RDS_AUTH_IT_MYSQL_HOST` | `127.0.0.1` |
| `RDS_AUTH_IT_MYSQL_PORT` | `53306` |
| `RDS_AUTH_IT_MYSQL_DATABASE` | `app` |
| `RDS_AUTH_IT_MYSQL_ADMIN_USER` | `root` |
| `RDS_AUTH_IT_MYSQL_ADMIN_PASSWORD` | `admin` |
| `RDS_AUTH_IT_MYSQL_CA_FILE` | `tests/Integration/docker/mysql-export/ca.pem` |
| `RDS_AUTH_IT_MOTO_ENDPOINT` | `http://127.0.0.1:5566` |

## Reference token fixtures

`RdsIamTokenGeneratorReferenceTest` compares tokens from `RdsIamTokenGenerator` with tokens an official AWS implementation signed for the same inputs and instant, recorded in `tests/fixtures/rds-iam-tokens.json`. Change the generator only with this test passing. Regenerate the fixtures with:

```bash
php tests/fixtures/generate-reference-tokens.php
```

## Test doubles

Reusable doubles live in `tests/Support`: `FakeDriver`, `FakeConnection`, `FrozenClock`, `StubDriverException`, `RecordingEventDispatcher`. Prefer them over new mocks.

## CI

`.github/workflows/ci.yml` runs the unit suite and PHPStan on a matrix of PHP 8.3, 8.4, and 8.5 with highest and lowest dependency versions, so code must work against the lowest versions composer.json allows. A separate job runs the integration suite on PHP 8.3 against the compose services.
