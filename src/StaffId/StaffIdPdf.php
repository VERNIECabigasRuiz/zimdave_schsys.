<?php

namespace App\StaffId;

use App\Core\Domain\Service\Pdf\PdfRendererInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

use function Symfony\Component\String\u;

/** A teacher's or principal's ID as a PDF at actual size, on the school's Big or Small template. */
class StaffIdPdf
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
            $file = $this->projectDir.$path;
            $backgrounds[$name] = is_file($file) ? 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($file)) : null;
        }

        return $this->twig->render('staff_id/'.$size->value.'.html.twig', ['card' => $card, 'bg' => $backgrounds]);
    }

    /** "ID (Big) - DELA CRUZ, JUAN.pdf": the surname and the first given name */
    public static function filename(StaffIdCard $card, StaffIdSize $size): string
    {
        $first = explode(' ', $card->givenNames)[0];
        $name = $card->surname === $card->givenNames ? $card->surname : $card->surname.', '.$first;

        return \sprintf('ID (%s) - %s.pdf', $size->label(), preg_replace('/[\/\\\\%]/', '-', $name));
    }

    /** The same, safe for the Content-Disposition fallback: PEÑA becomes PENA. */
    public static function asciiFilename(StaffIdCard $card, StaffIdSize $size): string
    {
        return (string) u(self::filename($card, $size))->ascii();
    }
}
