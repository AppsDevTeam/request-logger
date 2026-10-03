<?php

declare(strict_types=1);

namespace ADT\RequestLogger\Tests\Fixtures;

use ADT\DoctrineComponents\Entities\Traits\Identifier;
use ADT\RequestLogger\Entities\RequestLog;
use ADT\RequestLogger\Entities\RequestLogBody;
use ADT\RequestLogger\Entities\RequestLogBodyTrait;
use ADT\RequestLogger\Entities\RequestLogTrait;

final class TestRequestLog implements RequestLog
{
	use Identifier;
	use RequestLogTrait;
}

final class TestRequestLogBody implements RequestLogBody
{
	use Identifier;
	use RequestLogBodyTrait;
}
