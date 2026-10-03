<?php

declare(strict_types=1);

namespace ADT\RequestLogger\Tests\Fixtures;

use ADT\RequestLogger\SecurityUser;

final class TestSecurityUser implements SecurityUser
{
	public function __construct(
		private readonly bool $isLoggedIn = true,
		private readonly ?int $id = 1,
	) {
	}

	public function isLoggedIn(): bool
	{
		return $this->isLoggedIn;
	}

	public function getId(): ?int
	{
		return $this->isLoggedIn ? $this->id : null;
	}
}
