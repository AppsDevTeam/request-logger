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
 * RequestLogger - zapis pozadavku do tabulky request_log.
 *
 * Testuje se to, co nepotrebuje databazi: vlastni sloupce projektu a podminka, kdy se
 * pozadavek vubec neloguje.
 */
final class RequestLoggerTest extends TestCase
{
	private ?string $originalLogDirectory;
	private string $logDir;

	protected function setUp(): void
	{
		// Vsechny testy bezi v jednom procesu - staticky stav loggeru se musi vynulovat,
		// jinak by se prenasel z predchoziho testu.
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

	public function testVlastniSloupceSePridavaji(): void
	{
		self::assertSame([], self::extraLogData());

		RequestLogger::addValue('device_id', 'abc');
		RequestLogger::addValue('correlation_id', 'export-42');

		self::assertSame(['device_id' => 'abc', 'correlation_id' => 'export-42'], self::extraLogData());
	}

	public function testStejnySloupecSePrepise(): void
	{
		RequestLogger::addValue('device_id', 'prvni');
		RequestLogger::addValue('device_id', 'druhy');

		self::assertSame(['device_id' => 'druhy'], self::extraLogData());
	}

	public function testHodnotaSloupceMuzeBytCokoliv(): void
	{
		RequestLogger::addValue('pocet', 42);
		RequestLogger::addValue('priznak', true);
		RequestLogger::addValue('nic', null);

		self::assertSame(['pocet' => 42, 'priznak' => true, 'nic' => null], self::extraLogData());
	}

	public function testPrepinaceLogovaniJsouVeVychozimStavuVypnute(): void
	{
		self::assertFalse(RequestLogger::$logResponse);
		self::assertNull(RequestLogger::$apiKeyId);
	}

	public function testAnonymniPozadavekSeNeloguje(): void
	{
		// Bez toho by kazdy pozadavek neprihlaseneho navstevnika zakladal radek v request_log.
		// Databaze v testu neexistuje - kdyby se logovalo, spadne to a selhani se zapise
		// do critical.log.
		self::createLogger(isLoggedIn: false)->logRequest(new TestPresenter(), new TextResponse('ok'));

		self::assertFileDoesNotExist($this->logDir . '/critical.log');
	}

	public function testPozadavekSApiKlicemSeLogujeIBezPrihlaseni(): void
	{
		RequestLogger::$apiKeyId = 7;

		// Logovani se spusti (nevratilo se hned) a spadne az na nesestavenem pozadavku.
		// Selhani logovani nikdy nesmi shodit request - zaloguje se a jede se dal.
		self::createLogger(isLoggedIn: false)->logRequest(new TestPresenter(), new TextResponse('ok'));

		self::assertStringContainsString('RequestLogger selhal', file_get_contents($this->logDir . '/critical.log'));
	}

	public function testHloubkaJsonJeOmezenaLimitemMysql(): void
	{
		// MySQL pusti do sloupce json 100 urovni, PHP parsuje do 512 - telo mezi temito limity
		// by proslo aplikaci a rozbilo se az pri insertu.
		self::assertSame(100, new ReflectionClassConstant(RequestLogger::class, 'MAX_JSON_COLUMN_DEPTH')->getValue());
	}

	public function testKdyzSelzeTeloNezustanePoPozadavkuAniHlavicka(): void
	{
		// Hlavicka a telo jsou dva inserty, ale jedna transakce. Bez ni je mezi nimi okamzik,
		// kdy rodic uz v databazi je a telo jeste ne - a soubezny odvoz logu ho v tu chvili
		// muze odvezt a smazat, takze telo spadne na cizim klici:
		//   Cannot add or update a child row: a foreign key constraint fails
		// Tady se totez nasimuluje chybejici tabulkou tela: kdyz zapis tela selze, nesmi po
		// pozadavku zustat osirela hlavicka.
		$soubor = tempnam(sys_get_temp_dir(), 'requestlog') . '.sqlite';
		$dbParams = ['driver' => 'pdo_sqlite', 'path' => $soubor];

		try {
			$connection = DriverManager::getConnection($dbParams);
			$connection->executeStatement('CREATE TABLE request_log (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT)');
			// request_log_body schvalne neexistuje

			$logger = new RequestLogger($dbParams, new TestSecurityUser(isLoggedIn: true), new SensitiveDataSanitizer());
			$zapis = new ReflectionMethod(RequestLogger::class, 'writeLog');

			$selhalo = false;
			try {
				$zapis->invoke($logger, $connection, ['created_at' => '2026-09-20 12:00:00'], ['headers' => null]);
			} catch (DbalException) {
				$selhalo = true;
			}

			self::assertTrue($selhalo, 'zapis tela mel selhat');
			self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM request_log'), 'hlavicka se musela vratit zpet');
		} finally {
			@unlink($soubor);
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
