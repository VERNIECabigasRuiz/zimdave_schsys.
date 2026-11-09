<?php

declare(strict_types=1);

namespace App\Core\Domain\Service\FileStorage;

use App\Core\Domain\Exception\ImageProcessingException;

/**
 * Stores profile photos, school logos and ID signatures as small WebP files.
 *
 * Stored paths are relative to the public directory so templates can pass them to asset().
 */
interface ImageProcessorInterface
{
    /** Passport photo ratio on the PDS: 3.5 cm × 4.5 cm. */
    public const PHOTO_WIDTH = 350;
    public const PHOTO_HEIGHT = 450;
    public const LOGO_MAX = 400;
    public const SIGNATURE_MAX = 600;
    public const MAX_PIXELS = 25_000_000;

    /**
     * Stores the image at $sourcePath and deletes $oldPath.
     *
     * @throws ImageProcessingException when the file isn't a usable image
     */
    public function storePhoto(string $sourcePath, ?string $oldPath): string;

    /** @throws ImageProcessingException when the file isn't a usable image */
    public function storeLogo(string $sourcePath, ?string $oldPath): string;

    /**
     * A signature for IDs, transparency kept.
     *
     * @throws ImageProcessingException when the file isn't a usable image
     */
    public function storeSignature(string $sourcePath, ?string $oldPath): string;

    /** Deletes a stored image; ignores null and anything that isn't one of ours. */
    public function remove(?string $path): void;

    public function absolutePath(string $path): string;
}
