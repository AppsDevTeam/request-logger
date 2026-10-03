<?php

declare(strict_types=1);

use ADT\RequestLogger\Tests\Fixtures\TestRequestLog;
use ADT\RequestLogger\Tests\Fixtures\TestRequestLogBody;
use Tester\Assert;

/**
 * RequestLogTrait a RequestLogBodyTrait - provozni log pozadavku a jeho telo.
 */

require __DIR__ . '/bootstrap.php';


test('zaznam pozadavku nese metodu, URL, kod a IP', function () {
	$log = new TestRequestLog();
	$createdAt = new DateTimeImmutable('2026-03-01 12:00:00.123');

	$log->setCreatedAt($createdAt)
		->setMethod('POST')
		->setUrl('https://admin.example.com/api/orders')
		->setCode(201)
		->setIp('2001:db8::1');

	Assert::same($createdAt, $log->getCreatedAt());
	Assert::same('POST', $log->getMethod());
	Assert::same('https://admin.example.com/api/orders', $log->getUrl());
	Assert::same(201, $log->getCode());
	Assert::same('2001:db8::1', $log->getIp());
});


test('puvodce pozadavku je bud identita, nebo API klic', function () {
	$log = new TestRequestLog();

	Assert::null($log->getIdentityId());
	Assert::null($log->getApiKeyId());
	Assert::null($log->getCorrelationId());

	Assert::same(15, $log->setIdentityId(15)->getIdentityId());
	Assert::same(3, $log->setApiKeyId(3)->getApiKeyId());
	Assert::same('export-42', $log->setCorrelationId('export-42')->getCorrelationId());
});


test('doba odpovedi se uklada na desetiny milisekundy', function () {
	// Scale 4; se scale 2 by byly vsechny pozadavky pod 10 ms nerozlisitelne.
	$log = new TestRequestLog();

	Assert::null($log->getResponseTime());
	Assert::same(0.0123, $log->setResponseTime(0.0123)->getResponseTime());
	Assert::same(0.0123, $log->setResponseTime(0.01234)->getResponseTime());
	Assert::same(0.0124, $log->setResponseTime(0.012356)->getResponseTime());
	Assert::same(12.5, $log->setResponseTime(12.5)->getResponseTime());
	Assert::null($log->setResponseTime(null)->getResponseTime());
});


test('telo pozadavku je cele nepovinne', function () {
	$body = new TestRequestLogBody();

	Assert::null($body->getHeaders());
	Assert::null($body->getParams());
	Assert::null($body->getPostData());
	Assert::null($body->getRawDataJson());
	Assert::null($body->getRawDataText());
	Assert::null($body->getResponseJson());
	Assert::null($body->getResponseText());
});


test('telo pozadavku se navaze na zaznam pozadavku', function () {
	$log = new TestRequestLog();
	$body = new TestRequestLogBody();

	$body->setRequestLog($log)
		->setHeaders(['content-type' => 'application/json'])
		->setParams(['page' => '2'])
		->setPostData('a=1')
		->setRawDataJson(['a' => 1])
		->setRawDataText('{neni json')
		->setResponseJson(['ok' => true])
		->setResponseText('OK');

	Assert::same($log, $body->getRequestLog());
	Assert::same(['content-type' => 'application/json'], $body->getHeaders());
	Assert::same(['page' => '2'], $body->getParams());
	Assert::same('a=1', $body->getPostData());
	Assert::same(['a' => 1], $body->getRawDataJson());
	Assert::same('{neni json', $body->getRawDataText());
	Assert::same(['ok' => true], $body->getResponseJson());
	Assert::same('OK', $body->getResponseText());
});
