<?php

declare(strict_types=1);

namespace App\Staff\Infrastructure\StaffId;

use App\Core\Domain\Service\Pdf\PdfRendererInterface;
use App\Staff\Domain\Service\StaffId\StaffIdCard;
use App\Staff\Domain\Service\StaffId\StaffIdPdfInterface;
use App\Staff\Domain\Service\StaffId\StaffIdSize;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/** Renders an ID with Dompdf over the school's template backgrounds. */
class StaffIdPdf implements StaffIdPdfInterface
{
    /** 8.5 × 13 in and 8.5 × 11 in, in points */
    private const PAPER = ['big' => [0, 0, 612, 936], 'small' => [0, 0, 612, 792]];
    private const BACKGROUNDS = [
        'big' => '/assets/images/staff-id-big.jpg',
        'front' => '/assets/images/staff-id-small-front.jpg',
        'back' => '/assets/images/staff-id-small-back.jpg',
    ];

    public function __construct(
        private readonly PdfRendererInterface $pdf,
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function render(StaffIdCard $card, StaffIdSize $size): string
    {
        return $this->pdf->render($this->html($card, $size), self::PAPER[$size->value]);
    }

    public function html(StaffIdCard $card, StaffIdSize $size): string
    {
        $backgrounds = [];
        foreach (self::BACKGROUNDS as $name => $path) {
            $file = $this->projectDir . $path;
            $backgrounds[$name] = is_file($file)
                ? 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($file))
                : null;
        }

        return $this->twig->render(
            '@Staff/staff_id/' . $size->value . '.html.twig',
            ['card' => $card, 'bg' => $backgrounds],
        );
    }
}
