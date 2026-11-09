<?php

declare(strict_types=1);

namespace App\Learners\Domain\Service\LearnerId;

/** The images an ID card prints, as data URIs (the PDF may not load files or URLs). */
interface IdCardImagesInterface
{
    /** A stored school logo, or null when the file can't be read. */
    public function logo(string $path): ?string;

    /** A stored learner photo, or null when the file can't be read. */
    public function photo(string $path): ?string;

    /** The DepEd seal, or null when it is missing. */
    public function seal(): ?string;

    /** A QR code of $text. */
    public function qr(string $text): string;
}
