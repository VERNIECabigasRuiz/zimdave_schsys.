<?php

namespace App\Service;

/**
 * A learner change was refused for a reason other than the plan limits. The message is safe to show.
 */
class EnrollmentException extends \RuntimeException
{
}
