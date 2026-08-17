# Architecture

Namespace `TacticMedia\RdsAuth`, PHP >= 8.3, doctrine/dbal ^3.10 or ^4.0.

## Class map

- `RdsAuthMiddleware` - implements `Doctrine\DBAL\Driver\Middleware`. `wrap()` builds an `RdsAuthDriver` and detects the engine with `DatabaseEngine::fromDriver()`.
- `RdsAuthDriver` - extends `AbstractDriverMiddleware`. Owns mode selection, caching, the retry-once logic, and password-failure detection.
- `DatabaseEngine` - enum `Postgres | Mysql` with `fromDriver()` and `defaultPort()`. Unknown drivers get the PostgreSQL behaviour.
- `RdsIamTokenProvider` - holds the region, delegates to `RdsIamTokenGenerator`.
- `RdsIamTokenGenerator` - SigV4 presigner for RDS IAM tokens.
- `RdsSecretPasswordProvider` - reads the `password` key from the secret JSON through the AsyncAws `SecretsManagerClient`.
- `ConfiguredPasswordOutdated` - PSR-14 event carrying the secret ARN and the connection facts, never a credential.

## Connection flow

`RdsAuthDriver::connect()` selects the mode by precedence: a non-empty IAM username selects the IAM path, else a non-empty secret ARN selects the managed-password path, else the parameters pass through unchanged. Empty strings disable a mode exactly like null.

Both credential paths share the same shape:

1. Try the cached credential when one exists; on rejection delete it.
2. Fetch a fresh credential (sign a token, or read the secret) and connect.
3. Store the credential only after the database accepts it.
4. Never retry a fresh credential; the second failure propagates.

The IAM path additionally swaps `user`, applies the engine default port, and on PostgreSQL sets `sslmode=require` unless the parameters choose another mode. The managed-password path retries only when `isPasswordFailure()` matches; see [managed-password.md](managed-password.md) for the exact SQLSTATE and message rules. When that retry succeeds and the rejected password came from the connection parameters rather than the cache, the path dispatches `ConfiguredPasswordOutdated` on the optional PSR-14 dispatcher, after step 3 so a throwing listener cannot discard the accepted password.

## Why a hand-rolled token generator

AsyncAws has no RDS client, and the official AWS PHP SDK is deliberately not a dependency: it is large and only one feature would use it. `RdsIamTokenGenerator` therefore mirrors `Aws\Rds\AuthTokenGenerator`: SigV4-presign `GET https://{host}:{port}/?Action=connect&DBUser={user}` with service scope `rds-db`, then drop the scheme. `RdsIamTokenGeneratorReferenceTest` guards against drift by comparing generated tokens with fixtures recorded from the AWS CLI; see [testing.md](testing.md).

The default credential provider wraps the AsyncAws default chain in a `CacheProvider`, which keeps resolved credentials in memory until expiry. Without it every token mint would hit IMDS.

## Sensitive parameters

`#[\SensitiveParameter]` is not inherited. Every override or private method that receives `$params` must repeat the attribute to keep `$params['password']` out of stack traces.
