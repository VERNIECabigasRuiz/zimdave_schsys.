<?php

declare(strict_types=1);

namespace App\Learners\Application\Service;

enum LookupKind
{
    case New;
    case NotEnrolled;
    case Here;
    case Elsewhere;
}
