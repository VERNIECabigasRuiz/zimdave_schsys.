<?php

namespace App\Service;

/**
 * The thing is used by a teaching load or timetable and can't be removed or changed.
 * The message is safe to show to the user.
 */
final class InUseException extends \RuntimeException
{
}
