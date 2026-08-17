# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

A Doctrine DBAL driver middleware (DBAL ^3.10 or ^4.0, PHP >= 8.3, namespace `TacticMedia\RdsAuth`) that supplies the database credential for an Amazon RDS instance at connection time: IAM token, managed password from Secrets Manager, or pass-through. Symfony users consume it through `tacticmedia/rds-auth-bundle`, a separate repository.

## Commands

```bash
composer test                 # unit suite (default PHPUnit suite)
composer test:integration     # integration suite; first run: docker compose --profile integration up -d --wait postgres mysql moto
composer stan                 # PHPStan level 8 over src and tests
composer cs                   # php-cs-fixer fix
composer rector               # rector
composer qa                   # rector, cs, stan, test in sequence
composer coverage             # HTML and Clover reports in coverage/
```

Single test: `vendor/bin/phpunit tests/RdsAuthDriverTest.php` or `vendor/bin/phpunit --filter testName`.

## Documentation

Read the relevant file in `docs/` before working in its area; do not load all of them.

- `docs/architecture.md` - class map, connection flow, retry invariants, why the SigV4 generator is hand-rolled
- `docs/testing.md` - suites, Docker services, `RDS_AUTH_IT_*` variables, reference fixtures, CI matrix
- `docs/iam-authentication.md` - IAM mode behaviour, AWS-side setup, TLS per engine
- `docs/managed-password.md` - rotation recovery flow, password-failure detection rules
- `docs/caching.md` - PSR-6 invariants, TTLs, key format
- `docs/database-engines.md` - engine detection and per-engine differences
- `docs/getting-started.md` - installation and wiring examples

## Constraints

- `#[\SensitiveParameter]` is not inherited: repeat it on every method that receives `$params`.
- No test requires AWS access; keep it that way.
- CI tests lowest dependency versions and both DBAL majors, so code must work against the lowest versions composer.json allows with DBAL ^3.10 and ^4.0. Use only the API surface the two majors share.
- `FakeDriver` and `FakeConnection` keep one signature set valid against the driver interfaces of both DBAL majors; see docs/testing.md before changing them.
- Change `RdsIamTokenGenerator` only with `RdsIamTokenGeneratorReferenceTest` passing; it guards against drift from the official AWS signer.
- Prefer the doubles in `tests/Support` over new mocks.
- `docs/` is indexed by Context7 (see `context7.json`): keep every code block preceded by prose that describes it, and keep examples runnable.
