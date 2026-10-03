<?php

declare(strict_types=1);

namespace ADT\RequestLogger\Tests;

use ADT\RequestLogger\Tests\Fixtures\TestRequestLog;
use ADT\RequestLogger\Tests\Fixtures\TestRequestLogBody;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * RequestLogTrait and RequestLogBodyTrait - the operational request log and its body.
 */
final class LogEntitiesTest extends TestCase
{
	public function testRequestLogCarriesMethodUrlCodeAndIp(): void
	{
		$log = new TestRequestLog();
		$createdAt = new DateTimeImmutable('2026-03-01 12:00:00.123');

		$log->setCreatedAt($createdAt)
			->setMethod('POST')
			->setUrl('https://admin.example.com/api/orders')
			->setCode(201)
			->setIp('2001:db8::1');

		self::assertSame($createdAt, $log->getCreatedAt());
		self::assertSame('POST', $log->getMethod());
		self::assertSame('https://admin.example.com/api/orders', $log->getUrl());
		self::assertSame(201, $log->getCode());
		self::assertSame('2001:db8::1', $log->getIp());
	}

	public function testRequestOriginIsEitherIdentityOrApiKey(): void
	{
		$log = new TestRequestLog();

		self::assertNull($log->getIdentityId());
		self::assertNull($log->getApiKeyId());
		self::assertNull($log->getCorrelationId());

		self::assertSame(15, $log->setIdentityId(15)->getIdentityId());
		self::assertSame(3, $log->setApiKeyId(3)->getApiKeyId());
		self::assertSame('export-42', $log->setCorrelationId('export-42')->getCorrelationId());
	}

	public function testResponseTimeIsStoredToTenthOfMillisecond(): void
	{
		// Scale 4; with scale 2 all requests under 10 ms would be indistinguishable.
		$log = new TestRequestLog();

		self::assertNull($log->getResponseTime());
		self::assertSame(0.0123, $log->setResponseTime(0.0123)->getResponseTime());
		self::assertSame(0.0123, $log->setResponseTime(0.01234)->getResponseTime());
		self::assertSame(0.0124, $log->setResponseTime(0.012356)->getResponseTime());
		self::assertSame(12.5, $log->setResponseTime(12.5)->getResponseTime());
		self::assertNull($log->setResponseTime(null)->getResponseTime());
	}

	public function testRequestBodyIsEntirelyOptional(): void
	{
		$body = new TestRequestLogBody();

		self::assertNull($body->getHeaders());
		self::assertNull($body->getParams());
		self::assertNull($body->getPostData());
		self::assertNull($body->getRawDataJson());
		self::assertNull($body->getRawDataText());
		self::assertNull($body->getResponseJson());
		self::assertNull($body->getResponseText());
	}

	public function testRequestBodyIsLinkedToRequestLog(): void
	{
		$log = new TestRequestLog();
		$body = new TestRequestLogBody();

		$body->setRequestLog($log)
			->setHeaders(['content-type' => 'application/json'])
			->setParams(['page' => '2'])
			->setPostData('a=1')
			->setRawDataJson(['a' => 1])
			->setRawDataText('{not json')
			->setResponseJson(['ok' => true])
			->setResponseText('OK');

		self::assertSame($log, $body->getRequestLog());
		self::assertSame(['content-type' => 'application/json'], $body->getHeaders());
		self::assertSame(['page' => '2'], $body->getParams());
		self::assertSame('a=1', $body->getPostData());
		self::assertSame(['a' => 1], $body->getRawDataJson());
		self::assertSame('{not json', $body->getRawDataText());
		self::assertSame(['ok' => true], $body->getResponseJson());
		self::assertSame('OK', $body->getResponseText());
	}
}
