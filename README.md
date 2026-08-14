# RDS Auth middleware

**Important**: Symfony users should install [`tacticmedia/rds-auth-bundle`](https://github.com/tacticmedia/rds-auth-bundle) instead, which configures this package through bundle configuration.

A Doctrine DBAL driver middleware that supplies the database credential for an Amazon RDS instance. At connection time it selects one of three modes:

- When **IAM username** is configured: replace the user and password with a short-lived [RDS IAM authentication token](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.IAMDBAuth.html).
- When the **Secret ARN** configured: connect with the configured password; when the database rejects it, read the current password from Secrets Manager and retry once.
- Neither configured: pass the connection parameters through unchanged.

The **Secret ARN** mode exists to support automated RDS `ManageMasterUserPassword` rotation, which happens [every seven days by default](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/rds-secrets-manager.html). Most deployments read the password into configuration once, at deployment time: Elastic Beanstalk `environmentsecrets`, an ECS task definition, a Kubernetes secret. Between the rotation and the next deployment or restart the configured password is invalid, and every connection attempt fails with `password authentication failed`. Reading the secret at connection time lets the application recover without a deployment. The only IAM permission this requires is `secretsmanager:GetSecretValue` on that one secret ARN.

## A word of caution about the IAM authentication

RDS IAM authentication sounds like a no-brainer good decision: no passwords are always a good thing, right? Wrong.

AWS writes in their [documentation](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.IAMDBAuth.html):

> IAM DB authentication requires compute resources on the database instance. You must have between 300 and 1000 MiB extra memory on your database for reliable connectivity. To see the memory needed for your workload, compare the RES column for RDS processes in the Enhanced Monitoring processlist before and after enabling IAM DB authentication. 

In other words, enabling IAM Authentication involves resource overhead that will render small instances unusable. So, IAM
authentication will often be unsuitable for small deployments, free tier environments and so on.

That's why the other auth mode exists here: to enable using the managed password with automatic rotation. It's a step worse than no passwords at all, but it is a lot better than not rotating the password.  

## Installation

```bash
composer require tacticmedia/rds-auth-middleware
```

## Usage

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

Both providers use the [AsyncAws default credential chain](https://async-aws.com/authentication/) (environment, web identity, ini files, ECS container, EC2 instance metadata), which on AWS compute resolves the instance or task role. IAM authentication requires two grants: the role must have the [`rds-db:connect` permission](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.IAMDBAuth.IAMPolicy.html) for the target database user, and the database user must have the [`rds_iam` role](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.IAMDBAuth.DBAccounts.html) on PostgreSQL or the [`AWSAuthenticationPlugin`](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.IAMDBAuth.DBAccounts.html) on MySQL and MariaDB.

## Database engines

The middleware detects the engine from the wrapped DBAL driver: MySQL and MariaDB drivers select the MySQL behaviour, every other driver selects the PostgreSQL behaviour.

- PostgreSQL: a missing `port` defaults to 5432. The IAM path sets `sslmode=require` unless the connection parameters choose another mode, because [RDS requires TLS for IAM authentication](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.IAMDBAuth.html).
- MySQL and MariaDB: a missing `port` defaults to 3306. PDO MySQL configures TLS through `driverOptions`, not a connection parameter, so the middleware adds nothing; point `Pdo\Mysql::ATTR_SSL_CA` at the [RDS certificate bundle](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.SSL.html). RDS refuses IAM logins without TLS, so a missing TLS configuration fails closed.

```php
$params['driverOptions'] = [\Pdo\Mysql::ATTR_SSL_CA => '/path/to/global-bundle.pem'];
```

The secret mode detects a rejected password on both engines: PostgreSQL reports SQLSTATE `28P01` or `28000`, MySQL reports error 1045. MySQL error 1044, a missing database privilege, is not treated as a password failure.

## The credential cache

The driver stores a credential in the PSR-6 pool only after the database accepts it, so a database that is down never populates the cache, and an outage causes one connection attempt per request. Use a pool that the workers on a host share, for example one backed by APCu; a per-process pool works but reads the credential sources more often.

Caching is optional: omit the pool, or pass null, to disable it. Without a cache, every IAM connection signs a new token, and during a rotation every connection makes one rejected attempt and one Secrets Manager read.

Tokens are cached for 10 of their [15 minutes](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.IAMDBAuth.html). Signing a token sends nothing to RDS, but credential resolution can add one IMDS round trip when the in-memory credential cache is empty. The database rejects a cached token after the credentials that signed it rotate; the driver then deletes the token, signs a new one, and retries once. The secret mode never calls Secrets Manager while connections succeed; only a rejected password causes a read.

The token cache does not reduce the cost of the connections themselves: the database verifies every new IAM-authenticated connection, and AWS documents [additional memory requirements](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.IAMDBAuth.html#UsingWithRDS.IAMDBAuth.Limitations) for IAM database authentication. At high connection rates, reuse connections with a persistent-worker runtime or [RDS Proxy](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/rds-proxy.html).

## Development

```bash
composer install
composer test               # PHPUnit unit suite
composer test:integration   # PHPUnit integration suite, needs the services below
composer coverage           # PHPUnit with HTML and Clover reports in coverage/
composer stan               # PHPStan level 8
```

No suite requires AWS access. The unit suite signs tokens locally with static credentials and serves Secrets Manager responses from a Symfony MockHttpClient. Token signatures are verified against fixtures recorded from the AWS CLI in `tests/fixtures/rds-iam-tokens.json`; `php tests/fixtures/generate-reference-tokens.php` regenerates them.

### Integration tests

The integration suite drives the middleware through real endpoints: PostgreSQL 18 and MySQL 8.4, both with TLS, and a [moto](https://github.com/getmoto/moto) server for the Secrets Manager API. It exercises the full rotation flow, a genuine password rejection followed by a secret read and a reconnect, and the IAM token transport.

```bash
docker compose --profile integration up -d --wait postgres mysql moto
composer test:integration
```

The suite skips itself unless `RDS_AUTH_INTEGRATION_TESTS=1` is set; the composer script sets it. The `RDS_AUTH_IT_*` environment variables override the connection targets, see `tests/Integration/IntegrationTestCase.php` for the list and defaults. The MySQL service exports its generated CA certificate to `tests/Integration/docker/mysql-export/` because pdo_mysql enables TLS only with a CA file configured.

## License

MIT. See [LICENSE](LICENSE).
