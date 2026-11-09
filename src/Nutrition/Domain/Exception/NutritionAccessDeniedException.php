<?php

declare(strict_types=1);

namespace App\Nutrition\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The account may not do this with the section's nutrition data. */
final class NutritionAccessDeniedException extends DomainException
{
    public const ERROR_CODE = 'NUTRITION_ACCESS_DENIED';

    public function __construct(string $message = 'Access Denied.')
    {
        parent::__construct($message, self::ERROR_CODE);
    }
}
