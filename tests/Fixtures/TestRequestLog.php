<?php

declare(strict_types=1);

namespace ADT\RequestLogger\Tests\Fixtures;

use ADT\DoctrineComponents\Entities\Traits\Identifier;
use ADT\RequestLogger\Entities\RequestLog;
use ADT\RequestLogger\Entities\RequestLogTrait;

final class TestRequestLog implements RequestLog
{
	use Identifier;
	use RequestLogTrait;
}
