<?php

declare(strict_types=1);

namespace ADT\RequestLogger\Entities;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Column;

trait RequestLogBodyTrait
{
	#[ORM\OneToOne(targetEntity: 'RequestLog')]
	#[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
	protected RequestLog $requestLog;

	/**
	 * Its own timestamp, even though it is the same instant as the parent's - bodies have
	 * a SHORTER retention (typically a month versus half a year) and must be purged
	 * separately. Going through the parent would need a subquery or a join, on what is
	 * the largest table in the database. With its own column it is a plain DELETE by index.
	 *
	 * Always written in UTC, same as the parent's created_at - see RequestLogger.
	 *
	 * WARNING: Doctrine reads #[Index] only from the entity and IGNORES it on a trait. The
	 * project entity must therefore declare the index itself, otherwise retention purging
	 * will scan the whole table:
	 *
	 *   #[ORM\Index(fields: ['createdAt'])]
	 */
	#[Column]
	protected \DateTimeImmutable $createdAt;

	#[Column(type: 'json', nullable: true)]
	protected ?array $headers = null;

	#[Column(type: 'json', nullable: true)]
	protected ?array $params = null;

	#[Column(type: 'text', nullable: true)]
	protected ?string $postData = null;

	#[Column(type: 'json', nullable: true)]
	protected ?array $rawDataJson = null;

	#[Column(type: 'text', nullable: true)]
	protected ?string $rawDataText = null;

	#[Column(type: 'json', nullable: true)]
	protected ?array $responseJson = null;

	#[Column(type: 'text', nullable: true)]
	protected ?string $responseText = null;

	public function getCreatedAt(): \DateTimeImmutable
	{
		return $this->createdAt;
	}

	public function getRequestLog(): RequestLog
	{
		return $this->requestLog;
	}

	public function setRequestLog(RequestLog $requestLog): static
	{
		$this->requestLog = $requestLog;
		return $this;
	}

	public function getHeaders(): ?array
	{
		return $this->headers;
	}

	public function setHeaders(?array $headers): static
	{
		$this->headers = $headers;
		return $this;
	}

	public function getParams(): ?array
	{
		return $this->params;
	}

	public function setParams(?array $params): static
	{
		$this->params = $params;
		return $this;
	}

	public function getPostData(): ?string
	{
		return $this->postData;
	}

	public function setPostData(?string $postData): static
	{
		$this->postData = $postData;
		return $this;
	}

	public function getRawDataJson(): ?array
	{
		return $this->rawDataJson;
	}

	public function setRawDataJson(?array $rawDataJson): static
	{
		$this->rawDataJson = $rawDataJson;
		return $this;
	}

	public function getRawDataText(): ?string
	{
		return $this->rawDataText;
	}

	public function setRawDataText(?string $rawDataText): static
	{
		$this->rawDataText = $rawDataText;
		return $this;
	}

	public function getResponseJson(): ?array
	{
		return $this->responseJson;
	}

	public function setResponseJson(?array $responseJson): static
	{
		$this->responseJson = $responseJson;
		return $this;
	}

	public function getResponseText(): ?string
	{
		return $this->responseText;
	}

	public function setResponseText(?string $responseText): static
	{
		$this->responseText = $responseText;
		return $this;
	}
}
