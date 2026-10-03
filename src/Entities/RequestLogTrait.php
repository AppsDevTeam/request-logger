<?php

declare(strict_types=1);

namespace ADT\RequestLogger\Entities;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Column;

trait RequestLogTrait
{
	/**
	 * UTC, with milliseconds. Sub-seconds are needed to order requests
	 * within a single second - without them, when tracing an incident, you
	 * cannot tell what happened first. A plain DATETIME would silently drop them.
	 */
	#[ORM\Column(columnDefinition: 'DATETIME(3) NOT NULL')]
	protected DateTimeImmutable $createdAt;

	#[ORM\Column(type: 'integer', nullable: true)]
	protected ?int $identityId = null;

	#[ORM\Column(type: 'integer', nullable: true)]
	protected ?int $apiKeyId = null;

	#[ORM\Column(type: 'text')]
	protected string $url;

	#[ORM\Column(length: 7)]
	protected string $method;

	#[ORM\Column]
	protected int $code;

	// 45 = maximum for IPv6 (including the IPv4-mapped form). 15 would only fit
	// IPv4 and the first IPv6 client would break the insert.
	#[ORM\Column(length: 45)]
	protected string $ip;

	// scale 4 = 0.1 ms resolution; with scale 2 all requests under 10 ms were
	// indistinguishable (0.00 vs 0.01)
	#[Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
	protected ?string $responseTime = null;

	/**
	 * Identifier of the operation the request carried - the same value as
	 * audit_log.correlation_id. A bridge between the audit and operational layers:
	 * from an audit event, a single query finds the request including its payload.
	 *
	 * Untyped on purpose: request_log is generic and does not know in advance what
	 * kinds of operations it will carry. Filled via RequestLogger::addValue('correlation_id', ...)
	 * only where the request carries an operation; otherwise it stays NULL.
	 *
	 * WARNING: the index must be declared by the consuming entity - Doctrine ignores
	 * #[Index] attributes on traits.
	 */
	#[ORM\Column(nullable: true)]
	protected ?string $correlationId = null;

	public function getCreatedAt(): DateTimeImmutable
	{
		return $this->createdAt;
	}

	public function setCreatedAt(DateTimeImmutable $createdAt): static
	{
		$this->createdAt = $createdAt;
		return $this;
	}

	public function getIdentityId(): ?int
	{
		return $this->identityId;
	}

	public function setIdentityId(?int $identityId): static
	{
		$this->identityId = $identityId;
		return $this;
	}

	public function getApiKeyId(): ?int
	{
		return $this->apiKeyId;
	}

	public function setApiKeyId(?int $apiKeyId): static
	{
		$this->apiKeyId = $apiKeyId;
		return $this;
	}

	public function getUrl(): string
	{
		return $this->url;
	}

	public function setUrl(string $url): static
	{
		$this->url = $url;
		return $this;
	}

	public function getMethod(): string
	{
		return $this->method;
	}

	public function setMethod(string $method): static
	{
		$this->method = $method;
		return $this;
	}

	public function getCode(): int
	{
		return $this->code;
	}

	public function setCode(int $code): static
	{
		$this->code = $code;
		return $this;
	}

	public function getIp(): string
	{
		return $this->ip;
	}

	public function setIp(string $ip): static
	{
		$this->ip = $ip;
		return $this;
	}

	public function getResponseTime(): ?float
	{
		return $this->responseTime !== null ? round((float) $this->responseTime, 4) : null;
	}

	public function setResponseTime(?float $responseTime): static
	{
		$this->responseTime = $responseTime !== null ? (string) round($responseTime, 4) : null;
		return $this;
	}

	public function getCorrelationId(): ?string
	{
		return $this->correlationId;
	}

	public function setCorrelationId(?string $correlationId): static
	{
		$this->correlationId = $correlationId;
		return $this;
	}
}
