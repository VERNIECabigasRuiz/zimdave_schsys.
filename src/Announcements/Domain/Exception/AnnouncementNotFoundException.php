<?php

declare(strict_types=1);

namespace App\Announcements\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** No announcement has the given id. */
final class AnnouncementNotFoundException extends DomainException
{
    public const ERROR_CODE = 'ANNOUNCEMENT_NOT_FOUND';

    public function __construct(string $message = 'The announcement was not found.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
