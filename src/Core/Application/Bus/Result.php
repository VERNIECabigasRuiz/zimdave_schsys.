<?php

declare(strict_types=1);

namespace App\Core\Application\Bus;

final readonly class Result
{
    private function __construct(
        private bool $success,
        private mixed $data = null,
        private ?\Throwable $exception = null,
        private ?string $errorCode = null,
    ) {
    }

    public static function success(mixed $data = null): self
    {
        return new self(success: true, data: $data);
    }

    public static function failure(\Throwable $exception, string $errorCode): self
    {
        return new self(success: false, exception: $exception, errorCode: $errorCode);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isFailure(): bool
    {
        return !$this->success;
    }

    public function data(): mixed
    {
        return $this->data;
    }

    public function exception(): ?\Throwable
    {
        return $this->exception;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }
}
