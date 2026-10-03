<?php

declare(strict_types=1);

namespace ADT\RequestLogger\Tests;

use ADT\LogSanitizer\SensitiveDataSanitizer;
use ADT\RequestLogger\RequestLogger;
use ADT\RequestLogger\Tests\Fixtures\TestPresenter;
use ADT\RequestLogger\Tests\Fixtures\TestSecurityUser;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Nette\Application\Responses\TextResponse;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use Tracy\Debugger;

/**
 * RequestLogger - writing requests into the request_log table.
 *
 * Covers what does not need a database: custom project columns and the condition
 * under which a request is not logged at all.
 */
final class RequestLoggerTest extends TestCase
{
	private ?string $originalLogDirectory;
	private string $logDir;

	protected function setUp(): void
	{
		// All tests run in a single process - the logger's static state must be reset,
		// otherwise it would leak over from the previous test.
		self::resetRequestLogger();

		$this->originalLogDirectory = Debugger::$logDirectory;
		$this->logDir = sys_get_temp_dir() . '/request-logger-tests/' . getmypid() . '-' . bin2hex(random_bytes(4));
		mkdir($this->logDir, 0777, recursive: true);
		Debugger::$logDirectory = $this->logDir;
	}

	protected function tearDown(): void
	{
		self::resetRequestLogger();

		Debugger::$logDirectory = $this->originalLogDirectory;
		array_map(unlink(...), glob($this->logDir . '/*') ?: []);
		@rmdir($this->logDir);
	}

	public function testCustomColumnsAreAdded(): void
	{
		self::assertSame([], self::extraLogData());

		RequestLogger::addValue('device_id', 'abc');
		RequestLogger::addValue('correlation_id', 'export-42');

		self::assertSame(['device_id' => 'abc', 'correlation_id' => 'export-42'], self::extraLogData());
	}

	public function testSameColumnIsOverwritten(): void
	{
		RequestLogger::addValue('device_id', 'first');
		RequestLogger::addValue('device_id', 'second');

		self::assertSame(['device_id' => 'second'], self::extraLogData());
	}

	public function testColumnValueCanBeAnything(): void
	{
		RequestLogger::addValue('count', 42);
		RequestLogger::addValue('flag', true);
		RequestLogger::addValue('nothing', null);

		self::assertSame(['count' => 42, 'flag' => true, 'nothing' => null], self::extraLogData());
	}

	public function testLoggingSwitchesAreOffByDefault(): void
	{
		self::assertFalse(RequestLogger::$logResponse);
		self::assertNull(RequestLogger::$apiKeyId);
	}

	public function testAnonymousRequestIsNotLogged(): void
	{
		// Without this, every request of an anonymous visitor would create a row in request_log.
		// There is no database in the test - if it tried to log, it would fail and the failure
		// would be written to critical.log.
		self::createLogger(isLoggedIn: false)->logRequest(new TestPresenter(), new TextResponse('ok'));

		self::assertFileDoesNotExist($this->logDir . '/critical.log');
	}

	public function testRequestWithApiKeyIsLoggedEvenWithoutLogin(): void
	{
		RequestLogger::$apiKeyId = 7;

		// Logging starts (does not return early) and fails only on the unassembled request.
		// A logging failure must never break the request - it is logged and the request carries on.
		self::createLogger(isLoggedIn: false)->logRequest(new TestPresenter(), new TextResponse('ok'));

		self::assertStringContainsString('RequestLogger failed', file_get_contents($this->logDir . '/critical.log'));
	}

	public function testJsonDepthIsLimitedToMysqlLimit(): void
	{
		// MySQL accepts 100 levels in a json column, PHP parses up to 512 - a body between these
		// limits would pass through the application and only break on insert.
		self::assertSame(100, new ReflectionClassConstant(RequestLogger::class, 'MAX_JSON_COLUMN_DEPTH')->getValue());
	}

	public function testWhenBodyFailsNoHeaderIsLeftBehind(): void
	{
		// Header and body are two inserts, but one transaction. Without it there is a moment
		// when the parent is already in the database but the body is not - and a concurrent
		// log move can move it away and delete it at that moment, so the body fails on the
		// foreign key:
		//   Cannot add or update a child row: a foreign key constraint fails
		// Here the same is simulated by a missing body table: when writing the body fails,
		// no orphaned header may be left behind.
		$file = tempnam(sys_get_temp_dir(), 'requestlog') . '.sqlite';
		$dbParams = ['driver' => 'pdo_sqlite', 'path' => $file];

		try {
			$connection = DriverManager::getConnection($dbParams);
			$connection->executeStatement('CREATE TABLE request_log (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT)');
			// request_log_body intentionally does not exist

			$logger = new RequestLogger($dbParams, new TestSecurityUser(isLoggedIn: true), new SensitiveDataSanitizer());
			$writeLog = new ReflectionMethod(RequestLogger::class, 'writeLog');

			$failed = false;
			try {
				$writeLog->invoke($logger, $connection, ['created_at' => '2026-09-20 12:00:00'], ['headers' => null]);
			} catch (DbalException) {
				$failed = true;
			}

			self::assertTrue($failed, 'writing the body should have failed');
			self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM request_log'), 'the header must have been rolled back');
		} finally {
			@unlink($file);
		}
	}

	private static function extraLogData(): array
	{
		return new ReflectionProperty(RequestLogger::class, 'extraLogData')->getValue();
	}

	private static function resetRequestLogger(): void
	{
		new ReflectionProperty(RequestLogger::class, 'extraLogData')->setValue(null, []);
		RequestLogger::$apiKeyId = null;
		RequestLogger::$logResponse = false;
	}

	private static function createLogger(bool $isLoggedIn): RequestLogger
	{
		return new RequestLogger(
			['driver' => 'pdo_sqlite', 'memory' => true],
			new TestSecurityUser(isLoggedIn: $isLoggedIn),
			new SensitiveDataSanitizer(),
		);
	}
}
