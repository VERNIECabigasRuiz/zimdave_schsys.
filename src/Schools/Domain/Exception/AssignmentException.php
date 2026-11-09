<?php

declare(strict_types=1);

namespace App\Schools\Domain\Exception;

use App\Core\Domain\Exception\DomainException;

/**
 * A school assignment rule was broken. The message is safe to show to the admin.
 */
final class AssignmentException extends DomainException
{
    public const ERROR_CODE = 'ASSIGNMENT_REFUSED';

    /** @param int|null $schoolYearId the school year the refusal is about, for the page to go back to */
    public function __construct(string $message, private readonly ?int $schoolYearId = null)
    {
        parent::__construct($message, self::ERROR_CODE);
    }

    public function schoolYearId(): ?int
    {
        return $this->schoolYearId;
    }

    /** The same refusal, about $schoolYearId. */
    public function forSchoolYear(?int $schoolYearId): self
    {
        return new self($this->getMessage(), $schoolYearId);
    }
}
