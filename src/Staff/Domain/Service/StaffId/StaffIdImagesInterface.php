<?php

declare(strict_types=1);

namespace App\Staff\Domain\Service\StaffId;

/** The images a staff ID prints, as data URIs (the PDF may not load files or URLs). */
interface StaffIdImagesInterface
{
    /** A stored photo as JPEG, or null when there is none or it can't be read. */
    public function photo(?string $path): ?string;

    /** A stored school logo as PNG, or null when there is none or it can't be read. */
    public function logo(?string $path): ?string;

    /** A stored signature as PNG, keeping its transparency; null when there is none or it can't be read. */
    public function signature(?string $path): ?string;

    /** The DepEd seal, or null when it is missing. */
    public function seal(): ?string;
}
