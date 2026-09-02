# YOURLS modernization — Symfony 7.4 + Doctrine DBAL

This branch modernizes the procedural YOURLS core with Symfony 7.4 (Routing / HttpFoundation /
Console) and Doctrine DBAL 4.x + Doctrine Migrations 3.8, **without breaking backward
compatibility** with the existing procedural API and plugin ecosystem.

## Install / run after checkout

The Symfony and Doctrine packages are declared in `composer.json` but must be fetched:

```bash
composer update --no-dev --prefer-dist   # populates includes/vendor/{symfony,doctrine}/...
```

(The `includes/vendor/composer/autoload_psr4.php` and `autoload_static.php` files already register
the new namespaces so the app is loadable; `composer` will regenerate them identically.)

Then install YOURLS from the CLI:

```bash
php bin/console yourls:install          # schema via Doctrine migrations, then seed + .htaccess
php bin/console migrations:status       # Doctrine migration status
php bin/console migrations:migrate      # run pending migrations
php bin/console list                    # all commands
```

The web installer (`admin/install.php`) now delegates to the same `\YOURLS\Console\Installer`.

> Requires **PHP 8.2+** (Symfony 7.4 floor).

## 1. Front controller — `yourls-loader.php` → `\YOURLS\Http\Kernel`

- `yourls-loader.php` now boots YOURLS then hands the request to `\YOURLS\Http\Kernel`, built on
  Symfony **HttpFoundation** (`Request`/`Response`) and **Routing** (`RouteCollectionFactory`,
  `UrlMatcher`).
- **Legacy hook lifecycle preserved exactly**: `pre_load_template` fires BEFORE any dispatch, and
  the dispatch hooks (`load_template_go`, `load_template_infos`, `pre_redirect_bookmarklet`,
  `redirect_keyword_not_found`, `loader_failed`, …) fire at the same points as the old loader.
- **Tolerates `echo` / `header()` / `exit()` inside hooks and templates**: the whole dispatch runs
  inside an output buffer that becomes the `Response` body, and a `register_shutdown_function`
  guard flushes that buffer if a plugin/template `exit()`s — so "the plugin took over and exited"
  still yields a complete response. See the compatibility contract at the top of
  `includes/Http/Kernel.php`.

## 2. Database — `yourls_get_db()` → Doctrine DBAL

- `\YOURLS\Database\YDB` no longer extends `Aura\Sql\ExtendedPdo`; it wraps a Doctrine DBAL
  `Connection` (built by `\YOURLS\Database\DoctrineConnector`, which honours the historical
  `db_connect_*` filters).
- **Every legacy method + return contract is preserved**: `fetchObject()`→`stdClass|false`,
  `fetchObjects()`→`stdClass[]`, `fetchOne()`→`array|false`, `fetchPairs()`→assoc,
  `fetchValue()`→scalar|false, `fetchCol()`→list, `fetchAffected()`→int, `perform()`→`Result`.
  Named `:placeholder` binds keep working. DBAL exceptions are translated to `\PDOException` so
  existing `catch (PDOException|Exception)` blocks are unaffected.
- All queries route through `fetch_wrapper()`, so the `shunt_fetch_wrapper` and
  `fetch_wrapper_statement` plugin filters still fire.
- **QueryBuilder** is used for the option CRUD (`includes/Database/Options.php`) and the schema
  migration, via `YDB::createQueryBuilder()`.

## 3. Secure dynamic table prefixes

Table names derive from the admin-defined `YOURLS_DB_PREFIX` and are interpolated as SQL
identifiers (they cannot be bound as parameters). `\YOURLS\Database\TablePrefix` is the single choke
point that **validates** every prefix/table name against a strict `^[A-Za-z0-9_]+$` whitelist and
backtick-quotes it before it reaches the QueryBuilder — closing the identifier-injection vector.
Used by `Options`, the migration, and `MigrationsFactory`.

## 4. Install + migrations

- `includes/Database/Migrations/Version00010000000000.php` — creates the `url`, `options`, `log`
  tables byte-for-byte identical to the legacy `yourls_create_sql_tables()` (same collations,
  indexes, the historical composite options PK).
- `\YOURLS\Database\MigrationsFactory` — wires Doctrine Migrations to the live DBAL connection via
  `ConfigurationArray` + `ExistingConnection` + `DependencyFactory::fromConnection()`, and
  `migrateToLatest()` runs pending migrations.
- `\YOURLS\Console\Installer` orchestrates preflight → schema (migrations, legacy fallback) → seed
  (options + sample links) → rewrite rules, reusing the existing YOURLS functions so behaviour
  matches the classic installer.
- `bin/console` boots YOURLS in installing/admin context (so the init redirects don't fire) and
  runs the Symfony Console `Application`, which registers `yourls:install` plus Doctrine's
  `migrations:*` commands.

## Compatibility notes

- `aura/sql` is still a dependency (harmless) but no longer used by the core DB path.
- `perform()`/`query()` now return a `Doctrine\DBAL\Result` instead of a `PDOStatement`. No core
  caller depends on `PDOStatement`-specific methods; the one `true === perform(...)` check in the
  upgrade code was already always-false and remains so.
- Other entry points (`admin/index.php`, `yourls-api.php`, `yourls-go.php`, `yourls-infos.php`)
  are unchanged and keep working through the preserved function layer.
