<?php

declare(strict_types=1);

namespace ADT\RequestLogger\Tests\Fixtures;

use Nette\Application\UI\Presenter;

/** Nette\Application\UI\Presenter is abstract - tests need something instantiable. */
final class TestPresenter extends Presenter
{
}
