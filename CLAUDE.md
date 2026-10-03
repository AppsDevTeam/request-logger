# CLAUDE.md

Guidance for Claude Code (claude.ai/code) when working in this repository.

## What this is

`adt/request-logger` is a PHP library (PHP >=8.4) that logs HTTP requests (and optionally responses) of a Nette application into the `request_log` and `request_log_body` tables. It was extracted from `adt/fancyadmin` (`ADT\FancyAdmin\Model\RequestLogger`) and must not depend on fancyadmin - the user is abstracted by the minimal `ADT\RequestLogger\SecurityUser` interface (`isLoggedIn()`, `getId()`).

User-facing documentation is in `README.md`.

## Conventions

- **Everything is in English** - code comments, docblocks, README, test names, test data and runtime messages (e.g. the Tracy log message `RequestLogger failed`). Commit messages follow the ADT convention and are in Czech.
- **Tests use PHPUnit, not Nette Tester**, even though most other ADT components use Tester. PHPUnit was chosen so the package can later move to Codeception (which is built on PHPUnit) with a mechanical change. Keep the PHPUnit major version within the range supported by the current Codeception release (Codeception 5.3 supports PHPUnit 9-13).
- Tests are `TestCase` classes in `tests/` (namespace `ADT\RequestLogger\Tests`), fixtures one class per file in `tests/Fixtures/`, loaded via `autoload-dev`.

## Commands

```bash
composer install
composer test                                          # vendor/bin/phpunit
vendor/bin/phpunit --filter testAnonymousRequestIsNotLogged
```

Tests need no database - they use SQLite (`pdo_sqlite`) where a connection is required.

## Things that are easy to break

- **Static state.** `RequestLogger::$logResponse`, `$apiKeyId` and `$extraLogData` are static, and PHPUnit runs all tests in one process. `RequestLoggerTest` resets them (and `Tracy\Debugger::$logDirectory`) in `setUp()`/`tearDown()`; any new test touching the logger must rely on that.
- **Single transaction.** `writeLog()` inserts the header and the body in one transaction, so a concurrent log move (`fancyadmin:move-logs`) never sees a header without its body. Do not split it.
- **Logging never throws.** `logRequest()` catches everything and logs it via Tracy as CRITICAL; a logging failure must not break the request.
- **Own connection.** The logger opens its own DBAL connection from `$dbParams` (outside the Doctrine EntityManager) so an application rollback does not discard the log.
- **JSON depth.** `MAX_JSON_COLUMN_DEPTH = 100` matches MySQL's `json` column limit; deeper bodies are stored in the `*_text` columns instead.
- **UTC + one timestamp.** Header and body share the same UTC `created_at` with microseconds; retention purging compares them.
- **Indexes on traits are ignored by Doctrine.** `RequestLogTrait`/`RequestLogBodyTrait` cannot declare `#[ORM\Index]`; the consuming project entity must (see README).
