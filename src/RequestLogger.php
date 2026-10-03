<?php

declare(strict_types=1);

namespace ADT\RequestLogger;

use ADT\LogSanitizer\SensitiveDataSanitizer;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Nette\Application\Response;
use Nette\Application\Responses\FileResponse;
use Nette\Application\UI\Presenter;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Throwable;
use Tracy\Debugger;
use Tracy\ILogger;

final class RequestLogger
{
	/**
	 * The deepest nesting MySQL accepts in a `json` column; a deeper document is rejected
	 * with error 3157 (The JSON document exceeds the maximum depth). PHP parses up to
	 * 512 levels, so a body between these two limits passes through the application and
	 * only breaks here.
	 */
	private const int MAX_JSON_COLUMN_DEPTH = 100;

	public static bool $logResponse = false;
	public static ?int $apiKeyId = null;

	/** @var array<string, mixed> Custom project columns for the `request_log` table */
	private static array $extraLogData = [];

	public function __construct(
		private readonly array $dbParams,
		private readonly SecurityUser $securityUser,
		private readonly SensitiveDataSanitizer $sanitizer,
	) {
	}

	/**
	 * Adds a custom column to the request log (the `request_log` table).
	 *
	 * Call it at any point while the request is being processed (typically in a presenter), e.g.:
	 *   RequestLogger::addValue('device_id', $deviceId);
	 *
	 * System columns (created_at, method, url, ip, code, response_time,
	 * identity_id, api_key_id) cannot be overridden – this is for ADDING columns only.
	 */
	public static function addValue(string $column, mixed $value): void
	{
		self::$extraLogData[$column] = $value;
	}

	public function logRequest(Presenter $presenter, Response $response): void
	{
		if (!self::$apiKeyId && !$this->securityUser->isLoggedIn()) {
			return;
		}

		try {
			$this->doLogRequest($presenter, $response);
		} catch (Throwable $e) {
			Debugger::log('RequestLogger failed: ' . $e->getMessage(), ILogger::CRITICAL);
		}
	}

	/**
	 * @throws JsonException
	 * @throws Exception
	 * @throws \Exception
	 */
	private function doLogRequest(Presenter $presenter, Response $response): void
	{
		// Depth is checked together with validity: whatever does not fit into a `json` column
		// is stored as text. Such a request used to break the whole insert into
		// `request_log_body`, losing both the body and the response - sending one was enough
		// to stay out of the log. Text is harder to query, but it is still the full content.
		if (json_validate($presenter->getHttpRequest()->getRawBody(), self::MAX_JSON_COLUMN_DEPTH)) {
			$raw_data_text = null;
			$raw_data_json = Json::decode($presenter->getHttpRequest()->getRawBody(), forceArrays: true);
		} else {
			$raw_data_json = null;
			$raw_data_text = $presenter->getHttpRequest()->getRawBody();
		}

		if (self::$logResponse) {
			if (!$response instanceof FileResponse) {
				ob_start();
				$response->send($presenter->getHttpRequest(), $presenter->getHttpResponse());
				$response = ob_get_clean();
				if (json_validate($response, self::MAX_JSON_COLUMN_DEPTH)) {
					$response_text = null;
					$response_json = Json::decode($response, forceArrays: true);
				} else {
					$response_text = $response;
					$response_json = null;
				}
			} else {
				$response_text = null;
				$response_json = null;
			}
		} else {
			$response_text = null;
			$response_json = null;
		}

		// sanitizeHeaders() drops access-bearing headers entirely (authorization,
		// x-api-key, cookie...) and sanitizes the rest as values
		$headers = $this->sanitizer->sanitizeHeaders($presenter->getHttpRequest()->getHeaders());

		$connection = DriverManager::getConnection($this->dbParams);

		// Thanks to `+`, system columns always take precedence – extra data (see addValue())
		// can only ADD custom columns, never override the default logging.
		// One instant for both parent and body: retention purging compares them with each
		// other (the body has a shorter retention), so they must not differ even by a millisecond
		$createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

		$this->writeLog(
			$connection,
			[
				// UTC - same as audit_log, for correlation and to stay unambiguous across
				// the DST switch (2:30 happens twice)
				'created_at' => $createdAt,
				'method' => $presenter->getHttpRequest()->getMethod(),
				'url' => $presenter->getHttpRequest()->getUrl()->getBaseUrl() . ltrim($presenter->getHttpRequest()->getUrl()->getPath(), '/'),
				// the IP length is controlled by the client (X-Forwarded-For) - must not break the insert
				'ip' => mb_substr((string) $presenter->getHttpRequest()->getRemoteAddress(), 0, 45),
				'code' => $presenter->getHttpResponse()->getCode(),
				'response_time' => (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']),
				'identity_id' => $this->securityUser->isLoggedIn() ? $this->securityUser->getId() : null,
				'api_key_id' => self::$apiKeyId,
			] + self::$extraLogData,
			[
				'created_at' => $createdAt,
				'headers' => $headers ? Json::encode($headers) : null,
				'params' => $_GET ? Json::encode($this->sanitizer->sanitize($_GET)) : null,
				'post_data' => $_POST ? Json::encode($this->sanitizer->sanitize($_POST)) : null,
				'raw_data_json' => $raw_data_json ? Json::encode($this->sanitizer->sanitize($raw_data_json)) : null,
				'raw_data_text' => $raw_data_text === null ? null : $this->sanitizer->sanitize($raw_data_text),
				'response_json' => $response_json ? Json::encode($this->sanitizer->sanitize($response_json)) : null,
				'response_text' => $response_text === null ? null : $this->sanitizer->sanitize($response_text),
			],
		);
	}

	/**
	 * Writes the request header and body in ONE TRANSACTION, even though it is two inserts.
	 *
	 * Otherwise there is a moment between writing the header and the body when the parent
	 * is already in the database but the body is not. A log move (e.g. fancyadmin:move-logs)
	 * runs concurrently and at that moment nothing stops it from moving the parent away and
	 * deleting it from the source - the body then fails on the foreign key:
	 *
	 *   Cannot add or update a child row: a foreign key constraint fails
	 *   (`request_log_body`, CONSTRAINT `FK_...` FOREIGN KEY (`request_log_id`))
	 *
	 * and nothing is left of the request, neither the header nor the body. Within
	 * a transaction the parent does not exist for the move until the body is written too.
	 *
	 * @param array<string, mixed> $requestLog
	 * @param array<string, mixed> $requestLogBody
	 * @throws Exception
	 */
	private function writeLog(Connection $connection, array $requestLog, array $requestLogBody): void
	{
		$connection->beginTransaction();
		try {
			$connection->insert('request_log', $requestLog);
			$connection->insert('request_log_body', ['request_log_id' => $connection->lastInsertId()] + $requestLogBody);
			$connection->commit();
		} catch (Throwable $e) {
			$connection->rollBack();

			throw $e;
		}
	}

}
