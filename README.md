# ADT Request Logger

Logs HTTP requests (and optionally responses) of a Nette application into the
`request_log` and `request_log_body` tables.

```
composer require adt/request-logger
```

## Why

When investigating an incident or a customer complaint, you need to know exactly
what the client sent and what it got back — including headers and body. The logger
stores this in two tables with different retention: the request header (method,
URL, status code, IP, response time) is kept for a long time, while the bulky body
(headers, parameters, payload, response) is purged sooner.

- Sensitive data (passwords, tokens, card numbers) is stripped by
  [adt/log-sanitizer](https://github.com/AppsDevTeam/log-sanitizer) before anything is written.
- The header and the body are written in **a single transaction** — a concurrent
  log move/purge never catches an orphaned header without its body.
- Timestamps are always UTC with milliseconds, for correlation with the audit log
  and to stay unambiguous across the DST switch.
- Writes go through **a dedicated database connection** (outside the Doctrine
  EntityManager), so rolling back the application's transaction does not discard the log.
- A logging failure never breaks the request — it is logged via Tracy and the
  request carries on.

## Usage

Register the service (it opens its own connection, so it takes connection
parameters, not a `Connection`):

```neon
services:
	- ADT\RequestLogger\RequestLogger(%database%)
```

Your project's security user implements the minimal
`ADT\RequestLogger\SecurityUser` interface (`isLoggedIn()`, `getId()`).

In your base presenter:

```php
protected function shutdown(Nette\Application\Response $response): void
{
	$this->requestLogger->logRequest($this, $response);
}
```

Only requests of a logged-in user, or requests carrying an API key
(`RequestLogger::$apiKeyId`), are logged. Anonymous traffic is not.

### Optional switches

```php
// log the response body as well (mind the volume)
RequestLogger::$logResponse = true;

// the request came with an API key - log it even without a logged-in user
RequestLogger::$apiKeyId = $apiKey->getId();
```

### Labelling a request

Two optional columns the trait already provides; fill them at any point while the
request is being processed:

```php
// the id of the operation the request carried - the same value as audit_log.correlation_id,
// so an audit event leads to the request including its payload in a single query
RequestLogger::addValue('correlation_id', $transactionId);

// what happened to the request - a category from a closed set of project constants,
// so requests can be looked up by outcome, not only by URL and status code
RequestLogger::addValue('identifier', 'duplicate_order');
```

The two are complementary: `correlation_id` points at one record elsewhere,
`identifier` sorts requests into buckets.

### Custom columns

A project can add columns of its own to `request_log` the same way:

```php
RequestLogger::addValue('device_id', $deviceId);
```

System columns (`created_at`, `method`, `url`, `ip`, `code`, `response_time`,
`identity_id`, `api_key_id`) cannot be overridden.

## Entities

The package provides interfaces and traits; the entities themselves are declared
by the project:

```php
#[ORM\Entity]
#[ORM\Index(fields: ['createdAt'])]
class RequestLog implements \ADT\RequestLogger\Entities\RequestLog
{
	use Identifier;
	use \ADT\RequestLogger\Entities\RequestLogTrait;
}

#[ORM\Entity]
#[ORM\Index(fields: ['createdAt'])]
class RequestLogBody implements \ADT\RequestLogger\Entities\RequestLogBody
{
	use Identifier;
	use \ADT\RequestLogger\Entities\RequestLogBodyTrait;
}
```

**WARNING:** Doctrine reads `#[Index]` only from the entity and ignores it on
traits — the project must declare the indexes itself. `createdAt` is mandatory,
otherwise retention purging will scan the whole table; add `correlationId` and
`identifier` wherever they are searched.

## Tests

```
composer test
```
