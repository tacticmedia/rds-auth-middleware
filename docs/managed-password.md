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
