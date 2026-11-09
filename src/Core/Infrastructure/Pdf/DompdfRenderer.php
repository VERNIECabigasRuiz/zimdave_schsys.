<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Pdf;

use App\Core\Domain\Service\Pdf\PdfRendererInterface;
use Dompdf\Dompdf;
use Dompdf\Options;

class DompdfRenderer implements PdfRendererInterface
{
    public function render(string $html, string|array|null $paper = null, string $orientation = 'portrait'): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        if (null !== $paper) {
            $dompdf->setPaper($paper, $orientation);
        }
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
