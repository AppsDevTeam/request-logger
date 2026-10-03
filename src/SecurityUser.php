<?php

declare(strict_types=1);

namespace ADT\RequestLogger;

/**
 * All the logger needs to know about the user. The project's security user
 * (typically a wrapper around Nette\Security\User) just implements this
 * interface, no other coupling is needed.
 */
interface SecurityUser
{
	public function isLoggedIn(): bool;

	public function getId();
}
