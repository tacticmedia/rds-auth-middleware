# Database Engines

## Detection

`DatabaseEngine::fromDriver()` inspects the wrapped DBAL driver: drivers extending `Doctrine\DBAL\Driver\AbstractMySQLDriver` (this covers MariaDB) select the MySQL behaviour, every other driver selects the PostgreSQL behaviour.

## Differences

| | PostgreSQL | MySQL and MariaDB |
| --- | --- | --- |
| Default port when `port` is missing | 5432 | 3306 |
| TLS for IAM authentication | Middleware sets `sslmode=require` unless the parameters choose another mode | Middleware adds nothing; configure `driverOptions` yourself |
| Password rejection | SQLSTATE `28P01` or `28000`, or `password authentication failed` in the message | Error 1045; error 1044 is not treated as a password failure |

PDO MySQL configures TLS through `driverOptions`, not a connection parameter. RDS refuses IAM logins without TLS, so a missing TLS configuration fails closed. Point the CA option at the RDS certificate bundle:

```php
$params['driverOptions'] = [\PDO::MYSQL_ATTR_SSL_CA => '/path/to/global-bundle.pem'];
```

On PHP 8.4 or later, `\Pdo\Mysql::ATTR_SSL_CA` names the same option; the `PDO::MYSQL_ATTR_SSL_CA` name is deprecated since PHP 8.5.
