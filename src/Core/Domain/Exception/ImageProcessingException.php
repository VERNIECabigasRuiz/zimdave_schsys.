<?php

declare(strict_types=1);

namespace App\Core\Domain\Exception;

/** An uploaded image that can't be read or stored. */
final class ImageProcessingException extends DomainException
{
    public const ERROR_CODE = 'IMAGE_NOT_ACCEPTED';

    public function __construct(string $message)
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
