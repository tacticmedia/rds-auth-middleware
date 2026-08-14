# The Credential Cache

## Behaviour

Caching is optional: omit the pool argument, or pass null, to disable it. Without a cache every IAM connection signs a new token, and during a rotation every connection makes one rejected attempt and one Secrets Manager read.

The driver follows three invariants:

1. A credential is stored only after the database accepts it. A database that is down never populates the cache, so an outage causes one connection attempt per request.
2. A cached credential the database rejects is deleted; the driver fetches a fresh one and retries once.
3. A credential fetched fresh in the same request is never retried. It cannot be stale, and a second attempt would only add load to a database that already failed.

## TTLs

- IAM tokens: cached 600 seconds of their 900-second lifetime. The margin covers clock skew and the connection that uses the token.
- Passwords: cached 600 seconds to limit Secrets Manager reads during a rotation window.

## Choosing a pool

Use a pool that the workers on a host share, for example one backed by APCu. A per-process pool works but reads the credential sources more often.

```php
use Symfony\Component\Cache\Adapter\ApcuAdapter;

$cachePool = new ApcuAdapter('rds_auth');
```

Any PSR-6 `CacheItemPoolInterface` implementation works; symfony/cache is not a dependency of this package.

## Key format

Keys hash their inputs with SHA-256 to keep the length constant and to avoid the PSR-6 reserved characters `{}()/\@:`.

- Token: `rds_iam_token.v1.` + `sha256(host:port|username)`. An RDS endpoint host name contains the region, so the key needs no region segment.
- Password: `rds_secret_password.v1.` + `sha256(secretArn)`.
