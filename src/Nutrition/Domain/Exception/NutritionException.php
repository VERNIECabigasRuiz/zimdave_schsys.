<?php

declare(strict_types=1);

namespace App\Nutrition\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** A nutrition change that was refused; the message is shown to the user. */
final class NutritionException extends DomainException
{
    public const ERROR_CODE = 'NUTRITION_REFUSED';

    public function __construct(string $message)
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
