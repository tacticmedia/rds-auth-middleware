# IAM Token Authentication

## How it works

When the IAM username is configured, the driver replaces `user` and `password` in the connection parameters with a short-lived [RDS IAM authentication token](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.IAMDBAuth.html). The token is a SigV4-presigned URL with a 15-minute lifetime. Signing happens locally and sends nothing to RDS; credential resolution can add one IMDS round trip when the in-memory credential cache is empty.

With a [cache pool](caching.md) configured, an accepted token is reused for 10 minutes. A token the database rejects, for example after the signing credentials rotate, is deleted; the driver signs a new one and retries once.

## Example

Configure the middleware with the IAM username; leave the secret ARN null.

```php
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use TacticMedia\RdsAuth\RdsAuthMiddleware;
use TacticMedia\RdsAuth\RdsIamTokenProvider;
use TacticMedia\RdsAuth\RdsSecretPasswordProvider;

$middleware = new RdsAuthMiddleware(
    new RdsIamTokenProvider('ap-southeast-2'),
    new RdsSecretPasswordProvider('ap-southeast-2'),
    'app_user',      // database user with IAM authentication enabled
    null,
    $cachePool,
);

$configuration = new Configuration();
$configuration->setMiddlewares([$middleware]);

$connection = DriverManager::getConnection([
    'driver' => 'pdo_pgsql',
    'host' => 'mydb.abc123.ap-southeast-2.rds.amazonaws.com',
    'dbname' => 'app',
], $configuration);
```

## AWS-side requirements

The IAM role must have the [`rds-db:connect` permission](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.IAMDBAuth.IAMPolicy.html) for the target database user. The resource uses the DbiResourceId of the instance, not its name.

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": "rds-db:connect",
            "Resource": "arn:aws:rds-db:ap-southeast-2:123456789012:dbuser:db-ABCDEFGHIJKL/app_user"
        }
    ]
}
```

On PostgreSQL, grant the database user the `rds_iam` role:

```sql
CREATE USER app_user;
GRANT rds_iam TO app_user;
```

On MySQL and MariaDB, create the user with the `AWSAuthenticationPlugin`:

```sql
CREATE USER 'app_user'@'%' IDENTIFIED WITH AWSAuthenticationPlugin AS 'RDS';
```

## TLS

RDS refuses IAM logins without TLS.

- PostgreSQL: the middleware sets `sslmode=require` unless the connection parameters choose another mode.
- MySQL and MariaDB: PDO MySQL configures TLS through `driverOptions`, not a connection parameter, so the middleware adds nothing and a missing TLS configuration fails closed. Point `Pdo\Mysql::ATTR_SSL_CA` at the [RDS certificate bundle](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/UsingWithRDS.SSL.html):

```php
$params['driverOptions'] = [\Pdo\Mysql::ATTR_SSL_CA => '/path/to/global-bundle.pem'];
```

## Limitations

AWS documents that IAM database authentication needs 300 to 1000 MiB extra memory on the database instance for reliable connectivity. Small instances and free-tier environments often cannot afford this; use the [managed password mode](managed-password.md) there instead.

The token cache does not reduce the cost of the connections themselves: the database verifies every new IAM-authenticated connection. At high connection rates, reuse connections with a persistent-worker runtime or [RDS Proxy](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/rds-proxy.html).

## Generating a token without the middleware

`RdsIamTokenGenerator` signs tokens standalone, for example for a CLI client. The returned string is the endpoint without the `https://` scheme, ready to use as a password.

```php
use TacticMedia\RdsAuth\RdsIamTokenGenerator;

$generator = new RdsIamTokenGenerator();
$token = $generator->createToken('mydb.abc123.ap-southeast-2.rds.amazonaws.com:5432', 'ap-southeast-2', 'app_user');
```
