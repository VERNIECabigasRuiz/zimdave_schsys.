<?php

namespace App\ServiceRecord;

use App\Core\Domain\Service\Pdf\PdfRendererInterface;
use App\Entity\PersonalDataSheet;
use App\Schools\Domain\Model\School;
use App\Access\Domain\Model\User;
use App\Repository\PersonalDataSheetRepository;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Repository\ServiceRecordEntryRepository;
use App\Repository\ServiceRecordRepository;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

use function Symfony\Component\String\u;

/**
 * The DepEd service record as a PDF on long bond (8.5 × 13 in, portrait): header from the PDS, the rows, and the
 * school's signatories ("Certified correct" falls back to the school's principal).
 */
class ServiceRecordPdf
{
    /** 8.5 × 13 in in points. */
    private const LONG_BOND = [0, 0, 612, 936];
    private const SEAL = '/assets/images/deped-seal.png';

    public function __construct(
        private readonly PdfRendererInterface $pdf,
        private readonly Environment $twig,
        private readonly PersonalDataSheetRepository $sheets,
        private readonly ServiceRecordRepository $records,
        private readonly ServiceRecordEntryRepository $entries,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolClockInterface $clock,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function render(User $employee, School $school): string
    {
        return $this->pdf->render($this->html($employee, $school), self::LONG_BOND);
    }

    public function html(User $employee, School $school): string
    {
        $certifiedName = $school->getSrCertifiedByName();
        $certifiedDesignation = $school->getSrCertifiedByDesignation();
        if (null === $certifiedName) {
            $schoolYear = $this->schoolYears->findCurrent();
            $principal = null === $schoolYear ? null : $this->assignments->findPrincipal($school, $schoolYear)?->getUser();
            $certifiedName = null === $principal ? null : mb_strtoupper($principal->getFullName());
            $certifiedDesignation ??= null === $principal ? null : 'School Principal';
        }
        $seal = $this->projectDir.self::SEAL;

        return $this->twig->render('service_record/pdf.html.twig', [
            'school' => $school,
            'sheet' => $this->sheet($employee),
            'record' => $this->records->findOrNew($employee),
            'rows' => $this->entries->findForEmployee($employee),
            'certified_name' => $certifiedName,
            'certified_designation' => $certifiedDesignation,
            'printed_on' => $this->clock->now(),
            'seal' => is_file($seal) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($seal)) : null,
        ]);
    }

    /** "Service record - DELA CRUZ, JUAN.pdf"; "Service record.pdf" while the PDS has no name. */
    public function filename(User $employee): string
    {
        $sheet = $this->sheet($employee);
        $name = implode(', ', array_filter([
            mb_strtoupper(trim((string) $sheet?->surname)),
            mb_strtoupper(trim((string) $sheet?->firstName)),
        ]));
        $name = (string) preg_replace('/[\/\\\\%"]/', '-', $name);

        return '' === $name ? 'Service record.pdf' : 'Service record - '.$name.'.pdf';
    }

    /** filename() in plain ASCII ("PEÑA" → "PENA"), for browsers that ignore the UTF-8 name. */
    public function asciiFilename(User $employee): string
    {
        return (string) u($this->filename($employee))->ascii();
    }

    private function sheet(User $employee): ?PersonalDataSheet
    {
        return $this->sheets->findOneBy(['user' => $employee]);
    }
}
