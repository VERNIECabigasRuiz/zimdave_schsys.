<?php

declare(strict_types=1);

namespace App\Core\Domain\Exception;

abstract class DomainException extends \Exception
{
    private string $errorCode;

    /** @param array<string, mixed> $data */
    public function __construct(
        string $message = '',
        string $code = '',
        ?\Throwable $previous = null,
        private readonly array $data = [],
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $code;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }
}
