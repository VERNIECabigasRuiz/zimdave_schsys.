<?php

namespace App\Service;

/**
 * The school's plan does not allow the change. The message is safe to show to the user.
 */
final class PlanLimitException extends \DomainException
{
}
