<?php

declare(strict_types=1);

namespace ADT\RequestLogger;

/**
 * Jediné, co logger o uživateli potřebuje vědět. Projektový security user
 * (typicky obálka nad Nette\Security\User) tohle rozhraní jen implementuje,
 * žádná další vazba není potřeba.
 */
interface SecurityUser
{
	public function isLoggedIn(): bool;

	public function getId();
}
