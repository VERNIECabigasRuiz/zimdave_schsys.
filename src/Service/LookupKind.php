<?php

namespace App\Service;

enum LookupKind
{
    case New;
    case NotEnrolled;
    case Here;
    case Elsewhere;
}
