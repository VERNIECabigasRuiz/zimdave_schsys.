<?php

declare(strict_types=1);

namespace App\Access\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/** The account's role can't change; each reason is shown under the role field. */
final class AccountRoleChangeRefusedException extends DomainException
{
    public const ERROR_CODE = 'ROLE_CHANGE_REFUSED';

    /** @param non-empty-list<string> $reasons */
    public function __construct(private readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons), self::ERROR_CODE, data: ['reasons' => $reasons]);
    }

    /** @return non-empty-list<string> */
    public function reasons(): array
    {
        return $this->reasons;
    }
}
