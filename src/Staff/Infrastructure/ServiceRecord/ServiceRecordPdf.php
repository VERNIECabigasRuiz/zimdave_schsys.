<?php

declare(strict_types=1);

namespace App\Staff\Infrastructure\ServiceRecord;

use App\Core\Domain\Service\Pdf\PdfRendererInterface;
use App\Staff\Domain\Service\ServiceRecord\ServiceRecordPdfInterface;
use App\Staff\Domain\Service\ServiceRecord\ServiceRecordPrintout;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/** The DepEd service record on long bond (8.5 × 13 in, portrait), with the DepEd seal. */
class ServiceRecordPdf implements ServiceRecordPdfInterface
{
    /** 8.5 × 13 in in points. */
    private const LONG_BOND = [0, 0, 612, 936];
    private const SEAL = '/resources/images/deped-seal.png';

    public function __construct(
        private readonly PdfRendererInterface $pdf,
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function render(ServiceRecordPrintout $printout): string
    {
        return $this->pdf->render($this->html($printout), self::LONG_BOND);
    }

    public function html(ServiceRecordPrintout $printout): string
    {
        $seal = $this->projectDir . self::SEAL;

        return $this->twig->render('@Staff/service_record/pdf.html.twig', [
            'school' => $printout->school,
            'sheet' => $printout->sheet,
            'record' => $printout->record,
            'rows' => $printout->rows,
            'certified_name' => $printout->certifiedName,
            'certified_designation' => $printout->certifiedDesignation,
            'printed_on' => $printout->printedOn,
            'seal' => is_file($seal)
                ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($seal))
                : null,
        ]);
    }
}
