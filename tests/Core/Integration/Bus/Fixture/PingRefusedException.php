<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration\Bus\Fixture;

use App\Core\Domain\Exception\DomainException;

final class PingRefusedException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Ping refused.', 'PING_REFUSED');
    }
}
