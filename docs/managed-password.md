# Managed Password Mode

## The problem this solves

RDS `ManageMasterUserPassword` rotates the master password in Secrets Manager [every seven days by default](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/rds-secrets-manager.html). Most deployments read the password into configuration once, at deployment time: Elastic Beanstalk `environmentsecrets`, an ECS task definition, a Kubernetes secret. Between the rotation and the next deployment the configured password is invalid and every connection fails with `password authentication failed`.

## How it works

The driver connects with the configured password first. When the database rejects it as a password failure, the driver reads the current password from Secrets Manager, retries once, and [caches](caching.md) the accepted password. Secrets Manager is never called while connections succeed. The only IAM permission required is `secretsmanager:GetSecretValue` on that one secret ARN.

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": "secretsmanager:GetSecretValue",
            "Resource": "arn:aws:secretsmanager:ap-southeast-2:123456789012:secret:rds!db-abc123"
        }
    ]
}
```

## Example

Configure the middleware with the secret ARN; leave the IAM username null.

```php
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use TacticMedia\RdsAuth\RdsAuthMiddleware;
use TacticMedia\RdsAuth\RdsIamTokenProvider;
use TacticMedia\RdsAuth\RdsSecretPasswordProvider;

$middleware = new RdsAuthMiddleware(
    new RdsIamTokenProvider('ap-southeast-2'),
    new RdsSecretPasswordProvider('ap-southeast-2'),
    null,
    'arn:aws:secretsmanager:ap-southeast-2:123456789012:secret:rds!db-abc123',
    $cachePool,
);

$configuration = new Configuration();
$configuration->setMiddlewares([$middleware]);

$connection = DriverManager::getConnection([
    'driver' => 'pdo_pgsql',
    'host' => 'mydb.abc123.ap-southeast-2.rds.amazonaws.com',
    'dbname' => 'app',
    'user' => 'postgres',
    'password' => getenv('DB_PASSWORD'),   // the possibly stale deployed password
], $configuration);
```

## Secret format

The provider reads the `password` key from the [RDS-managed secret JSON](https://docs.aws.amazon.com/secretsmanager/latest/userguide/reference_secret_json_structure.html):

```json
{"username": "postgres", "password": "current-password"}
```

A secret without a string `password` key, with an empty `SecretString`, or with invalid JSON raises a `RuntimeException` that names the ARN.

## Password-failure detection

The retry runs only after an authentication failure; any other failure does not depend on the password and propagates unchanged.

- PostgreSQL: SQLSTATE `28P01` or `28000`. pdo_pgsql reports a rejected password as the generic SQLSTATE `08006` with the cause only in the message, so the message `password authentication failed` also matches.
- MySQL: error 1045 (`Access denied for user ... using password`). Error 1044, a missing database privilege, also starts with `Access denied for user` but is not a password failure; the `using password` suffix distinguishes them.

## Outdated configured password event

The recovery is silent: the connection succeeds and the application never learns that its deployed password is invalid. Pass a [PSR-14](https://www.php-fig.org/psr/psr-14/) dispatcher as the last `RdsAuthMiddleware` argument to receive a `TacticMedia\RdsAuth\ConfiguredPasswordOutdated` event at that moment, and act on it: alert operations, or trigger the redeployment that reloads the password.

The event fires when the database rejects the password from the connection parameters and then accepts the current secret password. It carries the secret ARN, `host`, `port`, `dbname`, `user`, and the SQLSTATE of the rejection; a property is null when the connection parameters did not supply the value. It never carries a password or the rejection exception, whose trace frames can hold unredacted parameters.

It does not fire when:

- A [cached](caching.md) password is rejected. That is a rotation inside the cache TTL and says nothing about the deployed configuration.
- The secret password is rejected too. The exception propagates instead.
- IAM mode or pass-through mode is active. Neither reads a configured password.

Connection parameters without a `password` key count as an outdated configured password.

Listeners run synchronously inside `connect()`, after the accepted password is cached. A listener that throws therefore fails the connection, but the cached password survives for the next attempt. A null dispatcher, the default, disables the dispatch.

Any PSR-14 implementation works. Symfony's `EventDispatcher` dispatches the event by class name, so listeners subscribe with `#[AsEventListener(ConfiguredPasswordOutdated::class)]`. Without a framework, a dispatcher can be this small:

```php
use Psr\EventDispatcher\EventDispatcherInterface;
use TacticMedia\RdsAuth\ConfiguredPasswordOutdated;

final class OutdatedPasswordAlerter implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        if ($event instanceof ConfiguredPasswordOutdated) {
            error_log(sprintf(
                'Deployed database password is outdated: secret %s, host %s. Redeploy to reload it.',
                $event->secretArn,
                $event->host ?? 'unknown',
            ));
        }

        return $event;
    }
}
```

Pass it after the cache pool:

```php
$middleware = new RdsAuthMiddleware(
    new RdsIamTokenProvider('ap-southeast-2'),
    new RdsSecretPasswordProvider('ap-southeast-2'),
    null,
    'arn:aws:secretsmanager:ap-southeast-2:123456789012:secret:rds!db-abc123',
    $cachePool,
    new OutdatedPasswordAlerter(),
);
```
