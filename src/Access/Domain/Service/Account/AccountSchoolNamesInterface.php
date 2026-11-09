<?php

declare(strict_types=1);

namespace App\Access\Domain\Service\Account;

/** The school each account belongs to this school year; implemented by the schools module. */
interface AccountSchoolNamesInterface
{
    /** @return array<int, string> school name by account id */
    public function schoolNamesByAccount(): array;
}
