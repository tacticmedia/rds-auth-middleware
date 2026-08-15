# Getting Started

## Requirements

PHP >= 8.3 and doctrine/dbal ^4.0. AWS calls go through AsyncAws; the official AWS SDK is not a dependency.

## Installation

Install the package with Composer:

```bash
composer require tacticmedia/rds-auth-middleware
```

Symfony applications should install [`tacticmedia/rds-auth-bundle`](https://github.com/tacticmedia/rds-auth-bundle) instead. The bundle configures this package through bundle configuration.

## Wiring the middleware

Register the middleware on the DBAL configuration. The same construction works in every environment; the two nullable arguments select the mode.

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

## Mode selection

The middleware selects one of three modes at connection time:

| Configuration | Mode |
| --- | --- |
| IAM username set | Replace user and password with a short-lived [RDS IAM authentication token](iam-authentication.md) |
| Secret ARN set | Connect with the configured password; on rejection, read the current password from [Secrets Manager](managed-password.md) and retry once |
| Neither set | Pass the connection parameters through unchanged |

The IAM username takes precedence when both are set. `null` and the empty string both disable a mode, so unset environment variables select pass-through and one build runs in every environment.

## AWS credentials

Both providers use the [AsyncAws default credential chain](https://async-aws.com/authentication/): environment, web identity, ini files, ECS container, EC2 instance metadata. On AWS compute this resolves the instance or task role without configuration.
