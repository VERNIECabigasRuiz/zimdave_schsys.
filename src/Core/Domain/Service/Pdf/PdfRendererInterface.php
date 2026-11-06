<?php

declare(strict_types=1);

namespace App\Core\Domain\Service\Pdf;

/** Turns an HTML page into a PDF. The HTML may not load files or URLs; embed images as data URIs. */
interface PdfRendererInterface
{
    /**
     * @param string|list<float|int>|null $paper a paper name ("A4") or [0, 0, width, height] in points;
     *                                           null leaves the size to the page's @page rule
     */
    public function render(string $html, string|array|null $paper = null, string $orientation = 'portrait'): string;
}
